<?php

namespace App\Http\Controllers\Auth;

use App\Actions\AcceptStaffInvitation;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcceptInvitationController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('auth/accept-invitation', [
            'token' => (string) $request->query('token', ''),
        ]);
    }

    public function store(Request $request, AcceptStaffInvitation $accept): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = $accept($data['token'], $data['name'], $data['password']);

        if (! $user) {
            throw ValidationException::withMessages([
                'token' => 'This invitation is invalid, expired, or already accepted. Ask your administrator for a new one.',
            ]);
        }

        return to_route('login')->with('status', $user->wasRecentlyCreated
            ? "Your account is ready. Sign in as {$user->email} with the password you just chose."
            : "Invitation accepted. {$user->email} already had an account, so sign in with your existing password.");
    }
}
