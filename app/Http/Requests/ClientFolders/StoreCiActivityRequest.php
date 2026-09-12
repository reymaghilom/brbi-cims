<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Services\ClientFolders\ActivePersonResolver;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCiActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('clientFolder'));
    }

    public function rules(): array
    {
        $bankCoopCheck = $this->isBankCoopCheck();
        $assetCheck = $this->isAssetCheck();
        $multiTargetCheck = $bankCoopCheck || $assetCheck;
        $excludeParentFields = $this->boolean('create_new_activity_type') || $multiTargetCheck;

        return [
            'co_maker_id' => ActivePersonResolver::rule($this->route('clientFolder')),
            'activity_definition_id' => [
                'nullable',
                Rule::requiredIf(! $this->boolean('create_new_activity_type')),
                'integer',
                Rule::exists('activity_definitions', 'id')->where('is_active', true),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->boolean('create_new_activity_type') || ! ctype_digit((string) $value)) {
                        return;
                    }

                    $code = ActivityDefinition::query()->whereKey((int) $value)->value('code');
                    if (ActivityDefinition::isMandatoryDefaultCode($code)) {
                        $fail('Barangay Check and Neighbor Check are added automatically and cannot be added manually.');
                    }
                },
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

                    if (ActivityDefinition::isCanonicalBuiltInName($value)) {
                        $fail('This name is reserved for a built-in Activity Type.');

                        return;
                    }

                    $existing = ActivityDefinition::equivalentToName($value);
                    if ($existing && ! $existing->is_active) {
                        $fail('An inactive activity type with this name already exists.');
                    }
                },
            ],
            'status' => [Rule::excludeIf($excludeParentFields), 'required', Rule::enum(ActivityStatus::class)],
            'scheduled_at' => [
                Rule::excludeIf($excludeParentFields),
                'nullable',
                'date',
                Rule::requiredIf(ActivityStatus::requiresScheduledDate($this->input('status'))),
            ],
            'scheduled_time' => [Rule::excludeIf($excludeParentFields), 'nullable', 'date_format:H:i'],
            'remarks' => [Rule::excludeIf($excludeParentFields), 'nullable', 'string', 'max:20000'],
            'bank_targets' => [
                Rule::excludeIf(! $bankCoopCheck),
                Rule::requiredIf($bankCoopCheck),
                'array',
                'min:1',
                'max:50',
            ],
            'bank_targets.*' => ['required', 'array'],
            'bank_targets.*.inquiry_type' => ['required', Rule::in(array_keys(CiActivityBankTarget::INQUIRY_TYPES))],
            'bank_targets.*.institution_name' => ['required', 'string', 'max:255'],
            'bank_targets.*.branch_location' => ['nullable', 'string', 'max:255'],
            'bank_targets.*.status' => ['required', Rule::enum(ActivityStatus::class)],
            'bank_targets.*.scheduled_at' => ['nullable', 'date', 'required_if:bank_targets.*.status,'.ActivityStatus::Scheduled->value.','.ActivityStatus::FollowUp->value],
            'bank_targets.*.scheduled_time' => ['nullable', 'date_format:H:i'],
            'bank_targets.*.remarks' => ['nullable', 'string', 'max:20000'],
            'asset_targets' => [
                Rule::excludeIf(! $assetCheck),
                Rule::requiredIf($assetCheck),
                'array',
                'min:1',
                'max:50',
            ],
            'asset_targets.*' => ['required', 'array'],
            'asset_targets.*.assessor_type' => ['required', Rule::in(array_keys(CiActivityAssetTarget::ASSESSOR_TYPES))],
            'asset_targets.*.office_location' => ['required', 'string', 'max:255'],
            'asset_targets.*.status' => ['required', Rule::enum(ActivityStatus::class)],
            'asset_targets.*.scheduled_at' => ['nullable', 'date', 'required_if:asset_targets.*.status,'.ActivityStatus::Scheduled->value.','.ActivityStatus::FollowUp->value],
            'asset_targets.*.scheduled_time' => ['nullable', 'date_format:H:i'],
            'asset_targets.*.remarks' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_at.required' => 'Please select a scheduled date.',
            'bank_targets.*.scheduled_at.required_if' => 'Please select a scheduled date.',
            'asset_targets.*.scheduled_at.required_if' => 'Please select a scheduled date.',
            'bank_targets.required' => 'Add at least one Bank / Coop target.',
            'bank_targets.*.institution_name.required' => 'Enter the Bank / Coop name.',
            'bank_targets.*.inquiry_type.required' => 'Select an inquiry type.',
            'bank_targets.*.status.required' => 'Select a target status.',
            'asset_targets.required' => 'Add at least one assessor target.',
            'asset_targets.*.assessor_type.required' => 'Select an assessor office.',
            'asset_targets.*.office_location.required' => 'Enter the office, municipality, city, or location.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['bank_targets', 'asset_targets'] as $collection) {
                if (! is_array($this->input($collection))) {
                    continue;
                }

                foreach ($this->input($collection) as $index => $target) {
                    if (is_array($target) && filled($target['scheduled_time'] ?? null) && blank($target['scheduled_at'] ?? null)) {
                        $validator->errors()->add("{$collection}.{$index}.scheduled_time", 'Select a date to use a specific time.');
                    }
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $createNewType = $this->input('activity_definition_id') === ActivityDefinition::NEW_TYPE_VALUE
            || $this->boolean('create_new_activity_type');
        $bankCoopCheck = $this->isBankCoopCheck();
        $assetCheck = $this->isAssetCheck();
        $multiTargetCheck = $bankCoopCheck || $assetCheck;
        $statusSupportsSchedule = ! $multiTargetCheck && in_array($this->input('status'), [
            ActivityStatus::Scheduled->value,
            ActivityStatus::FollowUp->value,
        ], true);
        $bankTargets = $this->input('bank_targets');
        if (is_array($bankTargets)) {
            $bankTargets = array_map(function (mixed $target): mixed {
                if (! is_array($target)) {
                    return $target;
                }

                $statusSupportsSchedule = in_array($target['status'] ?? null, [
                    ActivityStatus::Scheduled->value,
                    ActivityStatus::FollowUp->value,
                ], true);

                return [
                    'inquiry_type' => $target['inquiry_type'] ?? null,
                    'institution_name' => $this->normalizeNested($target['institution_name'] ?? null),
                    'branch_location' => ($target['inquiry_type'] ?? null) === CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY
                        ? null
                        : $this->normalizeNested($target['branch_location'] ?? null),
                    'status' => $target['status'] ?? null,
                    'scheduled_at' => $statusSupportsSchedule ? ($target['scheduled_at'] ?? null) : null,
                    'scheduled_time' => $statusSupportsSchedule ? ($target['scheduled_time'] ?? null) : null,
                    'remarks' => $this->normalizeNested($target['remarks'] ?? null),
                ];
            }, $bankTargets);
        }
        $assetTargets = $this->input('asset_targets');
        if (is_array($assetTargets)) {
            $assetTargets = array_map(function (mixed $target): mixed {
                if (! is_array($target)) {
                    return $target;
                }

                $supportsSchedule = in_array($target['status'] ?? null, ['scheduled', 'follow_up'], true);

                return [
                    'assessor_type' => $target['assessor_type'] ?? null,
                    'office_location' => $this->normalizeNested($target['office_location'] ?? null),
                    'status' => $target['status'] ?? null,
                    'scheduled_at' => $supportsSchedule ? ($target['scheduled_at'] ?? null) : null,
                    'scheduled_time' => $supportsSchedule ? ($target['scheduled_time'] ?? null) : null,
                    'remarks' => $this->normalizeNested($target['remarks'] ?? null),
                ];
            }, $assetTargets);
        }

        $this->merge([
            'co_maker_id' => filled($this->input('co_maker_id')) ? (int) $this->input('co_maker_id') : null,
            'activity_definition_id' => $createNewType ? null : $this->input('activity_definition_id'),
            'create_new_activity_type' => $createNewType,
            'new_activity_type' => is_string($this->input('new_activity_type'))
                ? ActivityDefinition::normalizeName($this->input('new_activity_type'))
                : null,
            'scheduled_at' => $statusSupportsSchedule ? $this->input('scheduled_at') : null,
            'scheduled_time' => $statusSupportsSchedule ? $this->input('scheduled_time') : null,
            'remarks' => $multiTargetCheck ? null : $this->normalized('remarks'),
            'bank_targets' => $bankTargets,
            'asset_targets' => $assetTargets,
        ]);
    }

    private function isBankCoopCheck(): bool
    {
        if ($this->boolean('create_new_activity_type') || ! ctype_digit((string) $this->input('activity_definition_id'))) {
            return false;
        }

        return ActivityDefinition::query()
            ->whereKey((int) $this->input('activity_definition_id'))
            ->where('is_active', true)
            ->where('code', ActivityDefinition::BANK_COOP_CHECK_CODE)
            ->exists();
    }

    private function isAssetCheck(): bool
    {
        if ($this->boolean('create_new_activity_type') || ! ctype_digit((string) $this->input('activity_definition_id'))) {
            return false;
        }

        return ActivityDefinition::query()
            ->whereKey((int) $this->input('activity_definition_id'))
            ->where('is_active', true)
            ->where('code', ActivityDefinition::ASSET_CHECK_CODE)
            ->exists();
    }

    private function normalizeNested(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
}
