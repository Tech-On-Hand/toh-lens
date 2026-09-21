<?php

namespace Tests\Concerns;

use App\Models\Classroom;
use App\Models\Computer;
use App\Models\LoginSession;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Str;

trait BuildsClassroomFixtures
{
    /** @return array{0: School, 1: Classroom} */
    protected function makeClassroom(string $schoolName = 'School A'): array
    {
        $school = School::create(['name' => $schoolName]);
        $classroom = Classroom::create(['school_id' => $school->id, 'uuid' => (string) Str::uuid(), 'name' => 'Lab 1']);

        return [$school, $classroom];
    }

    protected function makeDevice(School $school, Classroom $classroom, array $overrides = []): Computer
    {
        return Computer::create([
            'device_uuid' => (string) Str::uuid(),
            'school_id' => $school->id,
            'classroom_id' => $classroom->id,
            'name' => 'Lab PC '.Str::random(4),
            'role' => 'student',
            'presence_status' => 'online',
            'last_seen_at' => now(),
            ...$overrides,
        ]);
    }

    protected function signInStudent(Computer $device, string $admission = '1001'): LoginSession
    {
        $student = Student::firstOrCreate(
            ['school_id' => $device->school_id, 'admission_number' => $admission],
            ['full_name' => "Student {$admission}"],
        );

        return LoginSession::create([
            'uuid' => (string) Str::uuid(),
            'school_id' => $device->school_id,
            'computer_id' => $device->id,
            'classroom_id' => $device->classroom_id,
            'student_id' => $student->id,
            'admission_number' => $admission,
            'login_time' => now(),
            'status' => 'active',
        ]);
    }

    protected function deviceHeaders(Computer $device): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$device->createToken('device', ['device'])->plainTextToken];
    }

    protected function teacherHeaders(User $user): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('test', ['teacher'])->plainTextToken];
    }

    protected function makeTeacher(School $school, Classroom $classroom, string $role = 'primary_teacher'): User
    {
        $user = User::factory()->create();
        $user->schools()->attach($school, ['role' => 'teacher']);
        $classroom->users()->attach($user, ['role' => $role]);

        return $user;
    }
}
