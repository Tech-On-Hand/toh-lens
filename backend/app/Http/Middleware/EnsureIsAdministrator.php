<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First line of defense for the whole legacy admin panel: is this user an
 * administrator of anything at all, anywhere. Each controller still scopes
 * what one administrator can see/touch to their own school(s) — see
 * User::administeredSchoolIds() — this just keeps an ordinary teacher or
 * student-facing account out of the panel entirely.
 */
class EnsureIsAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isAnyAdministrator(), 403);

        return $next($request);
    }
}
