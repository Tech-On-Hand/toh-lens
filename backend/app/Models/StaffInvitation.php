<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'school_id', 'invited_by', 'email', 'role', 'token_hash', 'expires_at', 'accepted_at'])]
class StaffInvitation extends Model
{
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
