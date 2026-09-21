<?php

use App\Models\Classroom;
use App\Models\Computer;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('classroom.{classroomId}', function ($principal, int $classroomId): bool {
    $classroom = Classroom::find($classroomId);

    return $classroom && $principal instanceof User && $principal->canViewClassroom($classroom);
});

Broadcast::channel('device.{deviceUuid}', function ($principal, string $deviceUuid): bool {
    return $principal instanceof Computer
        && $principal->device_uuid === $deviceUuid
        && $principal->revoked_at === null;
});
