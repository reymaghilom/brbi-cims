<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ClientFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ClientFolder::class);
    }

    public function rules(): array
    {
        $assignmentRules = $this->user()->role === UserRole::Administrator
            ? [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('role', UserRole::CreditInvestigator->value)
                    ->where('status', UserStatus::Active->value)),
            ]
            : ['prohibited'];

        return [
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => [
                'bail', 'required', 'string', 'max:100',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $letters = preg_replace('/[^\pL]/u', '', trim((string) $value));
                    if (mb_strlen($letters) < 2) {
                        $fail('Middle name must be written in full, not as an initial.');
                    }
                },
            ],
            'suffix' => ['nullable', 'string', 'max:30'],
            'assigned_ci_id' => $assignmentRules,
        ];
    }

    public function messages(): array
    {
        return ['middle_name.required' => 'Middle name is required.'];
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
