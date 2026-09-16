<?php

namespace Tests\Feature\Admin;

use App\Models\Computer;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComputerControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_can_view_and_create_computers(): void
    {
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);

        $this->actingAs($user)
            ->get(route('admin.computers.index'))
            ->assertOk();

        $this->actingAs($user)
            ->post(route('admin.computers.store'), [
                'school_id' => $school->id,
                'name' => 'Lab PC 1',
                'role' => 'student',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('computers', ['name' => 'Lab PC 1', 'school_id' => $school->id]);
    }

    public function test_role_must_be_teacher_or_student(): void
    {
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);

        $this->actingAs($user)
            ->post(route('admin.computers.store'), [
                'school_id' => $school->id,
                'name' => 'Lab PC 1',
                'role' => 'admin',
            ])
            ->assertSessionHasErrors('role');
    }

    public function test_issuing_a_token_revokes_the_previous_one_and_flashes_the_new_plaintext_value_once(): void
    {
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);
        $computer = Computer::create(['school_id' => $school->id, 'name' => 'Lab PC 1', 'role' => 'student']);
        $oldToken = $computer->createToken('kiosk-token')->plainTextToken;

        $response = $this->actingAs($user)
            ->post(route('admin.computers.issue-token', $computer))
            ->assertRedirect();

        $response->assertInertiaFlash('issuedToken');
        $this->assertSame(1, $computer->tokens()->count());

        [$oldTokenId] = explode('|', $oldToken, 2);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldTokenId]);
    }

    public function test_an_authenticated_user_can_delete_a_computer(): void
    {
        $user = User::factory()->create();
        $school = School::create(['name' => 'Demo Primary School']);
        $computer = Computer::create(['school_id' => $school->id, 'name' => 'Lab PC 1', 'role' => 'student']);

        $this->actingAs($user)
            ->delete(route('admin.computers.destroy', $computer))
            ->assertRedirect();

        $this->assertDatabaseMissing('computers', ['id' => $computer->id]);
    }
}
