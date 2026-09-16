<?php

namespace Tests\Feature\Api;

use App\Models\Computer;
use App\Models\LoginSession;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionSyncTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(Computer $computer): array
    {
        return ['Authorization' => 'Bearer '.$computer->createToken('test')->plainTextToken];
    }

    public function test_syncing_the_same_uuid_twice_does_not_duplicate_the_row(): void
    {
        $school = School::create(['name' => 'School A']);
        $computer = Computer::create(['school_id' => $school->id, 'name' => 'PC A1', 'role' => 'student']);
        $uuid = '11111111-1111-4111-8111-111111111111';

        $payload = [
            'sessions' => [
                ['uuid' => $uuid, 'admission_number' => '1001', 'login_time' => '2026-09-16T08:00:00Z', 'logout_time' => null],
            ],
        ];

        $this->withHeaders($this->authHeader($computer))->postJson('/api/sessions/sync', $payload)->assertOk();
        $this->withHeaders($this->authHeader($computer))->postJson('/api/sessions/sync', $payload)->assertOk();

        $this->assertSame(1, LoginSession::where('uuid', $uuid)->count());
    }

    public function test_a_later_sync_with_a_logout_time_updates_the_same_row(): void
    {
        $school = School::create(['name' => 'School A']);
        $computer = Computer::create(['school_id' => $school->id, 'name' => 'PC A1', 'role' => 'student']);
        $uuid = '22222222-2222-4222-8222-222222222222';

        $this->withHeaders($this->authHeader($computer))->postJson('/api/sessions/sync', [
            'sessions' => [
                ['uuid' => $uuid, 'admission_number' => '1001', 'login_time' => '2026-09-16T08:00:00Z', 'logout_time' => null],
            ],
        ])->assertOk();

        $this->withHeaders($this->authHeader($computer))->postJson('/api/sessions/sync', [
            'sessions' => [
                ['uuid' => $uuid, 'admission_number' => '1001', 'login_time' => '2026-09-16T08:00:00Z', 'logout_time' => '2026-09-16T08:45:00Z'],
            ],
        ])->assertOk();

        $this->assertSame(1, LoginSession::where('uuid', $uuid)->count());
        $this->assertNotNull(LoginSession::where('uuid', $uuid)->first()->logout_time);
    }

    public function test_synced_session_is_attributed_to_the_matching_student_scoped_by_school(): void
    {
        $schoolA = School::create(['name' => 'School A']);
        $schoolB = School::create(['name' => 'School B']);
        $studentA = Student::create(['school_id' => $schoolA->id, 'admission_number' => '1001', 'full_name' => 'Student A1']);
        Student::create(['school_id' => $schoolB->id, 'admission_number' => '1001', 'full_name' => 'Student B1 (different school, same admission number)']);
        $computerA = Computer::create(['school_id' => $schoolA->id, 'name' => 'PC A1', 'role' => 'student']);
        $uuid = '33333333-3333-4333-8333-333333333333';

        $this->withHeaders($this->authHeader($computerA))->postJson('/api/sessions/sync', [
            'sessions' => [
                ['uuid' => $uuid, 'admission_number' => '1001', 'login_time' => '2026-09-16T08:00:00Z', 'logout_time' => null],
            ],
        ])->assertOk();

        $session = LoginSession::where('uuid', $uuid)->first();
        $this->assertSame($schoolA->id, $session->school_id);
        $this->assertSame($studentA->id, $session->student_id);
    }
}
