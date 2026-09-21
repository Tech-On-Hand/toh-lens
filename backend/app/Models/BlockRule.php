<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['school_id', 'classroom_id', 'domain', 'created_by'])]
class BlockRule extends Model {}
