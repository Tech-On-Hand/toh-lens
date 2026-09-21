<?php

namespace Tests\Feature\Admin;

use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class StudentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_can_view_and_create_students(): void
    {
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);

        $this->actingAs($user)
            ->get(route('admin.students.index'))
            ->assertOk();

        $this->actingAs($user)
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

    public function test_admission_number_must_be_unique_within_a_school_but_not_across_schools(): void
    {
        $user = User::factory()->create();
        $schoolA = School::create(['name' => 'School A']);
        $schoolB = School::create(['name' => 'School B']);
        Student::create(['school_id' => $schoolA->id, 'admission_number' => '1001', 'full_name' => 'Existing Student']);

        $this->actingAs($user)
            ->post(route('admin.students.store'), [
                'school_id' => $schoolA->id,
                'admission_number' => '1001',
                'full_name' => 'Duplicate Attempt',
            ])
            ->assertSessionHasErrors('admission_number');

        $this->actingAs($user)
            ->post(route('admin.students.store'), [
                'school_id' => $schoolB->id,
                'admission_number' => '1001',
                'full_name' => 'Same Number Different School',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('students', ['school_id' => $schoolB->id, 'admission_number' => '1001']);
    }

    public function test_an_authenticated_user_can_deactivate_a_student(): void
    {
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);
        $student = Student::create(['school_id' => $school->id, 'admission_number' => '1001', 'full_name' => 'Amara Otieno']);

        // The edit form always sends is_active as "0" or "1" (a hidden "0"
        // paired with the checkbox), never omits it — this is what an
        // unchecked box actually submits.
        $this->actingAs($user)
            ->put(route('admin.students.update', $student), [
                'school_id' => $school->id,
                'admission_number' => '1001',
                'full_name' => 'Amara Otieno',
                'is_active' => '0',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('students', ['id' => $student->id, 'is_active' => false]);
    }

    public function test_updating_a_student_without_an_is_active_field_at_all_preserves_its_current_value(): void
    {
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);
        $student = Student::create([
            'school_id' => $school->id,
            'admission_number' => '1001',
            'full_name' => 'Amara Otieno',
            'is_active' => false,
        ]);

        $this->actingAs($user)
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
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'Grade 4 Blue']);
        $existing = Student::create(['school_id' => $school->id, 'admission_number' => '1001', 'full_name' => 'Old Name']);

        $csv = "admission_number,full_name,class_name\n"
            ."1001,Amara Otieno,Grade 4 Blue\n" // updates the existing row
            ."1002,Brian Mwangi,Grade 4 Blue\n"; // creates a new one

        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $this->actingAs($user)
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

    public function test_csv_import_reports_issues_without_failing_the_whole_batch(): void
    {
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);

        $csv = "admission_number,full_name,class_name\n"
            .",Missing Admission Number,\n" // skipped: no admission_number
            ."1002,Brian Mwangi,Nonexistent Class\n"; // saved, but flagged

        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $response = $this->actingAs($user)
            ->post(route('admin.students.import'), ['school_id' => $school->id, 'csv' => $file])
            ->assertRedirect();

        $response->assertInertiaFlash('importIssues');
        $this->assertSame(1, Student::where('school_id', $school->id)->count());
        $this->assertDatabaseHas('students', ['admission_number' => '1002', 'class_id' => null]);
    }

    public function test_csv_import_is_scoped_to_the_selected_school(): void
    {
        $user = User::factory()->create();
        $schoolA = School::create(['name' => 'School A']);
        $schoolB = School::create(['name' => 'School B']);
        Student::create(['school_id' => $schoolB->id, 'admission_number' => '1001', 'full_name' => 'Existing In B']);

        $csv = "admission_number,full_name\n1001,Imported Into A\n";
        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $this->actingAs($user)
            ->post(route('admin.students.import'), ['school_id' => $schoolA->id, 'csv' => $file])
            ->assertRedirect();

        $this->assertDatabaseHas('students', ['school_id' => $schoolA->id, 'admission_number' => '1001', 'full_name' => 'Imported Into A']);
        $this->assertDatabaseHas('students', ['school_id' => $schoolB->id, 'admission_number' => '1001', 'full_name' => 'Existing In B']);
    }

    public function test_an_authenticated_user_can_delete_a_student(): void
    {
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);
        $student = Student::create(['school_id' => $school->id, 'admission_number' => '1001', 'full_name' => 'Amara Otieno']);

        $this->actingAs($user)
            ->delete(route('admin.students.destroy', $student))
            ->assertRedirect();

        $this->assertDatabaseMissing('students', ['id' => $student->id]);
    }
}
