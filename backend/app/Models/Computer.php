<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'device_uuid', 'school_id', 'classroom_id', 'class_id', 'name', 'role', 'hostname',
    'operating_system', 'agent_version', 'enrolled_at', 'last_seen_at', 'presence_status', 'revoked_at',
    'configuration_version',
])]
class Computer extends Model
{
    use HasApiTokens, HasFactory;

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function loginSessions(): HasMany
    {
        return $this->hasMany(LoginSession::class);
    }

    public function browserTabs(): HasMany
    {
        return $this->hasMany(BrowserTab::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function activeSession(): ?LoginSession
    {
        return $this->loginSessions()->where('status', 'active')->latest('login_time')->first();
    }

    public function isOnline(): bool
    {
        return $this->revoked_at === null
            && $this->presence_status === 'online'
            && $this->last_seen_at?->isAfter(now()->subSeconds(90));
    }
}
