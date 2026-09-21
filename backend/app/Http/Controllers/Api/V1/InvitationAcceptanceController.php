<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvitationAcceptanceController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $invitation = StaffInvitation::query()
                ->where('token_hash', hash('sha256', $data['token']))
                ->lockForUpdate()
                ->first();

            if (! $invitation || $invitation->accepted_at || $invitation->expires_at->isPast()) {
                return null;
            }

            $user = User::query()->firstOrCreate(
                ['email' => $invitation->email],
                ['name' => $data['name'], 'password' => $data['password'], 'email_verified_at' => now()]
            );

            if ($invitation->role === 'organization_administrator') {
                $user->organizations()->syncWithoutDetaching([$invitation->organization_id => ['role' => 'administrator']]);
            } else {
                $role = $invitation->role === 'school_administrator' ? 'administrator' : 'teacher';
                $user->schools()->syncWithoutDetaching([$invitation->school_id => ['role' => $role]]);
            }

            $invitation->update(['accepted_at' => now()]);

            return $user;
        });

        return $user
            ? $this->success(['accepted' => true, 'email' => $user->email], 201)
            : $this->error('INVITATION_INVALID', 'The invitation is invalid, expired, or already accepted.', 422);
    }
}
