<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Authentication\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'employee_id' => ['nullable', 'string', 'max:50', 'unique:users,employee_id'],
            'username' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/', 'unique:users,username'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'string', 'confirmed', PasswordPolicy::rule()],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'employee_id' => filled($this->employee_id) ? trim((string) $this->employee_id) : null,
            'username' => strtolower(trim((string) $this->username)),
        ]);
    }
}
