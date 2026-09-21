<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Classroom;
use App\Models\DeviceEnrollmentCode;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentCodeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_school_administrator_can_issue_an_enrollment_code(): void
    {
        $school = School::create(['name' => 'School A']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);
        $token = $admin->createToken('test', ['teacher'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/enrollment-codes', ['classroom_id' => $classroom->id]);

        $response->assertCreated();
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $response->json('data.code'));
        $this->assertSame(1, DeviceEnrollmentCode::where('classroom_id', $classroom->id)->count());
    }

    public function test_a_non_administrator_cannot_issue_an_enrollment_code(): void
    {
        $school = School::create(['name' => 'School A']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $teacher = User::factory()->create();
        $teacher->schools()->attach($school, ['role' => 'teacher']);
        $token = $teacher->createToken('test', ['teacher'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/enrollment-codes', ['classroom_id' => $classroom->id]);

        $response->assertStatus(403);
        $this->assertSame(0, DeviceEnrollmentCode::count());
    }

    public function test_an_administrator_of_a_different_school_cannot_issue_a_code(): void
    {
        $school = School::create(['name' => 'School A']);
        $otherSchool = School::create(['name' => 'School B']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $admin = User::factory()->create();
        $admin->schools()->attach($otherSchool, ['role' => 'administrator']);
        $token = $admin->createToken('test', ['teacher'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/enrollment-codes', ['classroom_id' => $classroom->id]);

        $response->assertStatus(403);
    }
}
