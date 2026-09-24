<?php

namespace Tests\Feature\Admin;

use App\Models\Computer;
use App\Models\LoginSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAdminFixtures;
use Tests\TestCase;

class ComputerControllerTest extends TestCase
{
    use BuildsAdminFixtures, RefreshDatabase;

    public function test_a_school_administrator_can_view_and_create_computers_in_their_school(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);

        $this->actingAs($admin)
            ->get(route('admin.computers.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('admin.computers.store'), [
                'school_id' => $school->id,
                'name' => 'Lab PC 1',
                'role' => 'student',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('computers', ['name' => 'Lab PC 1', 'school_id' => $school->id]);
    }

    public function test_an_administrator_cannot_create_a_computer_in_a_school_they_do_not_run(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        $admin = $this->makeSchoolAdmin($mine);

        $this->actingAs($admin)
            ->post(route('admin.computers.store'), ['school_id' => $theirs->id, 'name' => 'Lab PC 1', 'role' => 'student'])
            ->assertForbidden();

        $this->assertDatabaseMissing('computers', ['school_id' => $theirs->id]);
    }

    public function test_the_index_is_scoped_to_the_administrators_own_schools(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        Computer::create(['school_id' => $mine->id, 'name' => 'Mine', 'role' => 'student']);
        Computer::create(['school_id' => $theirs->id, 'name' => 'Theirs', 'role' => 'student']);
        $admin = $this->makeSchoolAdmin($mine);

        $response = $this->actingAs($admin)->get(route('admin.computers.index'))->assertOk();

        $this->assertSame(['Mine'], array_column($response->inertiaProps('computers'), 'name'));
    }

    public function test_role_must_be_teacher_or_student(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);

        $this->actingAs($admin)
            ->post(route('admin.computers.store'), [
                'school_id' => $school->id,
                'name' => 'Lab PC 1',
                'role' => 'admin',
            ])
            ->assertSessionHasErrors('role');
    }

    public function test_a_class_id_from_another_school_is_refused(): void
    {
        $schoolA = $this->makeSchool(null, 'School A');
        $schoolB = $this->makeSchool(null, 'School B');
        $classInB = \App\Models\SchoolClass::create(['school_id' => $schoolB->id, 'name' => 'Grade 4 Blue']);
        $admin = $this->makeSchoolAdmin($schoolA);

        $this->actingAs($admin)
            ->post(route('admin.computers.store'), [
                'school_id' => $schoolA->id,
                'class_id' => $classInB->id,
                'name' => 'Lab PC 1',
                'role' => 'student',
            ])
            ->assertSessionHasErrors('class_id');
    }

    public function test_issuing_a_token_revokes_the_previous_one_and_flashes_the_new_plaintext_value_once(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $computer = Computer::create(['school_id' => $school->id, 'name' => 'Lab PC 1', 'role' => 'student']);
        $oldToken = $computer->createToken('kiosk-token')->plainTextToken;

        $response = $this->actingAs($admin)
            ->post(route('admin.computers.issue-token', $computer))
            ->assertRedirect();

        $response->assertInertiaFlash('issuedToken');
        $this->assertSame(1, $computer->tokens()->count());

        [$oldTokenId] = explode('|', $oldToken, 2);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldTokenId]);
    }

    public function test_an_administrator_cannot_issue_a_token_for_a_computer_in_a_school_they_do_not_run(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        $admin = $this->makeSchoolAdmin($mine);
        $computer = Computer::create(['school_id' => $theirs->id, 'name' => 'Lab PC 1', 'role' => 'student']);

        $this->actingAs($admin)->post(route('admin.computers.issue-token', $computer))->assertForbidden();
    }

    public function test_the_index_surfaces_last_session_and_token_activity_for_remote_diagnosis(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeSchoolAdmin($school);
        $computer = Computer::create(['school_id' => $school->id, 'name' => 'Lab PC 1', 'role' => 'student']);

        // A computer that has never connected shows nulls, not an error.
        $response = $this->actingAs($admin)->get(route('admin.computers.index'))->assertOk();
        $rows = $response->inertiaProps('computers');
        $this->assertNull($rows[0]['last_session_synced_at']);
        $this->assertNull($rows[0]['token_last_used_at']);

        $token = $computer->createToken('kiosk-token');
        $token->accessToken->forceFill(['last_used_at' => now()])->save();
        LoginSession::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'school_id' => $school->id,
            'computer_id' => $computer->id,
            'admission_number' => '1001',
            'login_time' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.computers.index'))->assertOk();
        $rows = $response->inertiaProps('computers');
        $this->assertNotNull($rows[0]['last_session_synced_at']);
        $this->assertNotNull($rows[0]['token_last_used_at']);
    }

    public function test_an_administrator_can_delete_a_computer_in_their_school_but_not_another(): void
    {
        $mine = $this->makeSchool(null, 'My School');
        $theirs = $this->makeSchool(null, 'Their School');
        $admin = $this->makeSchoolAdmin($mine);
        $myComputer = Computer::create(['school_id' => $mine->id, 'name' => 'Mine', 'role' => 'student']);
        $theirComputer = Computer::create(['school_id' => $theirs->id, 'name' => 'Theirs', 'role' => 'student']);

        $this->actingAs($admin)->delete(route('admin.computers.destroy', $theirComputer))->assertForbidden();
        $this->assertDatabaseHas('computers', ['id' => $theirComputer->id]);

        $this->actingAs($admin)
            ->delete(route('admin.computers.destroy', $myComputer))
            ->assertRedirect();

        $this->assertDatabaseMissing('computers', ['id' => $myComputer->id]);
    }
}
