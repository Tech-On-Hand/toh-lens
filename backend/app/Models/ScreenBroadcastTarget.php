<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid', 'screen_broadcast_id', 'target_computer_id', 'status',
    'offer_sdp', 'answer_sdp', 'started_at', 'ended_at', 'end_reason',
])]
class ScreenBroadcastTarget extends Model
{
    protected function casts(): array
    {
        return [
            'offer_sdp' => 'array',
            'answer_sdp' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'active']);
    }

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(ScreenBroadcast::class, 'screen_broadcast_id');
    }

    public function targetComputer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'target_computer_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ScreenBroadcastTargetCandidate::class, 'target_id');
    }

    /** @return array<string, mixed> */
    public function toSummary(): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'offer' => $this->offer_sdp,
            'answer' => $this->answer_sdp,
        ];
    }
}
