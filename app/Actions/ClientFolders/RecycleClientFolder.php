<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecycleClientFolder
{
    public function execute(User $actor, ClientFolder $folder): void
    {
        DB::transaction(function () use ($actor, $folder): void {
            $folder->forceFill(['deleted_by' => $actor->id])->save();
            $folder->delete();

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'client_folder.recycled',
                'module' => 'client_folders',
                'description' => 'A client folder was moved to the Recycle Bin.',
                'metadata' => ['folder_number' => $folder->folder_number],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }
}
