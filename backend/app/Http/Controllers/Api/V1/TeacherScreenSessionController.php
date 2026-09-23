<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Classroom;
use App\Models\Computer;
use App\Models\ScreenSession;
use App\Models\ScreenSessionCandidate;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeacherScreenSessionController extends ApiController
{
    /** A teacher starts watching a device: one active viewer per device at a time. */
    public function store(Request $request, Classroom $classroom, Computer $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canViewClassroom($classroom), 403);
        abort_unless($device->classroom_id === $classroom->id && $device->revoked_at === null, 404);

        $data = $request->validate([
            'offer' => ['required', 'array'],
            'offer.type' => ['required', 'in:offer'],
            'offer.sdp' => ['required', 'string', 'max:20000'],
        ]);

        if (! $device->isOnline()) {
            return $this->error('DEVICE_OFFLINE', 'The device is offline.', 409);
        }

        $session = DB::transaction(function () use ($device, $user, $classroom, $data) {
            // Serialize concurrent watch requests for this device.
            Computer::query()->whereKey($device->id)->lockForUpdate()->first();

            if ($device->currentScreenSession()) {
                return null;
            }

            return ScreenSession::create([
                'uuid' => (string) Str::uuid(),
                'school_id' => $device->school_id,
                'classroom_id' => $classroom->id,
                'computer_id' => $device->id,
                'viewer_id' => $user->id,
                'status' => 'pending',
                'offer_sdp' => $data['offer'],
                'started_at' => now(),
            ]);
        });

        if (! $session) {
            return $this->error('SCREEN_ALREADY_WATCHED', 'Someone else is already watching this device.', 409);
        }

        Audit::record('screen.started', $user, $classroom, $device, ['session_id' => $session->uuid]);

        return $this->success($session->toSummary(), 201);
    }

    /** Polled after creating (in case the device answers fast) and after each nudge. */
    public function show(Request $request, Classroom $classroom, Computer $device, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canViewClassroom($classroom), 403);

        $session = ScreenSession::query()
            ->where('uuid', $uuid)->where('classroom_id', $classroom->id)->where('computer_id', $device->id)
            ->firstOrFail();

        // The only sign the viewer is still there for an already-active session
        // (see ExpireScreenSessions, which ends ones that go quiet).
        $session->touch();

        $after = (int) $request->query('after', 0);
        $candidates = $session->candidates()->where('source', 'device')->where('id', '>', $after)->orderBy('id')->get();

        return $this->success([
            ...$session->toSummary(),
            'candidates' => $candidates->map(fn (ScreenSessionCandidate $candidate) => ['id' => $candidate->id, 'payload' => $candidate->payload]),
        ]);
    }

    public function addCandidates(Request $request, Classroom $classroom, Computer $device, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canViewClassroom($classroom), 403);

        $session = ScreenSession::query()->current()
            ->where('uuid', $uuid)->where('classroom_id', $classroom->id)->where('computer_id', $device->id)
            ->firstOrFail();

        $data = $request->validate(['candidates' => ['required', 'array', 'max:50'], 'candidates.*' => ['required', 'array']]);
        foreach ($data['candidates'] as $payload) {
            $session->candidates()->create(['source' => 'viewer', 'payload' => $payload]);
        }

        return $this->success(['received' => count($data['candidates'])]);
    }

    /** Switches an already-watched device between thumbnail and full-view quality. */
    public function setQuality(Request $request, Classroom $classroom, Computer $device, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canViewClassroom($classroom), 403);

        $session = ScreenSession::query()->current()
            ->where('uuid', $uuid)->where('classroom_id', $classroom->id)->where('computer_id', $device->id)
            ->firstOrFail();
        abort_unless($session->viewer_id === $user->id || $user->canControlClassroom($classroom), 403);

        $data = $request->validate(['quality' => ['required', 'in:thumb,full']]);
        $session->update(['quality' => $data['quality']]);
        Audit::record('screen.quality_changed', $user, $classroom, $device, ['session_id' => $session->uuid, 'quality' => $data['quality']]);

        return $this->success($session->toSummary());
    }

    public function end(Request $request, Classroom $classroom, Computer $device, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canViewClassroom($classroom), 403);

        $session = ScreenSession::query()->current()
            ->where('uuid', $uuid)->where('classroom_id', $classroom->id)->where('computer_id', $device->id)
            ->firstOrFail();
        abort_unless($session->viewer_id === $user->id || $user->canControlClassroom($classroom), 403);

        $session->update(['status' => 'ended', 'ended_at' => now(), 'end_reason' => 'ended']);
        Audit::record('screen.ended', $user, $classroom, $device, ['session_id' => $session->uuid]);

        return $this->success($session->toSummary());
    }
}
