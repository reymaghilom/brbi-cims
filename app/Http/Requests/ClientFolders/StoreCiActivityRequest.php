<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Services\ClientFolders\ActivePersonResolver;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCiActivityRequest extends FormRequest
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'video/mp4' => ['mp4'],
    ];

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('clientFolder'));
    }

    public function rules(): array
    {
        return [
            'co_maker_id' => ActivePersonResolver::rule($this->route('clientFolder')),
            'activity_definition_id' => [
                'nullable',
                Rule::requiredIf(! $this->boolean('create_new_activity_type')),
                'integer',
                Rule::exists('activity_definitions', 'id')->where('is_active', true),
            ],
            'create_new_activity_type' => ['required', 'boolean'],
            'new_activity_type' => [
                'nullable',
                Rule::requiredIf($this->boolean('create_new_activity_type')),
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->boolean('create_new_activity_type') || ! is_string($value)) {
                        return;
                    }

                    if (ActivityDefinition::isDedicatedModuleName($value)) {
                        $fail('Residence Check and Business Check use their dedicated Client Folder modules.');

                        return;
                    }

                    $existing = ActivityDefinition::equivalentToName($value);
                    if ($existing && ! $existing->is_active) {
                        $fail('An inactive activity type with this name already exists.');
                    }
                },
            ],
            'status' => [Rule::excludeIf($this->boolean('create_new_activity_type')), 'required', Rule::enum(ActivityStatus::class)],
            'scheduled_at' => [
                Rule::excludeIf($this->boolean('create_new_activity_type')),
                'nullable',
                'date',
                Rule::requiredIf($this->input('status') === ActivityStatus::Scheduled->value),
            ],
            'scheduled_time' => [Rule::excludeIf($this->boolean('create_new_activity_type')), 'nullable', 'date_format:H:i'],
            'remarks' => [Rule::excludeIf($this->boolean('create_new_activity_type')), 'nullable', 'string', 'max:20000'],
            'attachment' => [
                Rule::prohibitedIf($this->boolean('create_new_activity_type') || $this->input('status') !== ActivityStatus::Completed->value),
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,mp4',
                'max:'.config('cims.media.video_max_kilobytes'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'attachment.prohibited' => 'Proof may only be uploaded when the activity status is Completed.',
            'attachment.mimes' => 'Only JPG, JPEG, PNG, WEBP, and MP4 files are supported.',
            'attachment.max' => 'The selected proof exceeds the 50 MB video limit.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('attachment');
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                return;
            }

            $mime = $this->verifiedMimeType($file);
            $extension = strtolower($file->getClientOriginalExtension());
            if (! isset(self::MIME_EXTENSIONS[$mime]) || ! in_array($extension, self::MIME_EXTENSIONS[$mime], true)) {
                $validator->errors()->add('attachment', 'The file extension does not match its verified media type.');
            }

            if (str_starts_with($mime, 'image/') && $file->getSize() > config('cims.media.image_max_kilobytes') * 1024) {
                $validator->errors()->add('attachment', 'Photos must not exceed 10 MB.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $createNewType = $this->input('activity_definition_id') === ActivityDefinition::NEW_TYPE_VALUE
            || $this->boolean('create_new_activity_type');
        $statusSupportsSchedule = in_array($this->input('status'), [
            ActivityStatus::Scheduled->value,
            ActivityStatus::FollowUp->value,
        ], true);

        $this->merge([
            'co_maker_id' => filled($this->input('co_maker_id')) ? (int) $this->input('co_maker_id') : null,
            'activity_definition_id' => $createNewType ? null : $this->input('activity_definition_id'),
            'create_new_activity_type' => $createNewType,
            'new_activity_type' => is_string($this->input('new_activity_type'))
                ? ActivityDefinition::normalizeName($this->input('new_activity_type'))
                : null,
            'scheduled_at' => $statusSupportsSchedule ? $this->input('scheduled_at') : null,
            'scheduled_time' => $statusSupportsSchedule ? $this->input('scheduled_time') : null,
            'remarks' => $this->normalized('remarks'),
        ]);
    }

    private function normalized(string $field): ?string
    {
        $value = $this->input($field);
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function verifiedMimeType(UploadedFile $file): string
    {
        $detector = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $detector->file($file->getRealPath());

        return strtolower(is_string($mime) ? $mime : 'application/octet-stream');
    }
}
