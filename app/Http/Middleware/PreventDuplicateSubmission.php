<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotent replay for full-page form saves whose response may be lost or delayed (a slow or
 * dropped connection, a browser retry, a double submit that slips past the client guard).
 *
 * The form renders a fresh random `submission_token` on every page load. The first request
 * carrying a given token runs normally; if it succeeds (a redirect with no validation errors and no
 * error status), its redirect target and flashed status are remembered for this user, route and
 * token. A later request with the SAME token and the IDENTICAL payload does not run the action
 * again: it receives that same redirect, so an accidental retry lands on the record that was
 * already saved instead of saving twice or tripping the duplicate/stale-revision guards.
 *
 * Deliberately narrow:
 *  - Opt-in per route, and a request without a well-formed token passes straight through, so the
 *    server's own guards (duplicate template rules, expected_revision, row locks) stay the
 *    authoritative protection and are never bypassed or weakened.
 *  - Same token but a different payload is never replayed: it runs normally and meets those guards.
 *  - Requests carrying uploaded files are never replayed.
 *  - Concurrent requests with the same token are serialized on a short cache lock, so the second
 *    one waits for the first and then replays its result.
 */
class PreventDuplicateSubmission
{
    public const FIELD = 'submission_token';

    private const TTL_SECONDS = 600;

    private const LOCK_SECONDS = 120;

    /** How long a retry waits for an in-flight request with the same token before giving up. */
    protected const WAIT_SECONDS = 20;

    public const IN_PROGRESS_MESSAGE = 'Your previous save is still being processed. Please wait a moment, then refresh to review the latest information.';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->input(self::FIELD);
        if (! is_string($token) || ! Str::isUuid($token) || $request->user() === null || $request->allFiles() !== []) {
            return $next($request);
        }

        $key = 'submission:'.hash('sha256', $request->user()->getAuthIdentifier().'|'.$request->method().'|'.$request->path().'|'.$token);
        $fingerprint = hash('sha256', (string) json_encode($request->except('_token')));

        try {
            return Cache::lock($key.':lock', self::LOCK_SECONDS)->block(static::WAIT_SECONDS, function () use ($request, $next, $key, $fingerprint): Response {
                $stored = Cache::get($key);
                if (is_array($stored) && hash_equals($stored['fingerprint'], $fingerprint)) {
                    return $this->replay($stored);
                }

                $response = $next($request);
                $this->remember($request, $response, $key, $fingerprint);

                return $response;
            });
        } catch (LockTimeoutException) {
            return redirect()->back()->with('status', self::IN_PROGRESS_MESSAGE)->with('statusType', 'error');
        }
    }

    private function remember(Request $request, Response $response, string $key, string $fingerprint): void
    {
        if (! $response instanceof RedirectResponse || ! $request->hasSession()) {
            return;
        }

        // Only what THIS request flashed counts: an older flash still readable from the previous
        // request must never make a success look like a failure, or the reverse.
        $session = $request->session();
        $flashed = (array) $session->get('_flash.new', []);
        if (in_array('errors', $flashed, true)
            || (in_array('statusType', $flashed, true) && $session->get('statusType') === 'error')) {
            return;
        }

        Cache::put($key, [
            'fingerprint' => $fingerprint,
            'location' => $response->getTargetUrl(),
            'status' => in_array('status', $flashed, true) ? $session->get('status') : null,
            'statusType' => in_array('statusType', $flashed, true) ? $session->get('statusType') : null,
        ], self::TTL_SECONDS);
    }

    /** @param  array{location: string, status: ?string, statusType: ?string}  $stored */
    private function replay(array $stored): RedirectResponse
    {
        $response = redirect()->to($stored['location']);
        if (is_string($stored['status'])) {
            $response->with('status', $stored['status']);
        }
        if (is_string($stored['statusType'])) {
            $response->with('statusType', $stored['statusType']);
        }

        return $response;
    }
}
