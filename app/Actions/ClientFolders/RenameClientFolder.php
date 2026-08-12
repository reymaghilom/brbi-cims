<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RenameClientFolder
{
    public function execute(User $actor, ClientFolder $folder, string $displayName): void
    {
        DB::transaction(function () use ($actor, $folder, $displayName): void {
            $previousName = $folder->display_name;
            $folder->update(['display_name' => $displayName]);

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'client_folder.renamed',
                'module' => 'client_folders',
                'description' => 'A client folder was renamed.',
                'metadata' => ['previous_name' => $previousName, 'new_name' => $displayName],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }
}
