<?php

namespace App\Http\Requests\ClientFolders;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCoMakerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('clientFolder'));
    }

    public function rules(): array
    {
        return [
            'co_maker_id' => [
                'nullable',
                'integer',
                Rule::exists('co_makers', 'id')->where('client_folder_id', $this->route('clientFolder')->id),
            ],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:30'],
            // Optional: the Add form does not collect it at all, and an existing co-maker whose
            // address was never captured must still be editable by name alone.
            'address' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $address = $this->input('address');
        if (is_string($address)) {
            $trimmed = trim((string) preg_replace('/\s+/u', ' ', $address));
            $this->merge(['address' => $trimmed === '' ? null : $trimmed]);
        }

        // A blank Middle Name field posts as an empty string — normalized to null here (same
        // canonical blank representation the Applicant's own name fields use) rather than storing
        // an empty string, so "no middle name" is never ambiguous with "not yet filled in".
        $middleName = $this->input('middle_name');
        if (is_string($middleName)) {
            $trimmed = trim((string) preg_replace('/\s+/u', ' ', $middleName));
            $this->merge(['middle_name' => $trimmed === '' ? null : $trimmed]);
        }
    }
}
