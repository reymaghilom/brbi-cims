<?php

namespace App\Http\Requests\ClientFolders;

use Illuminate\Foundation\Http\FormRequest;

class RenameClientFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('clientFolder'));
    }

    public function rules(): array
    {
        return [
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:30'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'last_name' => $this->normalizedName($this->input('last_name')),
            'first_name' => $this->normalizedName($this->input('first_name')),
            'middle_name' => $this->normalizedName($this->input('middle_name')),
            'suffix' => $this->normalizedName($this->input('suffix')),
        ]);
    }

    private function normalizedName(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return mb_strtoupper((string) preg_replace('/\s+/', ' ', trim((string) $value)));
    }
}
