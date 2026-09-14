<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'Username is required.',
            'password.required' => 'Password is required.',
        ];
    }

    /**
     * No login rate limiting or lockout by product decision: failed attempts are never counted,
     * and every failure answers with the same generic credentials message.
     */
    public function authenticate(): void
    {
        $authenticated = Auth::attempt([
            'username' => trim($this->string('username')->toString()),
            'password' => $this->string('password')->toString(),
            'status' => UserStatus::Active->value,
        ], $this->boolean('remember'));

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'authentication' => 'Invalid username or password. Please check your credentials and try again.',
            ]);
        }
    }
}
