<?php

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Str;

trait BuildsAdminFixtures
{
    protected function makeOrganization(string $name = 'Tech On Hand'): Organization
    {
        return Organization::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6)]);
    }

    protected function makeSchool(?Organization $organization = null, string $name = 'Demo Primary School'): School
    {
        return School::create(['organization_id' => $organization?->id, 'name' => $name]);
    }

    /** A user who directly administers one school, whether or not it has an organization. */
    protected function makeSchoolAdmin(School $school): User
    {
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);

        return $admin;
    }

    /** A user who administers a whole organization — every school in it. */
    protected function makeOrganizationAdmin(Organization $organization): User
    {
        $admin = User::factory()->create();
        $admin->organizations()->attach($organization, ['role' => 'administrator']);

        return $admin;
    }

    /** A signed-in user with no admin role anywhere — should be turned away at the door. */
    protected function makeNonAdmin(): User
    {
        return User::factory()->create();
    }
}
