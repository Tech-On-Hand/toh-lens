<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AcceptStaffInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationAcceptanceController extends ApiController
{
    public function store(Request $request, AcceptStaffInvitation $accept): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = $accept($data['token'], $data['name'], $data['password']);

        return $user
            ? $this->success(['accepted' => true, 'email' => $user->email], 201)
            : $this->error('INVITATION_INVALID', 'The invitation is invalid, expired, or already accepted.', 422);
    }
}
