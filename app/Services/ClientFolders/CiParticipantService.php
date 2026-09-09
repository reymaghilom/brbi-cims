<?php

namespace App\Services\ClientFolders;

use App\Models\User;
use App\Services\ClientFolders\Contracts\HasCiParticipants;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reusable CI participant behavior shared by Business Report (IncomeSource), Business Check,
 * and Residence Check. The primary CI (the original creator / CI-in-charge) is never stored in
 * the companion pivot — it always comes from the owning record's own actor column, resolved via
 * HasCiParticipants::ciPrimaryUserId(). That is what makes it structurally impossible for a
 * companion sync to remove it: it simply isn't part of the syncable set.
 *
 * CIBI does not implement HasCiParticipants and is intentionally untouched by this service.
 */
class CiParticipantService
{
    /**
     * Primary CI first, then companions in their saved order. Deduplicated, order-preserving.
     * With no companion rows yet (legacy records), this safely returns just the primary.
     *
     * @return array<int, int>
     */
    public function orderedParticipantIds(HasCiParticipants $owner): array
    {
        $primaryId = $owner->ciPrimaryUserId();

        $companionIds = $owner->contributors()
            ->orderByPivot('position')
            ->orderByPivot('id')
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id !== $primaryId)
            ->unique()
            ->values()
            ->all();

        return $primaryId ? [$primaryId, ...$companionIds] : $companionIds;
    }

    /** Same ordering as orderedParticipantIds(), hydrated into Users. */
    public function orderedParticipants(HasCiParticipants $owner): Collection
    {
        $ids = $this->orderedParticipantIds($owner);

        if ($ids === []) {
            return collect();
        }

        $usersById = User::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $usersById->get($id))->filter()->values();
    }

    /** "REYNALDO J. OBASA / MARK S. DELA CRUZ" — order preserved, casing left to the caller. */
    public function fullNames(HasCiParticipants $owner, string $glue = ' / '): string
    {
        return $this->orderedParticipants($owner)->map(fn (User $user) => $user->full_name)->implode($glue);
    }

    /**
     * ENCODING-FORM DISPLAY ONLY — never persisted, never printed on an output.
     *
     * The first two CIs on a record keep their full names; every CI after them is shown by first
     * given name alone, so a long companion list stays readable in the report header while the
     * stored users, assignments and signatories are completely untouched. The web preview, PDF and
     * Excel all keep using fullNames() above.
     *
     * @param  int  $position  0 for the primary CI, then 1, 2, … for each companion in saved order.
     */
    public static function compactDisplayName(?string $fullName, int $position): string
    {
        $name = trim((string) $fullName);

        if ($position < 2 || $name === '') {
            return $name;
        }

        return (string) Str::of($name)->explode(' ')->first();
    }

    /** "REY / MARK" — first token of full_name only, order preserved. */
    public function firstNames(HasCiParticipants $owner, string $glue = ' / '): string
    {
        return $this->orderedParticipants($owner)
            ->map(fn (User $user) => Str::of($user->full_name)->trim()->explode(' ')->first())
            ->implode($glue);
    }

    /**
     * Read-only: would syncCompanions() with this input actually change anything? Safe to call
     * before other not-yet-saved changes on the same owner — unlike syncCompanions() (and
     * anything built on it, e.g. UpdateIncomeSourceContributors), this never calls the owner's
     * refresh(), so it can't discard in-memory attribute changes a caller hasn't saved yet.
     *
     * @param  array<int, int|string>  $companionUserIds
     */
    public function wouldChangeCompanions(HasCiParticipants $owner, array $companionUserIds): bool
    {
        $primaryId = $owner->ciPrimaryUserId();
        $clean = $this->cleanCompanionIds($companionUserIds, $primaryId);
        $current = array_values(array_filter($this->orderedParticipantIds($owner), fn (int $id): bool => $id !== $primaryId));

        return $clean !== $current;
    }

    /**
     * Syncs the companion list for an owning record. The primary CI is always excluded from the
     * stored set (defensive, in case a future caller submits it alongside companions), input ids
     * are deduplicated while preserving the caller's given order, and position is persisted so
     * the order survives future adds/removes.
     *
     * @param  array<int, int|string>  $companionUserIds
     */
    public function syncCompanions(HasCiParticipants $owner, array $companionUserIds): CiParticipantSyncResult
    {
        $before = $this->orderedParticipantIds($owner);
        $primaryId = $owner->ciPrimaryUserId();
        $clean = $this->cleanCompanionIds($companionUserIds, $primaryId);

        $currentCompanionIds = array_values(array_filter($before, fn (int $id): bool => $id !== $primaryId));

        // Skip the write entirely when the companion set isn't actually changing — sync() would
        // otherwise still issue an UPDATE (and bump pivot timestamps) for every already-attached
        // row it's given fresh pivot data for, even when nothing about it actually changed.
        if ($clean === $currentCompanionIds) {
            return new CiParticipantSyncResult(before: $before, after: $before, added: [], removed: []);
        }

        $pivotData = [];
        foreach ($clean as $index => $id) {
            $pivotData[$id] = ['position' => $index + 1];
        }

        $owner->contributors()->sync($pivotData);

        $after = $this->orderedParticipantIds($owner);

        return new CiParticipantSyncResult(
            before: $before,
            after: $after,
            added: array_values(array_diff($after, $before)),
            removed: array_values(array_diff($before, $after)),
        );
    }

    /** @param  array<int, int|string>  $companionUserIds  @return array<int, int> */
    private function cleanCompanionIds(array $companionUserIds, ?int $primaryId): array
    {
        $seen = [];
        $clean = [];
        foreach ($companionUserIds as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0 || $id === $primaryId || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $clean[] = $id;
        }

        return $clean;
    }
}
