<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ChatMessage;
use App\Models\Classroom;
use App\Models\Computer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherChatController extends ApiController
{
    private const THREAD_LIMIT = 200;

    /**
     * The conversation with whoever is signed in on this device right now. Opening
     * it is what marks the student's messages read, so this is only called while a
     * teacher actually has the thread on screen.
     */
    public function index(Request $request, Classroom $classroom, Computer $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canViewClassroom($classroom), 403);
        abort_unless($device->classroom_id === $classroom->id && $device->revoked_at === null, 404);

        $session = $device->activeSession();
        if (! $session) {
            return $this->success(['session' => null, 'messages' => []]);
        }

        ChatMessage::query()
            ->where('login_session_id', $session->id)->where('direction', 'to_teacher')->whereNull('read_at')
            ->update(['read_at' => now()]);

        $messages = ChatMessage::query()
            ->with('sender:id,name')
            ->where('login_session_id', $session->id)
            ->orderByDesc('id')->limit(self::THREAD_LIMIT)->get()->reverse()->values();

        return $this->success([
            'session' => ['uuid' => $session->uuid, 'student_name' => $session->student?->full_name],
            'messages' => $messages->map(fn (ChatMessage $message) => $message->toSummary()),
        ]);
    }

    public function store(Request $request, Classroom $classroom, Computer $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canControlClassroom($classroom), 403);
        abort_unless($device->classroom_id === $classroom->id && $device->revoked_at === null, 404);

        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'body' => ['required', 'string', 'max:500'],
        ]);

        $existing = ChatMessage::query()->where('uuid', $data['uuid'])->first();
        if ($existing) {
            return $existing->sender_user_id === $user->id && $existing->computer_id === $device->id
                ? $this->success($existing->load('sender:id,name')->toSummary())
                : $this->error('MESSAGE_ID_TAKEN', 'That message id was already used.', 409);
        }

        $session = $device->activeSession();
        if (! $session) {
            return $this->error('NO_ACTIVE_STUDENT', 'No student is signed in on this computer.', 409);
        }

        $message = ChatMessage::create([
            'uuid' => $data['uuid'],
            'school_id' => $device->school_id,
            'classroom_id' => $classroom->id,
            'computer_id' => $device->id,
            'login_session_id' => $session->id,
            'direction' => 'to_student',
            'sender_user_id' => $user->id,
            'body' => trim($data['body']),
            'sent_at' => now(),
        ]);

        return $this->success($message->load('sender:id,name')->toSummary(), 201);
    }
}
