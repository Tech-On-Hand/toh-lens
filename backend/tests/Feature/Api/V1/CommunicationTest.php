<?php

namespace Tests\Feature\Api\V1;

use App\Models\Announcement;
use App\Models\HelpRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClassroomFixtures;
use Tests\TestCase;

class CommunicationTest extends TestCase
{
    use BuildsClassroomFixtures, RefreshDatabase;

    private function announce(string $message = 'Eyes on the board.'): array
    {
        return ['message' => $message];
    }

    public function test_a_controlling_teacher_sends_an_announcement(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $this->makeDevice($school, $classroom);

        $this->withHeaders($this->teacherHeaders($teacher))
            ->postJson("/api/v1/teacher/classrooms/{$classroom->id}/announcements", $this->announce())
            ->assertCreated()
            ->assertJsonPath('data.message', 'Eyes on the board.')
            ->assertJsonPath('data.total_devices', 1)
            ->assertJsonPath('data.delivered', 0);

        $this->assertDatabaseHas('audit_logs', ['action' => 'announcement.sent', 'actor_id' => $teacher->id]);
    }

    public function test_an_observer_cannot_send_an_announcement(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $observer = $this->makeTeacher($school, $classroom, 'observer');

        $this->withHeaders($this->teacherHeaders($observer))
            ->postJson("/api/v1/teacher/classrooms/{$classroom->id}/announcements", $this->announce())
            ->assertForbidden();
    }

    public function test_a_teacher_cannot_announce_to_another_classroom(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        [, $other] = $this->makeClassroom('School B');
        $teacher = $this->makeTeacher($school, $classroom);

        $this->withHeaders($this->teacherHeaders($teacher))
            ->postJson("/api/v1/teacher/classrooms/{$other->id}/announcements", $this->announce())
            ->assertForbidden();
    }

    public function test_an_announcement_is_delivered_then_read_and_the_teacher_sees_the_counts(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $first = $this->makeDevice($school, $classroom);
        $second = $this->makeDevice($school, $classroom);
        $id = $this->withHeaders($this->teacherHeaders($teacher))
            ->postJson("/api/v1/teacher/classrooms/{$classroom->id}/announcements", $this->announce())->json('data.id');

        $fetched = $this->withHeaders($this->deviceHeaders($first))->getJson('/api/v1/device/announcements');
        $fetched->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

        $this->withHeaders($this->deviceHeaders($first))->postJson("/api/v1/device/announcements/{$id}/read")->assertOk();

        // Once read it is not offered again; the other device still has it pending.
        $this->withHeaders($this->deviceHeaders($first))->getJson('/api/v1/device/announcements')->assertJsonCount(0, 'data');
        $this->withHeaders($this->deviceHeaders($second))->getJson('/api/v1/device/announcements')->assertJsonCount(1, 'data');

        $this->withHeaders($this->teacherHeaders($teacher))
            ->getJson("/api/v1/teacher/classrooms/{$classroom->id}/announcements")
            ->assertOk()
            ->assertJsonPath('data.0.total_devices', 2)
            ->assertJsonPath('data.0.delivered', 2)
            ->assertJsonPath('data.0.read', 1);
    }

    public function test_an_expired_announcement_is_no_longer_offered(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $device = $this->makeDevice($school, $classroom);
        $this->withHeaders($this->teacherHeaders($teacher))
            ->postJson("/api/v1/teacher/classrooms/{$classroom->id}/announcements", $this->announce());

        Announcement::query()->update(['expires_at' => now()->subMinute()]);

        $this->withHeaders($this->deviceHeaders($device))->getJson('/api/v1/device/announcements')->assertJsonCount(0, 'data');
    }

    public function test_a_device_only_sees_its_own_classrooms_announcements(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        [$otherSchool, $otherClassroom] = $this->makeClassroom('School B');
        $teacher = $this->makeTeacher($otherSchool, $otherClassroom);
        $device = $this->makeDevice($school, $classroom);
        $this->withHeaders($this->teacherHeaders($teacher))
            ->postJson("/api/v1/teacher/classrooms/{$otherClassroom->id}/announcements", $this->announce());

        $this->withHeaders($this->deviceHeaders($device))->getJson('/api/v1/device/announcements')->assertJsonCount(0, 'data');
    }

