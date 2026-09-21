<?php

namespace Tests\Feature\Api\V1;

use App\Events\StudentSessionChanged;
use App\Models\Classroom;
use App\Models\Computer;
use App\Models\LoginSession;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceSessionSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeDevice(): Computer
    {
        $school = School::create(['name' => 'School A']);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);
        $class = $school->classes()->create(['name' => 'Grade 1']);
        Student::create(['school_id' => $school->id, 'class_id' => $class->id, 'admission_number' => '1001', 'full_name' => 'Student A1']);

        return Computer::create([
            'device_uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'classroom_id' => $classroom->id,
            'name' => 'Lab PC 1', 'role' => 'student',
        ]);
    }

    private function authHeader(Computer $device): array
    {
        return ['Authorization' => 'Bearer '.$device->createToken('device', ['device'])->plainTextToken];
    }

    public function test_syncing_a_session_stamps_the_classroom_and_class_and_dispatches_an_event(): void
    {
        Event::fake();
        $device = $this->makeDevice();
        $uuid = (string) Str::uuid();

        $response = $this->withHeaders($this->authHeader($device))->postJson('/api/v1/device/student-sessions/sync', [
            'sessions' => [
                ['uuid' => $uuid, 'admission_number' => '1001', 'login_time' => '2026-09-16T08:00:00Z', 'logout_time' => null],
            ],
        ]);

        $response->assertOk();
        $session = LoginSession::where('uuid', $uuid)->first();
        $this->assertSame($device->classroom_id, $session->classroom_id);
        $this->assertSame('active', $session->status);
        Event::assertDispatched(StudentSessionChanged::class, fn ($event) => $event->session->is($session));
    }

    public function test_a_logout_update_marks_the_session_ended_and_dispatches_again(): void
    {
        Event::fake();
        $device = $this->makeDevice();
        $uuid = (string) Str::uuid();

        $this->withHeaders($this->authHeader($device))->postJson('/api/v1/device/student-sessions/sync', [
            'sessions' => [['uuid' => $uuid, 'admission_number' => '1001', 'login_time' => '2026-09-16T08:00:00Z', 'logout_time' => null]],
        ])->assertOk();

        $this->withHeaders($this->authHeader($device))->postJson('/api/v1/device/student-sessions/sync', [
            'sessions' => [['uuid' => $uuid, 'admission_number' => '1001', 'login_time' => '2026-09-16T08:00:00Z', 'logout_time' => '2026-09-16T08:45:00Z']],
        ])->assertOk();

        $session = LoginSession::where('uuid', $uuid)->first();
        $this->assertSame('ended', $session->status);
        Event::assertDispatched(StudentSessionChanged::class, 2);
    }

    public function test_resyncing_an_unchanged_session_does_not_redispatch(): void
    {
        Event::fake();
        $device = $this->makeDevice();
        $uuid = (string) Str::uuid();
        $payload = ['sessions' => [['uuid' => $uuid, 'admission_number' => '1001', 'login_time' => '2026-09-16T08:00:00Z', 'logout_time' => null]]];

        $this->withHeaders($this->authHeader($device))->postJson('/api/v1/device/student-sessions/sync', $payload)->assertOk();
        $this->withHeaders($this->authHeader($device))->postJson('/api/v1/device/student-sessions/sync', $payload)->assertOk();

        Event::assertDispatched(StudentSessionChanged::class, 1);
    }

    public function test_syncing_the_same_uuid_twice_does_not_duplicate_the_row(): void
    {
        $device = $this->makeDevice();
        $uuid = (string) Str::uuid();
        $payload = ['sessions' => [['uuid' => $uuid, 'admission_number' => '1001', 'login_time' => '2026-09-16T08:00:00Z', 'logout_time' => null]]];

        $this->withHeaders($this->authHeader($device))->postJson('/api/v1/device/student-sessions/sync', $payload)->assertOk();
        $this->withHeaders($this->authHeader($device))->postJson('/api/v1/device/student-sessions/sync', $payload)->assertOk();

        $this->assertSame(1, LoginSession::where('uuid', $uuid)->count());
    }
}
