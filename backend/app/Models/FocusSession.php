<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid', 'school_id', 'classroom_id', 'started_by', 'name', 'allowed_domains',
    'started_at', 'expires_at', 'ended_at', 'ended_by', 'end_reason',
])]
class FocusSession extends Model
{
    protected function casts(): array
    {
        return [
            'allowed_domains' => 'array',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** Correct without the expiry job: a session past its deadline is never active. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->where('expires_at', '>', now());
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** @return array<string, mixed> */
    public function toSummary(): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'allowed_domains' => $this->allowed_domains,
            'started_at' => $this->started_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
