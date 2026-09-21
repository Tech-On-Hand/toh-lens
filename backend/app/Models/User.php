<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->withPivot('role')->withTimestamps();
    }

    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class)->withPivot('role')->withTimestamps();
    }

    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class)->withPivot('role')->withTimestamps();
    }

    public function isOrganizationAdministrator(?int $organizationId = null): bool
    {
        return $this->organizations()
            ->when($organizationId, fn ($query) => $query->whereKey($organizationId))
            ->wherePivot('role', 'administrator')
            ->exists();
    }

    public function isSchoolAdministrator(int $schoolId): bool
    {
        return $this->isOrganizationAdministrator(
            School::query()->whereKey($schoolId)->value('organization_id')
        ) || $this->schools()->whereKey($schoolId)->wherePivot('role', 'administrator')->exists();
    }

    public function canViewClassroom(Classroom $classroom): bool
    {
        return $this->isSchoolAdministrator($classroom->school_id)
            || $this->classrooms()->whereKey($classroom->id)->exists();
    }

    public function canControlClassroom(Classroom $classroom): bool
    {
        return $this->isSchoolAdministrator($classroom->school_id)
            || $this->classrooms()
                ->whereKey($classroom->id)
                ->whereIn('classroom_user.role', ['primary_teacher', 'assistant_teacher'])
                ->exists();
    }
}
