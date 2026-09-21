<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Organization;
use App\Models\School;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InvitationController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'exists:organizations,id'],
            'school_id' => ['nullable', 'exists:schools,id'],
            'email' => ['required', 'email'],
            'role' => ['required', 'in:organization_administrator,school_administrator,teacher'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($actor->isOrganizationAdministrator((int) $data['organization_id']), 403);

        if ($data['role'] !== 'organization_administrator') {
            abort_unless(isset($data['school_id']), 422);
            abort_unless(School::query()->whereKey($data['school_id'])->where('organization_id', $data['organization_id'])->exists(), 422);
        }

        $token = Str::random(64);
        $invitation = StaffInvitation::create([
            ...$data,
            'email' => mb_strtolower($data['email']),
            'invited_by' => $actor->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);

        return $this->success(['token' => $token, 'expires_at' => $invitation->expires_at->toIso8601String()], 201);
    }
}
