<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\DeviceCommandIssued;
use App\Models\Classroom;
use App\Models\Computer;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TeacherCommandController extends ApiController
{
    private const URL_RULE = ['required', 'string', 'max:2048', 'regex:/^https?:\/\/[^\s]+$/i'];

    public function store(Request $request, Classroom $classroom, Computer $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canControlClassroom($classroom), 403);
        abort_unless($device->classroom_id === $classroom->id && $device->revoked_at === null, 404);

        $type = $request->input('type');
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', DeviceCommand::TYPES)],
            'student_session_id' => ['required', 'uuid'],
            'ttl_seconds' => ['nullable', 'integer', 'between:10,300'],
            'payload' => ['required', 'array'],
            'payload.url' => in_array($type, ['browser.open_url', 'browser.navigate'], true) ? self::URL_RULE : ['prohibited'],
            'payload.tab_id' => in_array($type, ['browser.navigate', 'browser.close_tab'], true) ? ['required', 'integer', 'min:0'] : ['prohibited'],
        ]);

        if (! $device->isOnline()) {
            return $this->error('DEVICE_OFFLINE', 'The device is offline.', 409);
        }

        $session = $device->activeSession();
        if (! $session || $session->uuid !== $data['student_session_id']) {
            return $this->error('SESSION_MISMATCH', 'That student is no longer signed in on this device.', 409);
        }

        $command = DeviceCommand::create([
            'uuid' => (string) Str::uuid(),
            'school_id' => $device->school_id,
            'classroom_id' => $classroom->id,
            'computer_id' => $device->id,
            'login_session_id' => $session->id,
            'issued_by' => $user->id,
            'type' => $data['type'],
            'payload' => $data['payload'],
            'status' => 'pending',
            'expires_at' => now()->addSeconds($data['ttl_seconds'] ?? 60),
        ]);

        DeviceCommandIssued::dispatch($command);

        return $this->success($this->summary($command), 201);
    }

    public function show(Request $request, Classroom $classroom, Computer $device, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canViewClassroom($classroom), 403);

        $command = DeviceCommand::query()
            ->where('uuid', $uuid)->where('classroom_id', $classroom->id)->where('computer_id', $device->id)
            ->firstOrFail();

        return $this->success($this->summary($command));
    }

    private function summary(DeviceCommand $command): array
    {
        return [
            'id' => $command->uuid,
            'type' => $command->type,
            'status' => $command->status,
            'result' => $command->result,
            'expires_at' => $command->expires_at->toIso8601String(),
        ];
    }
}
