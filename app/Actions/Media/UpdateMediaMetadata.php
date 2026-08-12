<?php

namespace App\Actions\Media;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateMediaMetadata
{
    public function execute(User $actor, ClientFolder $folder, MediaReference $media, array $data): void
    {
        DB::transaction(function () use ($actor, $folder, $media, $data): void {
            $media->update(Arr::only($data, ['category', 'label', 'remarks', 'captured_at', 'income_source_id']));
            $activityIds = filled($data['ci_activity_id'] ?? null) ? [(int) $data['ci_activity_id']] : [];
            $media->activities()->syncWithPivotValues($activityIds, ['label' => $media->label]);
            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'media.updated',
                'module' => 'media',
                'description' => 'Media evidence metadata was updated.',
                'metadata' => ['media_reference_id' => $media->id, 'media_type' => $media->media_type->value, 'category' => $media->category->value],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }
}
