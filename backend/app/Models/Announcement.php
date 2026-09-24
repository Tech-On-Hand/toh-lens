<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['uuid', 'school_id', 'classroom_id', 'sent_by', 'message', 'sent_at', 'expires_at'])]
class Announcement extends Model
{
    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function scopeUnexpired(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(AnnouncementReceipt::class);
    }

    /** @return array<string, mixed> */
    public function toSummary(): array
    {
        return [
            'id' => $this->uuid,
            'message' => $this->message,
            'sent_by' => $this->sender?->name,
            'sent_at' => $this->sent_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
