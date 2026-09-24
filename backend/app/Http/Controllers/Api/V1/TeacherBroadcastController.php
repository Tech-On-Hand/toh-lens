<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Classroom;
use App\Models\Computer;
use App\Models\ScreenBroadcast;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeacherBroadcastController extends ApiController
{
    /** Shows one device's screen live on every other online kiosk in the classroom. */
    public function store(Request $request, Classroom $classroom, Computer $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canControlClassroom($classroom), 403);
        abort_unless($device->classroom_id === $classroom->id && $device->revoked_at === null, 404);

        if (! $device->isOnline()) {
            return $this->error('DEVICE_OFFLINE', 'The device is offline.', 409);
        }

        $broadcast = DB::transaction(function () use ($device, $user, $classroom) {
            // Serialize concurrent start requests for this classroom.
            Classroom::query()->whereKey($classroom->id)->lockForUpdate()->first();

            if (ScreenBroadcast::query()->current()->where('classroom_id', $classroom->id)->exists()) {
                return null;
            }

            return ScreenBroadcast::create([
                'uuid' => (string) Str::uuid(),
                'school_id' => $device->school_id,
                'classroom_id' => $classroom->id,
                'source_computer_id' => $device->id,
                'started_by' => $user->id,
                'status' => 'active',
                'started_at' => now(),
            ]);
        });

        if (! $broadcast) {
            return $this->error('CLASSROOM_ALREADY_BROADCASTING', 'This classroom already has an active broadcast.', 409);
        }

        Audit::record('screen.broadcast_started', $user, $classroom, $device, ['broadcast_id' => $broadcast->uuid]);

        return $this->success($broadcast->toSummary(), 201);
    }

    public function end(Request $request, Classroom $classroom, Computer $device, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canControlClassroom($classroom), 403);

        $broadcast = ScreenBroadcast::query()->current()
            ->where('uuid', $uuid)->where('classroom_id', $classroom->id)->where('source_computer_id', $device->id)
            ->firstOrFail();

        $broadcast->targets()->current()->update(['status' => 'ended', 'ended_at' => now(), 'end_reason' => 'ended']);
        $broadcast->update(['status' => 'ended', 'ended_at' => now(), 'end_reason' => 'ended']);
        Audit::record('screen.broadcast_ended', $user, $classroom, $device, ['broadcast_id' => $broadcast->uuid]);

        return $this->success($broadcast->toSummary());
    }
}
