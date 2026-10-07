<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['school_id', 'teacher_id', 'name', 'grade', 'stream'])]
class SchoolClass extends Model
{
    use HasFactory;

    protected $table = 'classes';

    /** "Grade 4" + "Blue" => "Grade 4 Blue"; the label used when a class is given a grade and stream but no name. */
    public static function composeName(?string $grade, ?string $stream): string
    {
        return trim(preg_replace('/\s+/', ' ', trim((string) $grade).' '.trim((string) $stream)));
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'class_id');
    }

    public function computers(): HasMany
    {
        return $this->hasMany(Computer::class, 'class_id');
    }
}
