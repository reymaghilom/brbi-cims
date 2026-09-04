<?php

namespace App\Http\Requests\ClientFolders;

use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/** Same single-file, verified-MIME-vs-extension pattern as SaveResidenceCheckRequest's own map_screenshot field — screenshot images only, never a video. */
class UploadDocumentationMapScreenshotRequest extends FormRequest
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('documentation'));
    }

    public function rules(): array
    {
        return [
            'co_maker_id' => ActivePersonResolver::rule($this->route('clientFolder')),
            'map_screenshot' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('cims.media.image_max_kilobytes')],
            'remove_map_screenshot' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('map_screenshot');
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                return;
            }

            $mime = $this->verifiedMimeType($file);
            $extension = strtolower($file->getClientOriginalExtension());
            if (! isset(self::MIME_EXTENSIONS[$mime]) || ! in_array($extension, self::MIME_EXTENSIONS[$mime], true)) {
                $validator->errors()->add('map_screenshot', 'The file extension does not match its verified media type.');
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
