<?php

namespace Tests\Feature\Api\V1;

use App\Events\ScreenSessionUpdated;
use App\Models\Computer;
use App\Models\ScreenSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsClassroomFixtures;
use Tests\TestCase;

class ScreenSessionTest extends TestCase
{
    use BuildsClassroomFixtures, RefreshDatabase;

    private function offer(): array
    {
        return ['offer' => ['type' => 'offer', 'sdp' => 'v=0...fake-offer']];
    }

    private function answer(): array
    {
        return ['answer' => ['type' => 'answer', 'sdp' => 'v=0...fake-answer']];
    }

    private function watchUrl(Computer $device, string $suffix = ''): string
    {
        return "/api/v1/teacher/classrooms/{$device->classroom_id}/devices/{$device->id}/screen-sessions{$suffix}";
    }

    public function test_a_teacher_starts_watching_an_online_device(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);

        $response = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->watchUrl($device), $this->offer());

        $response->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.offer.sdp', 'v=0...fake-offer');
        $this->assertSame($teacher->id, ScreenSession::first()->viewer_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'screen.started', 'actor_id' => $teacher->id]);
    }

    public function test_watching_an_offline_device_is_refused(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom, ['presence_status' => 'offline']);
        $teacher = $this->makeTeacher($school, $classroom);

        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->watchUrl($device), $this->offer())
            ->assertStatus(409)->assertJsonPath('error.code', 'DEVICE_OFFLINE');
    }

    public function test_only_one_viewer_can_watch_a_device_at_a_time(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $first = $this->makeTeacher($school, $classroom);
        $second = $this->makeTeacher($school, $classroom);

        $this->withHeaders($this->teacherHeaders($first))->postJson($this->watchUrl($device), $this->offer())->assertCreated();
        $this->withHeaders($this->teacherHeaders($second))->postJson($this->watchUrl($device), $this->offer())
            ->assertStatus(409)->assertJsonPath('error.code', 'SCREEN_ALREADY_WATCHED');
        $this->assertSame(1, ScreenSession::count());
    }

    public function test_a_new_watch_is_allowed_once_the_previous_one_ended(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);
        $first = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->watchUrl($device), $this->offer())->json('data.id');

        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->watchUrl($device, "/{$first}/end"))->assertOk();
        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->watchUrl($device), $this->offer())->assertCreated();

        $this->assertSame(2, ScreenSession::count());
    }

    public function test_an_outsider_cannot_watch_a_classroom_they_do_not_belong_to(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $outsider = User::factory()->create();
        $outsider->schools()->attach($school, ['role' => 'teacher']);

        $this->withHeaders($this->teacherHeaders($outsider))->postJson($this->watchUrl($device), $this->offer())->assertForbidden();
        $this->assertSame(0, ScreenSession::count());
    }

    public function test_the_device_sees_the_pending_offer_and_answers_it(): void
    {
        Event::fake();
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);
        $id = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->watchUrl($device), $this->offer())->json('data.id');

        $current = $this->withHeaders($this->deviceHeaders($device))->getJson('/api/v1/device/screen-sessions/current');
        $current->assertOk()->assertJsonPath('data.session.id', $id)->assertJsonPath('data.session.status', 'pending')->assertJsonPath('data.session.offer.sdp', 'v=0...fake-offer');

        $answered = $this->withHeaders($this->deviceHeaders($device))->patchJson("/api/v1/device/screen-sessions/{$id}", $this->answer());
        $answered->assertOk()->assertJsonPath('data.status', 'active');
        Event::assertDispatched(ScreenSessionUpdated::class);

        $again = $this->withHeaders($this->deviceHeaders($device))->patchJson("/api/v1/device/screen-sessions/{$id}", $this->answer());
        $again->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_sdp_line_terminators_survive_the_round_trip(): void
    {
        // RFC 4566 requires CRLF line endings, including after the last line; the
        // global request-trimming middleware silently stripped a trailing "\r\n"
        // before this field was excepted, which real browsers reject as invalid.
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);
        $offerSdp = "v=0\r\ns=-\r\na=recvonly\r\n";
        $answerSdp = "v=0\r\ns=-\r\na=sendonly\r\n";

        $id = $this->withHeaders($this->teacherHeaders($teacher))
            ->postJson($this->watchUrl($device), ['offer' => ['type' => 'offer', 'sdp' => $offerSdp]])
            ->assertCreated()->assertJsonPath('data.offer.sdp', $offerSdp)
            ->json('data.id');

        $this->withHeaders($this->deviceHeaders($device))
            ->patchJson("/api/v1/device/screen-sessions/{$id}", ['answer' => ['type' => 'answer', 'sdp' => $answerSdp]])
            ->assertOk()->assertJsonPath('data.answer.sdp', $answerSdp);
    }

    public function test_a_device_never_sees_another_devices_session(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $other = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);
        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->watchUrl($device), $this->offer())->assertCreated();

        $this->withHeaders($this->deviceHeaders($other))->getJson('/api/v1/device/screen-sessions/current')
            ->assertOk()->assertJsonPath('data.session', null);
    }

    public function test_candidates_flow_both_ways_and_are_paged_by_after(): void
    {
        Event::fake();
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);
        $id = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->watchUrl($device), $this->offer())->json('data.id');

        $this->withHeaders($this->deviceHeaders($device))->postJson("/api/v1/device/screen-sessions/{$id}/candidates", [
            'candidates' => [['candidate' => 'device-candidate-1']],
        ])->assertOk();
        Event::assertDispatched(ScreenSessionUpdated::class);

        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->watchUrl($device, "/{$id}/candidates"), [
            'candidates' => [['candidate' => 'viewer-candidate-1'], ['candidate' => 'viewer-candidate-2']],
        ])->assertOk();

        $forTeacher = $this->withHeaders($this->teacherHeaders($teacher))->getJson($this->watchUrl($device, "/{$id}"));
        $forTeacher->assertJsonCount(1, 'data.candidates');
        $this->assertSame('device-candidate-1', $forTeacher->json('data.candidates.0.payload.candidate'));

        $forDevice = $this->withHeaders($this->deviceHeaders($device))->getJson('/api/v1/device/screen-sessions/current');
        $this->assertSame(['viewer-candidate-1', 'viewer-candidate-2'], collect($forDevice->json('data.session.candidates'))->pluck('payload.candidate')->all());

        $cursor = $forDevice->json('data.session.candidates.1.id');
        $paged = $this->withHeaders($this->deviceHeaders($device))->getJson("/api/v1/device/screen-sessions/current?after={$cursor}");
        $paged->assertJsonCount(0, 'data.session.candidates');
    }

    public function test_ending_a_session_makes_it_disappear_from_the_devices_poll(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);
        $id = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->watchUrl($device), $this->offer())->json('data.id');

        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->watchUrl($device, "/{$id}/end"))->assertOk()->assertJsonPath('data.status', 'ended');

        $this->withHeaders($this->deviceHeaders($device))->getJson('/api/v1/device/screen-sessions/current')->assertJsonPath('data.session', null);
    }

    public function test_only_the_viewer_or_a_classroom_controller_can_end_a_session(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $viewer = $this->makeTeacher($school, $classroom);
        $observer = $this->makeTeacher($school, $classroom, 'observer');
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);
        $id = $this->withHeaders($this->teacherHeaders($viewer))->postJson($this->watchUrl($device), $this->offer())->json('data.id');

        $this->withHeaders($this->teacherHeaders($observer))->postJson($this->watchUrl($device, "/{$id}/end"))->assertForbidden();
        $this->withHeaders($this->teacherHeaders($admin))->postJson($this->watchUrl($device, "/{$id}/end"))->assertOk();
    }

    public function test_revoking_a_device_ends_its_current_screen_session(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);
        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->watchUrl($device), $this->offer())->assertCreated();

        $this->withHeaders($this->teacherHeaders($admin))->postJson("/api/v1/admin/devices/{$device->id}/revoke")->assertOk();

        $session = ScreenSession::first();
        $this->assertSame('ended', $session->status);
        $this->assertSame('device_revoked', $session->end_reason);
    }

    public function test_marking_a_device_offline_ends_its_current_screen_session(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);
        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->watchUrl($device), $this->offer())->assertCreated();

        $device->update(['last_seen_at' => now()->subMinutes(5)]);
        $this->artisan('devices:mark-offline')->assertSuccessful();

        $session = ScreenSession::first();
        $this->assertSame('ended', $session->status);
        $this->assertSame('device_offline', $session->end_reason);
    }

    public function test_the_expiry_job_ends_sessions_the_device_never_answered(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);
        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->watchUrl($device), $this->offer())->assertCreated();

        $this->travel(31)->seconds();
        $this->artisan('screen-sessions:expire')->assertSuccessful();

        $session = ScreenSession::first();
        $this->assertSame('ended', $session->status);
        $this->assertSame('expired', $session->end_reason);

        // An already-active (answered) session is left alone by this job.
        $second = $this->makeDevice($school, $classroom);
        $id = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->watchUrl($second), $this->offer())->json('data.id');
        $this->withHeaders($this->deviceHeaders($second))->patchJson("/api/v1/device/screen-sessions/{$id}", $this->answer())->assertOk();
        $this->travel(31)->seconds();
        $this->artisan('screen-sessions:expire')->assertSuccessful();
        $this->assertSame('active', ScreenSession::where('computer_id', $second->id)->first()->status);
    }

    public function test_the_device_grid_shows_who_is_watching(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $viewer = $this->makeTeacher($school, $classroom);
        $viewer->name = 'Ms Rivera';
        $viewer->save();
        $another = $this->makeTeacher($school, $classroom);
        $this->withHeaders($this->teacherHeaders($viewer))->postJson($this->watchUrl($device), $this->offer())->assertCreated();

        $this->withHeaders($this->teacherHeaders($another))->getJson("/api/v1/teacher/classrooms/{$classroom->id}/devices")
            ->assertJsonPath('data.0.watched_by', 'Ms Rivera');
        $this->withHeaders($this->teacherHeaders($viewer))->getJson("/api/v1/teacher/classrooms/{$classroom->id}/devices")
            ->assertJsonPath('data.0.watched_by', null);
    }
}
