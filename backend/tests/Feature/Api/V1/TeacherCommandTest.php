<?php

namespace Tests\Feature\Api\V1;

use App\Events\DeviceCommandIssued;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsClassroomFixtures;
use Tests\TestCase;

class TeacherCommandTest extends TestCase
{
    use BuildsClassroomFixtures, RefreshDatabase;

    private function url($classroom, $device): string
    {
        return "/api/v1/teacher/classrooms/{$classroom->id}/devices/{$device->id}/commands";
    }

    public function test_a_teacher_can_open_a_url_for_the_signed_in_student(): void
    {
        Event::fake();
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);
        $teacher = $this->makeTeacher($school, $classroom);

        $response = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->url($classroom, $device), [
            'type' => 'browser.open_url', 'student_session_id' => $session->uuid, 'payload' => ['url' => 'https://example.com/lesson'],
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'pending');
        $command = DeviceCommand::first();
        $this->assertSame($session->id, $command->login_session_id);
        $this->assertSame($teacher->id, $command->issued_by);
        $this->assertTrue($command->expires_at->isFuture());
        Event::assertDispatched(DeviceCommandIssued::class);
    }

    public function test_an_observer_cannot_issue_commands(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);
        $observer = $this->makeTeacher($school, $classroom, 'observer');

        $this->withHeaders($this->teacherHeaders($observer))->postJson($this->url($classroom, $device), [
            'type' => 'browser.close_tab', 'student_session_id' => $session->uuid, 'payload' => ['tab_id' => 4],
        ])->assertForbidden();
    }

    public function test_a_teacher_outside_the_classroom_cannot_issue_commands(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);
        $outsider = User::factory()->create();
        $outsider->schools()->attach($school, ['role' => 'teacher']);

        $this->withHeaders($this->teacherHeaders($outsider))->postJson($this->url($classroom, $device), [
            'type' => 'browser.close_tab', 'student_session_id' => $session->uuid, 'payload' => ['tab_id' => 4],
        ])->assertForbidden();
    }

    public function test_the_command_is_refused_when_the_student_has_signed_out_or_changed(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $old = $this->signInStudent($device, '1001');
        $old->update(['status' => 'ended', 'logout_time' => now()]);
        $this->signInStudent($device, '1002');
        $teacher = $this->makeTeacher($school, $classroom);

        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->url($classroom, $device), [
            'type' => 'browser.open_url', 'student_session_id' => $old->uuid, 'payload' => ['url' => 'https://example.com'],
        ])->assertStatus(409)->assertJsonPath('error.code', 'SESSION_MISMATCH');
    }

    public function test_the_command_is_refused_when_the_device_is_offline(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom, ['presence_status' => 'offline']);
        $session = $this->signInStudent($device);
        $teacher = $this->makeTeacher($school, $classroom);

        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->url($classroom, $device), [
            'type' => 'browser.open_url', 'student_session_id' => $session->uuid, 'payload' => ['url' => 'https://example.com'],
        ])->assertStatus(409)->assertJsonPath('error.code', 'DEVICE_OFFLINE');
    }

    public function test_dangerous_url_schemes_and_malformed_payloads_are_rejected(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);
        $teacher = $this->makeTeacher($school, $classroom);
        $headers = $this->teacherHeaders($teacher);
        $sid = $session->uuid;

        foreach (['javascript:alert(1)', 'file:///c:/windows/win.ini', 'chrome://settings', 'data:text/html,hi', 'ftp://example.com'] as $url) {
            $this->withHeaders($headers)->postJson($this->url($classroom, $device), [
                'type' => 'browser.open_url', 'student_session_id' => $sid, 'payload' => ['url' => $url],
            ])->assertStatus(422);
        }

        $this->withHeaders($headers)->postJson($this->url($classroom, $device), ['type' => 'browser.navigate', 'student_session_id' => $sid, 'payload' => ['url' => 'https://example.com']])->assertStatus(422);
        $this->withHeaders($headers)->postJson($this->url($classroom, $device), ['type' => 'browser.close_tab', 'student_session_id' => $sid, 'payload' => ['tab_id' => 1, 'url' => 'https://example.com']])->assertStatus(422);
        $this->withHeaders($headers)->postJson($this->url($classroom, $device), ['type' => 'system.shutdown', 'student_session_id' => $sid, 'payload' => []])->assertStatus(422);
        $this->assertSame(0, DeviceCommand::count());
    }

    public function test_a_command_for_a_device_in_another_classroom_is_not_found(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $otherRoom = \App\Models\Classroom::create(['school_id' => $school->id, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Lab 2']);
        $device = $this->makeDevice($school, $otherRoom);
        $session = $this->signInStudent($device);
        $teacher = $this->makeTeacher($school, $classroom);

        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->url($classroom, $device), [
            'type' => 'browser.close_tab', 'student_session_id' => $session->uuid, 'payload' => ['tab_id' => 1],
        ])->assertNotFound();
    }

    public function test_a_viewer_can_read_command_status_and_the_tab_list(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);
        $teacher = $this->makeTeacher($school, $classroom);
        $observer = $this->makeTeacher($school, $classroom, 'observer');
        $headers = $this->teacherHeaders($teacher);

        $id = $this->withHeaders($headers)->postJson($this->url($classroom, $device), [
            'type' => 'browser.close_tab', 'student_session_id' => $session->uuid, 'payload' => ['tab_id' => 7],
        ])->json('data.id');

        $this->withHeaders($this->teacherHeaders($observer))->getJson($this->url($classroom, $device)."/{$id}")
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $this->withHeaders($this->teacherHeaders($observer))->getJson("/api/v1/teacher/classrooms/{$classroom->id}/devices/{$device->id}/browser-tabs")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_device_snapshot_includes_the_active_tab(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);
        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/browser/events', [
            'browser' => 'chrome',
            'snapshot' => ['observed_at' => now()->toIso8601String(), 'tabs' => [
                ['tab_id' => 1, 'window_id' => 1, 'url' => 'https://a.example/', 'title' => 'A', 'active' => false],
                ['tab_id' => 2, 'window_id' => 1, 'url' => 'https://b.example/', 'title' => 'B', 'active' => true],
            ]],
        ])->assertOk();

        $response = $this->withHeaders($this->teacherHeaders($teacher))->getJson("/api/v1/teacher/classrooms/{$classroom->id}/devices");

        $response->assertOk()->assertJsonPath('data.0.active_tab.url', 'https://b.example/');
        $this->withHeaders($this->teacherHeaders($teacher))->getJson("/api/v1/teacher/classrooms/{$classroom->id}/devices/{$device->id}/browser-tabs")
            ->assertOk()->assertJsonCount(2, 'data');
    }
}
