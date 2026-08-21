<?php

namespace App\Services\Users;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores Admin-uploaded user profile photos on the public disk (storage/app/public, served via
 * the storage:link symlink) — unlike client-folder media, these need to be directly viewable in
 * the application header for every request, so they can't live behind the private/protected
 * media disk the rest of the app uses for evidence files.
 */
class ProfilePhotoStorage
{
    private const DISK = 'public';

    private const DIRECTORY = 'profile-photos';

    public function store(UploadedFile $file): string
    {
        $detector = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $detector->file($file->getRealPath());
        $mime = strtolower(is_string($detectedMime) ? $detectedMime : 'application/octet-stream');
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new \InvalidArgumentException('Unsupported profile photo type.'),
        };

        $name = Str::uuid()->toString().'.'.$extension;
        $path = $file->storeAs(self::DIRECTORY, $name, self::DISK);
        throw_unless(is_string($path), \RuntimeException::class, 'The profile photo could not be stored.');

        return $path;
    }

    public function delete(?string $path): void
    {
        if (filled($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function url(?string $path): ?string
    {
        return filled($path) ? Storage::disk(self::DISK)->url($path) : null;
    }
}
