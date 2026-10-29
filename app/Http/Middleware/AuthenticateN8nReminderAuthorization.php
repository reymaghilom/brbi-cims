<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateN8nReminderAuthorization
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('services.n8n_reminder_authorization.enabled')) {
            abort(404);
        }

        $secret = (string) config('services.n8n_reminder_authorization.token');
        if (strlen($secret) < 32) {
            return response()->json(['result' => 'unavailable'], 503);
        }

        $provided = $request->bearerToken();
        if (! is_string($provided) || strlen($provided) !== strlen($secret) || ! hash_equals($secret, $provided)) {
            return response()->json(['result' => 'unauthorized'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        if (! $request->isJson()) {
            return response()->json(['result' => 'unsupported_media_type'], 415);
        }

        if (strlen($request->getContent()) > 16 * 1024) {
            return response()->json(['result' => 'payload_too_large'], 413);
        }

        return $next($request);
    }
}
