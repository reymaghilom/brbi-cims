<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCiActivityBankTargetRequest extends FormRequest
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
            && $activity->definition()->where('code', ActivityDefinition::BANK_COOP_CHECK_CODE)->exists();
    }

    public function rules(): array
    {
        return [
            'co_maker_id' => ActivePersonResolver::rule($this->route('clientFolder')),
            'institution_name' => ['required', 'string', 'max:255'],
            'branch_location' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::enum(ActivityStatus::class)],
            'scheduled_at' => ['nullable', 'date'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'remarks' => ['nullable', 'string', 'max:20000'],
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
        $statusSupportsSchedule = in_array($this->input('status'), [
            ActivityStatus::Scheduled->value,
            ActivityStatus::FollowUp->value,
        ], true);

        $this->merge([
            'co_maker_id' => filled($this->input('co_maker_id')) ? (int) $this->input('co_maker_id') : null,
            'institution_name' => $this->normalized('institution_name'),
            'branch_location' => $this->normalized('branch_location'),
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
}
