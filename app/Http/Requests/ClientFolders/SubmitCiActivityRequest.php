<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\ActivityStatus;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SubmitCiActivityRequest extends FormRequest
{
    protected $errorBag = 'submission';

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
            'submission_activity_id' => ['required', 'integer', 'in:'.$this->route('ciActivity')->id],
            'co_maker_id' => ActivePersonResolver::rule($this->route('clientFolder')),
            'submitted_to' => ['nullable', 'string', 'max:255'],
            'submission_note' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->route('ciActivity')?->status !== ActivityStatus::Completed) {
                $validator->errors()->add('submission_activity_id', 'Only completed activities can be submitted to the Credit Analyst.');
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
}
