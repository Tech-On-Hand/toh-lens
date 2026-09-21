<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends ApiController
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', mb_strtolower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->error('INVALID_CREDENTIALS', 'The email or password is incorrect.', 422);
        }

        if (! $user->organizations()->exists() && ! $user->schools()->exists() && ! $user->classrooms()->exists()) {
            return $this->error('ACCESS_NOT_ASSIGNED', 'This account has not been assigned to a school or classroom.', 403);
        }

        $token = $user->createToken($data['device_name'] ?? 'TOH Klas Teacher', ['teacher'])->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'is_administrator' => $user->isOrganizationAdministrator() || $user->schools()->wherePivot('role', 'administrator')->exists(),
            'realtime' => [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => (int) config('broadcasting.connections.reverb.options.port'),
                'scheme' => config('broadcasting.connections.reverb.options.scheme'),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->success(['logged_out' => true]);
    }
}
