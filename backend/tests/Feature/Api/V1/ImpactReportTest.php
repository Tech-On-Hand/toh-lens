<?php

namespace Tests\Feature\Api\V1;

use App\Models\AppActivity;
use App\Models\BrowserActivity;
use App\Models\Computer;
use App\Models\DeviceActivityDay;
use App\Models\LoginSession;
use App\Models\Organization;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsClassroomFixtures;
use Tests\TestCase;

class ImpactReportTest extends TestCase
{
    use BuildsClassroomFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 15:00:00')); // a Wednesday
    }

    private function admin(School $school): User
    {
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);

        return $admin;
    }

    private function impact(User $user, array $query): TestResponse
    {
        return $this->withHeaders($this->teacherHeaders($user))->getJson('/api/v1/admin/impact?'.http_build_query($query));
    }

    private function student(School $school, int $number): Student
    {
        return Student::firstOrCreate(['school_id' => $school->id, 'admission_number' => (string) $number], ['full_name' => "Secret Name {$number}"]);
    }

    /** A finished session of $hours starting at $start on $device for $student. */
    private function sitting(Computer $device, Student $student, string $start, float $hours = 1.0, bool $closed = true): LoginSession
    {
        $login = Carbon::parse($start);

        return LoginSession::create([
            'uuid' => (string) Str::uuid(), 'school_id' => $device->school_id, 'computer_id' => $device->id, 'classroom_id' => $device->classroom_id,
            'student_id' => $student->id, 'admission_number' => $student->admission_number, 'login_time' => $login,
            'logout_time' => $closed ? $login->copy()->addSeconds((int) ($hours * 3600)) : null, 'status' => $closed ? 'ended' : 'active',
        ]);
    }

    private function interval(LoginSession $session, string $process, int $minutes, bool $idle = false): void
    {
        AppActivity::create([
            'uuid' => (string) Str::uuid(), 'school_id' => $session->school_id, 'computer_id' => $session->computer_id, 'login_session_id' => $session->id,
            'student_id' => $session->student_id, 'process' => $process, 'is_idle' => $idle,
            'started_at' => $session->login_time, 'ended_at' => $session->login_time->copy()->addMinutes($minutes),
        ]);
    }

    private const SEPTEMBER = ['from' => '2026-09-01', 'to' => '2026-09-30'];

    public function test_it_totals_computers_students_and_hours_for_a_school(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $a = $this->makeDevice($school, $classroom, ['enrolled_at' => '2026-08-01']);
        $b = $this->makeDevice($school, $classroom, ['enrolled_at' => '2026-08-01']);
        $this->makeDevice($school, $classroom, ['enrolled_at' => '2026-08-01']); // never used
        foreach (range(1, 10) as $n) {
            $this->student($school, $n);
        }
        $this->sitting($a, $this->student($school, 1), '2026-09-02 09:00', 2);
        $this->sitting($a, $this->student($school, 2), '2026-09-03 09:00', 1);
        $this->sitting($b, $this->student($school, 3), '2026-09-10 09:00', 3);

        $data = $this->impact($this->admin($school), ['school_id' => $school->id, ...self::SEPTEMBER])->assertOk()->json('data');

        $this->assertSame(['enrolled' => 3, 'used' => 2, 'never_used' => 1, 'out_of_service' => 0], $data['computers']);
        $this->assertSame(['reached' => 3, 'on_roster' => 10, 'reach_percent' => 30], $data['students']);
        $this->assertSame(3, $data['usage']['sessions']);
        $this->assertEquals(6.0, $data['usage']['signed_in_hours']);
        $this->assertSame(120, $data['usage']['average_session_minutes']);
        $this->assertSame(3, $data['usage']['days_with_use']);
        $this->assertSame(22, $data['period']['school_days']);
        $this->assertSame('school', $data['scope']['type']);
    }

    public function test_the_report_names_no_student(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $this->sitting($device, $this->student($school, 1), '2026-09-02 09:00');

        $body = $this->impact($this->admin($school), ['school_id' => $school->id, ...self::SEPTEMBER])->assertOk()->getContent();

        $this->assertStringNotContainsString('Secret Name', $body);
        $this->assertStringNotContainsString('student_id', $body);
        $this->assertStringNotContainsString('admission', $body);
    }

    public function test_weekly_figures_cover_every_week_including_quiet_ones(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $this->sitting($device, $this->student($school, 1), '2026-09-02 09:00', 1);
        $this->sitting($device, $this->student($school, 2), '2026-09-22 09:00', 2);

        $weeks = $this->impact($this->admin($school), ['school_id' => $school->id, ...self::SEPTEMBER])->json('data.weekly');

        $this->assertSame(['2026-08-31', '2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28'], array_column($weeks, 'week_start'));
        $this->assertSame([1, 0, 0, 1, 0], array_column($weeks, 'sessions'));
        $this->assertEquals(2.0, $weeks[3]['hours']);
    }

    public function test_an_application_or_site_is_only_listed_when_five_students_used_it(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);

        foreach (range(1, 6) as $n) {
            $session = $this->sitting($device, $this->student($school, $n), "2026-09-0{$n} 09:00", 1);
            $this->interval($session, 'WINWORD.EXE', 30);
            if ($n <= 2) {
                $this->interval($session, 'scratch.exe', 20); // only two students
            }
            $this->interval($session, 'explorer.exe', 10); // the desktop is not a tool
            foreach ([['khan.example', $n <= 6], ['games.example', $n <= 2]] as [$domain, $visit]) {
                if ($visit) {
                    BrowserActivity::create(['uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'computer_id' => $device->id, 'login_session_id' => $session->id,
                        'student_id' => $session->student_id, 'browser' => 'chrome', 'event_type' => 'navigated', 'domain' => $domain, 'occurred_at' => $session->login_time]);
                }
            }
        }

        $tools = $this->impact($this->admin($school), ['school_id' => $school->id, ...self::SEPTEMBER])->json('data.tools');

        $this->assertSame(5, $tools['min_students']);
        $this->assertSame(['Word'], array_column($tools['apps'], 'name'));
        $this->assertEquals(3.0, $tools['apps'][0]['hours']);
        $this->assertSame(['khan.example'], array_column($tools['sites'], 'domain'));
    }

    public function test_active_and_idle_hours_come_from_application_activity(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $session = $this->sitting($device, $this->student($school, 1), '2026-09-02 09:00', 1);
        $this->interval($session, 'WINWORD.EXE', 45);
        $this->interval($session, 'WINWORD.EXE', 15, true);

        $usage = $this->impact($this->admin($school), ['school_id' => $school->id, ...self::SEPTEMBER])->json('data.usage');

        $this->assertEquals(0.8, $usage['active_hours']);
        $this->assertEquals(0.3, $usage['idle_hours']);
    }

    public function test_a_forgotten_session_is_capped_and_a_long_dead_one_is_not_guessed_at(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $this->sitting($device, $this->student($school, 1), '2026-09-02 09:00', 30);              // logged out a day and a half later
        $this->sitting($device, $this->student($school, 2), '2026-09-03 09:00', 1, closed: false); // never closed, long gone
        $this->sitting($device, $this->student($school, 3), '2026-09-30 13:00', 1, closed: false); // still going: 2 hours so far

        $data = $this->impact($this->admin($school), ['school_id' => $school->id, ...self::SEPTEMBER])->json('data');

        $this->assertEquals(10.0, $data['usage']['signed_in_hours']); // 8 (capped) + 0 + 2
        $this->assertNotEmpty($data['notes']);
    }

    public function test_availability_compares_used_days_with_days_the_computer_was_on_ignoring_weekends(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        foreach (['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-12'] as $day) { // Mon-Thu and a Saturday
            DeviceActivityDay::create(['computer_id' => $device->id, 'day' => $day]);
        }
        $this->sitting($device, $this->student($school, 1), '2026-09-07 09:00');
        $this->sitting($device, $this->student($school, 2), '2026-09-08 09:00');
        $this->sitting($device, $this->student($school, 3), '2026-09-12 09:00'); // Saturday: not counted

        $availability = $this->impact($this->admin($school), ['school_id' => $school->id, ...self::SEPTEMBER])->json('data.availability');

        $this->assertSame(['computer_days_on' => 4, 'computer_days_used' => 2, 'used_percent' => 50], $availability);
    }

    public function test_availability_is_left_out_when_there_is_no_history_yet(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $this->sitting($device, $this->student($school, 1), '2026-09-07 09:00');

        $availability = $this->impact($this->admin($school), ['school_id' => $school->id, ...self::SEPTEMBER])->json('data.availability');

        $this->assertNull($availability['used_percent']);
    }

    public function test_computers_not_heard_from_for_two_weeks_are_counted_as_out_of_service(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $this->makeDevice($school, $classroom, ['enrolled_at' => '2026-08-01', 'last_seen_at' => '2026-09-01']);
        $this->makeDevice($school, $classroom, ['enrolled_at' => '2026-08-01', 'last_seen_at' => '2026-09-29']);

        $computers = $this->impact($this->admin($school), ['school_id' => $school->id, ...self::SEPTEMBER])->json('data.computers');

        $this->assertSame(1, $computers['out_of_service']);
    }

    public function test_a_computer_enrolled_less_than_a_week_ago_is_not_called_unused(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $this->makeDevice($school, $classroom, ['enrolled_at' => '2026-09-28']);

        $this->assertSame(0, $this->impact($this->admin($school), ['school_id' => $school->id, ...self::SEPTEMBER])->json('data.computers.never_used'));
    }

    public function test_an_organization_report_adds_up_its_schools_and_breaks_them_down(): void
    {
        $organization = Organization::create(['name' => 'Donor Org', 'slug' => 'donor-org']);
        [$one, $roomOne] = $this->makeClassroom('School One');
        [$two, $roomTwo] = $this->makeClassroom('School Two');
        $one->update(['organization_id' => $organization->id]);
        $two->update(['organization_id' => $organization->id]);
        $this->sitting($this->makeDevice($one, $roomOne), $this->student($one, 1), '2026-09-02 09:00', 2);
        $this->sitting($this->makeDevice($two, $roomTwo), $this->student($two, 1), '2026-09-02 09:00', 3);

        $head = User::factory()->create();
        $head->organizations()->attach($organization, ['role' => 'administrator']);

        $data = $this->impact($head, ['organization_id' => $organization->id, ...self::SEPTEMBER])->assertOk()->json('data');

        $this->assertSame('organization', $data['scope']['type']);
        $this->assertSame(2, $data['usage']['sessions']);
        $this->assertEquals(5.0, $data['usage']['signed_in_hours']);
        $this->assertSame(['School One', 'School Two'], array_column($data['schools'], 'name'));
        $this->assertEquals([2.0, 3.0], array_column($data['schools'], 'hours'));
    }

    public function test_a_school_administrator_sees_only_their_own_school_and_never_the_organization(): void
    {
        $organization = Organization::create(['name' => 'Donor Org', 'slug' => 'donor-org']);
        [$one] = $this->makeClassroom('School One');
        [$two] = $this->makeClassroom('School Two');
        $one->update(['organization_id' => $organization->id]);
        $two->update(['organization_id' => $organization->id]);
        $adminOne = $this->admin($one);

        $this->impact($adminOne, ['school_id' => $one->id, ...self::SEPTEMBER])->assertOk();
        $this->impact($adminOne, ['school_id' => $two->id, ...self::SEPTEMBER])->assertForbidden();
        $this->impact($adminOne, ['organization_id' => $organization->id, ...self::SEPTEMBER])->assertForbidden();
    }

    public function test_a_teacher_cannot_read_the_impact_report(): void
    {
        [$school, $classroom] = $this->makeClassroom();

        $this->impact($this->makeTeacher($school, $classroom), ['school_id' => $school->id])->assertForbidden();
    }

    public function test_it_needs_a_scope_and_a_sensible_range(): void
    {
        [$school] = $this->makeClassroom();
        $admin = $this->admin($school);

        $this->impact($admin, [])->assertStatus(422);
        $this->impact($admin, ['school_id' => $school->id, 'from' => '2024-01-01', 'to' => '2026-09-30'])->assertStatus(422)->assertJsonPath('error.code', 'RANGE_TOO_LONG');
        $this->impact($admin, ['school_id' => $school->id])->assertOk()->assertJsonPath('data.period.to', '2026-09-30')->assertJsonPath('data.period.from', '2026-09-01');
    }

    public function test_a_heartbeat_records_the_day_once_however_often_the_computer_reports(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom, ['last_seen_at' => null]);
        $beat = fn () => $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/heartbeat', ['agent_version' => '0.1.0'])->assertOk();

        $beat();
        $beat();
        $this->assertSame(1, DeviceActivityDay::where('computer_id', $device->id)->count());

        $this->travelTo(Carbon::parse('2026-10-01 09:00:00'));
        $beat();
        $this->assertSame(['2026-09-30', '2026-10-01'], DeviceActivityDay::where('computer_id', $device->id)->orderBy('day')->get()->map(fn ($d) => $d->day->toDateString())->all());
    }
}
