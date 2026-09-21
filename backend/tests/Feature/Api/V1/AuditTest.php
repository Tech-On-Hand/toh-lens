<?php

namespace Tests\Feature\Api\V1;

use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClassroomFixtures;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use BuildsClassroomFixtures, RefreshDatabase;

    private function schoolAdmin(School $school): User
    {
        $admin = User::factory()->create(['name' => 'Ada Admin']);
        $admin->schools()->attach($school, ['role' => 'administrator']);

        return $admin;
    }

    public function test_teacher_actions_are_audited_and_visible_to_the_school_administrator(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);
        $teacher = $this->makeTeacher($school, $classroom);
        $admin = $this->schoolAdmin($school);

        $this->withHeaders($this->teacherHeaders($teacher))->postJson("/api/v1/teacher/classrooms/{$classroom->id}/devices/{$device->id}/commands", [
            'type' => 'browser.open_url', 'student_session_id' => $session->uuid, 'payload' => ['url' => 'https://example.com/task'],
        ])->assertCreated();
        $this->withHeaders($this->teacherHeaders($teacher))->postJson("/api/v1/teacher/classrooms/{$classroom->id}/focus-sessions", ['allowed_domains' => ['khan.example'], 'duration_minutes' => 5])->assertCreated();
        $this->withHeaders($this->teacherHeaders($admin))->postJson("/api/v1/admin/devices/{$device->id}/revoke")->assertOk();

        $response = $this->withHeaders($this->teacherHeaders($admin))->getJson("/api/v1/admin/audit?school_id={$school->id}");

        $response->assertOk();
        $this->assertSame(['device.revoked', 'focus.started', 'browser.command_issued'], $response->json('data.*.action'));
        $command = $response->json('data.2');
        $this->assertSame($teacher->name, $command['actor']['name']);
        $this->assertSame('https://example.com/task', $command['metadata']['payload']['url']);
        $this->assertSame($session->uuid, $command['metadata']['student_session_id']);
        $this->assertSame($device->id, $command['device_id']);
    }

    public function test_only_administrators_of_that_school_can_read_the_audit_log(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $otherSchool = School::create(['name' => 'School B']);
        $foreignAdmin = $this->schoolAdmin($otherSchool);

        $this->withHeaders($this->teacherHeaders($teacher))->getJson("/api/v1/admin/audit?school_id={$school->id}")->assertForbidden();
        $this->withHeaders($this->teacherHeaders($foreignAdmin))->getJson("/api/v1/admin/audit?school_id={$school->id}")->assertForbidden();
    }

    public function test_the_log_can_be_filtered_and_paged(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $other = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 2']);
        $teacher = $this->makeTeacher($school, $classroom);
        $otherTeacher = $this->makeTeacher($school, $other);
        $admin = $this->schoolAdmin($school);

        foreach (['a.example', 'b.example', 'c.example'] as $domain) {
            $this->withHeaders($this->teacherHeaders($teacher))->postJson("/api/v1/teacher/classrooms/{$classroom->id}/block-rules", ['domain' => $domain]);
        }
        $this->withHeaders($this->teacherHeaders($otherTeacher))->postJson("/api/v1/teacher/classrooms/{$other->id}/block-rules", ['domain' => 'z.example']);
        $headers = $this->teacherHeaders($admin);

        $inClassroom = $this->withHeaders($headers)->getJson("/api/v1/admin/audit?school_id={$school->id}&classroom_id={$classroom->id}");
        $this->assertCount(3, $inClassroom->json('data'));

        $page = $this->withHeaders($headers)->getJson("/api/v1/admin/audit?school_id={$school->id}&limit=2");
        $this->assertCount(2, $page->json('data'));
        $older = $this->withHeaders($headers)->getJson("/api/v1/admin/audit?school_id={$school->id}&limit=2&before_id={$page->json('data.1.id')}");
        $this->assertCount(2, $older->json('data'));
        $this->assertLessThan($page->json('data.1.id'), $older->json('data.0.id'));

        $this->withHeaders($headers)->getJson("/api/v1/admin/audit?school_id={$school->id}&action=block_rule.added")->assertJsonCount(4, 'data');
        $this->withHeaders($headers)->getJson("/api/v1/admin/audit?school_id={$school->id}&action=focus.started")->assertJsonCount(0, 'data');
    }

    public function test_audit_entries_cannot_be_changed_through_the_model(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $entry = \App\Support\Audit::record('test.action', null, $classroom);

        $this->assertNull($entry->updated_at);
        $this->assertNotNull($entry->created_at);
        $this->assertSame($school->id, $entry->school_id);
    }
}
