<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
            // Either give the class a name, or a grade (and optionally a stream) and the name is built from them.
            'name' => ['nullable', 'string', 'max:255'],
            'grade' => ['nullable', 'string', 'max:50', 'required_with:stream'],
            'stream' => ['nullable', 'string', 'max:50'],
        ]);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator((int) $data['school_id']), 403);

        $grade = $this->clean($data['grade'] ?? null);
        $stream = $this->clean($data['stream'] ?? null);
        $name = $this->clean($data['name'] ?? null) ?? ($grade !== null ? SchoolClass::composeName($grade, $stream) : null);

        if ($name === null) {
            throw ValidationException::withMessages(['grade' => 'Enter a grade (and a stream, like a colour) or give the class a name.']);
        }

        // The CSV import finds a class by name, so two classes in one school must not share it.
        $taken = SchoolClass::query()->where('school_id', $data['school_id'])->whereRaw('LOWER(name) = ?', [Str::lower($name)])->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => "This school already has a class called \"{$name}\"."]);
        }

        SchoolClass::create([
            'school_id' => $data['school_id'],
            'teacher_id' => $data['teacher_id'] ?? null,
            'name' => $name,
            'grade' => $grade,
            'stream' => $stream,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Class \"{$name}\" created."]);

        return back();
    }

    /** Sets or changes a class's grade and stream (e.g. for classes made before they existed). The name is left alone. */
    public function update(Request $request, SchoolClass $class): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator($class->school_id), 403);

        $data = $request->validate([
            'grade' => ['nullable', 'string', 'max:50', 'required_with:stream'],
            'stream' => ['nullable', 'string', 'max:50'],
        ]);

        $class->update(['grade' => $this->clean($data['grade'] ?? null), 'stream' => $this->clean($data['stream'] ?? null)]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Class updated.']);

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

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
