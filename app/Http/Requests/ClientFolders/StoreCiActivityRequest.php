<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Services\ClientFolders\ActivePersonResolver;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCiActivityRequest extends FormRequest
{
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
        ];
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
}
