<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid', 'school_id', 'classroom_id', 'computer_id', 'viewer_id', 'status', 'quality',
    'offer_sdp', 'answer_sdp', 'started_at', 'ended_at', 'end_reason',
])]
class ScreenSession extends Model
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

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewer_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ScreenSessionCandidate::class);
    }

    /** @return array<string, mixed> */
    public function toSummary(): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'quality' => $this->quality,
            'offer' => $this->offer_sdp,
            'answer' => $this->answer_sdp,
        ];
    }
}
