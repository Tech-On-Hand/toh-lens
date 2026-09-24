<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid', 'school_id', 'computer_id', 'classroom_id', 'login_session_id', 'student_id',
    'process', 'is_idle', 'started_at', 'ended_at',
])]
class AppActivity extends Model
{
    protected function casts(): array
    {
        return ['is_idle' => 'boolean', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function loginSession(): BelongsTo
    {
        return $this->belongsTo(LoginSession::class);
    }
}
