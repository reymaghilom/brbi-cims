<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\ActivityStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCiActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $folder = $this->route('clientFolder');
        $activity = $this->route('ciActivity');
        $rawCoMakerId = $this->input('co_maker_id');
        $expectedCoMakerId = blank($rawCoMakerId) ? null : (int) $rawCoMakerId;

        return $this->user()->can('update', $folder)
            && $activity !== null
            && $activity->client_folder_id === $folder->id
            && $activity->co_maker_id === $expectedCoMakerId
            && $this->user()->can('update', $activity);
    }

    public function rules(): array
    {
        return [
            'co_maker_id' => ActivePersonResolver::rule($this->route('clientFolder')),
            'assigned_ci_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('role', UserRole::CreditInvestigator->value)
                    ->where('status', UserStatus::Active->value)),
            ],
            'expected_updated_at' => ['nullable', 'date'],
            'status' => ['required', Rule::enum(ActivityStatus::class)],
            'target' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'date', Rule::requiredIf($this->input('status') === ActivityStatus::Scheduled->value)],
            'visit_date' => ['nullable', 'date', 'before_or_equal:today'],
            'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'visited_by' => ['nullable', 'string', 'max:255'],
            'person_met_contact' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:20000'],
            'supporting_reference' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $timeIn = $this->input('time_in');
            $timeOut = $this->input('time_out');

            if (filled($timeIn) && filled($timeOut) && $timeOut < $timeIn) {
                $validator->errors()->add('time_out', 'The time out must be at or after the time in.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['target', 'visited_by', 'person_met_contact'] as $field) {
            $normalized[$field] = $this->normalize($this->input($field));
        }
        foreach (['remarks', 'supporting_reference'] as $field) {
            $normalized[$field] = $this->trimmed($this->input($field));
        }
        $normalized['assigned_ci_id'] = filled($this->input('assigned_ci_id')) ? (int) $this->input('assigned_ci_id') : null;

        $this->merge($normalized);
    }

    private function normalize(mixed $value): ?string
    {
        $value = $this->trimmed($value);

        return $value === null ? null : (string) preg_replace('/\s+/u', ' ', $value);
    }

    private function trimmed(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
