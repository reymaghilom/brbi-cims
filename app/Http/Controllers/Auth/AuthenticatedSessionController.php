<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Authentication\LogoutCurrentSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        $user = $request->user();
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $request->session()->put('auth_session_version', $user->auth_session_version);

        if ($user->must_change_password) {
            return redirect()->route('password.change-required.edit');
        }

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request, LogoutCurrentSession $logout): RedirectResponse
    {
        $logout->execute($request);

        return redirect()->route('login');
    }
}
