<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['computer_id', 'day'])]
class DeviceActivityDay extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['day' => 'date'];
    }
}
