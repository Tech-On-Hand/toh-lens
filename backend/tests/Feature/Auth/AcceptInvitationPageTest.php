<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\School;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcceptInvitationPageTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-very-secret-invitation-token';

    private function makeInvitation(array $overrides = []): School
    {
        $organization = Organization::create(['name' => 'Test Org', 'slug' => 'test-org']);
        $school = School::create(['organization_id' => $organization->id, 'name' => 'School A']);

        StaffInvitation::create([
            'organization_id' => $organization->id,
            'school_id' => $school->id,
            'invited_by' => User::factory()->create()->id,
            'email' => 'newteacher@example.com',
            'role' => 'teacher',
            'token_hash' => hash('sha256', self::TOKEN),
            'expires_at' => now()->addDays(7),
            ...$overrides,
        ]);

        return $school;
    }

    private function accept(array $overrides = [])
    {
        return $this->post('/invitations/accept', [
            'token' => self::TOKEN,
            'name' => 'New Teacher',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
            ...$overrides,
        ]);
    }

    public function test_the_page_is_public_and_prefills_the_token_from_the_link(): void
    {
        $this->withoutVite();
        $this->get('/invitations/accept?token='.self::TOKEN)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/accept-invitation')->where('token', self::TOKEN));
    }

    public function test_a_teacher_can_redeem_a_token_and_is_sent_to_log_in(): void
    {
        $school = $this->makeInvitation();

        $this->accept()->assertRedirect(route('login'))->assertSessionHas('status');

        $user = User::firstWhere('email', 'newteacher@example.com');
        $this->assertTrue($user->schools()->whereKey($school->id)->wherePivot('role', 'teacher')->exists());
        $this->assertNotNull(StaffInvitation::first()->accepted_at);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('a-long-enough-password', $user->password));
    }

    public function test_a_used_or_unknown_token_is_rejected_with_a_message_on_the_token_field(): void
    {
        $this->makeInvitation(['accepted_at' => now()]);
        $this->accept()->assertSessionHasErrors('token');

        $this->accept(['token' => 'nope'])->assertSessionHasErrors('token');
        $this->assertSame(1, User::count()); // only the inviter
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $this->makeInvitation(['expires_at' => now()->subDay()]);

        $this->accept()->assertSessionHasErrors('token');
    }

    public function test_a_short_password_is_rejected_and_the_invitation_stays_usable(): void
    {
        $this->makeInvitation();

        $this->accept(['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->assertNull(StaffInvitation::first()->accepted_at);
    }
}
