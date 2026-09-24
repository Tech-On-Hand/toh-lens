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
    'operating_system', 'agent_version', 'health', 'enrolled_at', 'last_seen_at', 'presence_status', 'revoked_at',
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
            'health' => 'array',
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

    public function screenSessions(): HasMany
    {
        return $this->hasMany(ScreenSession::class);
    }

    public function currentScreenSession(): ?ScreenSession
    {
        return $this->screenSessions()->current()->latest('id')->first();
    }

    public function endCurrentScreenSession(string $reason): void
    {
        $this->currentScreenSession()?->update(['status' => 'ended', 'ended_at' => now(), 'end_reason' => $reason]);
    }

    public function helpRequests(): HasMany
    {
        return $this->hasMany(HelpRequest::class);
    }

    /** Broadcasts this device is the source of (its screen shown to the classroom). */
    public function sourceBroadcasts(): HasMany
    {
        return $this->hasMany(ScreenBroadcast::class, 'source_computer_id');
    }

    public function currentSourceBroadcast(): ?ScreenBroadcast
    {
        return $this->sourceBroadcasts()->current()->latest('id')->first();
    }

    /** Ends the broadcast this device is the source of, and every current target of it. */
    public function endCurrentSourceBroadcast(string $reason): void
    {
        $broadcast = $this->currentSourceBroadcast();
        if (! $broadcast) {
            return;
        }
        $broadcast->targets()->current()->update(['status' => 'ended', 'ended_at' => now(), 'end_reason' => $reason]);
        $broadcast->update(['status' => 'ended', 'ended_at' => now(), 'end_reason' => $reason]);
    }

    /** Broadcasts this device is receiving, as a target. */
    public function broadcastTargets(): HasMany
    {
        return $this->hasMany(ScreenBroadcastTarget::class, 'target_computer_id');
    }

    public function currentBroadcastTarget(): ?ScreenBroadcastTarget
    {
        return $this->broadcastTargets()->current()->latest('id')->first();
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
