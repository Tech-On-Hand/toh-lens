<?php

namespace Tests\Feature\Api\V1;

use App\Models\Classroom;
use App\Models\Computer;
use App\Models\LoginSession;
use App\Models\Organization;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherClassroomTest extends TestCase
{
    use RefreshDatabase;

    private function teacherToken(User $user): string
    {
        return $user->createToken('test', ['teacher'])->plainTextToken;
    }

    public function test_a_classroom_teacher_sees_only_their_assigned_classroom(): void
    {
        $school = School::create(['name' => 'School A']);
        $classroomA = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $classroomB = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 2']);
        $teacher = User::factory()->create();
        $classroomA->users()->attach($teacher, ['role' => 'primary_teacher']);
        $token = $this->teacherToken($teacher);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/teacher/classrooms');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $classroomA->id);
        $this->assertNotContains($classroomB->id, $response->json('data.*.id'));
    }

    public function test_a_school_administrator_sees_every_classroom_in_their_school(): void
    {
        $school = School::create(['name' => 'School A']);
        $otherSchool = School::create(['name' => 'School B']);
        Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 2']);
        Classroom::create(['school_id' => $otherSchool->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);
        $token = $this->teacherToken($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/teacher/classrooms');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_a_teacher_without_access_to_a_classroom_cannot_list_its_devices(): void
    {
        $school = School::create(['name' => 'School A']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $teacher = User::factory()->create();
        $token = $this->teacherToken($teacher);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/teacher/classrooms/{$classroom->id}/devices");

        $response->assertStatus(403);
    }

    public function test_a_classroom_teacher_sees_devices_with_their_active_session(): void
    {
        $school = School::create(['name' => 'School A']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $teacher = User::factory()->create();
        $classroom->users()->attach($teacher, ['role' => 'primary_teacher']);
        $student = Student::create(['school_id' => $school->id, 'admission_number' => '1001', 'full_name' => 'Student A1']);
        $device = Computer::create([
            'device_uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'classroom_id' => $classroom->id,
            'name' => 'Lab PC 1', 'role' => 'student', 'presence_status' => 'online', 'last_seen_at' => now(),
        ]);
        $idleDevice = Computer::create([
            'device_uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'classroom_id' => $classroom->id,
            'name' => 'Lab PC 2', 'role' => 'student',
        ]);
        $revokedDevice = Computer::create([
            'device_uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'classroom_id' => $classroom->id,
            'name' => 'Lab PC 3', 'role' => 'student', 'revoked_at' => now(),
        ]);
        LoginSession::create([
            'uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'computer_id' => $device->id,
            'classroom_id' => $classroom->id, 'student_id' => $student->id, 'admission_number' => '1001',
            'login_time' => now(), 'status' => 'active',
        ]);
        $token = $this->teacherToken($teacher);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/teacher/classrooms/{$classroom->id}/devices");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $this->assertNotContains($revokedDevice->id, $response->json('data.*.id'));

        $withSession = collect($response->json('data'))->firstWhere('id', $device->id);
        $this->assertSame('online', $withSession['status']);
        $this->assertSame('Student A1', $withSession['active_session']['student']['full_name']);

        $withoutSession = collect($response->json('data'))->firstWhere('id', $idleDevice->id);
        $this->assertNull($withoutSession['active_session']);
    }
}
