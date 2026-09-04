<?php

namespace App\Services\Media;

use App\Enums\MediaType;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Services\Storage\CiTeamDocumentStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrivateMediaStorage
{
    public function __construct(private readonly CiTeamDocumentStorage $documents) {}

    public function store(ClientFolder $folder, UploadedFile $file): array
    {
        [$mime, $type, $extension] = $this->metadata($file);
        $name = Str::uuid()->toString().'.'.$extension;
        $directory = 'client-media/'.$folder->id.'/'.now()->format('Y/m');
        $path = $file->storeAs($directory.'/originals', $name, config('cims.media_disk'));
        throw_unless(is_string($path), \RuntimeException::class, 'The media file could not be stored.');

        $thumbnail = $type === MediaType::Photo ? $this->thumbnail($file, Storage::disk(config('cims.media_disk')), $directory.'/thumbnails', pathinfo($name, PATHINFO_FILENAME)) : null;

        return $this->result($file, $mime, $type, $name, $path, $thumbnail, MediaReference::STORAGE_PROVIDER_LOCAL);
    }

    /**
     * Stores one evidence file inside the CI Team document tree (the exact Applicant/Co-Maker
     * directory the caller resolved) instead of the flat `client-media/...` media disk. No
     * thumbnail is generated: these directories are browsed directly by CI staff, so a sibling
     * `thumbnails` folder would be visible clutter — readers already fall back to the original
     * whenever `thumbnail_path` is null.
     */
    public function storeInDirectory(UploadedFile $file, string $directory): array
    {
        [$mime, $type, $extension] = $this->metadata($file);
        $name = Str::uuid()->toString().'.'.$extension;
        $directory = $this->documents->relative($directory);
        $path = $this->documents->disk()->putFileAs($directory, $file, $name);
        throw_unless(is_string($path), \RuntimeException::class, 'The media file could not be stored.');

        return $this->result($file, $mime, $type, $name, $path, null, MediaReference::STORAGE_PROVIDER_CI_TEAM);
    }

    /**
     * Deletes stored files using each path's own location, never a global setting: `client-media/`
     * paths belong to the configured media disk and everything else to the CI Team tree, so a
     * record saved under one Evidence Storage mode is still removed correctly under the other.
     */
    public function deleteStoredFiles(array $paths, string $provider = MediaReference::STORAGE_PROVIDER_LOCAL): void
    {
        foreach (array_values(array_filter($paths)) as $path) {
            $disk = $provider === MediaReference::STORAGE_PROVIDER_CI_TEAM
                ? $this->documents->disk()
                : $this->documents->evidenceDisk((string) $path);
            $disk->delete($path);
        }
    }

    private function result(UploadedFile $file, string $mime, MediaType $type, string $name, string $path, ?string $thumbnail, string $provider): array
    {
        return [
            'media_type' => $type,
            'storage_provider' => $provider,
            'file_name' => $name,
            'mime_type' => $mime,
            'byte_size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'temporary_local_path' => $path,
            'thumbnail_path' => $thumbnail,
            'suggested_label' => Str::of(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))->replace(['_', '-'], ' ')->squish()->limit(255, '')->toString(),
        ];
    }

    private function metadata(UploadedFile $file): array
    {
        $detector = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $detector->file($file->getRealPath());
        $mime = strtolower(is_string($detectedMime) ? $detectedMime : 'application/octet-stream');
        $type = str_starts_with($mime, 'image/') ? MediaType::Photo : MediaType::Video;
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            default => throw new \InvalidArgumentException('Unsupported verified media type.'),
        };

        return [$mime, $type, $extension];
    }

    private function thumbnail(UploadedFile $file, FilesystemAdapter $disk, string $directory, string $stem): ?string
    {
        $sourceBytes = file_get_contents($file->getRealPath());
        $source = $sourceBytes === false ? false : @imagecreatefromstring($sourceBytes);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(640 / max(1, $width), 480 / max(1, $height), 1);
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        $background = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $background);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($target, null, 82);
        $bytes = ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);
        if (! is_string($bytes)) {
            return null;
        }

        $path = $directory.'/'.$stem.'.jpg';

        return $disk->put($path, $bytes) ? $path : null;
    }
}
