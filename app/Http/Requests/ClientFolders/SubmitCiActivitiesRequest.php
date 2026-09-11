<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\ActivityStatus;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SubmitCiActivitiesRequest extends FormRequest
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    protected $errorBag = 'submission';

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('clientFolder'));
    }

    public function rules(): array
    {
        $folder = $this->route('clientFolder');
        $expectedCoMakerId = blank($this->input('co_maker_id')) ? null : (int) $this->input('co_maker_id');

        return [
            'co_maker_id' => ActivePersonResolver::rule($folder),
            'activity_ids' => ['required', 'array', 'min:1', 'max:50'],
            'activity_ids.*' => [
                'integer',
                Rule::exists('ci_activities', 'id')->where(function ($query) use ($folder, $expectedCoMakerId): void {
                    $query->where('client_folder_id', $folder->id)
                        ->where('status', ActivityStatus::Completed->value)
                        ->whereNull('deleted_at');
                    $expectedCoMakerId === null ? $query->whereNull('co_maker_id') : $query->where('co_maker_id', $expectedCoMakerId);
                }),
            ],
            'submitted_to' => ['nullable', 'string', 'max:255'],
            'submission_note' => ['nullable', 'string', 'max:20000'],
            'proofs' => ['nullable', 'array'],
            'proofs.*' => ['nullable', 'array'],
            'proofs.*.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:'.config('cims.media.image_max_kilobytes'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'activity_ids.required' => 'Select at least one completed CI activity to submit.',
            'activity_ids.*.exists' => 'Only exact, completed CI activities for the current Applicant/Co-Maker can be submitted.',
            'proofs.*.*.mimes' => 'Only JPG, PNG, and WEBP images are allowed.',
            'proofs.*.*.max' => 'Photos must not exceed 10 MB.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $activityIds = array_map('intval', array_filter((array) $this->input('activity_ids'), 'is_numeric'));
            $proofs = $this->file('proofs', []);
            $proofs = is_array($proofs) ? $proofs : [];

            foreach ($proofs as $activityKey => $files) {
                $files = is_array($files) ? $files : [];
                $newCount = count(array_filter($files, fn (mixed $file): bool => $file instanceof UploadedFile));
                if ($newCount === 0) {
                    continue;
                }

                if (! in_array((int) $activityKey, $activityIds, true)) {
                    $validator->errors()->add('proofs.'.$activityKey, 'Proof was submitted for an activity that is not part of this submission.');

                    continue;
                }

                $existingCount = (int) DB::table('activity_media')->where('ci_activity_id', (int) $activityKey)->count();
                $maxPhotos = (int) config('cims.media.max_files_per_upload');
                if ($existingCount + $newCount > $maxPhotos) {
                    $validator->errors()->add('proofs.'.$activityKey, "You can attach up to {$maxPhotos} supporting photos per activity.");
                }

                foreach ($files as $index => $file) {
                    if (! $file instanceof UploadedFile || ! $file->isValid()) {
                        continue;
                    }

                    $mime = $this->verifiedMimeType($file);
                    $extension = strtolower($file->getClientOriginalExtension());
                    if (! isset(self::MIME_EXTENSIONS[$mime]) || ! in_array($extension, self::MIME_EXTENSIONS[$mime], true)) {
                        $validator->errors()->add("proofs.{$activityKey}.{$index}", 'The file extension does not match its verified media type.');
                    }
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'co_maker_id' => filled($this->input('co_maker_id')) ? (int) $this->input('co_maker_id') : null,
            'submitted_to' => $this->normalized('submitted_to'),
            'submission_note' => $this->normalized('submission_note'),
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
