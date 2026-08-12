<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\ActivityStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCiActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $folder = $this->route('clientFolder');
        $activity = $this->route('ciActivity');

        return $this->user()->can('update', $folder)
            && $activity !== null
            && $activity->client_folder_id === $folder->id
            && $this->user()->can('update', $activity);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ActivityStatus::class)],
            'visit_date' => ['nullable', 'date', 'before_or_equal:today', Rule::requiredIf($this->input('status') === ActivityStatus::Completed->value)],
            'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'visited_by' => ['nullable', 'string', 'max:255', Rule::requiredIf($this->input('status') === ActivityStatus::Completed->value)],
            'person_met_contact' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:20000', Rule::requiredIf($this->input('status') === ActivityStatus::Completed->value)],
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
        foreach (['visited_by', 'person_met_contact'] as $field) {
            $normalized[$field] = $this->normalize($this->input($field));
        }
        foreach (['remarks', 'supporting_reference'] as $field) {
            $normalized[$field] = $this->trimmed($this->input($field));
        }

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
