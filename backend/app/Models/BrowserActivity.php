<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid', 'school_id', 'computer_id', 'classroom_id', 'login_session_id', 'student_id',
    'browser', 'event_type', 'url', 'domain', 'title', 'occurred_at',
])]
class BrowserActivity extends Model
{
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function loginSession(): BelongsTo
    {
        return $this->belongsTo(LoginSession::class);
    }
}
