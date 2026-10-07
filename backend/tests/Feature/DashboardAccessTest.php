<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    private function school(): School
    {
        $organization = Organization::create(['name' => 'Org', 'slug' => 'org']);

        return School::create(['organization_id' => $organization->id, 'name' => 'School']);
    }

    public function test_a_teacher_can_open_the_dashboard_but_is_not_flagged_as_an_administrator(): void
    {
        $this->withoutVite();
        $school = $this->school();
        $teacher = User::factory()->create();
        $school->users()->attach($teacher->id, ['role' => 'teacher']);

        $this->actingAs($teacher)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.is_administrator', false));
        $this->actingAs($teacher)->get('/admin/schools')->assertForbidden();
    }

    public function test_school_and_organization_administrators_are_flagged(): void
    {
        $this->withoutVite();
        $school = $this->school();
        $head = User::factory()->create();
        $school->users()->attach($head->id, ['role' => 'administrator']);
        $orgAdmin = User::factory()->create();
        $school->organization->users()->attach($orgAdmin->id, ['role' => 'administrator']);

        foreach ([$head, $orgAdmin] as $admin) {
            $this->actingAs($admin)->get('/dashboard')
                ->assertInertia(fn ($page) => $page->where('auth.is_administrator', true));
        }
    }
}
