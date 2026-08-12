<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowedRoles = array_filter(array_map(UserRole::tryFrom(...), $roles));

        abort_unless(in_array($request->user()->role, $allowedRoles, true), 403);

        return $next($request);
    }
}
