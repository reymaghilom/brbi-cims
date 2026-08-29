<?php

namespace App\Actions\Media;

use App\Enums\MediaCategory;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Models\User;
use App\Services\Media\CloudinaryCiActivityProofStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UploadCiActivityProof
{
    public function __construct(private readonly CloudinaryCiActivityProofStorage $storage) {}

    public function execute(User $actor, ClientFolder $folder, CiActivity $activity, UploadedFile $file): MediaReference
    {
        throw_unless(
            $activity->client_folder_id === $folder->id,
            \InvalidArgumentException::class,
            'The proof activity does not belong to the selected Client Folder.',
        );

        $stored = $this->storage->store($folder, $activity, $file);

        try {
            return DB::transaction(function () use ($actor, $folder, $activity, $stored): MediaReference {
                $media = MediaReference::create(Arr::except($stored, ['suggested_label']) + [
                    'client_folder_id' => $folder->id,
                    'co_maker_id' => $activity->co_maker_id,
                    'income_source_id' => null,
                    'category' => MediaCategory::Other->value,
                    'label' => $activity->name.' proof',
                    'remarks' => null,
                    'captured_at' => null,
                    'uploaded_by' => $actor->id,
                    'temporary_expires_at' => null,
                ]);
                $activity->mediaReferences()->attach($media->id, ['label' => $media->label]);
                AuditLog::create([
                    'user_id' => $actor->id,
                    'client_folder_id' => $folder->id,
                    'action' => 'media.uploaded',
                    'module' => 'media',
                    'description' => 'A protected media evidence item was uploaded.',
                    'metadata' => [
                        'media_reference_id' => $media->id,
                        'co_maker_id' => $media->co_maker_id,
                        'media_type' => $media->media_type->value,
                        'category' => $media->category->value,
                        'byte_size' => $media->byte_size,
                    ],
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]);

                return $media;
            });
        } catch (\Throwable $exception) {
            try {
                $this->storage->delete(
                    (string) ($stored['cloudinary_public_id'] ?? ''),
                    (string) ($stored['cloudinary_resource_type'] ?? ''),
                );
            } catch (\Throwable) {
                // Preserve the database failure; cleanup can be retried operationally using the stored public id.
            }

            throw $exception;
        }
    }
}
