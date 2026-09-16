<?php

namespace Tests\Feature\Api;

use App\Models\Computer;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_computer_can_fetch_its_own_school_roster(): void
    {
        $school = School::create(['name' => 'School A']);
        Student::create(['school_id' => $school->id, 'admission_number' => '1001', 'full_name' => 'Student A1']);
        $computer = Computer::create(['school_id' => $school->id, 'name' => 'PC A1', 'role' => 'student']);
        $token = $computer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/roster');

        $response->assertOk();
        $response->assertJsonPath('school_id', $school->id);
        $response->assertJsonCount(1, 'students');
        $response->assertJsonPath('students.0.admission_number', '1001');
    }

    public function test_a_computer_cannot_see_another_schools_roster(): void
    {
        $schoolA = School::create(['name' => 'School A']);
        $schoolB = School::create(['name' => 'School B']);
        Student::create(['school_id' => $schoolA->id, 'admission_number' => '1001', 'full_name' => 'Student A1']);
        Student::create(['school_id' => $schoolB->id, 'admission_number' => '2001', 'full_name' => 'Student B1']);
        $computerA = Computer::create(['school_id' => $schoolA->id, 'name' => 'PC A1', 'role' => 'student']);
        $token = $computerA->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/roster');

        $response->assertOk();
        $response->assertJsonCount(1, 'students');
        $response->assertJsonPath('students.0.admission_number', '1001');
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/roster');

        $response->assertUnauthorized();
    }
}
