<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Computer;
use App\Models\ScreenBroadcast;
use App\Models\ScreenBroadcastTarget;
use App\Models\ScreenBroadcastTargetCandidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceBroadcastController extends ApiController
{
    /**
     * The receiving-kiosk view: is my classroom currently broadcasting, and
     * (once I have joined) what is my own connection's state. Polled by the
     * kiosk's webview, which plays the offerer role here — the same role a
     * teacher plays watching a device, just another kiosk this time.
     */
    public function current(Request $request): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $broadcast = ScreenBroadcast::query()->current()->where('classroom_id', $device->classroom_id)->latest('id')->first();

        if (! $broadcast) {
            return $this->success(['broadcast' => null, 'target' => null]);
        }

        $target = $device->broadcastTargets()->current()->where('screen_broadcast_id', $broadcast->id)->first();
        if ($target) {
            // The only sign this receiving kiosk is still there for an
            // already-active target (see ExpireScreenSessions: target_lost).
            $target->touch();
        }

        $after = (int) $request->query('after', 0);
        $candidates = $target
            ? $target->candidates()->where('source', 'source')->where('id', '>', $after)->orderBy('id')->get()
            : collect();

        return $this->success([
            'broadcast' => ['id' => $broadcast->uuid, 'source_device_name' => $broadcast->sourceComputer->name],
            'target' => $target ? [
                ...$target->toSummary(),
                'candidates' => $candidates->map(fn (ScreenBroadcastTargetCandidate $c) => ['id' => $c->id, 'payload' => $c->payload]),
            ] : null,
        ]);
    }

    /** A receiving kiosk joins the classroom's current broadcast. */
    public function join(Request $request, string $uuid): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $broadcast = ScreenBroadcast::query()->current()->where('uuid', $uuid)->where('classroom_id', $device->classroom_id)->firstOrFail();

        $data = $request->validate([
            'offer' => ['required', 'array'],
            'offer.type' => ['required', 'in:offer'],
            'offer.sdp' => ['required', 'string', 'max:20000'],
        ]);

        $target = DB::transaction(function () use ($device, $broadcast, $data) {
            Computer::query()->whereKey($device->id)->lockForUpdate()->first();

            $existing = $device->broadcastTargets()->current()->where('screen_broadcast_id', $broadcast->id)->first();
            if ($existing) {
                return $existing;
            }

            return ScreenBroadcastTarget::create([
                'uuid' => (string) Str::uuid(),
                'screen_broadcast_id' => $broadcast->id,
                'target_computer_id' => $device->id,
                'status' => 'pending',
                'offer_sdp' => $data['offer'],
                'started_at' => now(),
            ]);
        });

        return $this->success($target->toSummary(), 201);
    }

    /**
     * The source kiosk's view: which receiving kiosks are waiting for an
     * answer or have sent new candidates. No `after` cursor — a classroom's
     * kiosk count and per-connection candidate count are both small enough
     * that resending everything each poll is simpler than per-target paging;
     * the source tracks what it has already applied locally.
     */
    public function outgoing(Request $request): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $broadcast = $device->currentSourceBroadcast();

        if (! $broadcast) {
            return $this->success(['broadcast' => null, 'targets' => []]);
        }

        $targets = $broadcast->targets()->current()->with(['candidates' => fn ($q) => $q->where('source', 'target')->orderBy('id')])->get();

        return $this->success([
            'broadcast' => ['id' => $broadcast->uuid],
            'targets' => $targets->map(fn (ScreenBroadcastTarget $target) => [
                ...$target->toSummary(),
                'candidates' => $target->candidates->map(fn (ScreenBroadcastTargetCandidate $c) => ['id' => $c->id, 'payload' => $c->payload]),
            ]),
        ]);
    }

    /** Idempotent: answering an already-active target just returns its current state. */
    public function answer(Request $request, string $uuid): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $target = ScreenBroadcastTarget::query()->current()->where('uuid', $uuid)->whereHas('broadcast', fn ($q) => $q->where('source_computer_id', $device->id))->firstOrFail();

        if ($target->status === 'pending') {
            $data = $request->validate([
                'answer' => ['required', 'array'],
                'answer.type' => ['required', 'in:answer'],
                'answer.sdp' => ['required', 'string', 'max:20000'],
            ]);
            $target->update(['answer_sdp' => $data['answer'], 'status' => 'active']);
        }

        return $this->success($target->toSummary());
    }

    /** Either side of a target connection may post candidates; the source is inferred from who is calling. */
    public function addCandidates(Request $request, string $uuid): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $target = ScreenBroadcastTarget::query()->current()->where('uuid', $uuid)->with('broadcast')->firstOrFail();

        $source = match ($device->id) {
            $target->target_computer_id => 'target',
            $target->broadcast->source_computer_id => 'source',
            default => null,
        };
        abort_if($source === null, 403);

        $data = $request->validate(['candidates' => ['required', 'array', 'max:50'], 'candidates.*' => ['required', 'array']]);
        foreach ($data['candidates'] as $payload) {
            $target->candidates()->create(['source' => $source, 'payload' => $payload]);
        }

        return $this->success(['received' => count($data['candidates'])]);
    }
}
