<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\ClientFolders\Concerns\ValidatesCheckPhotoUploads;
use App\Models\BusinessCheck;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveBusinessCheckRequest extends FormRequest
{
    use ValidatesCheckPhotoUploads;

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
                Rule::exists('business_checks', 'id')->where('client_folder_id', $folder->id)->where('co_maker_id', $coMakerId),
            ],
            // Business Check is independent from Business / Income Sources: referencing an
            // existing business is OPTIONAL. A person with no business yet records the business
            // manually (business_name below), and the check simply keeps income_source_id null.
            'income_source_id' => [
                'nullable', 'integer',
                Rule::exists('income_sources', 'id')->where('client_folder_id', $folder->id)->where('co_maker_id', $coMakerId),
            ],
            // Only ever read for a manual (unreferenced) Business Check — when a business IS
            // referenced, SaveBusinessCheck derives the name from that exact business instead and
            // whatever the read-only input submitted is ignored.
            'business_name' => [Rule::requiredIf(blank($this->input('income_source_id'))), 'nullable', 'string', 'max:255'],
            'expected_updated_at' => ['nullable', 'date'],
            'ci_date' => ['required', 'date', 'before_or_equal:today'],
            'location' => ['required', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:10000'],
            'competitor_remarks' => ['nullable', 'string', 'max:10000'],
            'removed_photo_ids' => ['nullable', 'array'],
            'removed_photo_ids.*' => ['integer'],
            'map_screenshot' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('cims.media.image_max_kilobytes')],
            'remove_map_screenshot' => ['nullable', 'boolean'],
            'contributor_ids' => ['sometimes', 'array', 'max:10'],
            'contributor_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('role', UserRole::CreditInvestigator->value)
                    ->where('status', UserStatus::Active->value)),
            ],
            'photo_groups' => ['nullable', 'array'],
            'photo_groups.*.id' => [
                'nullable', 'integer',
                Rule::exists('business_check_photo_groups', 'id')->where('business_check_id', $this->input('check_id')),
            ],
            'photo_groups.*.caption' => ['nullable', 'string', 'max:2000'],
            'photo_groups.*._delete' => ['nullable', 'boolean'],
            'photo_groups.*.photos' => ['nullable', 'array', 'max:'.config('cims.media.max_files_per_upload')],
            'photo_groups.*.photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('cims.media.image_max_kilobytes')],
            'photo_groups.*.removed_photo_ids' => ['nullable', 'array'],
            'photo_groups.*.removed_photo_ids.*' => ['integer'],
            'photo_groups.*.legacy_photo_ids' => ['nullable', 'array'],
            'photo_groups.*.legacy_photo_ids.*' => [
                'integer',
                Rule::exists('business_check_photos', 'id')->where('business_check_id', $this->input('check_id'))->whereNull('business_check_photo_group_id'),
            ],
        ] + $this->photoUploadRules('business_photos') + $this->photoUploadRules('competitor_photos');
    }

    public function messages(): array
    {
        return [
            'location.required' => 'Business location is required.',
            'ci_date.required' => 'CI Date is required.',
            'business_name.required' => 'Business Name is required.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validatePhotoUploads($validator, 'business_photos');
            $this->validatePhotoUploads($validator, 'competitor_photos');
            $this->validateGroupedPhotoUploads($validator);
            $this->validateMapScreenshotUpload($validator);
            $this->validateAtLeastOneBusinessPhoto($validator);

            $folder = $this->route('clientFolder');
            $coMakerId = blank($this->input('co_maker_id')) ? null : (int) $this->input('co_maker_id');
            $incomeSourceId = $this->integer('income_source_id');
            $validBusiness = filled($this->input('income_source_id')) && $folder->incomeSources()
                ->where('co_maker_id', $coMakerId)
                ->whereHas('template', fn ($query) => $query->where('is_fallback', false)->where('form_handler', 'dedicated-business'))
                ->whereKey($incomeSourceId)
                ->exists();
            if (filled($this->input('income_source_id')) && ! $validBusiness) {
                $validator->errors()->add('income_source_id', 'The selected business does not belong to this client folder.');

                return;
            }
            if ($validBusiness && blank($this->input('check_id')) && $folder->businessChecks()
                ->where('co_maker_id', $coMakerId)
                ->where('income_source_id', $incomeSourceId)
                ->exists()) {
                $validator->errors()->add('income_source_id', 'A Business Check already exists for the selected business. Open the existing Business Check to view or edit it.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'check_id' => filled($this->input('check_id')) ? $this->input('check_id') : null,
            // An unselected "Select a business" option posts an empty string; normalising it here
            // is what makes a manual Business Check store a real null rather than 0.
            'income_source_id' => filled($this->input('income_source_id')) ? $this->input('income_source_id') : null,
            'business_name' => is_string($this->input('business_name')) ? trim($this->input('business_name')) : $this->input('business_name'),
        ]);

        // The companion CI picker always renders this marker alongside its contributor_ids[]
        // hidden inputs, even when zero companions are selected — without it, an all-removed
        // submission would arrive with no contributor_ids key at all (browsers don't send empty
        // arrays), which is indistinguishable from "the companion picker wasn't part of this
        // submission" and would leave the last-saved companion list untouched instead of cleared.
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

    /** Same verified-MIME-vs-extension check as validatePhotoUploads(), applied to each Photo Group's own nested `photos` array. */
    private function validateGroupedPhotoUploads(Validator $validator): void
    {
        foreach ((array) $this->input('photo_groups', []) as $groupIndex => $groupData) {
            foreach ((array) $this->file("photo_groups.$groupIndex.photos", []) as $photoIndex => $file) {
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    continue;
                }

                $mime = $this->verifiedMimeType($file);
                $extension = strtolower($file->getClientOriginalExtension());
                if (! isset(self::MIME_EXTENSIONS[$mime]) || ! in_array($extension, self::MIME_EXTENSIONS[$mime], true)) {
                    $validator->errors()->add("photo_groups.$groupIndex.photos.$photoIndex", 'The file extension does not match its verified media type.');
                }
            }
        }
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

    /**
     * At least one Business Photo must exist after this save — a photo in the first/default Photo
     * Group is enough, but any newly uploaded or remaining-existing photo in ANY group (or the
     * legacy flat `business_photos` field, for any direct API caller that still uses it) counts
     * too; the CI never needs "+ Add Photo Group" just to satisfy this. Same
     * new-plus-remaining-existing shape as SaveResidenceCheckRequest::validateAtLeastOneResidencePicture().
     */
    private function validateAtLeastOneBusinessPhoto(Validator $validator): void
    {
        $newPhotoCount = collect((array) $this->file('business_photos', []))
            ->filter(fn ($file) => $file instanceof UploadedFile && $file->isValid())
            ->count();

        foreach (array_keys((array) $this->input('photo_groups', [])) as $groupIndex) {
            $newPhotoCount += collect((array) $this->file("photo_groups.$groupIndex.photos", []))
                ->filter(fn ($file) => $file instanceof UploadedFile && $file->isValid())
                ->count();
        }

        if ($newPhotoCount + $this->remainingExistingBusinessPhotoCount() < 1) {
            $validator->errors()->add('photo_groups', 'At least one business photo is required.');
        }
    }

    private function remainingExistingBusinessPhotoCount(): int
    {
        $check = $this->existingCheck();
        if (! $check) {
            return 0;
        }

        $removedIds = array_map('intval', (array) $this->input('removed_photo_ids', []));
        $deletedGroupIds = [];
        foreach ((array) $this->input('photo_groups', []) as $groupData) {
            $removedIds = [...$removedIds, ...array_map('intval', (array) ($groupData['removed_photo_ids'] ?? []))];
            if (filled($groupData['id'] ?? null) && filter_var($groupData['_delete'] ?? false, FILTER_VALIDATE_BOOL)) {
                $deletedGroupIds[] = (int) $groupData['id'];
            }
        }

        return $check->photos()
            ->where('category', 'business')
            ->when($deletedGroupIds !== [], fn ($query) => $query->whereNotIn('business_check_photo_group_id', $deletedGroupIds))
            ->whereNotIn('id', $removedIds)
            ->count();
    }

    private ?BusinessCheck $existingCheckCache = null;

    private bool $existingCheckResolved = false;

    /** The check this request is updating (null for a create), scoped by folder + co_maker_id exactly like the `check_id` validation rule above. */
    private function existingCheck(): ?BusinessCheck
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

        return $this->existingCheckCache = BusinessCheck::query()
            ->where('client_folder_id', $folder->id)
            ->where('co_maker_id', $coMakerId)
            ->find((int) $checkId);
    }
}
