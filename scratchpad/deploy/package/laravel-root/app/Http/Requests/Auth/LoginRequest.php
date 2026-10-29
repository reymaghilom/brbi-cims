<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserStatus;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Failed attempts allowed per email + IP before sign-in is paused for that pair. */
    public const MAX_ATTEMPTS = 5;

    /** Length of the failed-attempt window, in seconds. */
    public const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email is required.',
            'password.required' => 'Password is required.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->email)),
        ]);
    }

    /**
     * Failed attempts are counted per normalized email + client IP and paused after MAX_ATTEMPTS
     * within DECAY_SECONDS. The pause is temporary (never a permanent account lockout) and is
     * applied whether or not the email belongs to an account, so it reveals nothing about which
     * emails exist. Every credential failure keeps the same generic message; a successful sign-in
     * clears the counter for that pair.
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $authenticated = Auth::attempt([
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'status' => UserStatus::Active->value,
        ], $this->boolean('remember'));

        if (! $authenticated) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'authentication' => 'Invalid email or password. Please check your credentials and try again.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = max(1, RateLimiter::availableIn($this->throttleKey()));

        throw ValidationException::withMessages([
            'authentication' => 'Too many sign-in attempts. Please try again in '.$seconds.' '.Str::plural('second', $seconds).'.',
        ]);
    }

    /** Hashed so neither the email nor the IP is stored in plain text in the cache key. */
    private function throttleKey(): string
    {
        return 'login:'.hash('sha256', Str::transliterate($this->string('email')->lower()->toString()).'|'.$this->ip());
    }
}
