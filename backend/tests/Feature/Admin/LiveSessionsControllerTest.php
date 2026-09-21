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

class LiveSessionsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_live_sessions(): void
    {
        $this->get(route('admin.live.index'))->assertRedirect(route('login'));
    }

    public function test_only_open_sessions_are_shown(): void
    {
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);
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

        $response = $this->actingAs($user)->get(route('admin.live.index'))->assertOk();
        $sessions = $response->inertiaProps('sessions');

        $this->assertCount(1, $sessions);
        $this->assertSame($open->id, $sessions[0]['id']);
        $this->assertSame('Amara Otieno', $sessions[0]['full_name']);
        $this->assertSame('Grade 4 Blue', $sessions[0]['class_name']);
        $this->assertGreaterThanOrEqual(14, $sessions[0]['minutes_logged_in']);
    }

    public function test_can_filter_by_school(): void
    {
        $user = User::factory()->create();
        $schoolA = School::create(['name' => 'School A']);
        $schoolB = School::create(['name' => 'School B']);
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

        $response = $this->actingAs($user)
            ->get(route('admin.live.index', ['school_id' => $schoolA->id]))
            ->assertOk();

        $sessions = $response->inertiaProps('sessions');
        $this->assertCount(1, $sessions);
        $this->assertSame('1001', $sessions[0]['admission_number']);
    }
}
