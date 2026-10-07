<?php

namespace Tests\Feature\Admin;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsAdminFixtures;
use Tests\TestCase;

class StudentControllerTest extends TestCase
{
    use BuildsAdminFixtures, RefreshDatabase;

    public function test_a_school_administrator_can_view_and_create_students_in_their_school(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);

        $this->actingAs($admin)
            ->get(route('admin.students.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('admin.students.store'), [
                'school_id' => $school->id,
                'admission_number' => '1001',
                'full_name' => 'Amara Otieno',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('students', [
            'school_id' => $school->id,
            'admission_number' => '1001',
            'is_active' => true,
        ]);
    }

    public function test_an_administrator_cannot_create_a_student_in_a_school_they_do_not_run(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        $admin = $this->makeSchoolAdmin($mine);

        $this->actingAs($admin)
            ->post(route('admin.students.store'), ['school_id' => $theirs->id, 'admission_number' => '1001', 'full_name' => 'Someone'])
            ->assertForbidden();

        $this->assertDatabaseMissing('students', ['school_id' => $theirs->id]);
    }

    public function test_the_index_is_scoped_to_the_administrators_own_schools(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        Student::create(['school_id' => $mine->id, 'admission_number' => '1001', 'full_name' => 'Mine']);
        Student::create(['school_id' => $theirs->id, 'admission_number' => '2001', 'full_name' => 'Theirs']);
        $admin = $this->makeSchoolAdmin($mine);

        $response = $this->actingAs($admin)->get(route('admin.students.index'))->assertOk();

        $this->assertSame(['Mine'], array_column($response->inertiaProps('students'), 'full_name'));
    }

    public function test_filtering_by_a_school_the_administrator_does_not_run_is_refused(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        $admin = $this->makeSchoolAdmin($mine);

        $this->actingAs($admin)
            ->get(route('admin.students.index', ['school_id' => $theirs->id]))
            ->assertForbidden();
    }

    public function test_admission_number_must_be_unique_within_a_school_but_not_across_schools(): void
    {
        $schoolA = $this->makeSchool(null, 'School A');
        $schoolB = $this->makeSchool(null, 'School B');
        $admin = $this->makeSchoolAdmin($schoolA);
        $this->makeSchoolAdmin($schoolB); // not used directly, just to prove independence isn't required
        $schoolB->users()->attach($admin, ['role' => 'administrator']);
        Student::create(['school_id' => $schoolA->id, 'admission_number' => '1001', 'full_name' => 'Existing Student']);

        $this->actingAs($admin)
            ->post(route('admin.students.store'), [
                'school_id' => $schoolA->id,
                'admission_number' => '1001',
                'full_name' => 'Duplicate Attempt',
            ])
            ->assertSessionHasErrors('admission_number');

        $this->actingAs($admin)
            ->post(route('admin.students.store'), [
                'school_id' => $schoolB->id,
                'admission_number' => '1001',
                'full_name' => 'Same Number Different School',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('students', ['school_id' => $schoolB->id, 'admission_number' => '1001']);
    }

    public function test_a_class_id_from_another_school_is_refused(): void
    {
        $schoolA = $this->makeSchool(null, 'School A');
        $schoolB = $this->makeSchool(null, 'School B');
        $classInB = SchoolClass::create(['school_id' => $schoolB->id, 'name' => 'Grade 4 Blue']);
        $admin = $this->makeSchoolAdmin($schoolA);

        $this->actingAs($admin)
            ->post(route('admin.students.store'), [
                'school_id' => $schoolA->id,
                'class_id' => $classInB->id,
                'admission_number' => '1001',
                'full_name' => 'Amara Otieno',
            ])
            ->assertSessionHasErrors('class_id');
    }

    public function test_an_authenticated_administrator_can_deactivate_a_student(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $student = Student::create(['school_id' => $school->id, 'admission_number' => '1001', 'full_name' => 'Amara Otieno']);

        // The edit form always sends is_active as "0" or "1" (a hidden "0"
        // paired with the checkbox), never omits it — this is what an
        // unchecked box actually submits.
        $this->actingAs($admin)
            ->put(route('admin.students.update', $student), [
                'school_id' => $school->id,
                'admission_number' => '1001',
                'full_name' => 'Amara Otieno',
                'is_active' => '0',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('students', ['id' => $student->id, 'is_active' => false]);
    }

    public function test_an_administrator_cannot_update_a_student_in_a_school_they_do_not_run(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        $admin = $this->makeSchoolAdmin($mine);
        $student = Student::create(['school_id' => $theirs->id, 'admission_number' => '1001', 'full_name' => 'Someone']);

        $this->actingAs($admin)
            ->put(route('admin.students.update', $student), [
                'school_id' => $theirs->id,
                'admission_number' => '1001',
                'full_name' => 'Renamed',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('students', ['id' => $student->id, 'full_name' => 'Someone']);
    }

    public function test_updating_a_student_without_an_is_active_field_at_all_preserves_its_current_value(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $student = Student::create([
            'school_id' => $school->id,
            'admission_number' => '1001',
            'full_name' => 'Amara Otieno',
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.students.update', $student), [
                'school_id' => $school->id,
                'admission_number' => '1001',
                'full_name' => 'Amara Otieno Jr.',
                // is_active omitted entirely — not the same as the real edit
                // form's "unchecked" payload, which always sends "0".
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('students', ['id' => $student->id, 'is_active' => false, 'full_name' => 'Amara Otieno Jr.']);
    }

    public function test_csv_import_creates_and_updates_students_and_matches_class_by_name(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'Grade 4 Blue']);
        $existing = Student::create(['school_id' => $school->id, 'admission_number' => '1001', 'full_name' => 'Old Name']);

        $csv = "admission_number,full_name,class_name\n"
            ."1001,Amara Otieno,Grade 4 Blue\n" // updates the existing row
            ."1002,Brian Mwangi,Grade 4 Blue\n"; // creates a new one

        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $this->actingAs($admin)
            ->post(route('admin.students.import'), ['school_id' => $school->id, 'csv' => $file])
            ->assertRedirect();

        $existing->refresh();
        $this->assertSame('Amara Otieno', $existing->full_name);
        $this->assertSame($class->id, $existing->class_id);

        $this->assertDatabaseHas('students', [
            'school_id' => $school->id,
            'admission_number' => '1002',
            'full_name' => 'Brian Mwangi',
            'class_id' => $class->id,
        ]);
        $this->assertSame(2, Student::where('school_id', $school->id)->count());
    }

    public function test_the_downloadable_sample_csv_imports_five_students_without_issues(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $file = new UploadedFile(public_path('samples/students-sample.csv'), 'students-sample.csv', 'text/csv', null, true);

        $this->actingAs($admin)
            ->post(route('admin.students.import'), ['school_id' => $school->id, 'csv' => $file])
            ->assertRedirect()
            ->assertInertiaFlash('importIssues', []);

        $this->assertSame(5, Student::where('school_id', $school->id)->where('is_active', true)->count());
        // The sample teaches the grade + stream columns: three classes across two grades.
        $this->assertEqualsCanonicalizing(['Grade 4 Blue', 'Grade 4 Green', 'Grade 5 Blue'], SchoolClass::pluck('name')->all());
        $this->assertSame(2, SchoolClass::where('grade', 'Grade 5')->firstOrFail()->students()->count());
    }

    public function test_csv_import_creates_classes_from_grade_and_stream_and_reuses_them(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $csv = "admission_number,full_name,grade,stream\n1,A,Grade 4,Blue\n2,B,grade 4,blue\n3,C,Grade 4,Green\n4,D,Grade 5,\n";
        $file = UploadedFile::fake()->createWithContent('roster.csv', $csv);

        $this->actingAs($admin)
            ->post(route('admin.students.import'), ['school_id' => $school->id, 'csv' => $file])
            ->assertRedirect()
            ->assertInertiaFlash('importIssues', []);

        $this->assertEqualsCanonicalizing(['Grade 4 Blue', 'Grade 4 Green', 'Grade 5'], SchoolClass::pluck('name')->all());
        $blue = SchoolClass::firstWhere('name', 'Grade 4 Blue');
        $this->assertSame(['Grade 4', 'Blue'], [$blue->grade, $blue->stream]);
        $this->assertNull(SchoolClass::firstWhere('name', 'Grade 5')->stream);
        $this->assertSame($blue->id, Student::firstWhere('admission_number', '2')->class_id);
        $this->assertSame(2, $blue->students()->count());
    }

    public function test_csv_import_fills_in_the_grade_of_a_class_made_before_grades_existed_without_overwriting(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $legacy = SchoolClass::create(['school_id' => $school->id, 'name' => 'Grade 4 Blue']);
        $set = SchoolClass::create(['school_id' => $school->id, 'name' => 'Grade 4 Green', 'grade' => 'Year 4', 'stream' => 'G']);
        $csv = "admission_number,full_name,grade,stream\n1,A,Grade 4,Blue\n2,B,Grade 4,Green\n";

        $this->actingAs($admin)->post(route('admin.students.import'), ['school_id' => $school->id, 'csv' => UploadedFile::fake()->createWithContent('r.csv', $csv)]);

        $this->assertSame(2, SchoolClass::count());
        $this->assertSame(['Grade 4', 'Blue'], [$legacy->fresh()->grade, $legacy->fresh()->stream]);
        $this->assertSame(['Year 4', 'G'], [$set->fresh()->grade, $set->fresh()->stream]);
    }

    public function test_csv_import_flags_a_stream_without_a_grade(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $csv = "admission_number,full_name,grade,stream\n1,A,,Blue\n";

        $response = $this->actingAs($admin)->post(route('admin.students.import'), ['school_id' => $school->id, 'csv' => UploadedFile::fake()->createWithContent('r.csv', $csv)]);

        $response->assertInertiaFlash('importIssues');
        $this->assertSame(0, SchoolClass::count());
        $this->assertNull(Student::first()->class_id);
    }

    public function test_csv_import_reports_issues_without_failing_the_whole_batch(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);

        $csv = "admission_number,full_name,class_name\n"
            .",Missing Admission Number,\n" // skipped: no admission_number
            ."1002,Brian Mwangi,Nonexistent Class\n"; // saved, but flagged

        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $response = $this->actingAs($admin)
            ->post(route('admin.students.import'), ['school_id' => $school->id, 'csv' => $file])
            ->assertRedirect();

        $response->assertInertiaFlash('importIssues');
        $this->assertSame(1, Student::where('school_id', $school->id)->count());
        $this->assertDatabaseHas('students', ['admission_number' => '1002', 'class_id' => null]);
    }

    public function test_csv_import_is_scoped_to_the_selected_school(): void
    {
        $schoolA = $this->makeSchool(null, 'School A');
        $schoolB = $this->makeSchool(null, 'School B');
        $admin = $this->makeSchoolAdmin($schoolA);
        Student::create(['school_id' => $schoolB->id, 'admission_number' => '1001', 'full_name' => 'Existing In B']);

        $csv = "admission_number,full_name\n1001,Imported Into A\n";
        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $this->actingAs($admin)
            ->post(route('admin.students.import'), ['school_id' => $schoolA->id, 'csv' => $file])
            ->assertRedirect();

        $this->assertDatabaseHas('students', ['school_id' => $schoolA->id, 'admission_number' => '1001', 'full_name' => 'Imported Into A']);
        $this->assertDatabaseHas('students', ['school_id' => $schoolB->id, 'admission_number' => '1001', 'full_name' => 'Existing In B']);
    }

    public function test_importing_into_a_school_the_administrator_does_not_run_is_refused(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        $admin = $this->makeSchoolAdmin($mine);

        $csv = "admission_number,full_name\n1001,Should Not Import\n";
        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $this->actingAs($admin)
            ->post(route('admin.students.import'), ['school_id' => $theirs->id, 'csv' => $file])
            ->assertForbidden();

        $this->assertDatabaseMissing('students', ['school_id' => $theirs->id]);
    }

    public function test_an_administrator_can_delete_a_student_in_their_school_but_not_another(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        $admin = $this->makeSchoolAdmin($mine);
        $myStudent = Student::create(['school_id' => $mine->id, 'admission_number' => '1001', 'full_name' => 'Mine']);
        $theirStudent = Student::create(['school_id' => $theirs->id, 'admission_number' => '2001', 'full_name' => 'Theirs']);

        $this->actingAs($admin)->delete(route('admin.students.destroy', $theirStudent))->assertForbidden();
        $this->assertDatabaseHas('students', ['id' => $theirStudent->id]);

        $this->actingAs($admin)
            ->delete(route('admin.students.destroy', $myStudent))
            ->assertRedirect();

        $this->assertDatabaseMissing('students', ['id' => $myStudent->id]);
    }
}
