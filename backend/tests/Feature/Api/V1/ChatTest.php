<?php

namespace Tests\Feature\Api\V1;

use App\Models\ChatMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClassroomFixtures;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use BuildsClassroomFixtures, RefreshDatabase;

    private function teacherUrl($device): string
    {
        return "/api/v1/teacher/classrooms/{$device->classroom_id}/devices/{$device->id}/messages";
    }

    private function say(string $body = 'Hello'): array
    {
        return ['uuid' => (string) Str::uuid(), 'body' => $body];
    }

    public function test_a_teacher_messages_the_student_signed_in_on_a_device(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);

        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->teacherUrl($device), $this->say('How is it going?'))
            ->assertCreated()->assertJsonPath('data.direction', 'to_student')->assertJsonPath('data.body', 'How is it going?');

        $this->assertSame($session->id, ChatMessage::first()->login_session_id);
    }

    public function test_messaging_a_device_with_nobody_signed_in_is_refused(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $device = $this->makeDevice($school, $classroom);

        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->teacherUrl($device), $this->say())
            ->assertStatus(409)->assertJsonPath('error.code', 'NO_ACTIVE_STUDENT');
    }

    public function test_an_observer_can_read_but_not_send(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $observer = $this->makeTeacher($school, $classroom, 'observer');
        $device = $this->makeDevice($school, $classroom);
        $this->signInStudent($device);

        $this->withHeaders($this->teacherHeaders($observer))->postJson($this->teacherUrl($device), $this->say())->assertForbidden();
        $this->withHeaders($this->teacherHeaders($observer))->getJson($this->teacherUrl($device))->assertOk();
    }

    public function test_the_student_receives_it_which_marks_it_delivered_then_reads_it(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $device = $this->makeDevice($school, $classroom);
        $this->signInStudent($device);
        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->teacherUrl($device), $this->say('Look at question 2'));

        $fetched = $this->withHeaders($this->deviceHeaders($device))->getJson('/api/v1/device/messages');
        $fetched->assertOk()->assertJsonCount(1, 'data.messages')->assertJsonPath('data.unread', 1);
        $this->assertNotNull($fetched->json('data.messages.0.delivered_at'));
        $this->assertNull($fetched->json('data.messages.0.read_at'));

        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/messages/read')->assertOk();
        $this->withHeaders($this->deviceHeaders($device))->getJson('/api/v1/device/messages')->assertJsonPath('data.unread', 0);

        $thread = $this->withHeaders($this->teacherHeaders($teacher))->getJson($this->teacherUrl($device));
        $this->assertNotNull($thread->json('data.messages.0.read_at'));
    }

    public function test_a_student_reply_shows_as_unread_on_the_grid_until_the_teacher_opens_the_thread(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $device = $this->makeDevice($school, $classroom);
        $this->signInStudent($device);

        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/messages', $this->say('I am stuck'))
            ->assertCreated()->assertJsonPath('data.direction', 'to_teacher');

        $grid = "/api/v1/teacher/classrooms/{$classroom->id}/devices";
        $this->withHeaders($this->teacherHeaders($teacher))->getJson($grid)->assertJsonPath('data.0.unread_messages', 1);

        $thread = $this->withHeaders($this->teacherHeaders($teacher))->getJson($this->teacherUrl($device));
        $thread->assertJsonPath('data.messages.0.body', 'I am stuck')->assertJsonPath('data.session.student_name', 'Student 1001');

        $this->withHeaders($this->teacherHeaders($teacher))->getJson($grid)->assertJsonPath('data.0.unread_messages', 0);
    }

    public function test_the_next_student_on_a_computer_does_not_see_the_previous_conversation(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $device = $this->makeDevice($school, $classroom);
        $first = $this->signInStudent($device, '1001');
        $this->withHeaders($this->teacherHeaders($teacher))->postJson($this->teacherUrl($device), $this->say('Private note for the first student'));

        $first->update(['status' => 'ended', 'logout_time' => now()]);
        $this->signInStudent($device, '1002');

        $this->withHeaders($this->deviceHeaders($device))->getJson('/api/v1/device/messages')->assertJsonCount(0, 'data.messages');
        $this->withHeaders($this->teacherHeaders($teacher))->getJson($this->teacherUrl($device))->assertJsonCount(0, 'data.messages');
    }

    public function test_resending_a_message_id_does_not_duplicate_and_other_devices_cannot_reuse_it(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $other = $this->makeDevice($school, $classroom);
        $this->signInStudent($device);
        $this->signInStudent($other, '1002');
        $message = $this->say();

        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/messages', $message)->assertCreated();
        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/messages', $message)->assertOk();
        $this->withHeaders($this->deviceHeaders($other))->postJson('/api/v1/device/messages', $message)
            ->assertStatus(409)->assertJsonPath('error.code', 'MESSAGE_ID_TAKEN');
        $this->assertSame(1, ChatMessage::count());
    }

    public function test_a_delayed_message_is_attributed_to_the_session_it_was_written_in(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $device = $this->makeDevice($school, $classroom);
        $first = $this->signInStudent($device, '1001');
        $first->update(['status' => 'ended', 'logout_time' => now()]);
        $second = $this->signInStudent($device, '1002');

        // Written by the first student offline, delivered after the second signed in.
        $this->withHeaders($this->deviceHeaders($device))
            ->postJson('/api/v1/device/messages', [...$this->say('From the first student'), 'session_uuid' => $first->uuid])
            ->assertCreated();

        $this->assertSame($first->id, ChatMessage::first()->login_session_id);
        $this->withHeaders($this->teacherHeaders($teacher))->getJson($this->teacherUrl($device))
            ->assertJsonPath('data.session.uuid', $second->uuid)->assertJsonCount(0, 'data.messages');
    }

    public function test_a_message_for_a_session_the_server_has_not_seen_yet_is_retryable(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $this->signInStudent($device);

        $this->withHeaders($this->deviceHeaders($device))
            ->postJson('/api/v1/device/messages', [...$this->say(), 'session_uuid' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('error.code', 'SESSION_UNKNOWN');
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_a_student_cannot_send_without_signing_in(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);

        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/messages', $this->say())
            ->assertStatus(409)->assertJsonPath('error.code', 'NO_ACTIVE_STUDENT');
    }

    public function test_a_teacher_cannot_read_another_classrooms_thread(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        [$otherSchool, $otherClassroom] = $this->makeClassroom('School B');
        $outsider = $this->makeTeacher($otherSchool, $otherClassroom);
        $device = $this->makeDevice($school, $classroom);
        $this->signInStudent($device);

        $this->withHeaders($this->teacherHeaders($outsider))->getJson($this->teacherUrl($device))->assertForbidden();
    }
}
