<?php

namespace Tests\Feature\Admin;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\KenyaCbc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsAdminFixtures;
use Tests\TestCase;

class SeedCbcClassesTest extends TestCase
{
    use BuildsAdminFixtures, RefreshDatabase;

    public function test_seeding_the_primary_levels_creates_grades_1_to_6_with_the_grade_filled_in(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);

        $this->actingAs($admin)
            ->post(route('admin.classes.seed-cbc'), ['school_id' => $school->id, 'levels' => ['lower_primary', 'upper_primary']])
            ->assertRedirect()
            ->assertInertiaFlash('toast');

        $this->assertSame(
            ['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'],
            SchoolClass::orderBy('id')->pluck('name')->all(),
        );
        $this->assertSame(['Grade 4', null], [SchoolClass::firstWhere('name', 'Grade 4')->grade, SchoolClass::firstWhere('name', 'Grade 4')->stream]);
    }

    public function test_streams_make_one_class_per_grade_and_stream(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);

        $this->actingAs($admin)->post(route('admin.classes.seed-cbc'), [
            'school_id' => $school->id, 'levels' => ['pre_primary'], 'streams' => "Blue, green ;  Blue\nRed",
        ])->assertRedirect();

        $this->assertEqualsCanonicalizing(
            ['PP1 Blue', 'PP1 green', 'PP1 Red', 'PP2 Blue', 'PP2 green', 'PP2 Red'],
            SchoolClass::pluck('name')->all(),
        );
        $class = SchoolClass::firstWhere('name', 'PP2 green');
        $this->assertSame(['PP2', 'green'], [$class->grade, $class->stream]);
    }

    public function test_all_five_levels_cover_pp1_to_grade_12(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);

        $this->actingAs($admin)->post(route('admin.classes.seed-cbc'), ['school_id' => $school->id, 'levels' => array_keys(KenyaCbc::levels())])->assertRedirect();

        $this->assertSame(14, SchoolClass::count());
        $this->assertNotNull(SchoolClass::firstWhere('name', 'PP1'));
        $this->assertNotNull(SchoolClass::firstWhere('name', 'Grade 12'));
    }

    public function test_running_it_again_skips_existing_classes_and_leaves_them_untouched(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $existing = SchoolClass::create(['school_id' => $school->id, 'name' => 'grade 4']);
        $payload = ['school_id' => $school->id, 'levels' => ['upper_primary']];

        $this->actingAs($admin)->post(route('admin.classes.seed-cbc'), $payload)->assertRedirect();
        $this->actingAs($admin)->post(route('admin.classes.seed-cbc'), $payload)->assertRedirect();

        $this->assertSame(3, SchoolClass::count());
        $this->assertSame(['grade 4', null], [$existing->fresh()->name, $existing->fresh()->grade]);
    }

    public function test_the_sample_student_csv_lands_in_the_seeded_classes_without_creating_new_ones(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $this->actingAs($admin)->post(route('admin.classes.seed-cbc'), ['school_id' => $school->id, 'levels' => ['upper_primary'], 'streams' => 'Blue, Green']);
        $this->assertSame(6, SchoolClass::count());

        $file = new UploadedFile(public_path('samples/students-sample.csv'), 'students-sample.csv', 'text/csv', null, true);
        $this->actingAs($admin)->post(route('admin.students.import'), ['school_id' => $school->id, 'csv' => $file])->assertRedirect();

        $this->assertSame(6, SchoolClass::count());
        $this->assertSame(0, Student::whereNull('class_id')->count());
        $this->assertSame(2, SchoolClass::firstWhere('name', 'Grade 5 Blue')->students()->count());
    }

    public function test_the_same_names_in_another_school_are_not_skipped(): void
    {
        $a = $this->makeSchool(null, 'A');
        $b = $this->makeSchool(null, 'B');
        $admin = $this->makeSchoolAdmin($a);
        $admin->schools()->attach($b, ['role' => 'administrator']);
        SchoolClass::create(['school_id' => $a->id, 'name' => 'Grade 4']);

        $this->actingAs($admin)->post(route('admin.classes.seed-cbc'), ['school_id' => $b->id, 'levels' => ['upper_primary']])->assertRedirect();

        $this->assertSame(3, SchoolClass::where('school_id', $b->id)->count());
    }

    public function test_an_administrator_cannot_seed_a_school_they_do_not_run(): void
    {
        $mine = $this->makeSchool(null, 'Mine');
        $theirs = $this->makeSchool(null, 'Theirs');
        $admin = $this->makeSchoolAdmin($mine);

        $this->actingAs($admin)->post(route('admin.classes.seed-cbc'), ['school_id' => $theirs->id, 'levels' => ['upper_primary']])->assertForbidden();

        $this->assertSame(0, SchoolClass::count());
    }

    public function test_it_needs_a_known_level_and_a_sane_number_of_streams(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $post = fn (array $extra) => $this->actingAs($admin)->post(route('admin.classes.seed-cbc'), ['school_id' => $school->id, ...$extra]);

        $post([])->assertSessionHasErrors('levels');
        $post(['levels' => []])->assertSessionHasErrors('levels');
        $post(['levels' => ['university']])->assertSessionHasErrors('levels.0');
        $post(['levels' => ['upper_primary'], 'streams' => 'A,B,C,D,E,F,G,H,I,J,K'])->assertSessionHasErrors('streams');
        $post(['levels' => ['upper_primary'], 'streams' => str_repeat('x', 51)])->assertSessionHasErrors('streams');

        $this->assertSame(0, SchoolClass::count());
    }

    public function test_the_classes_page_offers_the_levels_with_the_primary_ones_ticked(): void
    {
        $this->withoutVite();
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);

        $levels = collect($this->actingAs($admin)->get(route('admin.classes.index'))->assertOk()->inertiaProps('cbcLevels'));

        $this->assertSame(['pre_primary', 'lower_primary', 'upper_primary', 'junior_school', 'senior_school'], $levels->pluck('key')->all());
        $this->assertSame(['lower_primary', 'upper_primary'], $levels->where('default', true)->pluck('key')->values()->all());
        $this->assertSame(['PP1', 'PP2'], $levels->first()['grades']);
    }
}
