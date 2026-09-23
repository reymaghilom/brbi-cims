<?php

namespace App\Http\Requests\ClientFolders;

use App\Http\Requests\ClientFolders\Concerns\ValidatesCheckPhotoUploads;
use App\Models\ResidenceCheck;
use App\Rules\CiContributorRule;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveResidenceCheckRequest extends FormRequest
{
    use ValidatesCheckPhotoUploads;

    private const DELETED_MESSAGE = 'This Residence Check was deleted by another user while you were working on it. Please return to the Residence & Business Check page.';

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('clientFolder'));
    }

    public function rules(): array
    {
        $folder = $this->route('clientFolder');
        $coMakerId = blank($this->input('co_maker_id')) ? null : (int) $this->input('co_maker_id');

        return [
            'co_maker_id' => ActivePersonResolver::rule($folder),
            'check_id' => [
                'nullable', 'integer',
                Rule::exists('residence_checks', 'id')->where('client_folder_id', $folder->id)->where('co_maker_id', $coMakerId),
            ],
            // Identifies one loaded copy of the Add Residence Check form (a fresh UUID rendered
            // once per page/iframe load — see the hidden input in residence-checks/form.blade.php),
            // not the CI or the record. SaveResidenceCheck uses it to tell "the same save, submitted
            // twice" (double-click, a retried request) apart from a genuinely separate Add action —
            // which always reloads the form and gets its own new token.
            'request_token' => ['nullable', 'string', 'max:100'],
            'expected_revision' => ['required_with:check_id', 'nullable', 'integer', 'min:1'],
            'ci_date' => ['nullable', 'date', 'before_or_equal:today'],
            'location' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:10000'],
            'google_maps_link' => ['nullable', 'string', 'max:2048'],
            'removed_photo_ids' => ['nullable', 'array'],
            'removed_photo_ids.*' => ['integer'],
            'map_screenshot' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('cims.media.image_max_kilobytes')],
            'remove_map_screenshot' => ['nullable', 'boolean'],
            'contributor_ids' => ['sometimes', 'array', 'max:10'],
            'contributor_ids.*' => [
                'integer',
                'distinct',
                CiContributorRule::exists($this->existingCheck()?->contributors()->pluck('users.id') ?? []),
            ],
        ] + $this->photoUploadRules('photos');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validatePhotoUploads($validator, 'photos');
            $this->validateMapScreenshotUpload($validator);
            $this->validateResidencePictureCount($validator);
        });
    }

    /**
     * The final intended count is all valid new uploads plus existing photos that were not marked
     * for removal. A Residence Check must retain between one and ten pictures after the save.
     */
    private function validateResidencePictureCount(Validator $validator): void
    {
        $newPhotoCount = collect((array) $this->file('photos', []))
            ->filter(fn ($file) => $file instanceof UploadedFile && $file->isValid())
            ->count();
        $finalPhotoCount = $newPhotoCount + $this->remainingExistingResidencePhotoCount();

        if ($finalPhotoCount < 1) {
            $validator->errors()->add('photos', 'At least one residence picture is required.');
        } elseif ($finalPhotoCount > 10) {
            $validator->errors()->add('photos', 'A maximum of 10 residence pictures is allowed.');
        }
    }

    public function messages(): array
    {
        return [
            'photos.max' => 'A maximum of 10 residence pictures is allowed.',
            // Only reachable when check_id was submitted (so this is an edit of an existing check)
            // and the rule above finds no such Residence Check for this exact folder + person — in
            // practice, the record was deleted while this form was still open. Scoped to this
            // request class and this one rule, so Laravel's default "selected ... is invalid"
            // wording is untouched everywhere else. The save still fails exactly as before; only
            // the sentence the CI reads changes.
            'check_id.exists' => 'This Residence Check was deleted by another user while you were working on it. Please return to the Residence & Business Report page.',
        ];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        // An AJAX edit whose exact folder + Applicant/Co-Maker scoped check_id disappeared is the
        // delete-while-editing outcome, not an ordinary field-validation failure. Return a stable
        // contract before the save action can upload media, write an audit row, or create anything.
        // Plain non-JS submissions retain Laravel's existing validation redirect and input/errors.
        $checkId = $this->input('check_id');
        $recordWasDeleted = filled($checkId)
            && ctype_digit((string) $checkId)
            && ResidenceCheck::query()->whereKey((int) $checkId)->doesntExist();

        if ($this->expectsJson() && $recordWasDeleted && $validator->errors()->has('check_id')) {
            $folder = $this->route('clientFolder');
            $activePerson = ActivePersonResolver::resolve($folder, blank($this->input('co_maker_id')) ? null : (int) $this->input('co_maker_id'));

            throw new HttpResponseException(response()->json([
                'result' => 'deleted',
                'message' => self::DELETED_MESSAGE,
                'status_type' => 'error',
                'residence_check_id' => (int) $checkId,
                'return_url' => route('client-folders.residence-business.edit', [$folder] + ActivePersonResolver::queryParams($activePerson)),
            ], 404));
        }

        parent::failedValidation($validator);
    }

    private function remainingExistingResidencePhotoCount(): int
    {
        $check = $this->existingCheck();
        if (! $check) {
            return 0;
        }

        $removedIds = array_map('intval', (array) $this->input('removed_photo_ids', []));

        return $check->photos()->whereNotIn('id', $removedIds)->count();
    }

    private ?ResidenceCheck $existingCheckCache = null;

    private bool $existingCheckResolved = false;

    /** The check this request is updating (null for a create), scoped by folder + co_maker_id exactly like the `check_id` validation rule above. */
    private function existingCheck(): ?ResidenceCheck
    {
        if ($this->existingCheckResolved) {
            return $this->existingCheckCache;
        }
        $this->existingCheckResolved = true;

        $checkId = $this->input('check_id');
        if (blank($checkId)) {
            return $this->existingCheckCache = null;
        }

        $folder = $this->route('clientFolder');
        $coMakerId = blank($this->input('co_maker_id')) ? null : (int) $this->input('co_maker_id');

        return $this->existingCheckCache = ResidenceCheck::query()
            ->where('client_folder_id', $folder->id)
            ->where('co_maker_id', $coMakerId)
            ->find((int) $checkId);
    }

    /** Same verified-MIME-vs-extension check as photoUploadRules()/validatePhotoUploads(), applied to the single map_screenshot file rather than an array field. */
    private function validateMapScreenshotUpload(Validator $validator): void
    {
        $file = $this->file('map_screenshot');
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return;
        }

        $mime = $this->verifiedMimeType($file);
        $extension = strtolower($file->getClientOriginalExtension());
        if (! isset(self::MIME_EXTENSIONS[$mime]) || ! in_array($extension, self::MIME_EXTENSIONS[$mime], true)) {
            $validator->errors()->add('map_screenshot', 'The file extension does not match its verified media type.');
        }
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['check_id' => filled($this->input('check_id')) ? $this->input('check_id') : null]);

        // The companion CI picker always renders this marker alongside its contributor_ids[]
        // hidden inputs, even when zero companions are selected — without it, an all-removed
        // submission would arrive with no contributor_ids key at all (browsers don't send empty
        // arrays), which is indistinguishable from "the companion picker wasn't part of this
        // submission" and would leave the last-saved companion list untouched instead of cleared.
        // Same convention as SaveBusinessCheckRequest's own contributor_ids_present handling.
        if ($this->boolean('contributor_ids_present')) {
            $this->merge([
                'contributor_ids' => collect((array) $this->input('contributor_ids', []))
                    ->filter(fn ($value): bool => filled($value))
                    ->map(fn ($value): int => (int) $value)
                    ->unique()
                    ->values()
                    ->all(),
            ]);
        }
    }
}
