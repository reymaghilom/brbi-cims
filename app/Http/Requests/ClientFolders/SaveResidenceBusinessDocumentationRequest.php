<?php

namespace App\Http\Requests\ClientFolders;

use App\Models\ResidenceBusinessDocumentation;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Saves one Residence/Business Documentation set. The media fields are optional and exist so the
 * very first save can create the set and attach whatever the encoder staged in the same step —
 * they never make the user create an empty set first. Once a set exists its media can also still
 * be added/removed through the dedicated per-set upload routes.
 */
class SaveResidenceBusinessDocumentationRequest extends FormRequest
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'video/mp4' => ['mp4'],
    ];

    public function authorize(): bool
    {
        $documentation = $this->route('documentation');

        return $documentation instanceof ResidenceBusinessDocumentation
            ? $this->user()->can('update', $documentation)
            : $this->user()->can('create', [ResidenceBusinessDocumentation::class, $this->route('clientFolder')]);
    }

    public function rules(): array
    {
        $maxFiles = config('cims.media.max_files_per_upload');
        $imageMax = config('cims.media.image_max_kilobytes');

        return [
            'co_maker_id' => ActivePersonResolver::rule($this->route('clientFolder')),
            'category' => ['required', Rule::in([
                ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
                ResidenceBusinessDocumentation::CATEGORY_BUSINESS,
            ])],
            'business_name' => [
                Rule::requiredIf(fn (): bool => $this->input('category') === ResidenceBusinessDocumentation::CATEGORY_BUSINESS
                    && ! ($this->route('documentation') instanceof ResidenceBusinessDocumentation && $this->route('documentation')->isLegacyBusiness())),
                Rule::prohibitedIf(fn (): bool => $this->input('category') === ResidenceBusinessDocumentation::CATEGORY_RESIDENCE),
                'nullable',
                'string',
                'max:255',
            ],
            'location' => ['required', 'string', 'max:2000'],
            // Optional for both Residence and Business — blank saves normally and never blocks.
            'remarks' => ['nullable', 'string', 'max:1000'],
            // Optional Google Map Screenshot: a single image, never a video.
            'map_screenshot' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.$imageMax],
            'remove_map_screenshot' => ['nullable', 'boolean'],
            'pictures' => ['nullable', 'array', 'max:'.$maxFiles],
            'pictures.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.$imageMax],
            // Video stays optional: no video must never fail validation.
            'videos' => ['nullable', 'array', 'max:'.$maxFiles],
            'videos.*' => ['file', 'mimes:mp4', 'max:'.config('cims.media.video_max_kilobytes')],
        ];
    }

    public function messages(): array
    {
        $videoMaxMegabytes = (int) ceil(config('cims.media.video_max_kilobytes') / 1024);

        return [
            'pictures.max' => 'Upload no more than :max pictures at one time.',
            'videos.max' => 'Upload no more than :max videos at one time.',
            'videos.*.mimes' => 'The selected video format is not supported. Upload an MP4 video.',
            'videos.*.max' => "The selected video is too large to upload. Maximum allowed size is {$videoMaxMegabytes} MB.",
        ];
    }

    /** Same verified-MIME-vs-extension guard the dedicated documentation upload requests apply, so a staged first-save can never smuggle in a mislabeled file. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateDocumentationType($validator);
            $this->assertVerifiedType($validator, 'map_screenshot', $this->file('map_screenshot'), false);

            foreach ((array) $this->file('pictures', []) as $index => $file) {
                $this->assertVerifiedType($validator, "pictures.$index", $file, false);
            }

            foreach ((array) $this->file('videos', []) as $index => $file) {
                if ($file instanceof UploadedFile && ! $file->isValid()) {
                    $this->replaceVideoUploadFailure($validator, "videos.$index", $file);

                    continue;
                }
                $this->assertVerifiedType($validator, "videos.$index", $file, true);
            }
        });
    }

    private function validateDocumentationType(Validator $validator): void
    {
        $documentation = $this->route('documentation');
        if ($documentation instanceof ResidenceBusinessDocumentation
            && $documentation->category !== $this->input('category')) {
            $validator->errors()->add('category', 'The documentation type cannot be changed after it is created.');

        }
    }

    protected function prepareForValidation(): void
    {
        $location = trim((string) preg_replace('/\s+/u', ' ', (string) $this->input('location')));
        $remarks = trim((string) $this->input('remarks'));
        $businessName = trim((string) $this->input('business_name'));
        $this->merge([
            'location' => $location === '' ? null : $location,
            'remarks' => $remarks === '' ? null : $remarks,
            'business_name' => $businessName === '' ? null : $businessName,
        ]);
    }

    private function assertVerifiedType(Validator $validator, string $key, mixed $file, bool $expectsVideo): void
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return;
        }

        $mime = $this->verifiedMimeType($file);
        $extension = strtolower($file->getClientOriginalExtension());
        if (! isset(self::MIME_EXTENSIONS[$mime]) || ! in_array($extension, self::MIME_EXTENSIONS[$mime], true)) {
            $validator->errors()->add($key, 'The file extension does not match its verified media type.');

            return;
        }

        $isVideoMime = $mime === 'video/mp4';
        if ($expectsVideo && ! $isVideoMime) {
            $validator->errors()->add($key, 'Only MP4 videos may be uploaded here.');
        } elseif (! $expectsVideo && $isVideoMime) {
            $validator->errors()->add($key, 'Only photos may be uploaded here.');
        }
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
