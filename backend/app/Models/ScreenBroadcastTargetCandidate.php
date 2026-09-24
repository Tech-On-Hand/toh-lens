<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['target_id', 'source', 'payload'])]
class ScreenBroadcastTargetCandidate extends Model
{
    // Table is screen_broadcast_candidates, not the Laravel-conventional
    // screen_broadcast_target_candidates — that name pushed the candidates
    // table's own FK constraint name past MySQL's 64-character limit.
    protected $table = 'screen_broadcast_candidates';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(ScreenBroadcastTarget::class, 'target_id');
    }
}
