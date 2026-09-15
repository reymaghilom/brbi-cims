<?php

namespace App\Actions\ClientFolders;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\ClientFolderEditingPresence;
use App\Services\ClientFolders\ClientFolderFileCleanup;
use App\Services\ClientFolders\ClientFolderSavedRecords;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Permanent Client Folder delete.
 *
 *  - Credit Investigator / Senior Credit Investigator: only a folder that is still empty (see
 *    ClientFolderSavedRecords) may be deleted.
 *  - Administrator: may also delete a folder that already contains saved records. The database
 *    graph is removed by the folder's cascading foreign keys inside one transaction, and the
 *    folder's stored files are retired only after that transaction commits (ClientFolderFileCleanup).
 *  - Every role: another user's live unsaved/saving work in the folder blocks the delete
 *    (ClientFolderEditingPresence).
 *
 * Any refusal or failure rolls the whole transaction back: nothing is removed, no file is touched,
 * and no successful-delete audit is written.
 */
class PurgeClientFolder
{
    private const HAS_SAVED_RECORDS = 'This Client Folder can no longer be deleted because it already contains saved records.';

    private const FAILED = 'Unable to delete this Client Folder. Please try again.';

    public function __construct(
        private readonly ClientFolderEditingPresence $editingPresence,
        private readonly ClientFolderSavedRecords $savedRecords,
        private readonly ClientFolderFileCleanup $fileCleanup,
    ) {}

    public function execute(User $actor, ClientFolder $folder): void
    {
        $mayDeleteSavedRecords = $actor->role === UserRole::Administrator;
        if (! $mayDeleteSavedRecords) {
            $this->assertStillEmpty($folder);
        }

        try {
            // Held around the whole check-and-delete, so no heartbeat can record new unsaved or
            // saving work between the presence check and the delete itself.
            $files = $this->editingPresence->whileLocked($folder->id, fn (): array => $this->purge($actor, $folder, $mayDeleteSavedRecords));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'confirmation' => 'This Client Folder is being updated right now. Nothing was deleted. Please try again in a moment.',
            ]);
        } catch (Throwable $exception) {
            // The transaction has already rolled back. Never surface database/exception text.
            report($exception);

            throw ValidationException::withMessages(['confirmation' => self::FAILED]);
        }

        $this->fileCleanup->retire($files);
    }

    private function assertStillEmpty(ClientFolder $folder): void
    {
        if ($this->savedRecords->hasSavedRecords($folder)) {
            throw ValidationException::withMessages(['confirmation' => self::HAS_SAVED_RECORDS]);
        }
    }

    private function assertNoOtherUnsavedWork(User $actor, ClientFolder $folder): void
    {
        $names = $this->editingPresence->blockingEditorNames($folder->id, $actor->id);
        if ($names === []) {
            return;
        }

        $who = match (count($names)) {
            1 => ($names[0] ?: 'another user').' currently has',
            default => implode(', ', array_slice($names, 0, -1)).' and '.end($names).' currently have',
        };

        throw ValidationException::withMessages([
            'confirmation' => "This Client Folder cannot be deleted because {$who} unsaved work in it. Please try again after they finish.",
        ]);
    }

    /** @return array<string, list<mixed>> the stored files to retire once the delete has committed */
    private function purge(User $actor, ClientFolder $folder, bool $mayDeleteSavedRecords): array
    {
        return DB::transaction(function () use ($actor, $folder, $mayDeleteSavedRecords): array {
            // Lock the folder row, then re-check inside the same transaction: a child record saved
            // after the first check (a save racing this delete) is seen here, and on databases with
            // row locks its foreign-key check waits on this lock instead of being cascaded away.
            ClientFolder::query()->whereKey($folder->id)->lockForUpdate()->firstOrFail();
            if (! $mayDeleteSavedRecords) {
                $this->assertStillEmpty($folder);
            }
            $this->assertNoOtherUnsavedWork($actor, $folder);

            $hadSavedRecords = $this->savedRecords->hasSavedRecords($folder);
            $files = $hadSavedRecords ? $this->fileCleanup->collect($folder) : [];

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'client_folder.permanently_deleted',
                'module' => 'client_folders',
                'description' => 'A client folder was permanently deleted.',
                'metadata' => [
                    'folder_id' => $folder->id,
                    'folder_number' => $folder->folder_number,
                    'display_name' => $folder->display_name,
                    'deleted_by' => $actor->id,
                    'had_saved_records' => $hadSavedRecords,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $folder->forceDelete();

            return $files;
        });
    }
}
