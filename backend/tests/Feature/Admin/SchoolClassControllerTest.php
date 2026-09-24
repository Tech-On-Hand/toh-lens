<?php

namespace Tests\Feature\Admin;

use App\Models\SchoolClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAdminFixtures;
use Tests\TestCase;

class SchoolClassControllerTest extends TestCase
{
    use BuildsAdminFixtures, RefreshDatabase;

    public function test_a_school_administrator_can_view_and_create_classes_in_their_school(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);

        $this->actingAs($admin)
            ->get(route('admin.classes.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('admin.classes.store'), [
                'school_id' => $school->id,
                'name' => 'Grade 4 Blue',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('classes', ['name' => 'Grade 4 Blue', 'school_id' => $school->id]);
    }

    public function test_a_class_requires_a_valid_school(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);

        $this->actingAs($admin)
            ->post(route('admin.classes.store'), ['school_id' => 999, 'name' => 'Grade 4 Blue'])
            ->assertSessionHasErrors('school_id');
    }

    public function test_an_administrator_cannot_create_a_class_in_a_school_they_do_not_run(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        $admin = $this->makeSchoolAdmin($mine);

        $this->actingAs($admin)
            ->post(route('admin.classes.store'), ['school_id' => $theirs->id, 'name' => 'Grade 4 Blue'])
            ->assertForbidden();

        $this->assertDatabaseMissing('classes', ['school_id' => $theirs->id]);
    }

    public function test_the_index_only_lists_classes_and_schools_the_administrator_runs(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        SchoolClass::create(['school_id' => $mine->id, 'name' => 'Mine']);
        SchoolClass::create(['school_id' => $theirs->id, 'name' => 'Theirs']);
        $admin = $this->makeSchoolAdmin($mine);

        $response = $this->actingAs($admin)->get(route('admin.classes.index'))->assertOk();

        $this->assertSame(['Mine'], array_column($response->inertiaProps('classes'), 'name'));
        $this->assertSame(['My School'], array_column($response->inertiaProps('schools'), 'name'));
    }

    public function test_an_administrator_can_delete_a_class_in_their_school_but_not_another(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        $myClass = SchoolClass::create(['school_id' => $mine->id, 'name' => 'Grade 4 Blue']);
        $theirClass = SchoolClass::create(['school_id' => $theirs->id, 'name' => 'Grade 5 Green']);
        $admin = $this->makeSchoolAdmin($mine);

        $this->actingAs($admin)->delete(route('admin.classes.destroy', $theirClass))->assertForbidden();
        $this->assertDatabaseHas('classes', ['id' => $theirClass->id]);

        $this->actingAs($admin)
            ->delete(route('admin.classes.destroy', $myClass))
            ->assertRedirect();

        $this->assertDatabaseMissing('classes', ['id' => $myClass->id]);
    }
}
