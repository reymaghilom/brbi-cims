<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validateWithBag('passwordReset', [
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        try {
            Password::sendResetLink(['email' => $validated['email']]);
        } catch (Throwable $exception) {
            // Preserve the same public response for known and unknown addresses. Do not log the
            // address, reset token, provider response body, or API credential.
            Log::warning('Password reset email delivery failed.', [
                'exception_type' => $exception::class,
            ]);
        }

        return back()->with('status', "If the email is registered, we'll send password reset instructions.");
    }
}
