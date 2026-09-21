<?php

namespace App\Http\Middleware;

use App\Models\Computer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeviceActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();

        if (! $device instanceof Computer || $device->revoked_at !== null || ! $device->tokenCan('device')) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'DEVICE_REVOKED', 'message' => 'This device is not authorized.'],
            ], 403);
        }

        return $next($request);
    }
}
