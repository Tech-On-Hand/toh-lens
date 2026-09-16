<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function index(Request $request): Response
    {
        $schoolId = $request->integer('school_id') ?: null;

        $students = Student::query()
            ->with(['school:id,name', 'schoolClass:id,name'])
            ->when($schoolId, fn ($query) => $query->where('school_id', $schoolId))
            ->orderBy('full_name')
            ->get();

        return Inertia::render('admin/students/index', [
            'students' => $students,
            'schools' => School::query()->orderBy('name')->get(['id', 'name']),
            'classes' => SchoolClass::query()->orderBy('name')->get(['id', 'name', 'school_id']),
            'filters' => ['school_id' => $schoolId],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        Student::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Student created.']);

        return back();
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $data = $this->validated($request, $student);

        $student->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Student updated.']);

        return back();
    }

    public function destroy(Student $student): RedirectResponse
    {
        $student->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Student deleted.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Student $student = null): array
    {
        $data = $request->validate([
            'school_id' => ['required', 'exists:schools,id'],
            'class_id' => ['nullable', 'exists:classes,id'],
            'admission_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('students')
                    ->where(fn ($query) => $query->where('school_id', $request->input('school_id')))
                    ->ignore($student?->id),
            ],
            'full_name' => ['required', 'string', 'max:255'],
        ]);

        // The edit form sends a hidden "0" alongside the checkbox so the
        // field is always present when submitted from there; the create
        // form has no checkbox at all, so fall back to active-by-default.
        $data['is_active'] = $request->has('is_active')
            ? $request->boolean('is_active')
            : ($student?->is_active ?? true);

        return $data;
    }
}
