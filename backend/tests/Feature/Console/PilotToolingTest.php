<?php

namespace Tests\Feature\Console;

use App\Console\Commands\CheckReadiness;
use App\Models\Classroom;
use App\Models\DeviceEnrollmentCode;
use App\Models\Organization;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsClassroomFixtures;
use Tests\TestCase;

class PilotToolingTest extends TestCase
{
    use BuildsClassroomFixtures, RefreshDatabase;

    public function test_bootstrap_creates_the_school_classrooms_and_a_working_administrator(): void
    {
        $this->artisan('klas:bootstrap', [
            'organization' => 'Tech On Hand', 'school' => 'Pilot Primary', 'admin_email' => 'Head@Pilot.example',
            '--classroom' => ['Computer Lab', 'Room 2'], '--admin-name' => 'Head Teacher',
        ])->assertSuccessful()->expectsOutputToContain('password:');

        $school = School::firstWhere('name', 'Pilot Primary');
        $admin = User::firstWhere('email', 'head@pilot.example');
        $this->assertSame('tech-on-hand', Organization::first()->slug);
        $this->assertSame($school->organization_id, Organization::first()->id);
        $this->assertEqualsCanonicalizing(['Computer Lab', 'Room 2'], Classroom::pluck('name')->all());
        $this->assertTrue($admin->isSchoolAdministrator($school->id));
        $this->assertNotNull($admin->email_verified_at);
        $this->assertSame('Head Teacher', $admin->name);
    }

    public function test_the_administrator_can_sign_in_with_the_password_that_was_printed(): void
    {
        Artisan::call('klas:bootstrap', ['organization' => 'Org', 'school' => 'School', 'admin_email' => 'a@b.example']);
        preg_match('/password:\s+(\S+)/', Artisan::output(), $match);

        $this->assertTrue(Hash::check($match[1], User::firstWhere('email', 'a@b.example')->password));
        $this->postJson('/api/v1/auth/login', ['email' => 'a@b.example', 'password' => $match[1], 'device_name' => 'test'])->assertSuccessful();
    }

    public function test_running_bootstrap_again_reuses_everything_and_prints_no_new_password(): void
    {
        $args = ['organization' => 'Org', 'school' => 'School', 'admin_email' => 'a@b.example', '--classroom' => ['Lab']];
        $this->artisan('klas:bootstrap', $args)->assertSuccessful();
        $hash = User::first()->password;

        $this->artisan('klas:bootstrap', $args)->assertSuccessful()->doesntExpectOutputToContain('password:');

        $this->assertSame(1, Organization::where('slug', 'org')->count());
        $this->assertSame(1, School::count());
        $this->assertSame(1, Classroom::count());
        $this->assertSame(1, User::count());
        $this->assertSame($hash, User::first()->password);
    }

    public function test_bootstrap_makes_an_existing_teacher_an_administrator_of_the_school(): void
    {
        [$school, $classroom] = $this->makeClassroom('Pilot');
        $teacher = $this->makeTeacher($school, $classroom);

        $this->artisan('klas:bootstrap', ['organization' => 'Org', 'school' => 'Pilot', 'admin_email' => $teacher->email])->assertSuccessful();

        $this->assertSame(1, User::where('email', $teacher->email)->count());
    }

    public function test_bootstrap_rejects_a_bad_email(): void
    {
        $this->artisan('klas:bootstrap', ['organization' => 'Org', 'school' => 'School', 'admin_email' => 'not-an-email'])->assertFailed();
        $this->assertSame(0, School::count());
    }

    public function test_enrollment_codes_are_issued_in_bulk_and_each_works_on_the_real_enrollment_endpoint(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);

        Artisan::call('klas:enrollment-codes', ['classroom' => $classroom->id, 'count' => 3, '--as' => $admin->email, '--json' => true]);
        $issued = json_decode(Artisan::output(), true);

        $this->assertCount(3, $issued['codes']);
        $this->assertCount(3, array_unique($issued['codes']));
        $this->assertSame(3, DeviceEnrollmentCode::count());
        $this->assertTrue(now()->addHours(23)->lt($issued['expires_at']));

        $this->postJson('/api/v1/devices/enroll', [
            'code' => $issued['codes'][0], 'device_uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Lab PC 1',
            'hostname' => 'PC1', 'operating_system' => 'Windows x86_64', 'agent_version' => '0.1.0',
        ])->assertSuccessful();
    }

    public function test_only_a_school_administrator_can_issue_codes_and_counts_are_bounded(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $teacher = $this->makeTeacher($school, $classroom);
        $admin = User::factory()->create();
        $admin->schools()->attach($school, ['role' => 'administrator']);

        $this->artisan('klas:enrollment-codes', ['classroom' => $classroom->id, '--as' => $teacher->email])->assertFailed();
        $this->artisan('klas:enrollment-codes', ['classroom' => $classroom->id, 'count' => 500, '--as' => $admin->email])->assertFailed();
        $this->artisan('klas:enrollment-codes', ['classroom' => 9999, '--as' => $admin->email])->assertFailed();
        $this->assertSame(0, DeviceEnrollmentCode::count());
    }

    public function test_the_check_fails_when_the_scheduler_has_never_run_and_passes_once_it_has(): void
    {
        Cache::forget(CheckReadiness::SCHEDULER_KEY);
        $this->artisan('klas:check')->assertFailed()->expectsOutputToContain('has never run');

        Cache::put(CheckReadiness::SCHEDULER_KEY, now()->timestamp);
        $this->artisan('klas:check')->assertSuccessful();
    }

    public function test_the_check_flags_a_stale_scheduler_and_a_localhost_address(): void
    {
        Cache::put(CheckReadiness::SCHEDULER_KEY, now()->subMinutes(30)->timestamp);
        config(['app.url' => 'http://localhost:8000']);

        $this->artisan('klas:check')->assertFailed()->expectsOutputToContain('last ran')->expectsOutputToContain('cannot reach localhost');
    }

    public function test_the_scheduler_heartbeat_is_registered(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('klas-scheduler-heartbeat')->assertSuccessful();
    }
}
