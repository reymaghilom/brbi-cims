<?php

namespace App\Http\Requests\ClientFolders;

use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Uploads pictures or videos into one Residence/Business Documentation set — same verified-MIME-vs-extension pattern as StoreMediaRequest, split so a "picture" upload can never sneak in an mp4 (or vice versa). */
class UploadDocumentationMediaRequest extends FormRequest
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'video/mp4' => ['mp4'],
    ];

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('documentation'));
    }

    public function rules(): array
    {
        $isVideo = $this->input('kind') === 'video';

        return [
            'co_maker_id' => ActivePersonResolver::rule($this->route('clientFolder')),
            'kind' => ['required', Rule::in(['picture', 'video'])],
            'files' => ['required', 'array', 'min:1', 'max:'.config('cims.media.max_files_per_upload')],
            'files.*' => [
                'required', 'file',
                $isVideo ? 'mimes:mp4' : 'mimes:jpg,jpeg,png,webp',
                'max:'.($isVideo ? config('cims.media.video_max_kilobytes') : config('cims.media.image_max_kilobytes')),
            ],
        ];
    }

    public function messages(): array
    {
        $videoMaxMegabytes = (int) ceil(config('cims.media.video_max_kilobytes') / 1024);

        return [
            'files.required' => 'Select at least one file.',
            'files.max' => 'Upload no more than :max files at one time.',
            'files.*.mimes' => $this->input('kind') === 'video'
                ? 'The selected video format is not supported. Upload an MP4 video.'
                : 'The selected picture format is not supported.',
            'files.*.max' => $this->input('kind') === 'video'
                ? "The selected video is too large to upload. Maximum allowed size is {$videoMaxMegabytes} MB."
                : 'The selected picture is too large to upload.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->file('files', []) as $index => $file) {
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    if ($file instanceof UploadedFile && $this->input('kind') === 'video') {
                        $this->replaceVideoUploadFailure($validator, "files.$index", $file);
                    }

                    continue;
                }

                $mime = $this->verifiedMimeType($file);
                $extension = strtolower($file->getClientOriginalExtension());
                if (! isset(self::MIME_EXTENSIONS[$mime]) || ! in_array($extension, self::MIME_EXTENSIONS[$mime], true)) {
                    $validator->errors()->add("files.$index", 'The file extension does not match its verified media type.');

                    continue;
                }

                $isVideoMime = $mime === 'video/mp4';
                if ($this->input('kind') === 'video' && ! $isVideoMime) {
                    $validator->errors()->add("files.$index", 'Only MP4 videos may be uploaded here.');
                } elseif ($this->input('kind') === 'picture' && $isVideoMime) {
                    $validator->errors()->add("files.$index", 'Only photos may be uploaded here.');
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

    private function replaceVideoUploadFailure(Validator $validator, string $key, UploadedFile $file): void
    {
        $message = match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE => 'The selected video is too large for the PHP server to upload. Current server file limit is '.config('cims.media.php_upload_max_filesize').'.',
            UPLOAD_ERR_FORM_SIZE => 'The selected video is too large to upload.',
            UPLOAD_ERR_PARTIAL => 'The video upload was interrupted before it completed. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'The server could not accept the selected video. Please contact the system administrator.',
            default => 'The selected video could not be uploaded. Please select it again and retry.',
        };

        $validator->errors()->forget($key);
        $validator->errors()->add($key, $message);
    }
}
