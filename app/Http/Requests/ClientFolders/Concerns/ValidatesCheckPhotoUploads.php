<?php

namespace App\Http\Requests\ClientFolders\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/** Shared photo-upload validation for Residence/Business Check forms: images only, MIME-sniffed and cross-checked against the extension (never trusted from the client), same size cap as the general media uploader. */
trait ValidatesCheckPhotoUploads
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /** @return array<string, mixed> */
    private function photoUploadRules(string $field, bool $required = false): array
    {
        return [
            $field => [$required ? 'required' : 'nullable', 'array', 'max:'.config('cims.media.max_files_per_upload')],
            "$field.*" => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('cims.media.image_max_kilobytes')],
        ];
    }

    private function validatePhotoUploads(Validator $validator, string $field): void
    {
        foreach ((array) $this->file($field, []) as $index => $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $mime = $this->verifiedMimeType($file);
            $extension = strtolower($file->getClientOriginalExtension());
            if (! isset(self::MIME_EXTENSIONS[$mime]) || ! in_array($extension, self::MIME_EXTENSIONS[$mime], true)) {
                $validator->errors()->add("$field.$index", 'The file extension does not match its verified media type.');
            }
        }
    }

    private function verifiedMimeType(UploadedFile $file): string
    {
        $detector = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $detector->file($file->getRealPath());

        return strtolower(is_string($mime) ? $mime : 'application/octet-stream');
    }
}
