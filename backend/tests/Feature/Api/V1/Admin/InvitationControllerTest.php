<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Organization;
use App\Models\School;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function orgAdmin(Organization $organization): User
    {
        $admin = User::factory()->create();
        $admin->organizations()->attach($organization, ['role' => 'administrator']);

        return $admin;
    }

    public function test_an_organization_administrator_can_invite_a_school_administrator(): void
    {
        $organization = Organization::create(['name' => 'Test Org', 'slug' => 'test-org']);
        $school = School::create(['organization_id' => $organization->id, 'name' => 'School A']);
        $admin = $this->orgAdmin($organization);
        $token = $admin->createToken('test', ['teacher'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/admin/invitations', [
            'organization_id' => $organization->id,
            'school_id' => $school->id,
            'email' => 'new-admin@example.com',
            'role' => 'school_administrator',
        ]);

        $response->assertCreated();
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertTrue(StaffInvitation::where('email', 'new-admin@example.com')->where('role', 'school_administrator')->exists());
    }

    public function test_an_organization_administrator_can_invite_another_organization_administrator_without_a_school(): void
    {
        $organization = Organization::create(['name' => 'Test Org', 'slug' => 'test-org']);
        $admin = $this->orgAdmin($organization);
        $token = $admin->createToken('test', ['teacher'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/admin/invitations', [
            'organization_id' => $organization->id,
            'email' => 'second-admin@example.com',
            'role' => 'organization_administrator',
        ]);

        $response->assertCreated();
    }

    public function test_a_teacher_invitation_requires_a_school_id(): void
    {
        $organization = Organization::create(['name' => 'Test Org', 'slug' => 'test-org']);
        $admin = $this->orgAdmin($organization);
        $token = $admin->createToken('test', ['teacher'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/admin/invitations', [
            'organization_id' => $organization->id,
            'email' => 'teacher@example.com',
            'role' => 'teacher',
        ]);

        $response->assertStatus(422);
    }

    public function test_a_school_from_a_different_organization_is_rejected(): void
    {
        $organization = Organization::create(['name' => 'Test Org', 'slug' => 'test-org']);
        $otherOrganization = Organization::create(['name' => 'Other Org', 'slug' => 'other-org']);
        $foreignSchool = School::create(['organization_id' => $otherOrganization->id, 'name' => 'School B']);
        $admin = $this->orgAdmin($organization);
        $token = $admin->createToken('test', ['teacher'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/admin/invitations', [
            'organization_id' => $organization->id,
            'school_id' => $foreignSchool->id,
            'email' => 'teacher@example.com',
            'role' => 'teacher',
        ]);

        $response->assertStatus(422);
    }

    public function test_a_non_organization_administrator_cannot_invite_staff(): void
    {
        $organization = Organization::create(['name' => 'Test Org', 'slug' => 'test-org']);
        $school = School::create(['organization_id' => $organization->id, 'name' => 'School A']);
        $schoolAdmin = User::factory()->create();
        $schoolAdmin->schools()->attach($school, ['role' => 'administrator']);
        $token = $schoolAdmin->createToken('test', ['teacher'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/admin/invitations', [
            'organization_id' => $organization->id,
            'school_id' => $school->id,
            'email' => 'teacher@example.com',
            'role' => 'teacher',
        ]);

        $response->assertStatus(403);
    }
}
