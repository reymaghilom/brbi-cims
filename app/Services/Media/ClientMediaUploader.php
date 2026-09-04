<?php

namespace App\Services\Media;

use App\Exceptions\CloudMediaUploadException;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\MediaReference;
use App\Services\Settings\EvidenceStorageSetting;
use App\Services\Storage\CiTeamDocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Single upload entry point for Residence/Business Check Pictures and their Map Screenshots. The
 * provider is decided by one administrator-controlled system-wide setting (EvidenceStorageSetting)
 * — never by whichever backend happens to be reachable — and there is deliberately no silent
 * fallback in either direction: a Cloudinary mode with no configured account fails with the same
 * safe CloudMediaUploadException a failed upload already produces, rather than quietly writing the
 * file to disk. Returns one normalized shape regardless of which backend stored the file, so
 * SaveResidenceCheck/SaveBusinessCheck never need to branch on where a file ended up.
 *
 * Only NEW uploads consult the setting. Existing records keep their own stored path/cloud metadata,
 * which is what deleteUpload()/deleteLocal()/retireCloudAsset() below act on.
 */
class ClientMediaUploader
{
    public function __construct(
        private readonly PrivateMediaStorage $local,
        private readonly CloudinaryMediaStorage $cloud,
        private readonly EvidenceStorageSetting $evidenceStorage,
        private readonly CiTeamDocumentStorage $documents,
        private readonly EvidenceStorageRecorder $recorder,
    ) {}

    /**
     * @return array{
     *     file_name: string, path: ?string, thumbnail_path: ?string, mime_type: string, byte_size: int, checksum: ?string,
     *     cloud_public_id: ?string, cloud_resource_type: ?string, cloud_delivery_type: ?string, cloud_format: ?string, cloud_width: ?int, cloud_height: ?int,
     * }
     */
    public function store(ClientFolder $folder, UploadedFile $file, string $cloudFolder, string $preset = 'photo', bool $organizeForApplicant = false, ?CoMaker $coMaker = null): array
    {
        if ($this->evidenceStorage->usesCloud()) {
            throw_unless($this->cloud->enabled(), CloudMediaUploadException::class);
            if ($organizeForApplicant) {
                $cloudFolder = $this->personCloudFolder($folder, $cloudFolder);
            } elseif ($coMaker) {
                $cloudFolder = $this->personCloudFolder($folder, $cloudFolder, $coMaker);
            }
            $stored = $this->cloud->store($file, $cloudFolder, $preset);
            $this->recorder->record(MediaReference::STORAGE_PROVIDER_CLOUDINARY);

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

        $directory = $this->localEvidenceDirectory($folder, $cloudFolder, $organizeForApplicant ? null : $coMaker);
        $stored = $directory === null
            ? $this->local->store($folder, $file)
            : $this->local->storeInDirectory($file, $directory);
        $this->recorder->record($stored['storage_provider']);

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

    /**
     * Maps one of the four known evidence kinds onto its CI Team local home, mirroring the exact
     * Applicant/Co-Maker directory the rest of that module already writes into. Returns null for
     * any other caller so nothing outside these evidence kinds silently changes storage location.
     */
    private function localEvidenceDirectory(ClientFolder $folder, string $mediaFolder, ?CoMaker $coMaker): ?string
    {
        return match (trim($mediaFolder, '/')) {
            'residence/photos' => $this->documents->residenceCheckPicturesDirectory($folder, $coMaker),
            'residence/map-screenshots' => $this->documents->residenceCheckMapDirectory($folder, $coMaker),
            'business/photos' => $this->documents->businessCheckPicturesDirectory($folder, $coMaker),
            'business/map-screenshots' => $this->documents->businessCheckMapDirectory($folder, $coMaker),
            default => null,
        };
    }

    /** Builds a fully rooted Cloudinary namespace for media belonging to one exact Applicant or Co-Maker. */
    public function rootedPersonCloudFolder(ClientFolder $folder, string $mediaFolder, ?CoMaker $coMaker = null): string
    {
        return $this->cloud->folderFor($this->personCloudFolder($folder, $mediaFolder, $coMaker));
    }

    private function personCloudFolder(ClientFolder $folder, string $mediaFolder, ?CoMaker $coMaker = null): string
    {
        return $coMaker
            ? $this->coMakerCloudFolder($folder, $coMaker, $mediaFolder)
            : $this->applicantCloudFolder($folder, $mediaFolder);
    }

    /** Builds the new Applicant-only Cloudinary namespace; Co-Maker uploads never call this. */
    private function applicantCloudFolder(ClientFolder $folder, string $mediaFolder): string
    {
        $slug = Str::slug((string) $folder->display_name);
        $slug = $slug !== '' ? $slug : 'client';
        $mediaFolder = trim((string) preg_replace('#/+#', '/', $mediaFolder), '/');

        return "clients/CF-{$folder->getKey()}-{$slug}/applicant/{$mediaFolder}";
    }

    /** Builds the namespace for future uploads belonging to one exact Co-Maker. */
    private function coMakerCloudFolder(ClientFolder $folder, CoMaker $coMaker, string $mediaFolder): string
    {
        $folderSlug = Str::slug((string) $folder->display_name) ?: 'client';
        $coMakerSlug = Str::slug((string) $coMaker->full_name) ?: 'co-maker';
        $mediaFolder = trim((string) preg_replace('#/+#', '/', $mediaFolder), '/');

        return "clients/CF-{$folder->getKey()}-{$folderSlug}/co-makers/CM-{$coMaker->getKey()}-{$coMakerSlug}/{$mediaFolder}";
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
