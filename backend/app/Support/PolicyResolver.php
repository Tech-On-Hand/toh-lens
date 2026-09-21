<?php

namespace App\Support;

use App\Models\BlockRule;
use App\Models\Classroom;
use App\Models\Computer;
use App\Models\FocusSession;

/** Works out what a device must enforce right now. */
class PolicyResolver
{
    /** @return array<string, mixed> */
    public static function forClassroom(int $schoolId, ?int $classroomId): array
    {
        $block = BlockRule::query()
            ->where('school_id', $schoolId)
            ->where(fn ($query) => $query->whereNull('classroom_id')->when($classroomId, fn ($q) => $q->orWhere('classroom_id', $classroomId)))
            ->pluck('domain')
            ->unique()->sort()->values()->all();

        $focus = $classroomId
            ? FocusSession::query()->active()->where('classroom_id', $classroomId)->latest('id')->first()
            : null;

        return [
            'version' => sha1(json_encode([
                $block,
                $focus ? [$focus->uuid, collect($focus->allowed_domains)->sort()->values()->all(), $focus->expires_at->getTimestamp()] : null,
            ])),
            'block' => $block,
            'focus' => $focus?->toSummary(),
        ];
    }

    /** @return array<string, mixed> */
    public static function forDevice(Computer $device): array
    {
        return self::forClassroom($device->school_id, $device->classroom_id) + ['server_time' => now()->toIso8601String()];
    }

    /** Private device channels to nudge when a classroom's (or a whole school's) policy changes. */
    public static function deviceUuids(int $schoolId, ?int $classroomId): array
    {
        return Computer::query()
            ->where('school_id', $schoolId)
            ->whereNull('revoked_at')
            ->when($classroomId, fn ($query) => $query->where('classroom_id', $classroomId))
            ->whereNotNull('device_uuid')
            ->pluck('device_uuid')
            ->all();
    }
}
