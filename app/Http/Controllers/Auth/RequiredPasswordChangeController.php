<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Authentication\SessionInvalidator;
use App\Support\Authentication\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RequiredPasswordChangeController extends Controller
{
    public function edit(): View|RedirectResponse
    {
        if (! request()->user()->must_change_password) {
            return redirect()->route('home');
        }

        return view('auth.change-required-password');
    }

    public function update(Request $request, SessionInvalidator $sessions): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', PasswordPolicy::rule()],
        ]);

        $user = $request->user();
        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        $sessions->invalidate($user);
        $user->refresh();

        $request->session()->regenerate();
        $request->session()->put('auth_session_version', $user->auth_session_version);

        return redirect()->route('home')->with('status', 'Password changed successfully.');
    }
}
