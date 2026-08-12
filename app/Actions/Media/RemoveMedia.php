<?php

namespace App\Actions\Media;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveMedia
{
    public function execute(User $actor, ClientFolder $folder, MediaReference $media): void
    {
        DB::transaction(function () use ($actor, $folder, $media): void {
            $media->delete();
            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'media.removed',
                'module' => 'media',
                'description' => 'A media evidence item was moved out of the active gallery.',
                'metadata' => ['media_reference_id' => $media->id, 'media_type' => $media->media_type->value, 'category' => $media->category->value],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }
}
