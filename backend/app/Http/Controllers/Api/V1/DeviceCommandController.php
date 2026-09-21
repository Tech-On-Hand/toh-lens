<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\DeviceCommandUpdated;
use App\Models\Computer;
use App\Models\DeviceCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceCommandController extends ApiController
{
    /** At-most-once delivery: a command handed out is marked delivered and never re-sent. */
    public function index(Request $request): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();

        $device->commands()
            ->whereIn('status', ['pending', 'delivered'])
            ->where('expires_at', '<=', now())
            ->each(fn (DeviceCommand $command) => $this->finish($command, 'expired', ['error' => 'EXPIRED']));

        $activeSessionId = $device->activeSession()?->id;
        $deliver = [];

        $device->commands()->where('status', 'pending')->orderBy('id')->get()
            ->each(function (DeviceCommand $command) use ($activeSessionId, &$deliver) {
                if ($command->login_session_id !== $activeSessionId) {
                    $this->finish($command, 'expired', ['error' => 'SESSION_CHANGED']);

                    return;
                }

                $command->update(['status' => 'delivered', 'delivered_at' => now()]);
                $deliver[] = $command->toEnvelope();
            });

        return $this->success(['commands' => $deliver]);
    }

    public function result(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:completed,failed'],
            'result' => ['nullable', 'array'],
        ]);

        /** @var Computer $device */
        $device = $request->user();
        $command = $device->commands()->where('uuid', $uuid)->firstOrFail();

        if (! $command->isFinished()) {
            $this->finish($command, $data['status'], $data['result'] ?? null);
        }

        return $this->success(['id' => $command->uuid, 'status' => $command->status]);
    }

    private function finish(DeviceCommand $command, string $status, ?array $result): void
    {
        $command->update(['status' => $status, 'result' => $result, 'completed_at' => now()]);
        DeviceCommandUpdated::dispatch($command);
    }
}
