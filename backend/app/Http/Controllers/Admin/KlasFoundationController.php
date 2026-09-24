<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\DeviceEnrollmentCode;
use App\Models\School;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class KlasFoundationController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $organizationIds = $user->organizations()->wherePivot('role', 'administrator')->pluck('organizations.id');
        $schoolIds = $user->schools()->wherePivot('role', 'administrator')->pluck('schools.id');
        $schools = School::query()
            ->whereIn('organization_id', $organizationIds)
            ->orWhereIn('id', $schoolIds)
            ->with(['classrooms' => fn ($query) => $query->withCount('computers')->orderBy('name')])
            ->orderBy('name')->get();

        return Inertia::render('admin/klas/index', [
            'schools' => $schools,
            'staff' => User::query()
                ->whereHas('schools', fn ($query) => $query->whereIn('schools.id', $schools->pluck('id')))
                ->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function classroom(Request $request): RedirectResponse
    {
        $data = $request->validate(['school_id' => ['required', 'exists:schools,id'], 'name' => ['required', 'string', 'max:255']]);
        abort_unless($request->user()->isSchoolAdministrator((int) $data['school_id']), 403);
        Classroom::create([...$data, 'uuid' => (string) Str::uuid()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Classroom created.']);
        return back();
    }

    public function enrollmentCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['classroom_id' => ['required', 'exists:classrooms,id']]);
        $classroom = Classroom::findOrFail($data['classroom_id']);
        abort_unless($request->user()->isSchoolAdministrator($classroom->school_id), 403);
        $code = strtoupper(Str::random(4).'-'.Str::random(4));
        DeviceEnrollmentCode::create([
            'school_id' => $classroom->school_id,
            'classroom_id' => $classroom->id,
            'created_by' => $request->user()->id,
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes(30),
        ]);
        Inertia::flash('issuedEnrollmentCode', $code);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Enrollment code created. It expires in 30 minutes.']);
        return back();
    }

    public function invitation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'school_id' => ['required', 'exists:schools,id'],
            'email' => ['required', 'email'],
            'role' => ['required', 'in:school_administrator,teacher'],
        ]);
        $school = School::findOrFail($data['school_id']);
        // A school with no organization can't be invited into — passing null
        // straight through would mean "any organization administrator".
        abort_unless($school->organization_id !== null && $request->user()->isOrganizationAdministrator($school->organization_id), 403);
        $token = Str::random(64);
        StaffInvitation::create([
            'organization_id' => $school->organization_id,
            'school_id' => $school->id,
            'invited_by' => $request->user()->id,
            'email' => mb_strtolower($data['email']),
            'role' => $data['role'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);
        Inertia::flash('issuedInvitationToken', $token);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Invitation created. The token is shown once.']);
        return back();
    }

    public function assignStaff(Request $request, Classroom $classroom): RedirectResponse
    {
        abort_unless($request->user()->isSchoolAdministrator($classroom->school_id), 403);
        $data = $request->validate(['user_id' => ['required', 'exists:users,id'], 'role' => ['required', 'in:primary_teacher,assistant_teacher,observer']]);
        $staff = User::findOrFail($data['user_id']);
        abort_unless($staff->schools()->whereKey($classroom->school_id)->exists(), 422);
        $classroom->users()->syncWithoutDetaching([$staff->id => ['role' => $data['role']]]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Classroom role assigned.']);
        return back();
    }
}
