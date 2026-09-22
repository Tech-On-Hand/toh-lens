<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['screen_session_id', 'source', 'payload'])]
class ScreenSessionCandidate extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    public function screenSession(): BelongsTo
    {
        return $this->belongsTo(ScreenSession::class);
    }
}
