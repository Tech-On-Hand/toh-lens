<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Events\DeviceConfigurationUpdated;
use App\Events\DeviceRevoked;
use App\Models\Classroom;
use App\Models\Computer;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    private function teacherToken(User $user): string
    {
        return $user->createToken('test', ['teacher'])->plainTextToken;
    }

    public function test_a_school_administrator_can_rename_and_move_a_device(): void
    {
        Event::fake();
        $school = School::create(['name' => 'School A']);
        $classroomA = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $classroomB = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 2']);
        $device = Computer::create([
            'device_uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'classroom_id' => $classroomA->id,
            'name' => 'Lab PC 1', 'role' => 'student', 'configuration_version' => 1,
        ]);
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);
        $token = $this->teacherToken($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/devices/{$device->id}", ['name' => 'Lab PC 1 (renamed)', 'classroom_id' => $classroomB->id]);

        $response->assertOk();
        $device->refresh();
        $this->assertSame('Lab PC 1 (renamed)', $device->name);
        $this->assertSame($classroomB->id, $device->classroom_id);
        $this->assertSame(2, $device->configuration_version);
        Event::assertDispatched(DeviceConfigurationUpdated::class);
    }

    public function test_a_device_cannot_be_moved_to_a_classroom_in_another_school(): void
    {
        $school = School::create(['name' => 'School A']);
        $otherSchool = School::create(['name' => 'School B']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $foreignClassroom = Classroom::create(['school_id' => $otherSchool->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $device = Computer::create([
            'device_uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'classroom_id' => $classroom->id,
            'name' => 'Lab PC 1', 'role' => 'student',
        ]);
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);
        $token = $this->teacherToken($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/devices/{$device->id}", ['classroom_id' => $foreignClassroom->id]);

        $response->assertStatus(422);
    }

    public function test_a_non_administrator_cannot_update_a_device(): void
    {
        $school = School::create(['name' => 'School A']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $device = Computer::create([
            'device_uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'classroom_id' => $classroom->id,
            'name' => 'Lab PC 1', 'role' => 'student',
        ]);
        $teacher = User::factory()->create();
        $teacher->schools()->attach($school, ['role' => 'teacher']);
        $token = $this->teacherToken($teacher);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/devices/{$device->id}", ['name' => 'Renamed']);

        $response->assertStatus(403);
    }

    public function test_revoking_a_device_deletes_its_tokens_and_broadcasts(): void
    {
        Event::fake();
        $school = School::create(['name' => 'School A']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $device = Computer::create([
            'device_uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'classroom_id' => $classroom->id,
            'name' => 'Lab PC 1', 'role' => 'student', 'presence_status' => 'online',
        ]);
        $device->createToken('device', ['device']);
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);
        $token = $this->teacherToken($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/admin/devices/{$device->id}/revoke");

        $response->assertOk();
        $device->refresh();
        $this->assertNotNull($device->revoked_at);
        $this->assertSame('offline', $device->presence_status);
        $this->assertSame(0, $device->tokens()->count());
        Event::assertDispatched(DeviceRevoked::class);
    }
}
