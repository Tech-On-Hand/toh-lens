<?php

namespace Tests\Feature\Api\V1;

use App\Models\Classroom;
use App\Models\Computer;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceRosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_device_receives_only_active_students_from_its_own_school(): void
    {
        $schoolA = School::create(['name' => 'School A']);
        $schoolB = School::create(['name' => 'School B']);
        $classroom = Classroom::create(['school_id' => $schoolA->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        Student::create(['school_id' => $schoolA->id, 'admission_number' => '1001', 'full_name' => 'Active A1', 'is_active' => true]);
        Student::create(['school_id' => $schoolA->id, 'admission_number' => '1002', 'full_name' => 'Inactive A2', 'is_active' => false]);
        Student::create(['school_id' => $schoolB->id, 'admission_number' => '2001', 'full_name' => 'Active B1', 'is_active' => true]);
        $device = Computer::create([
            'device_uuid' => (string) Str::uuid(), 'school_id' => $schoolA->id, 'classroom_id' => $classroom->id,
            'name' => 'Lab PC 1', 'role' => 'student',
        ]);
        $token = $device->createToken('device', ['device'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/device/roster');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.students');
        $response->assertJsonPath('data.students.0.admission_number', '1001');
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/device/roster')->assertUnauthorized();
    }
}
