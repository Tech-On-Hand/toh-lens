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

    public function test_a_class_can_be_made_from_a_grade_and_a_stream(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);

        $this->actingAs($admin)
            ->post(route('admin.classes.store'), ['school_id' => $school->id, 'grade' => ' Grade 4 ', 'stream' => 'Blue'])
            ->assertRedirect();

        $this->assertDatabaseHas('classes', ['school_id' => $school->id, 'name' => 'Grade 4 Blue', 'grade' => 'Grade 4', 'stream' => 'Blue']);
    }

    public function test_a_grade_alone_is_a_class_and_an_explicit_name_wins(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);

        $this->actingAs($admin)->post(route('admin.classes.store'), ['school_id' => $school->id, 'grade' => 'Grade 5'])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.classes.store'), ['school_id' => $school->id, 'name' => 'Maths Club', 'grade' => 'Grade 5', 'stream' => 'Red'])->assertRedirect();

        $this->assertDatabaseHas('classes', ['name' => 'Grade 5', 'grade' => 'Grade 5', 'stream' => null]);
        $this->assertDatabaseHas('classes', ['name' => 'Maths Club', 'grade' => 'Grade 5', 'stream' => 'Red']);
    }

    public function test_a_class_needs_a_name_or_a_grade_and_a_stream_needs_a_grade(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);

        $this->actingAs($admin)->post(route('admin.classes.store'), ['school_id' => $school->id])->assertSessionHasErrors('grade');
        $this->actingAs($admin)->post(route('admin.classes.store'), ['school_id' => $school->id, 'stream' => 'Blue'])->assertSessionHasErrors('grade');
        $this->assertSame(0, SchoolClass::count());
    }

    public function test_two_classes_in_a_school_cannot_share_a_name_but_two_schools_can(): void
    {
        $school = $this->makeSchool(null, 'A');
        $other = $this->makeSchool(null, 'B');
        $admin = $this->makeSchoolAdmin($school);
        $admin->schools()->attach($other, ['role' => 'administrator']);
        SchoolClass::create(['school_id' => $school->id, 'name' => 'Grade 4 Blue']);

        $this->actingAs($admin)->post(route('admin.classes.store'), ['school_id' => $school->id, 'grade' => 'grade 4', 'stream' => 'blue'])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post(route('admin.classes.store'), ['school_id' => $other->id, 'grade' => 'Grade 4', 'stream' => 'Blue'])->assertSessionHasNoErrors();

        $this->assertSame(2, SchoolClass::where('name', 'Grade 4 Blue')->count());
    }

    public function test_grade_and_stream_can_be_set_on_an_existing_class_without_renaming_it(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'Grade 4 Blue']);

        $this->actingAs($admin)->put(route('admin.classes.update', $class), ['grade' => 'Grade 4', 'stream' => 'Blue'])->assertRedirect();
        $this->assertDatabaseHas('classes', ['id' => $class->id, 'name' => 'Grade 4 Blue', 'grade' => 'Grade 4', 'stream' => 'Blue']);

        $this->actingAs($admin)->put(route('admin.classes.update', $class), ['grade' => '', 'stream' => ''])->assertRedirect();
        $this->assertDatabaseHas('classes', ['id' => $class->id, 'grade' => null, 'stream' => null]);

        $this->actingAs($admin)->put(route('admin.classes.update', $class), ['stream' => 'Blue'])->assertSessionHasErrors('grade');
    }

    public function test_an_administrator_cannot_edit_a_class_in_another_school(): void
    {
        $mine = $this->makeSchool(null, 'Mine');
        $theirs = $this->makeSchool(null, 'Theirs');
        $admin = $this->makeSchoolAdmin($mine);
        $class = SchoolClass::create(['school_id' => $theirs->id, 'name' => 'Grade 4 Blue']);

        $this->actingAs($admin)->put(route('admin.classes.update', $class), ['grade' => 'Grade 9'])->assertForbidden();
        $this->assertDatabaseHas('classes', ['id' => $class->id, 'grade' => null]);
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
