<?php

namespace Tests\Feature\Admin;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_the_admin_schools_page(): void
    {
        $this->get(route('admin.schools.index'))->assertRedirect(route('login'));
    }

    public function test_an_authenticated_user_can_view_and_create_schools(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.schools.index'))
            ->assertOk();

        $this->actingAs($user)
            ->post(route('admin.schools.store'), ['name' => 'Demo Primary School'])
            ->assertRedirect();

        $this->assertDatabaseHas('schools', ['name' => 'Demo Primary School']);
    }

    public function test_school_name_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.schools.store'), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_an_authenticated_user_can_delete_a_school(): void
    {
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);

        $this->actingAs($user)
            ->delete(route('admin.schools.destroy', $school))
            ->assertRedirect();

        $this->assertDatabaseMissing('schools', ['id' => $school->id]);
    }
}
