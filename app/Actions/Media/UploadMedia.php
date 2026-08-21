<?php

namespace App\Actions\Media;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Models\User;
use App\Services\Media\PrivateMediaStorage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UploadMedia
{
    public function __construct(private readonly PrivateMediaStorage $storage) {}

    public function execute(User $actor, ClientFolder $folder, array $data): array
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($actor, $folder, $data, &$storedPaths): array {
                $records = [];
                foreach ($data['files'] as $file) {
                    $stored = $this->storage->store($folder, $file);
                    $storedPaths[] = $stored['temporary_local_path'];
                    $storedPaths[] = $stored['thumbnail_path'];
                    $media = MediaReference::create(Arr::except($stored, ['suggested_label']) + [
                        'client_folder_id' => $folder->id,
                        'co_maker_id' => $data['co_maker_id'] ?? null,
                        'income_source_id' => $data['income_source_id'] ?? null,
                        'category' => $data['category'],
                        'label' => $data['label'] ?: $stored['suggested_label'],
                        'remarks' => $data['remarks'] ?? null,
                        'captured_at' => $data['captured_at'] ?? null,
                        'uploaded_by' => $actor->id,
                        'temporary_expires_at' => null,
                    ]);
                    if (filled($data['ci_activity_id'] ?? null)) {
                        $media->activities()->attach((int) $data['ci_activity_id'], ['label' => $media->label]);
                    }
                    AuditLog::create([
                        'user_id' => $actor->id,
                        'client_folder_id' => $folder->id,
                        'action' => 'media.uploaded',
                        'module' => 'media',
                        'description' => 'A protected media evidence item was uploaded.',
                        'metadata' => ['media_reference_id' => $media->id, 'media_type' => $media->media_type->value, 'category' => $media->category->value, 'byte_size' => $media->byte_size],
                        'ip_address' => request()?->ip(),
                        'user_agent' => request()?->userAgent(),
                    ]);
                    $records[] = $media;
                }

                return $records;
            });
        } catch (\Throwable $exception) {
            $this->storage->deleteStoredFiles($storedPaths);
            throw $exception;
        }
    }
}
