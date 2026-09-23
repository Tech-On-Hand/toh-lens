<?php

namespace Tests\Feature\Api\V1;

use App\Models\Classroom;
use App\Models\Computer;
use App\Models\DeviceEnrollmentCode;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeCode(Classroom $classroom, array $overrides = []): array
    {
        $code = 'ABCD-1234';
        $creator = User::factory()->create();
        DeviceEnrollmentCode::create([
            'school_id' => $classroom->school_id,
            'classroom_id' => $classroom->id,
            'created_by' => $creator->id,
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes(30),
            ...$overrides,
        ]);

        return [$code];
    }

    private function enrollPayload(string $code, string $deviceUuid): array
    {
        return [
            'code' => $code,
            'device_uuid' => $deviceUuid,
            'name' => 'Lab PC 1',
            'hostname' => 'LAB-PC-01',
            'operating_system' => 'Windows 11',
            'agent_version' => '1.0.0',
        ];
    }

    public function test_a_valid_code_enrolls_a_new_device_and_issues_a_token(): void
    {
        $school = School::create(['name' => 'School A']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        [$code] = $this->makeCode($classroom);
        $deviceUuid = (string) Str::uuid();

        $response = $this->postJson('/api/v1/devices/enroll', $this->enrollPayload($code, $deviceUuid));

        $response->assertCreated();
        $response->assertJsonPath('data.device.classroom_id', $classroom->id);
        // Regression check: `save()` on a freshly-created row doesn't pull the
        // DB-level default back into the model, so this silently serialized as
        // null until `deviceConfiguration()` explicitly refreshed the model —
        // the Rust agent requires it as a non-optional number and fails to parse
        // the whole response when it's missing.
        $response->assertJsonPath('data.device.configuration_version', 1);
        $this->assertNotEmpty($response->json('data.token'));

        $device = Computer::where('device_uuid', $deviceUuid)->first();
        $this->assertNotNull($device);
        $this->assertSame($classroom->id, $device->classroom_id);
        $this->assertNotNull(DeviceEnrollmentCode::first()->used_at);
    }

    public function test_an_already_used_code_is_rejected(): void
    {
        $school = School::create(['name' => 'School A']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        [$code] = $this->makeCode($classroom, ['used_at' => now()]);

        $response = $this->postJson('/api/v1/devices/enroll', $this->enrollPayload($code, (string) Str::uuid()));

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'ENROLLMENT_CODE_INVALID');
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $school = School::create(['name' => 'School A']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        [$code] = $this->makeCode($classroom, ['expires_at' => now()->subMinute()]);

        $response = $this->postJson('/api/v1/devices/enroll', $this->enrollPayload($code, (string) Str::uuid()));

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'ENROLLMENT_CODE_INVALID');
    }

    public function test_a_revoked_device_cannot_re_enroll(): void
    {
        $school = School::create(['name' => 'School A']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $deviceUuid = (string) Str::uuid();
        Computer::create([
            'device_uuid' => $deviceUuid, 'school_id' => $school->id, 'classroom_id' => $classroom->id,
            'name' => 'Lab PC 1', 'role' => 'student', 'revoked_at' => now(),
        ]);
        [$code] = $this->makeCode($classroom);

        $response = $this->postJson('/api/v1/devices/enroll', $this->enrollPayload($code, $deviceUuid));

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'ENROLLMENT_CODE_INVALID');
    }

    public function test_re_enrolling_the_same_device_in_its_own_school_rotates_the_token(): void
    {
        $school = School::create(['name' => 'School A']);
        $classroomA = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $classroomB = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 2']);
        $deviceUuid = (string) Str::uuid();

        [$firstCode] = $this->makeCode($classroomA);
        $firstResponse = $this->postJson('/api/v1/devices/enroll', $this->enrollPayload($firstCode, $deviceUuid));
        $firstToken = $firstResponse->json('data.token');

        [$secondCode] = $this->makeCode($classroomB, ['code_hash' => hash('sha256', 'EFGH-5678')]);
        $secondResponse = $this->postJson('/api/v1/devices/enroll', [
            ...$this->enrollPayload('EFGH-5678', $deviceUuid),
        ]);

        $secondResponse->assertCreated();
        $secondResponse->assertJsonPath('data.device.classroom_id', $classroomB->id);
        $this->assertSame(1, Computer::where('device_uuid', $deviceUuid)->count());

        $this->withHeader('Authorization', "Bearer {$firstToken}")
            ->getJson('/api/v1/device/configuration')
            ->assertUnauthorized();
    }

    public function test_a_device_belonging_to_a_different_school_cannot_re_enroll_with_a_code_for_another_school(): void
    {
        $schoolA = School::create(['name' => 'School A']);
        $schoolB = School::create(['name' => 'School B']);
        $classroomA = Classroom::create(['school_id' => $schoolA->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $classroomB = Classroom::create(['school_id' => $schoolB->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $deviceUuid = (string) Str::uuid();
        Computer::create([
            'device_uuid' => $deviceUuid, 'school_id' => $schoolA->id, 'classroom_id' => $classroomA->id,
            'name' => 'Lab PC 1', 'role' => 'student',
        ]);
        [$code] = $this->makeCode($classroomB);

        $response = $this->postJson('/api/v1/devices/enroll', $this->enrollPayload($code, $deviceUuid));

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'ENROLLMENT_CODE_INVALID');
    }
}
