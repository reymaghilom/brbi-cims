<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivityAssetTarget;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCiActivityAssetTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $folder = $this->route('clientFolder');
        $activity = $this->route('ciActivity');
        $expectedCoMakerId = blank($this->input('co_maker_id')) ? null : (int) $this->input('co_maker_id');

        return $folder !== null
            && $activity !== null
            && $this->user()->can('update', $folder)
            && $this->user()->can('update', $activity)
            && $activity->client_folder_id === $folder->id
            && $activity->co_maker_id === $expectedCoMakerId
            && $activity->definition()->where('code', ActivityDefinition::ASSET_CHECK_CODE)->exists();
    }

    public function rules(): array
    {
        return [
            'co_maker_id' => ActivePersonResolver::rule($this->route('clientFolder')),
            'assessor_type' => ['required', Rule::in(array_keys(CiActivityAssetTarget::ASSESSOR_TYPES))],
            'office_location' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::enum(ActivityStatus::class)],
            'scheduled_at' => ['nullable', 'date', Rule::requiredIf(ActivityStatus::requiresScheduledDate($this->input('status')))],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'remarks' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_at.required' => 'Please select a scheduled date.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (filled($this->input('scheduled_time')) && blank($this->input('scheduled_at'))) {
                $validator->errors()->add('scheduled_time', 'Select a date to use a specific time.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $supportsSchedule = in_array($this->input('status'), ['scheduled', 'follow_up'], true);
        $this->merge([
            'co_maker_id' => filled($this->input('co_maker_id')) ? (int) $this->input('co_maker_id') : null,
            'assessor_type' => $this->input('assessor_type'),
            'office_location' => $this->normalized('office_location'),
            'scheduled_at' => $supportsSchedule ? $this->input('scheduled_at') : null,
            'scheduled_time' => $supportsSchedule ? $this->input('scheduled_time') : null,
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
}
