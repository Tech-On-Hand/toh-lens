<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

    /**
     * Bulk-onboard a school's roster from a CSV (Phase 6 — the one-at-a-time
     * form above doesn't scale past a pilot classroom). Upserts by
     * (school_id, admission_number): a re-uploaded file with corrections
     * updates existing rows instead of duplicating them.
     *
     * Expected columns (header row required): admission_number, full_name,
     * class_name (optional — matched by name within the chosen school),
     * is_active (optional, defaults to true).
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'school_id' => ['required', 'exists:schools,id'],
            'csv' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $schoolId = (int) $request->input('school_id');
        $classesByName = SchoolClass::query()
            ->where('school_id', $schoolId)
            ->get()
            ->keyBy(fn (SchoolClass $c) => Str::lower($c->name));

        $handle = fopen($request->file('csv')->getRealPath(), 'r');
        $header = array_map(
            fn (string $h) => Str::of($h)->trim()->lower()->snake()->toString(),
            fgetcsv($handle) ?: [],
        );

        $created = 0;
        $updated = 0;
        $issues = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // blank line
            }

            // array_combine requires equal-length arrays — pad short rows,
            // truncate ragged long ones (extra trailing columns are ignored).
            $data = array_combine($header, array_slice(array_pad($row, count($header), null), 0, count($header)));

            $admissionNumber = trim((string) ($data['admission_number'] ?? ''));
            $fullName = trim((string) ($data['full_name'] ?? ''));

            if ($admissionNumber === '' || $fullName === '') {
                $issues[] = "Row {$rowNumber}: missing admission_number or full_name — skipped.";

                continue;
            }

            $className = trim((string) ($data['class_name'] ?? ''));
            $classMatch = $className !== '' ? $classesByName->get(Str::lower($className)) : null;
            if ($className !== '' && ! $classMatch) {
                $issues[] = "Row {$rowNumber}: class \"{$className}\" not found in this school — saved without a class.";
            }

            $isActive = true;
            if (($data['is_active'] ?? '') !== '' && $data['is_active'] !== null) {
                $isActive = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
            }

            $student = Student::query()
                ->where('school_id', $schoolId)
                ->where('admission_number', $admissionNumber)
                ->first();

            $attributes = ['full_name' => $fullName, 'is_active' => $isActive];
            // Only touch class_id when the column was actually provided —
            // an unrelated re-upload shouldn't silently unassign a class.
            if ($className !== '' && $classMatch) {
                $attributes['class_id'] = $classMatch->id;
            }

            if ($student) {
                $student->update($attributes);
                $updated++;
            } else {
                Student::create([
                    ...$attributes,
                    'school_id' => $schoolId,
                    'admission_number' => $admissionNumber,
                    'class_id' => $attributes['class_id'] ?? null,
                ]);
                $created++;
            }
        }
        fclose($handle);

        $summary = "Import complete: {$created} created, {$updated} updated";
        if (count($issues) > 0) {
            $summary .= ', '.count($issues).' issue(s) — see below.';
        }

        Inertia::flash('toast', ['type' => count($issues) > 0 ? 'warning' : 'success', 'message' => $summary]);
        Inertia::flash('importIssues', $issues);

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
