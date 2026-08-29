<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\ActivityStatus;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class ReplaceCiActivityProofRequest extends FormRequest
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'video/mp4' => ['mp4'],
    ];

    public function authorize(): bool
    {
        $folder = $this->route('clientFolder');
        $activity = $this->route('ciActivity');
        $media = $this->route('mediaReference');

        return $folder !== null
            && $activity !== null
            && $media !== null
            && $this->user()->can('update', $folder)
            && $this->user()->can('update', $activity)
            && $activity->client_folder_id === $folder->id
            && $media->client_folder_id === $folder->id
            && $media->co_maker_id === $activity->co_maker_id
            && $activity->mediaReferences()->whereKey($media->id)->exists();
    }

    public function rules(): array
    {
        return [
            'attachment' => [
                Rule::prohibitedIf($this->route('ciActivity')?->status !== ActivityStatus::Completed),
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,mp4',
                'max:'.config('cims.media.video_max_kilobytes'),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile || ! $value->isValid()) {
                        return;
                    }

                    $mime = $this->verifiedMimeType($value);
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (! isset(self::MIME_EXTENSIONS[$mime]) || ! in_array($extension, self::MIME_EXTENSIONS[$mime], true)) {
                        $fail('The file extension does not match its verified media type.');
                    }

                    if (str_starts_with($mime, 'image/') && $value->getSize() > config('cims.media.image_max_kilobytes') * 1024) {
                        $fail('Photos must not exceed 10 MB.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'attachment.prohibited' => 'Proof may only be replaced when the activity status is Completed.',
            'attachment.mimes' => 'Only JPG, JPEG, PNG, WEBP, and MP4 files are supported.',
            'attachment.max' => 'The selected proof exceeds the 50 MB video limit.',
        ];
    }

    private function verifiedMimeType(UploadedFile $file): string
    {
        $detector = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $detector->file($file->getRealPath());

        return strtolower(is_string($mime) ? $mime : 'application/octet-stream');
    }
}