    public function test_a_student_raises_a_hand_and_it_shows_on_the_teachers_grid(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $device = $this->makeDevice($school, $classroom);
        $this->signInStudent($device);
        $uuid = (string) Str::uuid();

        $this->withHeaders($this->deviceHeaders($device))
            ->postJson('/api/v1/device/help-requests', ['uuid' => $uuid, 'message' => 'Stuck on question 3'])
            ->assertCreated()->assertJsonPath('data.status', 'open');

        $this->assertNotNull(HelpRequest::first()->student_id);

        $grid = $this->withHeaders($this->teacherHeaders($teacher))->getJson("/api/v1/teacher/classrooms/{$classroom->id}/devices");
        $grid->assertJsonPath('data.0.help_request.id', $uuid)->assertJsonPath('data.0.help_request.message', 'Stuck on question 3');

        $this->withHeaders($this->teacherHeaders($teacher))->getJson("/api/v1/teacher/classrooms/{$classroom->id}/help-requests")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_name', 'Student 1001');
    }

    public function test_retrying_the_same_request_or_raising_another_does_not_duplicate(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $uuid = (string) Str::uuid();

        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/help-requests', ['uuid' => $uuid])->assertCreated();
        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/help-requests', ['uuid' => $uuid])->assertOk();
        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/help-requests', ['uuid' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.id', $uuid);

        $this->assertSame(1, HelpRequest::count());
    }

    public function test_a_hand_raised_by_a_student_who_has_since_signed_out_is_refused(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $gone = $this->signInStudent($device, '1001');
        $gone->update(['status' => 'ended', 'logout_time' => now()]);
        $this->signInStudent($device, '1002');

        $this->withHeaders($this->deviceHeaders($device))
            ->postJson('/api/v1/device/help-requests', ['uuid' => (string) Str::uuid(), 'session_uuid' => $gone->uuid])
            ->assertStatus(409)->assertJsonPath('error.code', 'SESSION_ENDED');
        $this->assertSame(0, HelpRequest::count());
    }

    public function test_a_queued_hand_is_credited_to_the_session_it_was_raised_in(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device, '1001');

        $this->withHeaders($this->deviceHeaders($device))
            ->postJson('/api/v1/device/help-requests', ['uuid' => (string) Str::uuid(), 'session_uuid' => $session->uuid])
            ->assertCreated();

        $this->assertSame($session->student_id, HelpRequest::first()->student_id);
    }

    public function test_a_request_id_used_by_another_device_is_refused(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $first = $this->makeDevice($school, $classroom);
        $second = $this->makeDevice($school, $classroom);
        $uuid = (string) Str::uuid();

        $this->withHeaders($this->deviceHeaders($first))->postJson('/api/v1/device/help-requests', ['uuid' => $uuid])->assertCreated();
        $this->withHeaders($this->deviceHeaders($second))->postJson('/api/v1/device/help-requests', ['uuid' => $uuid])
            ->assertStatus(409)->assertJsonPath('error.code', 'REQUEST_ID_TAKEN');
    }

    public function test_the_teacher_resolves_a_request_and_the_device_sees_it_clear(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $device = $this->makeDevice($school, $classroom);
        $uuid = (string) Str::uuid();
        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/help-requests', ['uuid' => $uuid]);

        $this->withHeaders($this->deviceHeaders($device))->getJson('/api/v1/device/help-requests/current')
            ->assertJsonPath('data.help_request.id', $uuid);

        $this->withHeaders($this->teacherHeaders($teacher))
            ->postJson("/api/v1/teacher/classrooms/{$classroom->id}/help-requests/{$uuid}/resolve")
            ->assertOk()->assertJsonPath('data.status', 'resolved');

        $this->withHeaders($this->deviceHeaders($device))->getJson('/api/v1/device/help-requests/current')
            ->assertJsonPath('data.help_request', null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'help.resolved', 'actor_id' => $teacher->id]);
    }

    public function test_an_observer_cannot_resolve_a_request(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $observer = $this->makeTeacher($school, $classroom, 'observer');
        $device = $this->makeDevice($school, $classroom);
        $uuid = (string) Str::uuid();
        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/help-requests', ['uuid' => $uuid]);

        $this->withHeaders($this->teacherHeaders($observer))
            ->postJson("/api/v1/teacher/classrooms/{$classroom->id}/help-requests/{$uuid}/resolve")->assertForbidden();
    }

    public function test_a_student_can_cancel_their_own_request_but_not_anothers(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $other = $this->makeDevice($school, $classroom);
        $uuid = (string) Str::uuid();
        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/help-requests', ['uuid' => $uuid]);

        $this->withHeaders($this->deviceHeaders($other))->postJson("/api/v1/device/help-requests/{$uuid}/cancel")->assertNotFound();
        $this->withHeaders($this->deviceHeaders($device))->postJson("/api/v1/device/help-requests/{$uuid}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, HelpRequest::query()->open()->count());
    }
}
