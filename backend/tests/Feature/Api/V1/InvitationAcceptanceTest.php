<?php

namespace Tests\Feature\Api\V1;

use App\Models\Organization;
use App\Models\School;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvitation(array $overrides = []): array
    {
        $organization = Organization::create(['name' => 'Test Org', 'slug' => 'test-org']);
        $school = School::create(['organization_id' => $organization->id, 'name' => 'School A']);
        $inviter = User::factory()->create();
        $token = 'a-very-secret-invitation-token';

        $invitation = StaffInvitation::create([
            'organization_id' => $organization->id,
            'school_id' => $school->id,
            'invited_by' => $inviter->id,
            'email' => 'newteacher@example.com',
            'role' => 'teacher',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
            ...$overrides,
        ]);

        return [$invitation, $token, $school];
    }

    public function test_a_valid_invitation_creates_a_user_and_assigns_the_school_role(): void
    {
        [, $token, $school] = $this->makeInvitation();

        $response = $this->postJson('/api/v1/invitations/accept', [
            'token' => $token,
            'name' => 'New Teacher',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.email', 'newteacher@example.com');

        $user = User::where('email', 'newteacher@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->schools()->whereKey($school->id)->wherePivot('role', 'teacher')->exists());
        $this->assertNotNull(StaffInvitation::first()->accepted_at);
    }

    public function test_an_organization_administrator_invitation_assigns_the_organization_role(): void
    {
        [, $token] = $this->makeInvitation([
            'role' => 'organization_administrator',
            'school_id' => null,
            'token_hash' => hash('sha256', 'org-admin-token'),
        ]);

        $response = $this->postJson('/api/v1/invitations/accept', [
            'token' => 'org-admin-token',
            'name' => 'Org Admin',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]);

        $response->assertCreated();
        $user = User::where('email', 'newteacher@example.com')->first();
        $this->assertTrue($user->isOrganizationAdministrator());
    }

    public function test_an_already_accepted_invitation_is_rejected(): void
    {
        [, $token] = $this->makeInvitation(['accepted_at' => now()]);

        $response = $this->postJson('/api/v1/invitations/accept', [
            'token' => $token,
            'name' => 'New Teacher',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVITATION_INVALID');
    }

    public function test_an_expired_invitation_is_rejected(): void
    {
        [, $token] = $this->makeInvitation(['expires_at' => now()->subDay()]);

        $response = $this->postJson('/api/v1/invitations/accept', [
            'token' => $token,
            'name' => 'New Teacher',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVITATION_INVALID');
    }

    public function test_a_mismatched_password_confirmation_fails_validation(): void
    {
        [, $token] = $this->makeInvitation();

        $response = $this->postJson('/api/v1/invitations/accept', [
            'token' => $token,
            'name' => 'New Teacher',
            'password' => 'a-strong-password',
            'password_confirmation' => 'does-not-match',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }
}
