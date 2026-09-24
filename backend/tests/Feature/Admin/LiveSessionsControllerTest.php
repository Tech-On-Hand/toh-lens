<?php

namespace Tests\Feature\Admin;

use App\Models\Computer;
use App\Models\LoginSession;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsAdminFixtures;
use Tests\TestCase;

class LiveSessionsControllerTest extends TestCase
{
    use BuildsAdminFixtures, RefreshDatabase;

    public function test_guests_cannot_access_live_sessions(): void
    {
        $this->get(route('admin.live.index'))->assertRedirect(route('login'));
    }

    public function test_only_open_sessions_are_shown(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'Grade 4 Blue']);
        $computer = Computer::create(['school_id' => $school->id, 'name' => 'Lab PC 1', 'role' => 'student']);
        $student = Student::create(['school_id' => $school->id, 'class_id' => $class->id, 'admission_number' => '1001', 'full_name' => 'Amara Otieno']);

        $open = LoginSession::create([
            'uuid' => (string) Str::uuid(),
            'school_id' => $school->id,
            'computer_id' => $computer->id,
            'student_id' => $student->id,
            'admission_number' => $student->admission_number,
            'login_time' => now()->subMinutes(15),
            'logout_time' => null,
        ]);

        LoginSession::create([
            'uuid' => (string) Str::uuid(),
            'school_id' => $school->id,
            'computer_id' => $computer->id,
            'student_id' => $student->id,
            'admission_number' => $student->admission_number,
            'login_time' => now()->subHour(),
            'logout_time' => now()->subMinutes(45),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.live.index'))->assertOk();
        $sessions = $response->inertiaProps('sessions');

        $this->assertCount(1, $sessions);
        $this->assertSame($open->id, $sessions[0]['id']);
        $this->assertSame('Amara Otieno', $sessions[0]['full_name']);
        $this->assertSame('Grade 4 Blue', $sessions[0]['class_name']);
        $this->assertGreaterThanOrEqual(14, $sessions[0]['minutes_logged_in']);
    }

    public function test_can_filter_by_an_own_school(): void
    {
        $schoolA = $this->makeSchool(null, 'School A');
        $schoolB = $this->makeSchool(null, 'School B');
        $admin = $this->makeSchoolAdmin($schoolA);
        $schoolB->users()->attach($admin, ['role' => 'administrator']);
        $computerA = Computer::create(['school_id' => $schoolA->id, 'name' => 'PC A1', 'role' => 'student']);
        $computerB = Computer::create(['school_id' => $schoolB->id, 'name' => 'PC B1', 'role' => 'student']);

        LoginSession::create([
            'uuid' => (string) Str::uuid(),
            'school_id' => $schoolA->id,
            'computer_id' => $computerA->id,
            'admission_number' => '1001',
            'login_time' => now(),
        ]);
        LoginSession::create([
            'uuid' => (string) Str::uuid(),
            'school_id' => $schoolB->id,
            'computer_id' => $computerB->id,
            'admission_number' => '2001',
            'login_time' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.live.index', ['school_id' => $schoolA->id]))
            ->assertOk();

        $sessions = $response->inertiaProps('sessions');
        $this->assertCount(1, $sessions);
        $this->assertSame('1001', $sessions[0]['admission_number']);
    }

    public function test_the_index_only_shows_sessions_at_schools_the_administrator_runs(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        $admin = $this->makeSchoolAdmin($mine);
        $computerA = Computer::create(['school_id' => $mine->id, 'name' => 'PC A1', 'role' => 'student']);
        $computerB = Computer::create(['school_id' => $theirs->id, 'name' => 'PC B1', 'role' => 'student']);

        LoginSession::create(['uuid' => (string) Str::uuid(), 'school_id' => $mine->id, 'computer_id' => $computerA->id, 'admission_number' => '1001', 'login_time' => now()]);
        LoginSession::create(['uuid' => (string) Str::uuid(), 'school_id' => $theirs->id, 'computer_id' => $computerB->id, 'admission_number' => '2001', 'login_time' => now()]);

        $response = $this->actingAs($admin)->get(route('admin.live.index'))->assertOk();

        $sessions = $response->inertiaProps('sessions');
        $this->assertCount(1, $sessions);
        $this->assertSame('1001', $sessions[0]['admission_number']);
    }

    public function test_filtering_by_a_school_the_administrator_does_not_run_is_refused(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        $admin = $this->makeSchoolAdmin($mine);

        $this->actingAs($admin)
            ->get(route('admin.live.index', ['school_id' => $theirs->id]))
            ->assertForbidden();
    }
}
