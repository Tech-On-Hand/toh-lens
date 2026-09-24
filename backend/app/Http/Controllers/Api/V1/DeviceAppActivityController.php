<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AppActivity;
use App\Models\Computer;
use App\Models\LoginSession;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceAppActivityController extends ApiController
{
    /**
     * Foreground-application intervals from the Student Agent. Idempotent on the
     * interval uuid, and an interval still open when it was first sent can be sent
     * again with a later end. The reply lists which uuids were taken; one whose
     * login session has not reached the server yet is left out so the agent retries
     * it after the session has synced.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'intervals' => ['required', 'array', 'max:200'],
            'intervals.*.uuid' => ['required', 'uuid'],
            'intervals.*.session_uuid' => ['required', 'uuid'],
            'intervals.*.process' => ['required', 'string', 'max:100'],
            'intervals.*.is_idle' => ['required', 'boolean'],
            'intervals.*.started_at' => ['required', 'date'],
            'intervals.*.ended_at' => ['required', 'date'],
        ]);

        /** @var Computer $device */
        $device = $request->user();
        $sessions = [];
        $accepted = [];

        foreach ($data['intervals'] as $interval) {
            $session = $sessions[$interval['session_uuid']] ??= LoginSession::query()
                ->where('uuid', $interval['session_uuid'])->where('computer_id', $device->id)->first();
            if (! $session) {
                continue;
            }

            $startedAt = Carbon::parse($interval['started_at']);
            $endedAt = Carbon::parse($interval['ended_at']);
            if ($endedAt->lt($startedAt)) {
                $accepted[] = $interval['uuid']; // nothing useful in it; do not have it re-sent
                continue;
            }

            $activity = AppActivity::query()->firstOrNew(['uuid' => $interval['uuid']]);
            if ($activity->exists && $activity->computer_id !== $device->id) {
                continue;
            }
            if (! $activity->exists) {
                $activity->fill([
                    'school_id' => $device->school_id,
                    'computer_id' => $device->id,
                    'classroom_id' => $session->classroom_id ?? $device->classroom_id,
                    'login_session_id' => $session->id,
                    'student_id' => $session->student_id,
                    'process' => $interval['process'],
                    'is_idle' => $interval['is_idle'],
                    'started_at' => $startedAt,
                    'ended_at' => $endedAt,
                ])->save();
            } elseif ($endedAt->gt($activity->ended_at)) {
                $activity->update(['ended_at' => $endedAt]);
            }
            $accepted[] = $interval['uuid'];
        }

        return $this->success(['accepted' => $accepted]);
    }
}
