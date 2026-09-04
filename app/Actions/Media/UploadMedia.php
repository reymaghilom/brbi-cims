<?php

namespace App\Actions\Media;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Models\ResidenceBusinessDocumentation;
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
        $storageProvider = MediaReference::STORAGE_PROVIDER_LOCAL;

        try {
            return DB::transaction(function () use ($actor, $folder, $data, &$storedPaths, &$storageProvider): array {
                $records = [];
                $documentation = $data['documentation'] ?? null;
                foreach ($data['files'] as $file) {
                    $stored = $documentation instanceof ResidenceBusinessDocumentation
                        ? $this->storage->storeDocumentation($documentation, $file, $data['documentation_kind'] ?? (str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'picture'))
                        : $this->storage->store($folder, $file);
                    $storageProvider = $stored['storage_provider'];
                    $storedPaths[] = $stored['temporary_local_path'];
                    $storedPaths[] = $stored['thumbnail_path'];
                    $media = MediaReference::create(Arr::except($stored, ['suggested_label']) + [
                        'client_folder_id' => $folder->id,
                        'co_maker_id' => $data['co_maker_id'] ?? null,
                        'income_source_id' => $documentation instanceof ResidenceBusinessDocumentation ? null : ($data['income_source_id'] ?? null),
                        'residence_business_documentation_id' => $data['residence_business_documentation_id'] ?? null,
                        'category' => $data['category'],
                        'label' => ($data['label'] ?? null) ?: $stored['suggested_label'],
                        'remarks' => $data['remarks'] ?? null,
                        'captured_at' => $data['captured_at'] ?? null,
                        'uploaded_by' => $actor->id,
                        'temporary_expires_at' => null,
                    ]);
                    if (filled($data['ci_activity_id'] ?? null)) {
                        $media->activities()->attach((int) $data['ci_activity_id'], ['label' => $media->label]);
                    }
                    if (! $documentation instanceof ResidenceBusinessDocumentation) {
                        AuditLog::create([
                            'user_id' => $actor->id,
                            'client_folder_id' => $folder->id,
                            'action' => 'media.uploaded',
                            'module' => 'media',
                            'description' => 'A protected media evidence item was uploaded.',
                            'metadata' => ['media_reference_id' => $media->id, 'co_maker_id' => $media->co_maker_id, 'media_type' => $media->media_type->value, 'category' => $media->category->value, 'byte_size' => $media->byte_size],
                            'ip_address' => request()?->ip(),
                            'user_agent' => request()?->userAgent(),
                        ]);
                    }
                    $records[] = $media;
                }

                if ($documentation instanceof ResidenceBusinessDocumentation && $records !== []) {
                    $kind = ($data['documentation_kind'] ?? null) === 'video' ? 'video' : 'picture';
                    $category = ucfirst($documentation->category);
                    $count = count($records);
                    $subject = $kind === 'video' ? 'Video' : 'Picture';
                    AuditLog::create([
                        'user_id' => $actor->id,
                        'client_folder_id' => $folder->id,
                        'action' => 'residence_business_documentation.media_uploaded',
                        'module' => 'media',
                        'description' => "Uploaded {$count} {$category} ".str($subject)->plural($count).'.',
                        'metadata' => [
                            'residence_business_documentation_id' => $documentation->id,
                            'co_maker_id' => $documentation->co_maker_id,
                            'business_name' => $documentation->business_name,
                            'category' => $documentation->category,
                            'documentation_kind' => $kind,
                            'count' => $count,
                        ],
                        'ip_address' => request()?->ip(),
                        'user_agent' => request()?->userAgent(),
                    ]);
                }

                return $records;
            });
        } catch (\Throwable $exception) {
            try {
                $this->storage->deleteStoredFiles($storedPaths, $storageProvider);
            } catch (\Throwable) {
                // Rollback cleanup is best-effort and must not replace the original storage error.
            }
            throw $exception;
        }
    }
}
