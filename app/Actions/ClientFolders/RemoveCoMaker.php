<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveCoMaker
{
    public function execute(User $actor, ClientFolder $folder, CoMaker $coMaker): void
    {
        DB::transaction(function () use ($actor, $folder, $coMaker): void {
            $fullName = $coMaker->full_name;
            $coMakerId = $coMaker->id;
            $coMaker->delete();

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'co_maker.removed',
                'module' => 'client_folders',
                'description' => 'A co-maker was removed.',
                'metadata' => ['co_maker_id' => $coMakerId, 'full_name' => $fullName],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }
}
