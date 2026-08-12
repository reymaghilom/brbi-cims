<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurgeClientFolder
{
    public function execute(User $actor, ClientFolder $folder): void
    {
        if ($this->hasDeferredExternalCleanup($folder)) {
            throw ValidationException::withMessages([
                'confirmation' => 'This folder has file or external-integration references. Permanent deletion is deferred until the approved cleanup workflow is available.',
            ]);
        }

        DB::transaction(function () use ($actor, $folder): void {
            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'client_folder.permanently_deleted',
                'module' => 'client_folders',
                'description' => 'A recycled client folder was permanently deleted.',
                'metadata' => [
                    'folder_id' => $folder->id,
                    'folder_number' => $folder->folder_number,
                    'display_name' => $folder->display_name,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $folder->forceDelete();
        });
    }

    private function hasDeferredExternalCleanup(ClientFolder $folder): bool
    {
        return $folder->mediaReferences()->exists()
            || $folder->attachments()->exists()
            || $folder->driveReferences()->exists()
            || $folder->telegramMessages()->exists()
            || $folder->generatedReports()->exists();
    }
}
