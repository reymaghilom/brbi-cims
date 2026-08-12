<?php

namespace App\Services\Media;

use App\Enums\MediaType;
use App\Models\ClientFolder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrivateMediaStorage
{
    public function store(ClientFolder $folder, UploadedFile $file): array
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
        $name = Str::uuid()->toString().'.'.$extension;
        $directory = 'client-media/'.$folder->id.'/'.now()->format('Y/m');
        $path = $file->storeAs($directory.'/originals', $name, config('cims.media_disk'));
        throw_unless(is_string($path), \RuntimeException::class, 'The media file could not be stored.');

        $thumbnail = $type === MediaType::Photo ? $this->thumbnail($file, $directory, pathinfo($name, PATHINFO_FILENAME)) : null;

        return [
            'media_type' => $type,
            'file_name' => $name,
            'mime_type' => $mime,
            'byte_size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'temporary_local_path' => $path,
            'thumbnail_path' => $thumbnail,
            'suggested_label' => Str::of(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))->replace(['_', '-'], ' ')->squish()->limit(255, '')->toString(),
        ];
    }

    public function deleteStoredFiles(array $paths): void
    {
        Storage::disk(config('cims.media_disk'))->delete(array_values(array_filter($paths)));
    }

    private function thumbnail(UploadedFile $file, string $directory, string $stem): ?string
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

        $path = $directory.'/thumbnails/'.$stem.'.jpg';

        return Storage::disk(config('cims.media_disk'))->put($path, $bytes) ? $path : null;
    }
}
