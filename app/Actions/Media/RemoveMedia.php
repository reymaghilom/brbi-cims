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
            $media->loadMissing('documentation');
            $documentationId = $media->residence_business_documentation_id;
            $category = $media->category->value;
            $mediaType = $media->media_type->value;
            $media->delete();
            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => $documentationId ? 'residence_business_documentation.media_removed' : 'media.removed',
                'module' => 'media',
                'description' => $documentationId
                    ? 'Removed a '.ucfirst($category).' '.($mediaType === 'video' ? 'Video.' : 'Picture.')
                    : 'A media evidence item was moved out of the active gallery.',
                'metadata' => [
                    'media_reference_id' => $media->id,
                    'residence_business_documentation_id' => $documentationId,
                    'co_maker_id' => $media->co_maker_id,
                    'business_name' => $media->documentation?->business_name,
                    'media_type' => $mediaType,
                    'category' => $category,
                    'documentation_kind' => $mediaType === 'video' ? 'video' : 'picture',
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }
}
