<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid', 'school_id', 'classroom_id', 'computer_id', 'login_session_id', 'issued_by', 'type',
    'payload', 'status', 'result', 'expires_at', 'delivered_at', 'completed_at',
])]
class DeviceCommand extends Model
{
    public const TYPES = ['browser.open_url', 'browser.navigate', 'browser.close_tab'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    public function loginSession(): BelongsTo
    {
        return $this->belongsTo(LoginSession::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'expired'], true);
    }

    /** @return array<string, mixed> */
    public function toEnvelope(): array
    {
        $this->loadMissing('loginSession:id,uuid');

        return [
            'version' => 1,
            'id' => $this->uuid,
            'type' => $this->type,
            'organization_id' => School::query()->whereKey($this->school_id)->value('organization_id'),
            'classroom_id' => $this->classroom_id,
            'device_id' => $this->computer_id,
            'student_session_id' => $this->loginSession?->uuid,
            'issued_by' => $this->issued_by,
            'issued_at' => $this->created_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'payload' => $this->payload,
        ];
    }
}
