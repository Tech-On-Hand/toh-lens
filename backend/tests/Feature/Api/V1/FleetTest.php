<?php

namespace Tests\Feature\Api\V1;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClassroomFixtures;
use Tests\TestCase;

class FleetTest extends TestCase
{
    use BuildsClassroomFixtures, RefreshDatabase;

    private function admin(School $school): User
    {
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);

        return $admin;
    }

    private function fleet(User $admin, School $school): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->teacherHeaders($admin))->getJson("/api/v1/admin/fleet?school_id={$school->id}");
    }

    private function issueCodes(array $device): array
    {
        return array_column($device['issues'], 'code');
    }

    public function test_a_healthy_device_has_no_issues(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $this->makeDevice($school, $classroom, ['agent_version' => '0.1.0', 'health' => ['screen_capture_supported' => true, 'unsynced_sessions' => 0]]);

        $response = $this->fleet($this->admin($school), $school)->assertOk();

        $response->assertJsonPath('data.summary.devices', 1)->assertJsonPath('data.summary.online', 1)->assertJsonPath('data.summary.needing_attention', 0);
        $this->assertSame([], $response->json('data.devices.0.issues'));
    }

    public function test_a_device_that_has_never_connected_is_flagged(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $this->makeDevice($school, $classroom, ['last_seen_at' => null, 'presence_status' => 'offline']);

        $device = $this->fleet($this->admin($school), $school)->json('data.devices.0');

        $this->assertSame(['never_seen'], $this->issueCodes($device));
    }

    public function test_a_switched_off_pc_is_only_flagged_after_a_day(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $this->makeDevice($school, $classroom, ['name' => 'Overnight', 'presence_status' => 'offline', 'last_seen_at' => now()->subHours(3)]);
        $this->makeDevice($school, $classroom, ['name' => 'Missing', 'presence_status' => 'offline', 'last_seen_at' => now()->subDays(3)]);

        $devices = collect($this->fleet($this->admin($school), $school)->json('data.devices'))->keyBy('name');

        $this->assertSame([], $devices['Overnight']['issues']);
        $this->assertSame(['offline'], $this->issueCodes($devices['Missing']));
    }

    public function test_an_agent_older_than_the_newest_in_the_school_is_flagged(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $this->makeDevice($school, $classroom, ['name' => 'Old', 'agent_version' => '0.1.0']);
        $this->makeDevice($school, $classroom, ['name' => 'New', 'agent_version' => '0.2.0']);

        $response = $this->fleet($this->admin($school), $school);
        $devices = collect($response->json('data.devices'))->keyBy('name');

        $response->assertJsonPath('data.summary.newest_agent_version', '0.2.0');
        $this->assertSame(['outdated_agent'], $this->issueCodes($devices['Old']));
        $this->assertSame([], $devices['New']['issues']);
    }

    public function test_an_online_device_that_cannot_capture_the_screen_or_has_a_sync_backlog_is_flagged(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $this->makeDevice($school, $classroom, ['health' => ['screen_capture_supported' => false, 'unsynced_sessions' => 12]]);

        $device = $this->fleet($this->admin($school), $school)->json('data.devices.0');

        $this->assertEqualsCanonicalizing(['screen_capture', 'sync_backlog'], $this->issueCodes($device));
    }

    public function test_devices_needing_attention_come_first(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $this->makeDevice($school, $classroom, ['name' => 'A fine one']);
        $this->makeDevice($school, $classroom, ['name' => 'Z broken', 'health' => ['screen_capture_supported' => false]]);

        $names = array_column($this->fleet($this->admin($school), $school)->json('data.devices'), 'name');

        $this->assertSame(['Z broken', 'A fine one'], $names);
    }

    public function test_only_administrators_of_that_school_can_see_the_fleet(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $foreignAdmin = $this->admin(School::create(['name' => 'School B']));

        $this->fleet($teacher, $school)->assertForbidden();
        $this->fleet($foreignAdmin, $school)->assertForbidden();
    }

    public function test_revoked_devices_are_left_out(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $this->makeDevice($school, $classroom, ['revoked_at' => now()]);

        $this->fleet($this->admin($school), $school)->assertJsonPath('data.summary.devices', 0);
    }

    public function test_a_heartbeat_records_what_the_agent_reports_about_itself(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);

        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/heartbeat', [
            'agent_version' => '0.1.0', 'health' => ['screen_capture_supported' => false, 'unsynced_sessions' => 7],
        ])->assertOk();

        $this->assertSame(['screen_capture_supported' => false, 'unsynced_sessions' => 7], $device->fresh()->health);
    }

    public function test_an_older_agent_that_reports_no_health_keeps_the_last_known_state(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom, ['health' => ['screen_capture_supported' => true, 'unsynced_sessions' => 1]]);

        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/heartbeat', ['agent_version' => '0.1.0'])->assertOk();

        $this->assertSame(['screen_capture_supported' => true, 'unsynced_sessions' => 1], $device->fresh()->health);
    }

    public function test_the_audit_log_names_the_classroom_and_device(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom, ['name' => 'Lab PC 7']);
        $teacher = $this->makeTeacher($school, $classroom);
        $this->signInStudent($device);
        $this->withHeaders($this->teacherHeaders($teacher))->postJson("/api/v1/teacher/classrooms/{$classroom->id}/devices/{$device->id}/messages", ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'body' => 'hi']);
        $this->withHeaders($this->teacherHeaders($teacher))->postJson("/api/v1/teacher/classrooms/{$classroom->id}/announcements", ['message' => 'Hello class'])->assertCreated();

        $entry = $this->withHeaders($this->teacherHeaders($this->admin($school)))->getJson("/api/v1/admin/audit?school_id={$school->id}")->json('data.0');

        $this->assertSame('announcement.sent', $entry['action']);
        $this->assertSame('Lab 1', $entry['classroom_name']);
    }
}
