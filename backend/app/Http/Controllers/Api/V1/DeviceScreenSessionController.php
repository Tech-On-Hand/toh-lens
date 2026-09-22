<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ScreenSessionUpdated;
use App\Models\Computer;
use App\Models\ScreenSession;
use App\Models\ScreenSessionCandidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceScreenSessionController extends ApiController
{
    /** Polled every tick; `null` (no current session) means "stop capturing." */
    public function current(Request $request): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $session = $device->currentScreenSession();

        if (! $session) {
            return $this->success(['session' => null]);
        }

        $after = (int) $request->query('after', 0);
        $candidates = $session->candidates()->where('source', 'viewer')->where('id', '>', $after)->orderBy('id')->get();

        return $this->success([
            'session' => [
                ...$session->toSummary(),
                'candidates' => $candidates->map(fn (ScreenSessionCandidate $candidate) => ['id' => $candidate->id, 'payload' => $candidate->payload]),
            ],
        ]);
    }

    /** Idempotent: answering an already-active session just returns its current state. */
    public function answer(Request $request, string $uuid): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $session = $device->screenSessions()->current()->where('uuid', $uuid)->firstOrFail();

        if ($session->status === 'pending') {
            $data = $request->validate([
                'answer' => ['required', 'array'],
                'answer.type' => ['required', 'in:answer'],
                'answer.sdp' => ['required', 'string', 'max:20000'],
            ]);
            $session->update(['answer_sdp' => $data['answer'], 'status' => 'active']);
            ScreenSessionUpdated::dispatch($session);
        }

        return $this->success($session->toSummary());
    }

    public function addCandidates(Request $request, string $uuid): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $session = $device->screenSessions()->current()->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate(['candidates' => ['required', 'array', 'max:50'], 'candidates.*' => ['required', 'array']]);
        foreach ($data['candidates'] as $payload) {
            $session->candidates()->create(['source' => 'device', 'payload' => $payload]);
        }
        ScreenSessionUpdated::dispatch($session);

        return $this->success(['received' => count($data['candidates'])]);
    }
}
