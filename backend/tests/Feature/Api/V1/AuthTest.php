<?php

namespace Tests\Feature\Api\V1;

use App\Models\Organization;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_assigned_to_a_school_can_log_in(): void
    {
        $school = School::create(['name' => 'School A']);
        $user = User::factory()->create(['email' => 'teacher@example.com']);
        $user->schools()->attach($school, ['role' => 'teacher']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'teacher@example.com',
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.is_administrator', false);
        $response->assertJsonPath('data.user.email', 'teacher@example.com');
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_wrong_password_is_rejected(): void
    {
        $school = School::create(['name' => 'School A']);
        $user = User::factory()->create(['email' => 'teacher@example.com']);
        $user->schools()->attach($school, ['role' => 'teacher']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'teacher@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_a_user_with_no_organization_school_or_classroom_assignment_cannot_log_in(): void
    {
        User::factory()->create(['email' => 'nobody@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'ACCESS_NOT_ASSIGNED');
    }

    public function test_an_organization_administrator_is_flagged_as_administrator(): void
    {
        $organization = Organization::create(['name' => 'Test Org', 'slug' => 'test-org']);
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $user->organizations()->attach($organization, ['role' => 'administrator']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.is_administrator', true);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $school = School::create(['name' => 'School A']);
        $user = User::factory()->create();
        $user->schools()->attach($school, ['role' => 'teacher']);
        $token = $user->createToken('test', ['teacher'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }
}
