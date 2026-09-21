<?php

namespace Tests\Feature\Api\V1;

use App\Events\DevicePolicyChanged;
use App\Events\FocusSessionChanged;
use App\Models\AuditLog;
use App\Models\BlockRule;
use App\Models\Classroom;
use App\Models\FocusSession;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClassroomFixtures;
use Tests\TestCase;

class FocusAndBlockingTest extends TestCase
{
    use BuildsClassroomFixtures, RefreshDatabase;

    private function focusUrl(Classroom $classroom, string $suffix = ''): string
    {
        return "/api/v1/teacher/classrooms/{$classroom->id}/focus-sessions{$suffix}";
    }

    public function test_a_teacher_starts_a_focus_session_with_normalized_domains(): void
    {
        Event::fake();
        [$school, $classroom] = $this->makeClassroom();
        $this->makeDevice($school, $classroom);
        $teacher = $this->makeTeacher($school, $classroom);

        $response = $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->focusUrl($classroom), [
            'allowed_domains' => ['https://Khan.example/lesson', 'wikipedia.org', 'khan.example'],
            'duration_minutes' => 20,
            'name' => 'Fractions',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.allowed_domains', ['khan.example', 'wikipedia.org']);
        $session = FocusSession::first();
        $this->assertSame($teacher->id, $session->started_by);
        $this->assertEqualsWithDelta(20 * 60, $session->started_at->diffInSeconds($session->expires_at), 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'focus.started', 'actor_id' => $teacher->id, 'classroom_id' => $classroom->id]);
        Event::assertDispatched(FocusSessionChanged::class, fn ($e) => $e->state === 'started');
        Event::assertDispatched(DevicePolicyChanged::class, fn ($e) => count($e->deviceUuids) === 1);
    }

