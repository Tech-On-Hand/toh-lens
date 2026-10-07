<?php

namespace Tests\Feature\Admin;

use App\Models\Computer;
use App\Models\LoginSession;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsAdminFixtures;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use BuildsAdminFixtures, RefreshDatabase;

    public function test_guests_cannot_access_reports(): void
    {
        $this->get(route('admin.reports.index'))->assertRedirect(route('login'));
    }

    public function test_with_no_class_selected_the_page_renders_without_a_report(): void
    {
        $admin = $this->makeSchoolAdmin($this->makeSchool());

        $response = $this->actingAs($admin)->get(route('admin.reports.index'))->assertOk();

        $this->assertNull($response->inertiaProps('report'));
    }

    public function test_report_computes_totals_average_duration_and_no_usage_list(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'Grade 4 Blue']);

        $computer = Computer::create(['school_id' => $school->id, 'name' => 'Lab PC 1', 'role' => 'student']);
        $active = Student::create(['school_id' => $school->id, 'class_id' => $class->id, 'admission_number' => '1001', 'full_name' => 'Amara Otieno']);
        $inactive = Student::create(['school_id' => $school->id, 'class_id' => $class->id, 'admission_number' => '1002', 'full_name' => 'Brian Mwangi']);

        // A completed 30-minute session for the active student, inside range.
        LoginSession::create([
            'uuid' => (string) Str::uuid(),
            'school_id' => $school->id,
            'computer_id' => $computer->id,
            'student_id' => $active->id,
            'admission_number' => $active->admission_number,
            'login_time' => now()->subDays(1)->setTime(9, 0),
            'logout_time' => now()->subDays(1)->setTime(9, 30),
        ]);

        // A session for the same student far outside the requested range —
        // must not affect the computed average or active count either way
        // here, but proves the date filter is actually applied.
        LoginSession::create([
            'uuid' => (string) Str::uuid(),
            'school_id' => $school->id,
            'computer_id' => $computer->id,
            'student_id' => $active->id,
            'admission_number' => $active->admission_number,
            'login_time' => now()->subYear(),
            'logout_time' => now()->subYear()->addHours(5),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.reports.index', [
            'class_id' => $class->id,
            'from' => now()->subDays(2)->toDateString(),
            'to' => now()->toDateString(),
        ]))->assertOk();

        $report = $response->inertiaProps('report');

        $this->assertSame(2, $report['total_students']);
        $this->assertSame(1, $report['active_students']);
        $this->assertSame(1, $report['total_sessions']);
        // Round-trips through JSON, so a whole-number float (30.0) may come
        // back as an int (30) — compare numerically, not by type.
        $this->assertEquals(30.0, $report['average_duration_minutes']);
        $this->assertCount(1, $report['no_usage_students']);
        $this->assertSame($inactive->admission_number, $report['no_usage_students'][0]['admission_number']);
    }

    private function sessionFor(School $school, Computer $computer, Student $student, int $minutes): void
    {
        LoginSession::create([
            'uuid' => (string) Str::uuid(),
            'school_id' => $school->id,
            'computer_id' => $computer->id,
            'student_id' => $student->id,
            'admission_number' => $student->admission_number,
            'login_time' => now()->subDay()->setTime(9, 0),
            'logout_time' => now()->subDay()->setTime(9, 0)->addMinutes($minutes),
        ]);
    }

    public function test_a_grade_report_adds_up_every_stream_and_breaks_the_numbers_down_by_class(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $computer = Computer::create(['school_id' => $school->id, 'name' => 'PC', 'role' => 'student']);
        $blue = SchoolClass::create(['school_id' => $school->id, 'name' => 'Grade 4 Blue', 'grade' => 'Grade 4', 'stream' => 'Blue']);
        $green = SchoolClass::create(['school_id' => $school->id, 'name' => 'Grade 4 Green', 'grade' => 'Grade 4', 'stream' => 'Green']);
        $other = SchoolClass::create(['school_id' => $school->id, 'name' => 'Grade 5 Blue', 'grade' => 'Grade 5', 'stream' => 'Blue']);
        $b1 = Student::create(['school_id' => $school->id, 'class_id' => $blue->id, 'admission_number' => '1', 'full_name' => 'B One']);
        Student::create(['school_id' => $school->id, 'class_id' => $blue->id, 'admission_number' => '2', 'full_name' => 'B Two']);
        $g1 = Student::create(['school_id' => $school->id, 'class_id' => $green->id, 'admission_number' => '3', 'full_name' => 'G One']);
        $o1 = Student::create(['school_id' => $school->id, 'class_id' => $other->id, 'admission_number' => '4', 'full_name' => 'Other']);
        $this->sessionFor($school, $computer, $b1, 30);
        $this->sessionFor($school, $computer, $g1, 60);
        $this->sessionFor($school, $computer, $o1, 10); // a different grade: must not count

        $response = $this->actingAs($admin)->get(route('admin.reports.index', [
            'school_id' => $school->id, 'grade' => 'grade 4', 'from' => now()->subDays(2)->toDateString(), 'to' => now()->toDateString(),
        ]))->assertOk();
        $report = $response->inertiaProps('report');

        $this->assertSame('grade', $report['scope']);
        $this->assertSame('Grade 4 — all classes', $report['class']['name']);
        $this->assertSame(3, $report['total_students']);
        $this->assertSame(2, $report['active_students']);
        $this->assertSame(2, $report['total_sessions']);
        $this->assertEquals(45.0, $report['average_duration_minutes']);
        $this->assertSame(['B Two'], array_column($report['no_usage_students'], 'full_name'));

        $this->assertSame(['Blue', 'Green'], array_column($report['breakdown'], 'stream'));
        [$blueRow, $greenRow] = $report['breakdown'];
        $this->assertSame([2, 1, 1], [$blueRow['total_students'], $blueRow['active_students'], $blueRow['total_sessions']]);
        $this->assertEquals(30.0, $blueRow['average_duration_minutes']);
        $this->assertSame([1, 1, 1], [$greenRow['total_students'], $greenRow['active_students'], $greenRow['total_sessions']]);
        $this->assertEquals(60.0, $greenRow['average_duration_minutes']);

        // The picker offers each grade once, with how many classes it holds.
        $grades = collect($response->inertiaProps('grades'));
        $this->assertSame(['Grade 4' => 2, 'Grade 5' => 1], $grades->pluck('classes_count', 'grade')->all());
    }

    public function test_a_single_class_report_has_no_breakdown(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'Grade 4 Blue', 'grade' => 'Grade 4', 'stream' => 'Blue']);

        $report = $this->actingAs($admin)->get(route('admin.reports.index', ['class_id' => $class->id]))->assertOk()->inertiaProps('report');

        $this->assertSame('class', $report['scope']);
        $this->assertSame([], $report['breakdown']);
    }

    public function test_a_grade_in_a_school_the_administrator_does_not_run_yields_no_report(): void
    {
        $mine = $this->makeSchool(null, 'Mine');
        $theirs = $this->makeSchool(null, 'Theirs');
        $admin = $this->makeSchoolAdmin($mine);
        SchoolClass::create(['school_id' => $theirs->id, 'name' => 'Grade 4 Blue', 'grade' => 'Grade 4', 'stream' => 'Blue']);

        $response = $this->actingAs($admin)->get(route('admin.reports.index', ['school_id' => $theirs->id, 'grade' => 'Grade 4']))->assertOk();

        $this->assertNull($response->inertiaProps('report'));
        $this->assertSame([], $response->inertiaProps('grades'));
    }

    public function test_report_handles_a_class_with_no_sessions_at_all(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'Grade 4 Blue']);
        Student::create(['school_id' => $school->id, 'class_id' => $class->id, 'admission_number' => '1001', 'full_name' => 'Amara Otieno']);

        $response = $this->actingAs($admin)
            ->get(route('admin.reports.index', ['class_id' => $class->id]))
            ->assertOk();

        $report = $response->inertiaProps('report');

        $this->assertSame(0, $report['active_students']);
        $this->assertNull($report['average_duration_minutes']);
        $this->assertCount(1, $report['no_usage_students']);
    }

    public function test_a_class_in_a_school_the_administrator_does_not_run_yields_no_report(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        $theirClass = SchoolClass::create(['school_id' => $theirs->id, 'name' => 'Grade 4 Blue']);
        $admin = $this->makeSchoolAdmin($mine);

        $response = $this->actingAs($admin)
            ->get(route('admin.reports.index', ['class_id' => $theirClass->id]))
            ->assertOk();

        $this->assertNull($response->inertiaProps('report'));
        $this->assertSame([], $response->inertiaProps('classes'));
    }
}
