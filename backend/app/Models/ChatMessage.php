<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid', 'school_id', 'classroom_id', 'computer_id', 'login_session_id', 'direction',
    'sender_user_id', 'body', 'sent_at', 'delivered_at', 'read_at',
])]
class ChatMessage extends Model
{
    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /** @return array<string, mixed> */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'direction' => $this->direction,
            'sender_name' => $this->direction === 'to_student' ? $this->sender?->name : null,
            'body' => $this->body,
            'sent_at' => $this->sent_at->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
        ];
    }
}
