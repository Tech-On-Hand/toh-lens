<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SchoolController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $organizationIds = $user->organizations()->wherePivot('role', 'administrator')->pluck('organizations.id');

        $schools = School::query()
            ->whereIn('id', $user->administeredSchoolIds())
            ->withCount(['classes', 'students', 'computers'])
            ->orderBy('name')
            ->get()
            ->map(fn (School $school) => [
                'id' => $school->id,
                'name' => $school->name,
                'classes_count' => $school->classes_count,
                'students_count' => $school->students_count,
                'computers_count' => $school->computers_count,
                // Deleting a school is organization-level (see store/destroy
                // below); a plain school administrator can manage everything
                // inside a school but not make the school itself disappear.
                'can_delete' => $school->organization_id !== null && $organizationIds->contains($school->organization_id),
            ]);

        return Inertia::render('admin/schools/index', [
            'schools' => $schools,
            // Only organizations this user can create a school under —
            // matches what store() below will actually allow.
            'organizations' => Organization::query()->whereIn('id', $organizationIds)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Creating a new school is an organization-level action — it needs an
     * organization to belong to, and only that organization's administrator
     * may add one. (The very first organization and its administrator are
     * set up outside the browser, via `php artisan klas:bootstrap`.) */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isOrganizationAdministrator((int) $data['organization_id']), 403);

        School::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'School created.']);

        return back();
    }

    /** Deleting a school is also organization-level — a school's own
     * administrator manages its classes/students/computers, not the school's
     * existence. */
    public function destroy(Request $request, School $school): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($school->organization_id !== null && $user->isOrganizationAdministrator($school->organization_id), 403);

        $school->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'School deleted.']);

        return back();
    }
}
