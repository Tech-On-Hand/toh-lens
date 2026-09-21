<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'computer_id', 'classroom_id', 'login_session_id', 'browser', 'browser_tab_id',
    'window_id', 'url', 'title', 'is_active', 'observed_at',
])]
class BrowserTab extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'observed_at' => 'datetime'];
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    public function loginSession(): BelongsTo
    {
        return $this->belongsTo(LoginSession::class);
    }
}
