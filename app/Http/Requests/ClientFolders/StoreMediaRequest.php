<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\MediaCategory;
use App\Models\MediaReference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMediaRequest extends FormRequest
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'video/mp4' => ['mp4'],
    ];

    public function authorize(): bool
    {
        return $this->user()->can('create', [MediaReference::class, $this->route('clientFolder')]);
    }

    public function rules(): array
    {
        return [
            'media_form' => ['nullable', 'string'],
            'files' => ['required', 'array', 'min:1', 'max:'.config('cims.media.max_files_per_upload')],
            'files.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,mp4', 'max:'.config('cims.media.video_max_kilobytes')],
            'category' => ['required', Rule::enum(MediaCategory::class)],
            'label' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:10000'],
            'captured_at' => ['nullable', 'date', 'before_or_equal:today'],
            'income_source_id' => ['nullable', 'integer'],
            'ci_activity_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'files.required' => 'Select at least one photo or video.',
            'files.max' => 'Upload no more than :max files at one time.',
            'files.*.mimes' => 'Only JPG, JPEG, PNG, WEBP, and MP4 files are supported.',
            'files.*.max' => 'A selected file exceeds the 50 MB video limit.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateRelationships($validator);

            foreach ((array) $this->file('files', []) as $index => $file) {
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    continue;
                }

                $mime = $this->verifiedMimeType($file);
                $extension = strtolower($file->getClientOriginalExtension());
                if (! isset(self::MIME_EXTENSIONS[$mime]) || ! in_array($extension, self::MIME_EXTENSIONS[$mime], true)) {
                    $validator->errors()->add("files.$index", 'The file extension does not match its verified media type.');
                }

                if (str_starts_with($mime, 'image/') && $file->getSize() > config('cims.media.image_max_kilobytes') * 1024) {
                    $validator->errors()->add("files.$index", 'Photos must not exceed 10 MB.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'label' => $this->normalized('label'),
            'remarks' => $this->normalized('remarks'),
            'income_source_id' => filled($this->input('income_source_id')) ? $this->input('income_source_id') : null,
            'ci_activity_id' => filled($this->input('ci_activity_id')) ? $this->input('ci_activity_id') : null,
        ]);
    }

    private function validateRelationships(Validator $validator): void
    {
        $folder = $this->route('clientFolder');
        if (filled($this->input('income_source_id')) && ! $folder->incomeSources()->whereKey($this->integer('income_source_id'))->exists()) {
            $validator->errors()->add('income_source_id', 'The selected income source does not belong to this client folder.');
        }
        if (filled($this->input('ci_activity_id')) && ! $folder->activities()->whereKey($this->integer('ci_activity_id'))->exists()) {
            $validator->errors()->add('ci_activity_id', 'The selected CI activity does not belong to this client folder.');
        }
        if ($this->input('category') !== MediaCategory::Business->value && filled($this->input('income_source_id'))) {
            $validator->errors()->add('income_source_id', 'Only Business media may be linked to an income source.');
        }
    }

    private function normalized(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value === '' ? null : $value;
    }

    private function verifiedMimeType(UploadedFile $file): string
    {
        $detector = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $detector->file($file->getRealPath());

        return strtolower(is_string($mime) ? $mime : 'application/octet-stream');
    }
}
