<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Computer;
use App\Models\User;

class Audit
{
    /** @param array<string, mixed> $metadata */
    public static function record(
        string $action,
        ?User $actor = null,
        ?Classroom $classroom = null,
        ?Computer $device = null,
        array $metadata = [],
        ?int $schoolId = null,
    ): AuditLog {
        return AuditLog::create([
            'school_id' => $schoolId ?? $classroom?->school_id ?? $device?->school_id,
            'classroom_id' => $classroom?->id ?? $device?->classroom_id,
            'computer_id' => $device?->id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'metadata' => $metadata ?: null,
        ]);
    }
}
