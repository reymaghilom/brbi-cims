<?php

namespace App\Services\ClientFolders;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Folder-level view of the existing editing-presence heartbeats (EditingPresenceController).
 *
 * Every heartbeat for a presence-enabled record is also recorded here under that record's own
 * Client Folder, together with the page's reported state:
 *  - viewing: the page is open with no unsaved changes (informational only)
 *  - dirty:   a real editable value differs from what the server rendered
 *  - saving:  a save/update/upload request is in flight
 *
 * Only another user's live dirty/saving entry blocks a permanent folder delete. Entries share the
 * heartbeat TTL, so a closed or crashed browser stops blocking once its last heartbeat expires —
 * there are no lock rows and nothing to unlock manually.
 */
class ClientFolderEditingPresence
{
    public const TTL_SECONDS = 90;

    public const STATE_VIEWING = 'viewing';

    public const STATES = [self::STATE_VIEWING, 'dirty', 'saving'];

    private const BLOCKING_STATES = ['dirty', 'saving'];

    private const LOCK_SECONDS = 10;

    /** $coMakerId is the record's own person scope: null for Applicant records. */
    public function record(int $clientFolderId, string $type, int $recordId, User $user, string $state, ?int $coMakerId = null): void
    {
        $this->withFolderLock($clientFolderId, 3, function () use ($clientFolderId, $type, $recordId, $user, $state, $coMakerId): void {
            $entries = $this->activeEntries($clientFolderId);
            $entries["{$type}:{$recordId}:{$user->id}"] = [
                'user_id' => $user->id,
                'name' => $user->full_name,
                'co_maker_id' => $coMakerId,
                'state' => in_array($state, self::STATES, true) ? $state : self::STATE_VIEWING,
                'expires_at' => now()->addSeconds(self::TTL_SECONDS)->toJSON(),
            ];

            Cache::put($this->indexKey($clientFolderId), $entries, self::TTL_SECONDS);
        }, bestEffort: true);
    }

    public function forget(int $clientFolderId, string $type, int $recordId, int $userId): void
    {
        $this->withFolderLock($clientFolderId, 3, function () use ($clientFolderId, $type, $recordId, $userId): void {
            $entries = $this->activeEntries($clientFolderId);
            unset($entries["{$type}:{$recordId}:{$userId}"]);

            $entries === []
                ? Cache::forget($this->indexKey($clientFolderId))
                : Cache::put($this->indexKey($clientFolderId), $entries, self::TTL_SECONDS);
        }, bestEffort: true);
    }

    /**
     * Distinct display names of OTHER users with live dirty/saving work in this folder.
     *
     * @return list<string>
     */
    public function blockingEditorNames(int $clientFolderId, int $exceptUserId): array
    {
        return $this->blockingNames($clientFolderId, $exceptUserId, fn (): bool => true);
    }

    /**
     * Same, limited to work on records owned by this exact Co-Maker — so editing Co-Maker A never
     * blocks deleting Co-Maker B, and Applicant work never blocks deleting a Co-Maker. An entry
     * recorded without a person scope (from before this field existed) is treated as blocking.
     *
     * @return list<string>
     */
    public function blockingEditorNamesForCoMaker(int $clientFolderId, int $coMakerId, int $exceptUserId): array
    {
        return $this->blockingNames(
            $clientFolderId,
            $exceptUserId,
            fn (array $entry): bool => ! array_key_exists('co_maker_id', $entry) || ($entry['co_maker_id'] !== null && (int) $entry['co_maker_id'] === $coMakerId),
        );
    }

    /** @return list<string> */
    private function blockingNames(int $clientFolderId, int $exceptUserId, Closure $inScope): array
    {
        return collect($this->activeEntries($clientFolderId))
            ->filter(fn (array $entry): bool => $entry['user_id'] !== $exceptUserId && in_array($entry['state'], self::BLOCKING_STATES, true) && $inScope($entry))
            ->unique('user_id')
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * Runs $callback while holding the folder's presence lock, so no heartbeat can record new
     * dirty/saving work between a delete's presence check and the delete itself.
     *
     * @throws LockTimeoutException
     */
    public function whileLocked(int $clientFolderId, Closure $callback): mixed
    {
        return $this->withFolderLock($clientFolderId, 5, $callback);
    }

    private function withFolderLock(int $clientFolderId, int $waitSeconds, Closure $callback, bool $bestEffort = false): mixed
    {
        try {
            return Cache::lock($this->indexKey($clientFolderId).':lock', self::LOCK_SECONDS)->block($waitSeconds, $callback);
        } catch (LockTimeoutException $exception) {
            // A heartbeat is a best-effort signal; the next one (or the TTL) catches up.
            if ($bestEffort) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, array{user_id: int, name: string, state: string, expires_at: string}>
     */
    private function activeEntries(int $clientFolderId): array
    {
        $now = now();

        return array_filter(
            Cache::get($this->indexKey($clientFolderId), []),
            fn (array $entry): bool => $now->lt($entry['expires_at']),
        );
    }

    private function indexKey(int $clientFolderId): string
    {
        return "editing:folder:{$clientFolderId}";
    }
}
