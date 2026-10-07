<?php

namespace App\Actions;

use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Redeems a staff invitation token. Shared by the public API endpoint and the
 * web "Accept invitation" page so both enforce the same rules.
 */
class AcceptStaffInvitation
{
    /**
     * Returns the user, or null when the token is unknown, expired or already used.
     * `$user->wasRecentlyCreated` is false when the email already had an account
     * (its existing password is kept, the name and password given here are ignored).
     */
    public function __invoke(string $token, string $name, string $password): ?User
    {
        return DB::transaction(function () use ($token, $name, $password) {
            $invitation = StaffInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $invitation || $invitation->accepted_at || $invitation->expires_at->isPast()) {
                return null;
            }

            $user = User::query()->firstOrCreate(
                ['email' => $invitation->email],
                ['name' => $name, 'password' => $password, 'email_verified_at' => now()]
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
    }
}
