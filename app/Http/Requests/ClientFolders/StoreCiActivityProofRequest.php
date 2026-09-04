<?php

namespace App\Http\Requests\ClientFolders;

use App\Models\CiActivity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class StoreCiActivityProofRequest extends FormRequest
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    public function authorize(): bool
    {
        $folder = $this->route('clientFolder');
        $activity = $this->route('ciActivity');

        return $folder !== null
            && $activity !== null
            && $this->user()->can('update', $folder)
            && $this->user()->can('update', $activity)
            && $activity->client_folder_id === $folder->id;
    }

    public function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:'.config('cims.media.image_max_kilobytes'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'photos.required' => 'Select at least one supporting photo.',
            'photos.*.mimes' => 'Only JPG, PNG, and WEBP images are allowed.',
            'photos.*.max' => 'Photos must not exceed 10 MB.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $activity = $this->route('ciActivity');
            $photos = $this->file('photos', []);
            $photos = is_array($photos) ? $photos : [];
            $newCount = count(array_filter($photos, fn (mixed $photo): bool => $photo instanceof UploadedFile));
            $existingCount = $activity instanceof CiActivity ? $activity->mediaReferences()->count() : 0;

            if ($existingCount + $newCount > 5) {
                $validator->errors()->add('photos', 'You can attach up to 5 supporting photos per activity.');
            }

            foreach ($photos as $index => $photo) {
                if (! $photo instanceof UploadedFile || ! $photo->isValid()) {
                    continue;
                }

                $mime = $this->verifiedMimeType($photo);
                $extension = strtolower($photo->getClientOriginalExtension());
                if (! isset(self::MIME_EXTENSIONS[$mime]) || ! in_array($extension, self::MIME_EXTENSIONS[$mime], true)) {
                    $validator->errors()->add("photos.{$index}", 'The file extension does not match its verified media type.');
                }
            }
        });
    }

    private function verifiedMimeType(UploadedFile $file): string
    {
        $detector = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $detector->file($file->getRealPath());

        return strtolower(is_string($mime) ? $mime : 'application/octet-stream');
    }
}