    public function test_only_one_focus_session_runs_at_a_time_until_it_expires(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $payload = ['allowed_domains' => ['khan.example'], 'duration_minutes' => 10];

        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->focusUrl($classroom), $payload)->assertCreated();
        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->focusUrl($classroom), $payload)
            ->assertStatus(409)->assertJsonPath('error.code', 'FOCUS_ALREADY_ACTIVE');

        $this->travel(11)->minutes();
        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->focusUrl($classroom), $payload)->assertCreated();
    }

    public function test_invalid_focus_requests_are_rejected(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $headers = $this->teacherHeaders($this->makeTeacher($school, $classroom));

        $this->withHeaders($headers)->postJson($this->focusUrl($classroom), ['allowed_domains' => ['ok.example', 'nope'], 'duration_minutes' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('allowed_domains.1');
        $this->withHeaders($headers)->postJson($this->focusUrl($classroom), ['allowed_domains' => [], 'duration_minutes' => 5])->assertStatus(422);
        $this->withHeaders($headers)->postJson($this->focusUrl($classroom), ['allowed_domains' => ['ok.example'], 'duration_minutes' => 0])->assertStatus(422);
        $this->withHeaders($headers)->postJson($this->focusUrl($classroom), ['allowed_domains' => ['ok.example'], 'duration_minutes' => 241])->assertStatus(422);
        $this->withHeaders($headers)->postJson($this->focusUrl($classroom), ['allowed_domains' => array_map(fn ($i) => "site{$i}.example", range(1, 101)), 'duration_minutes' => 5])->assertStatus(422);
        $this->assertSame(0, FocusSession::count());
    }

    public function test_observers_and_outsiders_cannot_start_focus(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $observer = $this->makeTeacher($school, $classroom, 'observer');
        $outsider = User::factory()->create();
        $outsider->schools()->attach($school, ['role' => 'teacher']);
        $payload = ['allowed_domains' => ['khan.example'], 'duration_minutes' => 10];

        $this->withHeaders($this->teacherHeaders($observer))->postJson($this->focusUrl($classroom), $payload)->assertForbidden();
        $this->withHeaders($this->teacherHeaders($outsider))->postJson($this->focusUrl($classroom), $payload)->assertForbidden();
        $this->assertSame(0, FocusSession::count());
    }

    public function test_ending_a_focus_session_is_audited_and_cannot_be_repeated(): void
    {
        Event::fake();
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $headers = $this->teacherHeaders($teacher);
        $id = $this->withHeaders($headers)->postJson($this->focusUrl($classroom), ['allowed_domains' => ['khan.example'], 'duration_minutes' => 30])->json('data.id');

        $this->withHeaders($headers)->postJson($this->focusUrl($classroom, "/{$id}/end"))->assertOk();
        $session = FocusSession::first();
        $this->assertSame('ended', $session->end_reason);
        $this->assertSame($teacher->id, $session->ended_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'focus.ended', 'actor_id' => $teacher->id]);

        $this->withHeaders($headers)->postJson($this->focusUrl($classroom, "/{$id}/end"))->assertStatus(409)->assertJsonPath('error.code', 'FOCUS_NOT_ACTIVE');
    }

    public function test_a_teacher_cannot_end_another_classrooms_session(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $other = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 2']);
        $teacherA = $this->makeTeacher($school, $classroom);
        $teacherB = $this->makeTeacher($school, $other);
        $id = $this->withHeaders($this->teacherHeaders($teacherB))->postJson($this->focusUrl($other), ['allowed_domains' => ['khan.example'], 'duration_minutes' => 30])->json('data.id');

        $this->withHeaders($this->teacherHeaders($teacherA))->postJson($this->focusUrl($classroom, "/{$id}/end"))->assertNotFound();
        $this->assertNull(FocusSession::first()->ended_at);
    }

    public function test_a_teacher_manages_classroom_block_rules(): void
    {
        Event::fake();
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $headers = $this->teacherHeaders($teacher);
        $url = "/api/v1/teacher/classrooms/{$classroom->id}/block-rules";

        $first = $this->withHeaders($headers)->postJson($url, ['domain' => 'https://www.YouTube.com/watch?v=abc']);
        $first->assertCreated()->assertJsonPath('data.domain', 'www.youtube.com')->assertJsonPath('data.scope', 'classroom');
        $this->withHeaders($headers)->postJson($url, ['domain' => 'www.youtube.com'])->assertOk();
        $this->assertSame(1, BlockRule::count());
        $this->withHeaders($headers)->postJson($url, ['domain' => 'localhost'])->assertStatus(422)->assertJsonValidationErrors('domain');

        $this->withHeaders($headers)->deleteJson("{$url}/{$first->json('data.id')}")->assertOk();
        $this->assertSame(0, BlockRule::count());
        $this->assertSame(['block_rule.added', 'block_rule.removed'], AuditLog::orderBy('id')->pluck('action')->all());
        Event::assertDispatched(DevicePolicyChanged::class);
    }

    public function test_school_wide_rules_are_admin_only_and_teachers_cannot_remove_them(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);

        $this->withHeaders($this->teacherHeaders($teacher))->postJson('/api/v1/admin/block-rules', ['school_id' => $school->id, 'domain' => 'games.example'])->assertForbidden();

        $created = $this->withHeaders($this->teacherHeaders($admin))->postJson('/api/v1/admin/block-rules', ['school_id' => $school->id, 'domain' => 'games.example']);
        $created->assertCreated()->assertJsonPath('data.scope', 'school');
        $ruleId = $created->json('data.id');

        $this->withHeaders($this->teacherHeaders($teacher))->deleteJson("/api/v1/teacher/classrooms/{$classroom->id}/block-rules/{$ruleId}")
            ->assertForbidden()->assertJsonPath('error.code', 'SCHOOL_RULE');
        $this->withHeaders($this->teacherHeaders($teacher))->deleteJson("/api/v1/admin/block-rules/{$ruleId}")->assertForbidden();

        $view = $this->withHeaders($this->teacherHeaders($teacher))->getJson("/api/v1/teacher/classrooms/{$classroom->id}/policy");
        $view->assertOk()->assertJsonPath('data.block_rules.0.domain', 'games.example')->assertJsonPath('data.block_rules.0.scope', 'school');

        $this->withHeaders($this->teacherHeaders($admin))->deleteJson("/api/v1/admin/block-rules/{$ruleId}")->assertOk();
        $this->assertSame(0, BlockRule::count());
    }

    public function test_admin_rules_cannot_target_a_classroom_from_another_school(): void
    {
        [$school] = $this->makeClassroom();
        $otherSchool = School::create(['name' => 'School B']);
        $foreign = Classroom::create(['school_id' => $otherSchool->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab']);
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);

        $this->withHeaders($this->teacherHeaders($admin))->postJson('/api/v1/admin/block-rules', [
            'school_id' => $school->id, 'classroom_id' => $foreign->id, 'domain' => 'games.example',
        ])->assertStatus(422);
    }

    public function test_a_viewer_sees_the_policy_with_the_active_focus_session(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $observer = $this->makeTeacher($school, $classroom, 'observer');
        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->focusUrl($classroom), ['allowed_domains' => ['khan.example'], 'duration_minutes' => 15, 'name' => 'Maths']);

        $this->withHeaders($this->teacherHeaders($observer))->getJson("/api/v1/teacher/classrooms/{$classroom->id}/policy")
            ->assertOk()
            ->assertJsonPath('data.focus.name', 'Maths')
            ->assertJsonPath('data.focus.allowed_domains', ['khan.example']);
    }
}
