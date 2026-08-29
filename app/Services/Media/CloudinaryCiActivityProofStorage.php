<?php

namespace App\Services\Media;

use App\Enums\MediaType;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class CloudinaryCiActivityProofStorage
{
    private ?Cloudinary $client = null;

    public function folderFor(ClientFolder $folder, CiActivity $activity): string
    {
        $personPath = $activity->co_maker_id === null
            ? 'applicant'
            : 'co-makers/'.$activity->co_maker_id;

        return trim((string) config('cims.cloudinary.root', 'brbi-cims'), '/')
            .'/client-folders/'.$folder->id
            .'/'.$personPath
            .'/ci-activities/'.$activity->id
            .'/proof';
    }

    public function store(ClientFolder $folder, CiActivity $activity, UploadedFile $file): array
    {
        throw_unless(
            $activity->client_folder_id === $folder->id,
            \InvalidArgumentException::class,
            'The proof activity does not belong to the selected Client Folder.',
        );

        $mime = $this->verifiedMimeType($file);
        $mediaType = str_starts_with($mime, 'image/') ? MediaType::Photo : MediaType::Video;
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            default => throw new \InvalidArgumentException('Unsupported verified proof media type.'),
        };
        $assetName = Str::uuid()->toString();
        $result = $this->cloudinary()->uploadApi()->upload($file->getRealPath(), [
            'folder' => $this->folderFor($folder, $activity),
            'public_id' => $assetName,
            'resource_type' => 'auto',
            'overwrite' => false,
            'use_filename' => false,
            'unique_filename' => false,
        ]);
        $publicId = (string) ($result['public_id'] ?? '');
        $resourceType = (string) ($result['resource_type'] ?? '');
        $secureUrl = (string) ($result['secure_url'] ?? '');
        throw_if($publicId === '' || $resourceType === '' || $secureUrl === '', \RuntimeException::class, 'Cloudinary returned incomplete proof metadata.');

        $originalName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        if ($originalName === '') {
            $originalName = $assetName.'.'.$extension;
        }

        return [
            'media_type' => $mediaType,
            'file_name' => $originalName,
            'mime_type' => $mime,
            'byte_size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'storage_provider' => 'cloudinary',
            'temporary_local_path' => null,
            'thumbnail_path' => null,
            'cloudinary_public_id' => $publicId,
            'cloudinary_resource_type' => $resourceType,
            'cloudinary_secure_url' => $secureUrl,
            'suggested_label' => Str::of(pathinfo($originalName, PATHINFO_FILENAME))->replace(['_', '-'], ' ')->squish()->limit(255, '')->toString(),
        ];
    }

    public function delete(string $publicId, string $resourceType): void
    {
        if ($publicId === '' || $resourceType === '') {
            return;
        }

        $this->cloudinary()->uploadApi()->destroy($publicId, [
            'resource_type' => $resourceType,
            'invalidate' => true,
        ]);
    }

    private function verifiedMimeType(UploadedFile $file): string
    {
        $detector = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $detector->file($file->getRealPath());

        return strtolower(is_string($detectedMime) ? $detectedMime : 'application/octet-stream');
    }

    private function cloudinary(): Cloudinary
    {
        if ($this->client instanceof Cloudinary) {
            return $this->client;
        }

        $url = (string) config('cims.cloudinary.url');
        throw_if($url === '', \RuntimeException::class, 'Cloudinary proof storage is not configured.');

        return $this->client = new Cloudinary($url);
    }
}
