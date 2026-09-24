<?php

namespace Tests\Feature\Api\V1;

use App\Models\Computer;
use App\Models\ScreenBroadcast;
use App\Models\ScreenBroadcastTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClassroomFixtures;
use Tests\TestCase;

class ScreenBroadcastTest extends TestCase
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

    private function broadcastUrl(Computer $device, string $suffix = ''): string
    {
        return "/api/v1/teacher/classrooms/{$device->classroom_id}/devices/{$device->id}/broadcast{$suffix}";
    }

    public function test_a_controlling_teacher_starts_broadcasting_an_online_device(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);

        $response = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->broadcastUrl($device));

        $response->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.source_device_id', $device->id);
        $this->assertSame($device->id, ScreenBroadcast::first()->source_computer_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'screen.broadcast_started', 'actor_id' => $teacher->id]);
    }

    public function test_broadcasting_an_offline_device_is_refused(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom, ['presence_status' => 'offline']);
        $teacher = $this->makeTeacher($school, $classroom);

        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->broadcastUrl($device))
            ->assertStatus(409)->assertJsonPath('error.code', 'DEVICE_OFFLINE');
    }

    public function test_only_one_broadcast_per_classroom_at_a_time(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $first = $this->makeDevice($school, $classroom);
        $second = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);

        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->broadcastUrl($first))->assertCreated();
        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->broadcastUrl($second))
            ->assertStatus(409)->assertJsonPath('error.code', 'CLASSROOM_ALREADY_BROADCASTING');
        $this->assertSame(1, ScreenBroadcast::count());
    }

    public function test_an_observer_cannot_start_or_end_a_broadcast(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $controller = $this->makeTeacher($school, $classroom);
        $observer = $this->makeTeacher($school, $classroom, 'observer');

        $this->withHeaders($this->teacherHeaders($observer))->postJson($this->broadcastUrl($device))->assertForbidden();

        $id = $this->withHeaders($this->teacherHeaders($controller))->postJson($this->broadcastUrl($device))->json('data.id');
        $this->withHeaders($this->teacherHeaders($observer))->postJson($this->broadcastUrl($device, "/{$id}/end"))->assertForbidden();
    }

    public function test_a_receiving_kiosk_joins_and_the_source_answers(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $source = $this->makeDevice($school, $classroom, ['name' => 'Teacher PC']);
        $viewer = $this->makeDevice($school, $classroom, ['name' => 'Student PC']);
        $teacher = $this->makeTeacher($school, $classroom);
        $broadcastId = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->broadcastUrl($source))->json('data.id');

        // The receiving kiosk sees the broadcast before joining.
        $before = $this->withHeaders($this->deviceHeaders($viewer))->getJson('/api/v1/device/broadcasts/current');
        $before->assertOk()->assertJsonPath('data.broadcast.id', $broadcastId)->assertJsonPath('data.broadcast.source_device_name', 'Teacher PC')
            ->assertJsonPath('data.target', null);

        // It joins with an offer.
        $joined = $this->withHeaders($this->deviceHeaders($viewer))->postJson("/api/v1/device/broadcasts/{$broadcastId}/join", $this->offer());
        $joined->assertCreated()->assertJsonPath('data.status', 'pending');
        $targetId = $joined->json('data.id');

        // The source sees the pending target and its offer.
        $outgoing = $this->withHeaders($this->deviceHeaders($source))->getJson('/api/v1/device/broadcasts/outgoing');
        $outgoing->assertOk()->assertJsonPath('data.broadcast.id', $broadcastId)
            ->assertJsonPath('data.targets.0.id', $targetId)->assertJsonPath('data.targets.0.offer.sdp', 'v=0...fake-offer');

        // The source answers.
        $this->withHeaders($this->deviceHeaders($source))->patchJson("/api/v1/device/broadcasts/targets/{$targetId}", $this->answer())
            ->assertOk()->assertJsonPath('data.status', 'active');

        // The receiving kiosk now sees the answer.
        $after = $this->withHeaders($this->deviceHeaders($viewer))->getJson('/api/v1/device/broadcasts/current');
        $after->assertOk()->assertJsonPath('data.target.status', 'active')->assertJsonPath('data.target.answer.sdp', 'v=0...fake-answer');
    }

    public function test_joining_twice_returns_the_same_target_instead_of_duplicating(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $source = $this->makeDevice($school, $classroom);
        $viewer = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);
        $broadcastId = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->broadcastUrl($source))->json('data.id');

        $first = $this->withHeaders($this->deviceHeaders($viewer))->postJson("/api/v1/device/broadcasts/{$broadcastId}/join", $this->offer())->json('data.id');
        $second = $this->withHeaders($this->deviceHeaders($viewer))->postJson("/api/v1/device/broadcasts/{$broadcastId}/join", $this->offer())->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, ScreenBroadcastTarget::count());
    }

    public function test_candidates_flow_both_ways(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $source = $this->makeDevice($school, $classroom);
        $viewer = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);
        $broadcastId = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->broadcastUrl($source))->json('data.id');
        $targetId = $this->withHeaders($this->deviceHeaders($viewer))->postJson("/api/v1/device/broadcasts/{$broadcastId}/join", $this->offer())->json('data.id');

        $this->withHeaders($this->deviceHeaders($viewer))->postJson("/api/v1/device/broadcasts/targets/{$targetId}/candidates", ['candidates' => [['candidate' => 'target-candidate']]])
            ->assertOk()->assertJsonPath('data.received', 1);
        $this->withHeaders($this->deviceHeaders($source))->postJson("/api/v1/device/broadcasts/targets/{$targetId}/candidates", ['candidates' => [['candidate' => 'source-candidate']]])
            ->assertOk()->assertJsonPath('data.received', 1);

        // A device that is neither the target nor the source is refused.
        $outsider = $this->makeDevice($school, $classroom);
        $this->withHeaders($this->deviceHeaders($outsider))->postJson("/api/v1/device/broadcasts/targets/{$targetId}/candidates", ['candidates' => [['candidate' => 'nope']]])
            ->assertForbidden();

        $outgoing = $this->withHeaders($this->deviceHeaders($source))->getJson('/api/v1/device/broadcasts/outgoing');
        $this->assertSame(['target-candidate'], collect($outgoing->json('data.targets.0.candidates'))->pluck('payload.candidate')->all());

        $current = $this->withHeaders($this->deviceHeaders($viewer))->getJson('/api/v1/device/broadcasts/current');
        $this->assertSame(['source-candidate'], collect($current->json('data.target.candidates'))->pluck('payload.candidate')->all());
    }

    public function test_ending_a_broadcast_ends_its_targets_too(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $source = $this->makeDevice($school, $classroom);
        $viewer = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);
        $broadcastId = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->broadcastUrl($source))->json('data.id');
        $targetId = $this->withHeaders($this->deviceHeaders($viewer))->postJson("/api/v1/device/broadcasts/{$broadcastId}/join", $this->offer())->json('data.id');
        $this->withHeaders($this->deviceHeaders($source))->patchJson("/api/v1/device/broadcasts/targets/{$targetId}", $this->answer())->assertOk();

        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->broadcastUrl($source, "/{$broadcastId}/end"))
            ->assertOk()->assertJsonPath('data.status', 'ended');

        $this->assertSame('ended', ScreenBroadcastTarget::first()->status);
        $this->assertSame('ended', ScreenBroadcastTarget::first()->end_reason);

        // A new broadcast can now start in the same classroom.
        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->broadcastUrl($source))->assertCreated();
    }

    public function test_the_expiry_job_ends_unanswered_and_quiet_targets(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $source = $this->makeDevice($school, $classroom);
        $unanswered = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);
        $broadcastId = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->broadcastUrl($source))->json('data.id');
        $this->withHeaders($this->deviceHeaders($unanswered))->postJson("/api/v1/device/broadcasts/{$broadcastId}/join", $this->offer())->assertCreated();

        $this->travel(31)->seconds();
        $this->artisan('screen-sessions:expire')->assertSuccessful();
        $unansweredTarget = ScreenBroadcastTarget::where('target_computer_id', $unanswered->id)->first();
        $this->assertSame('ended', $unansweredTarget->status);
        $this->assertSame('expired', $unansweredTarget->end_reason);

        // A second broadcast (a fresh classroom — only one broadcast per
        // classroom is allowed, and the first is still active), answered, whose
        // receiving kiosk keeps polling stays active...
        [$school2, $classroom2] = $this->makeClassroom('School B');
        $second = $this->makeDevice($school2, $classroom2);
        $polling = $this->makeDevice($school2, $classroom2);
        $teacher2 = $this->makeTeacher($school2, $classroom2);
        $secondBroadcastId = $this->withHeaders($this->teacherHeaders($teacher2))->postJson($this->broadcastUrl($second))->json('data.id');
        $pollingTargetId = $this->withHeaders($this->deviceHeaders($polling))->postJson("/api/v1/device/broadcasts/{$secondBroadcastId}/join", $this->offer())->json('data.id');
        $this->withHeaders($this->deviceHeaders($second))->patchJson("/api/v1/device/broadcasts/targets/{$pollingTargetId}", $this->answer())->assertOk();
        $this->travel(31)->seconds();
        $this->withHeaders($this->deviceHeaders($polling))->getJson('/api/v1/device/broadcasts/current')->assertOk();
        $this->artisan('screen-sessions:expire')->assertSuccessful();
        $this->assertSame('active', ScreenBroadcastTarget::where('target_computer_id', $polling->id)->first()->status);

        // ...but one whose receiving kiosk goes quiet after answering does not.
        [$school3, $classroom3] = $this->makeClassroom('School C');
        $third = $this->makeDevice($school3, $classroom3);
        $quiet = $this->makeDevice($school3, $classroom3);
        $teacher3 = $this->makeTeacher($school3, $classroom3);
        $thirdBroadcastId = $this->withHeaders($this->teacherHeaders($teacher3))->postJson($this->broadcastUrl($third))->json('data.id');
        $quietTargetId = $this->withHeaders($this->deviceHeaders($quiet))->postJson("/api/v1/device/broadcasts/{$thirdBroadcastId}/join", $this->offer())->json('data.id');
        $this->withHeaders($this->deviceHeaders($third))->patchJson("/api/v1/device/broadcasts/targets/{$quietTargetId}", $this->answer())->assertOk();
        $this->travel(31)->seconds();
        $this->artisan('screen-sessions:expire')->assertSuccessful();
        $quietTarget = ScreenBroadcastTarget::where('target_computer_id', $quiet->id)->first();
        $this->assertSame('ended', $quietTarget->status);
        $this->assertSame('target_lost', $quietTarget->end_reason);
    }

    public function test_revoking_the_source_device_ends_its_broadcast(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $source = $this->makeDevice($school, $classroom);
        $viewer = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);
        $broadcastId = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->broadcastUrl($source))->json('data.id');
        $targetId = $this->withHeaders($this->deviceHeaders($viewer))->postJson("/api/v1/device/broadcasts/{$broadcastId}/join", $this->offer())->json('data.id');

        $this->withHeaders($this->teacherHeaders($admin))->postJson("/api/v1/admin/devices/{$source->id}/revoke")->assertOk();

        $this->assertSame('ended', ScreenBroadcast::first()->status);
        $this->assertSame('source_revoked', ScreenBroadcast::first()->end_reason);
        $this->assertSame('source_revoked', ScreenBroadcastTarget::find($targetId)->end_reason ?? ScreenBroadcastTarget::first()->end_reason);
    }

    public function test_the_device_grid_shows_which_device_is_broadcasting(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);

        $before = $this->withHeaders($this->teacherHeaders($teacher))->getJson("/api/v1/teacher/classrooms/{$classroom->id}/devices");
        $before->assertJsonPath('data.0.broadcast_id', null);

        $broadcastId = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->broadcastUrl($device))->json('data.id');

        $after = $this->withHeaders($this->teacherHeaders($teacher))->getJson("/api/v1/teacher/classrooms/{$classroom->id}/devices");
        $after->assertJsonPath('data.0.broadcast_id', $broadcastId);
    }
}
