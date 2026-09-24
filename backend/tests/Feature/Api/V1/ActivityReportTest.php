<?php

namespace Tests\Feature\Api\V1;

use App\Models\AppActivity;
use App\Models\BrowserActivity;
use App\Models\LoginSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClassroomFixtures;
use Tests\TestCase;

class ActivityReportTest extends TestCase
{
    use BuildsClassroomFixtures, RefreshDatabase;

    private function interval(LoginSession $session, string $process, string $start, string $end, bool $idle = false): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'session_uuid' => $session->uuid,
            'process' => $process,
            'is_idle' => $idle,
            'started_at' => now()->setTimeFromTimeString($start)->toIso8601String(),
            'ended_at' => now()->setTimeFromTimeString($end)->toIso8601String(),
        ];
    }

    private function report($teacher, $classroom, string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->teacherHeaders($teacher))->getJson("/api/v1/teacher/classrooms/{$classroom->id}/reports/activity{$query}");
    }

    public function test_a_device_uploads_application_intervals_for_its_own_sessions(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);
        $interval = $this->interval($session, 'WINWORD.EXE', '09:00', '09:10');

        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/app-activity', ['intervals' => [$interval]])
            ->assertOk()->assertJsonPath('data.accepted.0', $interval['uuid']);

        $stored = AppActivity::first();
        $this->assertSame('WINWORD.EXE', $stored->process);
        $this->assertSame($session->id, $stored->login_session_id);
        $this->assertSame($session->student_id, $stored->student_id);
    }

    public function test_an_interval_still_open_can_be_extended_but_never_shortened(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);
        $interval = $this->interval($session, 'excel.exe', '09:00', '09:05');
        $post = fn (array $body) => $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/app-activity', ['intervals' => [$body]])->assertOk();

        $post($interval);
        $post([...$interval, 'ended_at' => now()->setTimeFromTimeString('09:12')->toIso8601String()]);
        $post([...$interval, 'ended_at' => now()->setTimeFromTimeString('09:03')->toIso8601String()]);

        $this->assertSame(1, AppActivity::count());
        $this->assertSame('09:12', AppActivity::first()->ended_at->format('H:i'));
    }

    public function test_an_interval_for_a_session_the_server_has_not_seen_is_left_for_a_retry(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $this->signInStudent($device);
        $interval = ['uuid' => (string) Str::uuid(), 'session_uuid' => (string) Str::uuid(), 'process' => 'a.exe', 'is_idle' => false,
            'started_at' => now()->subMinutes(5)->toIso8601String(), 'ended_at' => now()->toIso8601String()];

        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/app-activity', ['intervals' => [$interval]])
            ->assertOk()->assertJsonCount(0, 'data.accepted');
        $this->assertSame(0, AppActivity::count());
    }

    public function test_another_devices_session_cannot_be_written_to(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $other = $this->makeDevice($school, $classroom);
        $theirSession = $this->signInStudent($other, '2002');

        $this->withHeaders($this->deviceHeaders($device))
            ->postJson('/api/v1/device/app-activity', ['intervals' => [$this->interval($theirSession, 'a.exe', '09:00', '09:05')]])
            ->assertOk()->assertJsonCount(0, 'data.accepted');
        $this->assertSame(0, AppActivity::count());
    }

    public function test_the_report_totals_active_and_idle_time_and_ranks_applications(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);
        $session->update(['login_time' => now()->setTimeFromTimeString('09:00'), 'logout_time' => now()->setTimeFromTimeString('10:00'), 'status' => 'ended']);

        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/app-activity', ['intervals' => [
            $this->interval($session, 'WINWORD.EXE', '09:00', '09:25'),
            $this->interval($session, 'chrome.exe', '09:25', '09:40'),
            $this->interval($session, 'chrome.exe', '09:40', '09:50', true),
            $this->interval($session, 'WINWORD.EXE', '09:50', '10:00'),
        ]])->assertOk();

        $report = $this->report($teacher, $classroom)->assertOk();
        $report->assertJsonPath('data.sessions.0.signed_in_minutes', 60)
            ->assertJsonPath('data.sessions.0.active_minutes', 50)
            ->assertJsonPath('data.sessions.0.idle_minutes', 10)
            ->assertJsonPath('data.sessions.0.apps.0.name', 'Word')
            ->assertJsonPath('data.sessions.0.apps.0.minutes', 35)
            ->assertJsonPath('data.sessions.0.apps.1.name', 'Chrome')
            ->assertJsonPath('data.sessions.0.apps.1.minutes', 15)
            ->assertJsonPath('data.sessions.0.student.name', 'Student 1001')
            ->assertJsonPath('data.classroom_apps.0.name', 'Word');
    }

    public function test_the_report_lists_sites_and_blocked_attempts(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);
        foreach ([['khan.example', 'navigated'], ['khan.example', 'navigated'], ['games.example', 'blocked']] as [$domain, $type]) {
            BrowserActivity::create([
                'uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'computer_id' => $device->id, 'classroom_id' => $classroom->id,
                'login_session_id' => $session->id, 'student_id' => $session->student_id, 'browser' => 'chrome', 'event_type' => $type,
                'url' => "https://{$domain}/", 'domain' => $domain, 'occurred_at' => now(),
            ]);
        }

        $this->report($teacher, $classroom)->assertOk()
            ->assertJsonPath('data.sessions.0.sites.0.domain', 'khan.example')
            ->assertJsonPath('data.sessions.0.sites.0.visits', 2)
            ->assertJsonPath('data.sessions.0.blocked_attempts', 1);
    }

    public function test_the_report_only_covers_the_requested_dates_and_this_classroom(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $device = $this->makeDevice($school, $classroom);
        $old = $this->signInStudent($device, '1001');
        $old->update(['login_time' => now()->subDays(3), 'logout_time' => now()->subDays(3)->addHour(), 'status' => 'ended']);
        $today = $this->signInStudent($device, '1002');

        $this->report($teacher, $classroom)->assertJsonCount(1, 'data.sessions')->assertJsonPath('data.sessions.0.session_uuid', $today->uuid);
        $range = '?from='.now()->subDays(4)->toDateString().'&to='.now()->toDateString();
        $this->report($teacher, $classroom, $range)->assertJsonCount(2, 'data.sessions');
    }

    public function test_a_range_longer_than_a_month_is_refused(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);

        $this->report($teacher, $classroom, '?from='.now()->subDays(40)->toDateString().'&to='.now()->toDateString())
            ->assertStatus(422)->assertJsonPath('error.code', 'RANGE_TOO_LONG');
    }

    public function test_a_teacher_cannot_see_another_classrooms_report(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        [$otherSchool, $otherClassroom] = $this->makeClassroom('School B');
        $outsider = $this->makeTeacher($otherSchool, $otherClassroom);

        $this->report($outsider, $classroom)->assertForbidden();
    }

    public function test_pruning_deletes_only_records_past_the_retention_period(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);
        foreach ([now()->subDays(100), now()->subDays(10)] as $when) {
            AppActivity::create(['uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'computer_id' => $device->id, 'login_session_id' => $session->id,
                'process' => 'a.exe', 'is_idle' => false, 'started_at' => $when, 'ended_at' => $when->copy()->addMinutes(5)]);
            BrowserActivity::create(['uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'computer_id' => $device->id, 'browser' => 'chrome',
                'event_type' => 'navigated', 'domain' => 'a.example', 'occurred_at' => $when]);
        }

        $this->artisan('activity:prune')->assertSuccessful();

        $this->assertSame(1, AppActivity::count());
        $this->assertSame(1, BrowserActivity::count());
        $this->artisan('activity:prune', ['--days' => 5])->assertSuccessful();
        $this->assertSame(0, AppActivity::count());
    }
}
