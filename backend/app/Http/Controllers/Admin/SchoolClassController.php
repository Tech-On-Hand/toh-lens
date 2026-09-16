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
    public function index(): Response
    {
        return Inertia::render('admin/classes/index', [
            'classes' => SchoolClass::query()
                ->with(['school:id,name', 'teacher:id,name'])
                ->withCount(['students', 'computers'])
                ->orderBy('name')
                ->get(),
            'schools' => School::query()->orderBy('name')->get(['id', 'name']),
            'teachers' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'school_id' => ['required', 'exists:schools,id'],
            'teacher_id' => ['nullable', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        SchoolClass::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Class created.']);

        return back();
    }

    public function destroy(SchoolClass $class): RedirectResponse
    {
        $class->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Class deleted.']);

        return back();
    }
}
