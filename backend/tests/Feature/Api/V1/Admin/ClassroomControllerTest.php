<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Classroom;
use App\Models\Organization;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClassroomControllerTest extends TestCase
{
    use RefreshDatabase;

    private function teacherToken(User $user): string
    {
        return $user->createToken('test', ['teacher'])->plainTextToken;
    }

    public function test_a_school_administrator_can_create_a_classroom(): void
    {
        $school = School::create(['name' => 'School A']);
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);
        $token = $this->teacherToken($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/classrooms', ['school_id' => $school->id, 'name' => 'Lab 3']);

        $response->assertCreated();
        $this->assertTrue(Classroom::where('school_id', $school->id)->where('name', 'Lab 3')->exists());
    }

    public function test_an_organization_administrator_can_create_a_classroom_in_any_of_their_schools(): void
    {
        $organization = Organization::create(['name' => 'Test Org', 'slug' => 'test-org']);
        $school = School::create(['organization_id' => $organization->id, 'name' => 'School A']);
        $admin = User::factory()->create();
        $admin->organizations()->attach($organization, ['role' => 'administrator']);
        $token = $this->teacherToken($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/classrooms', ['school_id' => $school->id, 'name' => 'Lab 3']);

        $response->assertCreated();
    }

    public function test_a_plain_teacher_cannot_create_a_classroom(): void
    {
        $school = School::create(['name' => 'School A']);
        $teacher = User::factory()->create();
        $teacher->schools()->attach($school, ['role' => 'teacher']);
        $token = $this->teacherToken($teacher);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/classrooms', ['school_id' => $school->id, 'name' => 'Lab 3']);

        $response->assertStatus(403);
    }

    public function test_index_only_returns_classrooms_the_administrator_can_manage(): void
    {
        $school = School::create(['name' => 'School A']);
        $otherSchool = School::create(['name' => 'School B']);
        Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        Classroom::create(['school_id' => $otherSchool->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);
        $token = $this->teacherToken($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/admin/classrooms');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_assigning_a_staff_member_outside_the_school_is_rejected(): void
    {
        $school = School::create(['name' => 'School A']);
        $otherSchool = School::create(['name' => 'School B']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);
        $outsider = User::factory()->create();
        $outsider->schools()->attach($otherSchool, ['role' => 'teacher']);
        $token = $this->teacherToken($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/admin/classrooms/{$classroom->id}/staff/{$outsider->id}", ['role' => 'primary_teacher']);

        $response->assertStatus(422);
    }

    public function test_assigning_a_staff_member_in_the_school_succeeds(): void
    {
        $school = School::create(['name' => 'School A']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);
        $teacher = User::factory()->create();
        $teacher->schools()->attach($school, ['role' => 'teacher']);
        $token = $this->teacherToken($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/admin/classrooms/{$classroom->id}/staff/{$teacher->id}", ['role' => 'primary_teacher']);

        $response->assertOk();
        $this->assertTrue($classroom->users()->whereKey($teacher->id)->wherePivot('role', 'primary_teacher')->exists());
    }
}
