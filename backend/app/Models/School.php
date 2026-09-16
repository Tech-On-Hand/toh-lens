<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class School extends Model
{
    use HasFactory;

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function computers(): HasMany
    {
        return $this->hasMany(Computer::class);
    }

    public function loginSessions(): HasMany
    {
        return $this->hasMany(LoginSession::class);
    }
}
