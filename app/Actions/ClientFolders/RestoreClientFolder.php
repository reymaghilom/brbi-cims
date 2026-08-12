<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreClientFolder
{
    public function execute(User $actor, ClientFolder $folder): void
    {
        DB::transaction(function () use ($actor, $folder): void {
            $folder->restore();
            $folder->forceFill(['deleted_by' => null])->save();

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'client_folder.restored',
                'module' => 'client_folders',
                'description' => 'A client folder was restored from the Recycle Bin.',
                'metadata' => ['folder_number' => $folder->folder_number],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }
}
