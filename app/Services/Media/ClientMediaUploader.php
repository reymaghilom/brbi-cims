<?php

namespace App\Services\Media;

use App\Models\ClientFolder;
use Illuminate\Http\UploadedFile;

/**
 * Single upload entry point for the four Cloudinary-migrated media kinds (Residence/Business
 * Pictures and their Map Screenshots): prefers Cloudinary when it's configured
 * (CloudinaryMediaStorage::enabled()), and transparently falls back to this app's existing
 * local/private storage (PrivateMediaStorage) otherwise. Returns one normalized shape regardless
 * of which backend actually stored the file, so SaveResidenceCheck/SaveBusinessCheck never need to
 * branch on where a file ended up — they just persist whichever fields come back.
 */
class ClientMediaUploader
{
    public function __construct(
        private readonly PrivateMediaStorage $local,
        private readonly CloudinaryMediaStorage $cloud,
    ) {}

    /**
     * @return array{
     *     file_name: string, path: ?string, thumbnail_path: ?string, mime_type: string, byte_size: int, checksum: ?string,
     *     cloud_public_id: ?string, cloud_resource_type: ?string, cloud_delivery_type: ?string, cloud_format: ?string, cloud_width: ?int, cloud_height: ?int,
     * }
     */
    public function store(ClientFolder $folder, UploadedFile $file, string $cloudFolder, string $preset = 'photo'): array
    {
        if ($this->cloud->enabled()) {
            $stored = $this->cloud->store($file, $cloudFolder, $preset);

            return [
                'file_name' => $stored['file_name'],
                'path' => null,
                'thumbnail_path' => null,
                'mime_type' => $stored['mime_type'],
                'byte_size' => $stored['byte_size'],
                'checksum' => $stored['checksum'],
                'cloud_public_id' => $stored['cloud_public_id'],
                'cloud_resource_type' => $stored['cloud_resource_type'],
                'cloud_delivery_type' => $stored['cloud_delivery_type'],
                'cloud_format' => $stored['cloud_format'],
                'cloud_width' => $stored['cloud_width'],
                'cloud_height' => $stored['cloud_height'],
            ];
        }

        $stored = $this->local->store($folder, $file);

        return [
            'file_name' => $stored['file_name'],
            'path' => $stored['temporary_local_path'],
            'thumbnail_path' => $stored['thumbnail_path'],
            'mime_type' => $stored['mime_type'],
            'byte_size' => $stored['byte_size'],
            'checksum' => $stored['checksum'],
            'cloud_public_id' => null,
            'cloud_resource_type' => null,
            'cloud_delivery_type' => null,
            'cloud_format' => null,
            'cloud_width' => null,
            'cloud_height' => null,
        ];
    }

    /** Deletes a newly-uploaded orphan after a store() whose owning save failed elsewhere in the same transaction — local file or Cloudinary asset, whichever store() actually produced. */
    public function deleteUpload(array $stored): void
    {
        if (filled($stored['cloud_public_id'] ?? null)) {
            $this->cloud->destroy($stored['cloud_public_id'], $stored['cloud_resource_type'] ?? null, $stored['cloud_delivery_type'] ?? null);

            return;
        }
        $this->local->deleteStoredFiles([$stored['path'] ?? null, $stored['thumbnail_path'] ?? null]);
    }

    /** Immediately deletes an existing local file — unchanged existing behavior, still safe to run inside a DB transaction the way it already does. Cloudinary assets are never deleted through this method; see deferredCloudCleanup() below for why. */
    public function deleteLocal(?string $path, ?string $thumbnailPath): void
    {
        $this->local->deleteStoredFiles([$path, $thumbnailPath]);
    }

    /**
     * Actually destroys a Cloudinary asset that a save replaced or removed — callers must only
     * invoke this AFTER the owning DB transaction has committed successfully (never from inside
     * it), so a rolled-back save can never end up having deleted the asset it just failed to stop
     * using. Local-file removal doesn't need this — see deleteLocal() above.
     */
    public function retireCloudAsset(?string $publicId, ?string $resourceType, ?string $deliveryType): void
    {
        $this->cloud->destroy($publicId, $resourceType, $deliveryType);
    }
}
