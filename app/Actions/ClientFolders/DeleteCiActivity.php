<?php

namespace App\Actions\ClientFolders;

use App\Actions\Media\RemoveCiActivityProof;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\CiActivitiesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lock order across the CI Activity module, kept compatible so no path can deadlock another:
 *
 *  - DELETE: the ci_activities row first, then each of that activity's media_references rows.
 *  - UPDATE (UpdateCiActivity): the ci_activities row only.
 *  - CREATE (CreateCiActivity): the activity_definitions row, then it INSERTs a ci_activities row;
 *    it never waits on an existing activity row lock, so it cannot close a cycle.
 *  - The client_folders lock used by progress recalculation is never held alongside any of these:
 *    ClientProgressService defers to DB::afterCommit() when called inside a transaction.
 */
class DeleteCiActivity
{
    public function __construct(
        private readonly CiActivitiesCompletionEvaluator $completion,
        private readonly ClientProgressService $progress,
        private readonly RemoveCiActivityProof $proofRemoval,
    ) {}

    public function execute(User $actor, ClientFolder $folder, CiActivity $activity): void
    {
        $this->executeMany($actor, $folder, [$activity]);
    }

    /** @param iterable<CiActivity> $activities */
    public function executeMany(User $actor, ClientFolder $folder, iterable $activities): void
    {
        // Barangay Check and Neighbor Check used to be undeletable, because nothing but the
        // retired auto-seeding could put them back. They are manually added built-in types now, so
        // deleting one is an ordinary, reversible action: the requirement itself stays mandatory
        // and simply reads incomplete again until the type is re-added and completed. Progress and
        // folder status are recalculated authoritatively below, exactly as for any other activity.
        $activities = collect($activities)->values();

        $cleanups = DB::transaction(function () use ($actor, $folder, $activities): array {
            $cleanups = [];

            foreach ($activities as $activity) {
                // The route-bound instance is a snapshot from before this transaction: its status,
                // definition, creator and proof list may all already be stale. The row is re-read
                // and locked FIRST — scoped to this exact folder and this exact person
                // (co_maker_id NULL = Applicant, an exact id = that Co-Maker) and this exact id —
                // and everything below reads from that locked row. A second delete of the same
                // activity then finds nothing and fails as not-found instead of writing another
                // audit event and retiring the same proof twice.
                $lockedActivity = $this->exactActivityQuery($folder, $activity)->lockForUpdate()->firstOrFail();
                $lockedActivity->load('definition');

                // Proof is gathered only after the activity lock is held, so a file attached by an
                // edit that won the race is included rather than orphaned. This also fixes the lock
                // ORDER: every delete now takes CiActivity -> MediaReference, never the reverse.
                array_push($cleanups, ...$this->proofRemoval->detachForActivityDeletion($folder, $lockedActivity));
                $this->deleteOne($actor, $folder, $lockedActivity);
            }

            $this->completion->evaluate($folder);
            $this->progress->recalculate($folder);

            return $cleanups;
        });

        foreach ($cleanups as $cleanup) {
            $this->proofRemoval->retireStorage($cleanup);
        }
    }

    /**
     * Delete identity is the exact CI Activity ROW — its id, inside this exact folder and this
     * exact person's scope. Never its name, definition or status, so one activity can never stand
     * in for another.
     */
    private function exactActivityQuery(ClientFolder $folder, CiActivity $activity): Builder
    {
        return CiActivity::query()
            ->whereKey($activity->getKey())
            ->where('client_folder_id', $folder->id)
            ->when(
                $activity->co_maker_id === null,
                fn (Builder $query) => $query->whereNull('co_maker_id'),
                fn (Builder $query) => $query->where('co_maker_id', $activity->co_maker_id),
            );
    }

    private function deleteOne(User $actor, ClientFolder $folder, CiActivity $activity): void
    {
        $activity->forceFill(['updated_by' => $actor->id])->save();
        $deletionTime = now();

        AuditLog::create([
            'user_id' => $actor->id,
            'client_folder_id' => $folder->id,
            'action' => 'ci_activity.deleted',
            'module' => 'ci_activities',
            'description' => 'A CI activity was permanently deleted.',
            'metadata' => [
                'activity_id' => $activity->id,
                'co_maker_id' => $activity->co_maker_id,
                'activity_definition_id' => $activity->activity_definition_id,
                'activity_title' => $activity->definition->name,
                'creator_id' => $activity->creator_id,
                'status' => $activity->status->value,
                'deletion_time' => $deletionTime->toISOString(),
            ],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);

        $activity->forceDelete();
    }
}
