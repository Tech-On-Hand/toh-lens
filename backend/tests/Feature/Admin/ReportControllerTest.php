<?php

namespace Tests\Feature\Admin;

use App\Models\Computer;
use App\Models\LoginSession;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_reports(): void
    {
        $this->get(route('admin.reports.index'))->assertRedirect(route('login'));
    }

    public function test_with_no_class_selected_the_page_renders_without_a_report(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('admin.reports.index'))->assertOk();

        $this->assertNull($response->inertiaProps('report'));
    }

    public function test_report_computes_totals_average_duration_and_no_usage_list(): void
    {
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);
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

        $response = $this->actingAs($user)->get(route('admin.reports.index', [
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

    public function test_report_handles_a_class_with_no_sessions_at_all(): void
    {
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'Grade 4 Blue']);
        Student::create(['school_id' => $school->id, 'class_id' => $class->id, 'admission_number' => '1001', 'full_name' => 'Amara Otieno']);

        $response = $this->actingAs($user)
            ->get(route('admin.reports.index', ['class_id' => $class->id]))
            ->assertOk();

        $report = $response->inertiaProps('report');

        $this->assertSame(0, $report['active_students']);
        $this->assertNull($report['average_duration_minutes']);
        $this->assertCount(1, $report['no_usage_students']);
    }
}
