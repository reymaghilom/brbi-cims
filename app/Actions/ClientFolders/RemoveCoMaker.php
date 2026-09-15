<?php

namespace App\Actions\ClientFolders;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Services\ClientFolders\ClientFolderEditingPresence;
use App\Services\ClientFolders\ClientFolderFileCleanup;
use App\Services\ClientFolders\CoMakerSavedRecords;
use App\Services\Progress\ClientProgressService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Permanent Co-Maker delete — the same role model as PurgeClientFolder, scoped to one exact
 * Co-Maker (client_folder_id + co_maker_id):
 *
 *  - Credit Investigator: only a Co-Maker that is still empty (CoMakerSavedRecords).
 *  - Senior CI / Administrator: also a Co-Maker with saved investigation records. (This differs
 *    on purpose from PurgeClientFolder, where a Senior CI may still only delete an EMPTY folder.)
 *    Its owned rows are removed by
 *    the co_maker_id cascading foreign keys (never re-assigned to the Applicant or another
 *    Co-Maker), and its stored files are retired only after the transaction commits.
 *  - Every role: another user's live unsaved/saving work on THIS Co-Maker's records blocks.
 *
 * A refusal or failure rolls everything back: nothing removed, no file touched, no audit.
 */
class RemoveCoMaker
{
    private const HAS_SAVED_RECORDS = 'This Co-Maker already has saved records. Only a Senior CI or Administrator can delete it.';

    private const FAILED = 'Unable to delete this Co-Maker. Please try again.';

    public function __construct(
        private readonly ClientProgressService $progress,
        private readonly CoMakerSavedRecords $savedRecords,
        private readonly ClientFolderEditingPresence $editingPresence,
        private readonly ClientFolderFileCleanup $fileCleanup,
    ) {}

    public function execute(User $actor, ClientFolder $folder, CoMaker $coMaker): void
    {
        // Route scopeBindings() already guarantee this; kept so an in-process caller can never
        // cross folders either.
        if ((int) $coMaker->client_folder_id !== (int) $folder->id) {
            throw (new ModelNotFoundException)->setModel(CoMaker::class, [$coMaker->id]);
        }

        $mayDeleteSavedRecords = self::mayDeleteSavedRecords($actor);
        if (! $mayDeleteSavedRecords) {
            $this->assertStillEmpty($coMaker);
        }

        try {
            $files = $this->editingPresence->whileLocked($folder->id, fn (): array => $this->remove($actor, $folder, $coMaker, $mayDeleteSavedRecords));
        } catch (ValidationException|ModelNotFoundException $exception) {
            throw $exception;
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'co_maker' => 'This Co-Maker is being updated right now. Please try again in a moment.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['co_maker' => self::FAILED]);
        }

        $this->fileCleanup->retire($files);
    }

    /**
     * Co-Maker only: Senior CIs and Administrators may delete a Co-Maker that already contains
     * saved investigation records; Credit Investigators may not. The delete dialog reads the same
     * rule, so the UI and the server can never disagree.
     */
    public static function mayDeleteSavedRecords(User $user): bool
    {
        return in_array($user->role, [UserRole::Administrator, UserRole::SeniorCreditInvestigator], true);
    }

    private function assertStillEmpty(CoMaker $coMaker): void
    {
        if ($this->savedRecords->hasSavedRecords($coMaker)) {
            throw ValidationException::withMessages(['co_maker' => self::HAS_SAVED_RECORDS]);
        }
    }

    /** @return array<string, list<mixed>> */
    private function remove(User $actor, ClientFolder $folder, CoMaker $coMaker, bool $mayDeleteSavedRecords): array
    {
        return DB::transaction(function () use ($actor, $folder, $coMaker, $mayDeleteSavedRecords): array {
            // Locked, exact re-read: a Co-Maker already removed by someone else answers as missing,
            // and a record saved for it after the first check is seen here.
            $locked = CoMaker::query()->whereKey($coMaker->id)->where('client_folder_id', $folder->id)->lockForUpdate()->firstOrFail();
            if (! $mayDeleteSavedRecords) {
                $this->assertStillEmpty($locked);
            }

            $names = $this->editingPresence->blockingEditorNamesForCoMaker($folder->id, $locked->id, $actor->id);
            if ($names !== []) {
                $who = count($names) === 1
                    ? $names[0].' is'
                    : implode(', ', array_slice($names, 0, -1)).' and '.end($names).' are';

                throw ValidationException::withMessages([
                    'co_maker' => "This Co-Maker cannot be deleted because {$who} currently working on it. Please try again after they finish.",
                ]);
            }

            $hadSavedRecords = $this->savedRecords->hasSavedRecords($locked);
            $files = $hadSavedRecords ? $this->fileCleanup->collect($folder, $locked) : [];
            $fullName = $locked->full_name;
            $coMakerId = $locked->id;

            $locked->delete();
            // Their four mandatory requirements leave the folder with them.
            $this->progress->recalculate($folder);

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'co_maker.removed',
                'module' => 'client_folders',
                'description' => 'A co-maker was removed.',
                'metadata' => [
                    'co_maker_id' => $coMakerId,
                    'full_name' => $fullName,
                    'deleted_by' => $actor->id,
                    'had_saved_records' => $hadSavedRecords,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return $files;
        });
    }
}
