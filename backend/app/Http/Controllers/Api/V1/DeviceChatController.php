<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ClassroomCommunicationChanged;
use App\Models\ChatMessage;
use App\Models\Computer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceChatController extends ApiController
{
    private const THREAD_LIMIT = 200;

    /**
     * The conversation for whoever is signed in on this device, from `after` on.
     * Fetching what a teacher sent is what marks it delivered. `unread` counts the
     * teacher's messages the student has not opened yet, for the badge on the
     * floating button.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $session = $device->activeSession();
        if (! $session) {
            return $this->success(['session_uuid' => null, 'unread' => 0, 'messages' => []]);
        }

        ChatMessage::query()
            ->where('login_session_id', $session->id)->where('direction', 'to_student')->whereNull('delivered_at')
            ->update(['delivered_at' => now()]);

        $after = (int) $request->query('after', 0);
        $messages = ChatMessage::query()
            ->with('sender:id,name')
            ->where('login_session_id', $session->id)->where('id', '>', $after)
            ->orderBy('id')->limit(self::THREAD_LIMIT)->get();

        return $this->success([
            'session_uuid' => $session->uuid,
            'unread' => ChatMessage::query()->where('login_session_id', $session->id)->where('direction', 'to_student')->whereNull('read_at')->count(),
            'messages' => $messages->map(fn (ChatMessage $message) => $message->toSummary()),
        ]);
    }

    /** The student sends a message to their teacher. Idempotent on the device-chosen uuid. */
    public function store(Request $request): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();

        // `session_uuid` is the login session the student wrote this in. A message
        // held back while offline is delivered later, possibly after that student has
        // gone and someone else has signed in, so it must not be attributed to
        // whoever happens to be signed in when it finally arrives.
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'body' => ['required', 'string', 'max:500'],
            'session_uuid' => ['nullable', 'uuid'],
        ]);

        $existing = ChatMessage::query()->where('uuid', $data['uuid'])->first();
        if ($existing) {
            return $existing->computer_id === $device->id
                ? $this->success($existing->toSummary())
                : $this->error('MESSAGE_ID_TAKEN', 'That message id was already used.', 409);
        }

        if ($device->classroom_id === null) {
            return $this->error('NO_ACTIVE_STUDENT', 'Sign in to send a message.', 409);
        }

        if (isset($data['session_uuid'])) {
            $session = $device->loginSessions()->where('uuid', $data['session_uuid'])->first();
            if (! $session) {
                // The kiosk syncs sessions separately and may not have got this one
                // across yet; the message is worth retrying once it has.
                return $this->error('SESSION_UNKNOWN', 'That login session has not reached the server yet.', 409);
            }
        } else {
            $session = $device->activeSession();
            if (! $session) {
                return $this->error('NO_ACTIVE_STUDENT', 'Sign in to send a message.', 409);
            }
        }

        $message = ChatMessage::create([
            'uuid' => $data['uuid'],
            'school_id' => $device->school_id,
            'classroom_id' => $device->classroom_id,
            'computer_id' => $device->id,
            'login_session_id' => $session->id,
            'direction' => 'to_teacher',
            'body' => trim($data['body']),
            'sent_at' => now(),
            'delivered_at' => now(),
        ]);

        ClassroomCommunicationChanged::dispatch($device->classroom_id, 'chat');

        return $this->success($message->toSummary(), 201);
    }

    /** The student opened the chat: everything the teacher sent so far counts as read. */
    public function markRead(Request $request): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $session = $device->activeSession();

        if ($session) {
            ChatMessage::query()
                ->where('login_session_id', $session->id)->where('direction', 'to_student')->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        return $this->success(['read' => true]);
    }
}
