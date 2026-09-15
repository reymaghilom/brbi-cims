<?php

namespace App\Http\Middleware;

use App\Actions\Authentication\LogoutCurrentSession;
use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentAuthenticationSession
{
    public function __construct(private readonly LogoutCurrentSession $logout) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->status !== UserStatus::Active) {
            $this->logout->execute($request);

            return redirect()->route('login')->withErrors([
                'authentication' => 'Your account is currently unavailable. Please contact the system administrator.',
            ]);
        }

        $sessionVersion = $request->session()->get('auth_session_version');

        if ($sessionVersion === null) {
            $request->session()->put('auth_session_version', $user->auth_session_version);

            return $next($request);
        }

        if ((int) $sessionVersion !== $user->auth_session_version) {
            $this->logout->execute($request);

            return redirect()->route('login')->withErrors([
                'authentication' => 'Your session has expired. Please log in again.',
            ]);
        }

        return $next($request);
    }
}
