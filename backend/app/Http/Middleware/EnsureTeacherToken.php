<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeacherToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User || ! $request->user()->tokenCan('teacher')) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'TEACHER_ACCESS_REQUIRED', 'message' => 'Teacher access is required.'],
            ], 403);
        }

        return $next($request);
    }
}
