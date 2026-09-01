<?php

namespace App\Actions\Media;

use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Models\User;
use App\Services\Media\ClientMediaUploader;
use App\Services\Media\CloudinaryCiActivityProofStorage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class RemoveCiActivityProof
{
    public function __construct(
        private readonly ClientMediaUploader $mediaUploader,
        private readonly CloudinaryCiActivityProofStorage $cloudinaryStorage,
    ) {}

    public function execute(User $actor, ClientFolder $folder, CiActivity $activity, MediaReference $media): void
    {
        $cleanup = DB::transaction(function () use ($actor, $folder, $activity, $media): ?array {
            $lockedMedia = MediaReference::query()->lockForUpdate()->findOrFail($media->id);
            $this->ensureExactOwnership($folder, $activity, $lockedMedia);

            $cleanup = $this->detachAndRetireIfOrphaned($activity, $lockedMedia);
            $deleteReference = $cleanup !== null;

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'ci_activity.proof_removed',
                'module' => 'ci_activities',
                'description' => 'A CI activity proof attachment was removed.',
                'metadata' => [
                    'activity_id' => $activity->id,
                    'co_maker_id' => $activity->co_maker_id,
                    'media_reference_id' => $lockedMedia->id,
                    'media_reference_deleted' => $deleteReference,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return $cleanup;
        });

        $this->retireStorage($cleanup);
    }

    /** @return list<array{storage_provider: string, public_id: mixed, resource_type: mixed, path: mixed, thumbnail_path: mixed}> */
    public function detachForActivityDeletion(ClientFolder $folder, CiActivity $activity): array
    {
        $cleanups = [];
        $mediaIds = $activity->mediaReferences()->pluck('media_references.id');

        foreach ($mediaIds as $mediaId) {
            $lockedMedia = MediaReference::query()->lockForUpdate()->findOrFail($mediaId);
            $this->ensureExactOwnership($folder, $activity, $lockedMedia);
            $cleanup = $this->detachAndRetireIfOrphaned($activity, $lockedMedia);

            if ($cleanup !== null) {
                $cleanups[] = $cleanup;
            }
        }

        return $cleanups;
    }

    /** @param array{storage_provider: string, public_id: mixed, resource_type: mixed, path: mixed, thumbnail_path: mixed}|null $cleanup */
    public function retireStorage(?array $cleanup): void
    {
        if ($cleanup === null) {
            return;
        }

        if ($cleanup['storage_provider'] === MediaReference::STORAGE_PROVIDER_CLOUDINARY) {
            $this->cloudinaryStorage->delete(
                (string) ($cleanup['public_id'] ?? ''),
                (string) ($cleanup['resource_type'] ?? ''),
            );

            return;
        }

        $this->mediaUploader->deleteLocal($cleanup['path'] ?? null, $cleanup['thumbnail_path'] ?? null);
    }

    /** @return array{storage_provider: string, public_id: mixed, resource_type: mixed, path: mixed, thumbnail_path: mixed}|null */
    private function detachAndRetireIfOrphaned(CiActivity $activity, MediaReference $media): ?array
    {
        $activity->mediaReferences()->detach($media->id);

        if ($media->activities()->exists() || $media->photoReportItems()->exists()) {
            return null;
        }

        $cleanup = [
            'storage_provider' => $media->storage_provider,
            'public_id' => $media->cloudinary_public_id,
            'resource_type' => $media->cloudinary_resource_type,
            'path' => $media->temporary_local_path,
            'thumbnail_path' => $media->thumbnail_path,
        ];
        $media->delete();

        return $cleanup;
    }

    private function ensureExactOwnership(ClientFolder $folder, CiActivity $activity, MediaReference $media): void
    {
        $isExact = $activity->client_folder_id === $folder->id
            && $media->client_folder_id === $folder->id
            && $media->co_maker_id === $activity->co_maker_id
            && $activity->mediaReferences()->whereKey($media->id)->exists();

        if (! $isExact) {
            throw (new ModelNotFoundException)->setModel(MediaReference::class, [$media->id]);
        }
    }
}
