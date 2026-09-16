<?php

namespace Tests\Feature\Admin;

use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolClassControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_can_view_and_create_classes(): void
    {
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);

        $this->actingAs($user)
            ->get(route('admin.classes.index'))
            ->assertOk();

        $this->actingAs($user)
            ->post(route('admin.classes.store'), [
                'school_id' => $school->id,
                'name' => 'Grade 4 Blue',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('classes', ['name' => 'Grade 4 Blue', 'school_id' => $school->id]);
    }

    public function test_a_class_requires_a_valid_school(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.classes.store'), ['school_id' => 999, 'name' => 'Grade 4 Blue'])
            ->assertSessionHasErrors('school_id');
    }

    public function test_an_authenticated_user_can_delete_a_class(): void
    {
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'Grade 4 Blue']);

        $this->actingAs($user)
            ->delete(route('admin.classes.destroy', $class))
            ->assertRedirect();

        $this->assertDatabaseMissing('classes', ['id' => $class->id]);
    }
}
