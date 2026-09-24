<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SchoolClassController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $schoolIds = $user->administeredSchoolIds();

        return Inertia::render('admin/classes/index', [
            'classes' => SchoolClass::query()
                ->whereIn('school_id', $schoolIds)
                ->with(['school:id,name', 'teacher:id,name'])
                ->withCount(['students', 'computers'])
                ->orderBy('name')
                ->get(),
            'schools' => School::query()->whereIn('id', $schoolIds)->orderBy('name')->get(['id', 'name']),
            // Only staff already attached to one of these schools — not the
            // whole system's directory of names and emails.
            'teachers' => User::query()
                ->whereHas('schools', fn ($query) => $query->whereIn('schools.id', $schoolIds))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'school_id' => ['required', 'exists:schools,id'],
            'teacher_id' => ['nullable', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator((int) $data['school_id']), 403);

        SchoolClass::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Class created.']);

        return back();
    }

    public function destroy(Request $request, SchoolClass $class): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator($class->school_id), 403);

        $class->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Class deleted.']);

        return back();
    }
}
