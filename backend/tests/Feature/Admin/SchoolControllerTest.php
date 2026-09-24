<?php

namespace Tests\Feature\Admin;

use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAdminFixtures;
use Tests\TestCase;

class SchoolControllerTest extends TestCase
{
    use BuildsAdminFixtures, RefreshDatabase;

    public function test_guests_cannot_access_the_admin_schools_page(): void
    {
        $this->get(route('admin.schools.index'))->assertRedirect(route('login'));
    }

    public function test_a_non_administrator_cannot_reach_the_admin_panel_at_all(): void
    {
        $this->actingAs($this->makeNonAdmin())->get(route('admin.schools.index'))->assertForbidden();
    }

    public function test_an_organization_administrator_can_view_and_create_schools_in_their_organization(): void
    {
        $organization = $this->makeOrganization();
        $admin = $this->makeOrganizationAdmin($organization);

        $this->actingAs($admin)
            ->get(route('admin.schools.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('admin.schools.store'), ['organization_id' => $organization->id, 'name' => 'Demo Primary School'])
            ->assertRedirect();

        $this->assertDatabaseHas('schools', ['organization_id' => $organization->id, 'name' => 'Demo Primary School']);
    }

    public function test_a_plain_school_administrator_cannot_create_a_new_school(): void
    {
        $organization = $this->makeOrganization();
        $school = $this->makeSchool($organization);
        $admin = $this->makeSchoolAdmin($school);

        $this->actingAs($admin)
            ->post(route('admin.schools.store'), ['organization_id' => $organization->id, 'name' => 'Another School'])
            ->assertForbidden();
    }

    public function test_an_administrator_of_a_different_organization_cannot_create_a_school_in_this_one(): void
    {
        $organization = $this->makeOrganization();
        $outsider = $this->makeOrganizationAdmin($this->makeOrganization('Someone Else'));

        $this->actingAs($outsider)
            ->post(route('admin.schools.store'), ['organization_id' => $organization->id, 'name' => 'Demo Primary School'])
            ->assertForbidden();
    }

    public function test_school_name_and_organization_are_required(): void
    {
        $organization = $this->makeOrganization();
        $admin = $this->makeOrganizationAdmin($organization);

        $this->actingAs($admin)
            ->post(route('admin.schools.store'), ['organization_id' => $organization->id, 'name' => ''])
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->post(route('admin.schools.store'), ['name' => 'Demo Primary School'])
            ->assertSessionHasErrors('organization_id');
    }

    public function test_an_organization_administrator_can_delete_their_own_school(): void
    {
        $organization = $this->makeOrganization();
        $school = $this->makeSchool($organization);
        $admin = $this->makeOrganizationAdmin($organization);

        $this->actingAs($admin)
            ->delete(route('admin.schools.destroy', $school))
            ->assertRedirect();

        $this->assertDatabaseMissing('schools', ['id' => $school->id]);
    }

    public function test_a_school_administrator_cannot_delete_the_school_they_administer(): void
    {
        $organization = $this->makeOrganization();
        $school = $this->makeSchool($organization);
        $admin = $this->makeSchoolAdmin($school);

        $this->actingAs($admin)
            ->delete(route('admin.schools.destroy', $school))
            ->assertForbidden();

        $this->assertDatabaseHas('schools', ['id' => $school->id]);
    }

    public function test_one_schools_administrator_cannot_see_or_delete_another_schools(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $schoolA = $this->makeSchool($orgA, 'School A');
        $schoolB = $this->makeSchool($orgB, 'School B');
        $adminA = $this->makeOrganizationAdmin($orgA);

        $response = $this->actingAs($adminA)->get(route('admin.schools.index'))->assertOk();
        $names = array_column($response->inertiaProps('schools'), 'name');
        $this->assertContains('School A', $names);
        $this->assertNotContains('School B', $names);

        $this->actingAs($adminA)->delete(route('admin.schools.destroy', $schoolB))->assertForbidden();
        $this->assertDatabaseHas('schools', ['id' => $schoolB->id]);
    }

    public function test_the_index_only_offers_organizations_this_user_administers_and_flags_deletable_schools(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $schoolA = $this->makeSchool($orgA, 'School A');
        $orphan = School::create(['name' => 'Orphan School']);
        $admin = $this->makeOrganizationAdmin($orgA);
        $orphan->users()->attach($admin, ['role' => 'administrator']); // a direct school admin, not via any org
        $this->makeOrganizationAdmin($orgB); // an unrelated org admin should not affect this user's list

        $response = $this->actingAs($admin)->get(route('admin.schools.index'))->assertOk();

        $this->assertSame(['Org A'], array_column($response->inertiaProps('organizations'), 'name'));

        $schools = collect($response->inertiaProps('schools'))->keyBy('name');
        $this->assertTrue($schools['School A']['can_delete']);
        // A direct school-admin pivot (no organization) is not enough to delete the school itself.
        $this->assertFalse($schools['Orphan School']['can_delete']);
    }

    public function test_an_organization_administrator_of_any_organization_cannot_manage_a_school_with_no_organization(): void
    {
        $orphan = School::create(['name' => 'Orphan School']);
        $someOrgAdmin = $this->makeOrganizationAdmin($this->makeOrganization());

        // Regression: isOrganizationAdministrator(null) used to mean "any organization admin".
        $this->actingAs($someOrgAdmin)->delete(route('admin.schools.destroy', $orphan))->assertForbidden();
        $this->assertDatabaseHas('schools', ['id' => $orphan->id]);
    }
}
