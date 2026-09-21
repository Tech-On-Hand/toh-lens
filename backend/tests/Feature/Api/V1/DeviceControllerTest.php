<?php

namespace Tests\Feature\Api\V1;

use App\Events\DevicePresenceChanged;
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

    private function makeDevice(array $overrides = []): Computer
    {
        $school = School::create(['name' => 'School A']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);

        return Computer::create([
            'device_uuid' => (string) Str::uuid(),
            'school_id' => $school->id,
            'classroom_id' => $classroom->id,
            'name' => 'Lab PC 1',
            'role' => 'student',
            'presence_status' => 'offline',
            ...$overrides,
        ]);
    }

    private function deviceToken(Computer $device): string
    {
        return $device->createToken('device', ['device'])->plainTextToken;
    }

    public function test_a_device_can_fetch_its_own_configuration(): void
    {
        $device = $this->makeDevice();
        $token = $this->deviceToken($device);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/device/configuration');

        $response->assertOk();
        $response->assertJsonPath('data.device_uuid', $device->device_uuid);
        $response->assertJsonPath('data.classroom_id', $device->classroom_id);
    }

    public function test_a_heartbeat_marks_the_device_online_and_broadcasts_the_transition(): void
    {
        Event::fake();
        $device = $this->makeDevice(['presence_status' => 'offline']);
        $token = $this->deviceToken($device);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/device/heartbeat', [
            'agent_version' => '1.2.0',
        ]);

        $response->assertOk();
        $device->refresh();
        $this->assertSame('online', $device->presence_status);
        $this->assertNotNull($device->last_seen_at);
        Event::assertDispatched(DevicePresenceChanged::class, fn ($event) => $event->device->is($device) && $event->status === 'online');
    }

    public function test_a_repeat_heartbeat_with_unchanged_metadata_does_not_rebroadcast(): void
    {
        Event::fake();
        $device = $this->makeDevice(['presence_status' => 'online', 'agent_version' => '1.2.0', 'last_seen_at' => now()]);
        $token = $this->deviceToken($device);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/device/heartbeat', [
            'agent_version' => '1.2.0',
        ])->assertOk();

        Event::assertNotDispatched(DevicePresenceChanged::class);
    }

    public function test_a_revoked_device_is_rejected_even_with_a_valid_token(): void
    {
        $device = $this->makeDevice(['revoked_at' => now()]);
        $token = $this->deviceToken($device);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/device/configuration');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'DEVICE_REVOKED');
    }

    public function test_a_teacher_token_cannot_access_device_routes(): void
    {
        $school = School::create(['name' => 'School A']);
        $user = User::factory()->create();
        $user->schools()->attach($school, ['role' => 'teacher']);
        $token = $user->createToken('test', ['teacher'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/device/configuration');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'DEVICE_REVOKED');
    }
}
