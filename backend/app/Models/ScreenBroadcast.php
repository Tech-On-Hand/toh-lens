<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid', 'school_id', 'classroom_id', 'source_computer_id', 'started_by',
    'status', 'started_at', 'ended_at', 'end_reason',
])]
class ScreenBroadcast extends Model
{
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function sourceComputer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'source_computer_id');
    }

    public function startedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(ScreenBroadcastTarget::class);
    }

    /** @return array<string, mixed> */
    public function toSummary(): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'source_device_id' => $this->source_computer_id,
            'source_device_name' => $this->sourceComputer->name,
        ];
    }
}
