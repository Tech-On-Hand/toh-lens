<?php

namespace Tests\Feature\Api\V1;

use App\Events\DevicePolicyChanged;
use App\Events\FocusSessionChanged;
use App\Models\BlockRule;
use App\Models\Classroom;
use App\Models\FocusSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClassroomFixtures;
use Tests\TestCase;

class DevicePolicyTest extends TestCase
{
    use BuildsClassroomFixtures, RefreshDatabase;

    private function startFocus($teacher, Classroom $classroom, array $domains = ['khan.example'], int $minutes = 20): string
    {
        return $this->withHeaders($this->teacherHeaders($teacher))
            ->postJson("/api/v1/teacher/classrooms/{$classroom->id}/focus-sessions", ['allowed_domains' => $domains, 'duration_minutes' => $minutes])
            ->json('data.id');
    }

    public function test_a_device_gets_school_and_own_classroom_rules_but_not_other_classrooms(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $other = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 2']);
        BlockRule::create(['school_id' => $school->id, 'classroom_id' => null, 'domain' => 'school.example']);
        BlockRule::create(['school_id' => $school->id, 'classroom_id' => $classroom->id, 'domain' => 'mine.example']);
        BlockRule::create(['school_id' => $school->id, 'classroom_id' => $other->id, 'domain' => 'theirs.example']);
        $device = $this->makeDevice($school, $classroom);

        $response = $this->withHeaders($this->deviceHeaders($device))->getJson('/api/v1/device/policy');

        $response->assertOk()->assertJsonPath('data.block', ['mine.example', 'school.example'])->assertJsonPath('data.focus', null);
        $this->assertNotEmpty($response->json('data.server_time'));
    }

    public function test_the_device_sees_only_its_own_classrooms_focus_session(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $other = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 2']);
        $teacher = $this->makeTeacher($school, $classroom);
        $this->startFocus($teacher, $classroom, ['khan.example', 'wikipedia.org']);
        $mine = $this->makeDevice($school, $classroom);
        $theirs = $this->makeDevice($school, $other);

        $this->withHeaders($this->deviceHeaders($mine))->getJson('/api/v1/device/policy')
            ->assertJsonPath('data.focus.allowed_domains', ['khan.example', 'wikipedia.org']);
        $this->withHeaders($this->deviceHeaders($theirs))->getJson('/api/v1/device/policy')->assertJsonPath('data.focus', null);
    }

    public function test_the_version_changes_with_the_policy_and_a_known_version_returns_a_tiny_answer(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $device = $this->makeDevice($school, $classroom);
        $headers = $this->deviceHeaders($device);

        $empty = $this->withHeaders($headers)->getJson('/api/v1/device/policy')->json('data.version');
        $this->withHeaders($headers)->getJson("/api/v1/device/policy?known={$empty}")->assertJsonPath('data.unchanged', true)->assertJsonMissingPath('data.block');

        BlockRule::create(['school_id' => $school->id, 'classroom_id' => $classroom->id, 'domain' => 'games.example']);
        $blocked = $this->withHeaders($headers)->getJson("/api/v1/device/policy?known={$empty}");
        $blocked->assertJsonMissingPath('data.unchanged');
        $this->assertNotSame($empty, $blocked->json('data.version'));

        $this->startFocus($teacher, $classroom);
        $focused = $this->withHeaders($headers)->getJson('/api/v1/device/policy')->json('data.version');
        $this->assertNotSame($blocked->json('data.version'), $focused);
    }

    public function test_a_focus_session_stops_applying_at_its_deadline_even_before_the_job_runs(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $this->startFocus($teacher, $classroom, ['khan.example'], 10);
        $device = $this->makeDevice($school, $classroom);
        $headers = $this->deviceHeaders($device);

        $this->withHeaders($headers)->getJson('/api/v1/device/policy')->assertJsonPath('data.focus.allowed_domains', ['khan.example']);

        $this->travel(10)->minutes();
        $this->travel(1)->seconds();
        $this->withHeaders($headers)->getJson('/api/v1/device/policy')->assertJsonPath('data.focus', null);
    }

    public function test_the_expiry_job_closes_overdue_sessions_and_tells_everyone(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $this->makeDevice($school, $classroom);
        $this->startFocus($teacher, $classroom, ['khan.example'], 10);
        $this->travel(11)->minutes();
        Event::fake();

        $this->artisan('focus-sessions:expire')->assertSuccessful();

        $session = FocusSession::first();
        $this->assertSame('expired', $session->end_reason);
        $this->assertNotNull($session->ended_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'focus.expired', 'classroom_id' => $classroom->id]);
        Event::assertDispatched(FocusSessionChanged::class, fn ($e) => $e->state === 'expired');
        Event::assertDispatched(DevicePolicyChanged::class);

        $this->artisan('focus-sessions:expire')->assertSuccessful();
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'focus.expired')->count());
    }

    public function test_a_revoked_device_gets_no_policy(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom, ['revoked_at' => now()]);

        $this->withHeaders($this->deviceHeaders($device))->getJson('/api/v1/device/policy')->assertForbidden();
    }

    public function test_blocked_navigations_reported_by_the_browser_are_recorded_against_the_student(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);

        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/browser/events', ['browser' => 'chrome', 'events' => [[
            'uuid' => (string) Str::uuid(), 'type' => 'blocked', 'url' => 'https://games.example/play', 'title' => 'Blocked',
            'session_uuid' => $session->uuid, 'occurred_at' => now()->toIso8601String(),
        ]]])->assertOk()->assertJsonPath('data.events_recorded', 1);

        $this->assertDatabaseHas('browser_activities', ['event_type' => 'blocked', 'domain' => 'games.example', 'login_session_id' => $session->id]);
    }
}
