<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
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
        /** @var User $user */
        $user = $request->user();
        $schoolIds = $user->administeredSchoolIds();

        $schoolId = $request->integer('school_id') ?: null;
        abort_if($schoolId && ! $schoolIds->contains($schoolId), 403);

        $students = Student::query()
            ->whereIn('school_id', $schoolIds)
            ->with(['school:id,name', 'schoolClass:id,name'])
            ->when($schoolId, fn ($query) => $query->where('school_id', $schoolId))
            ->orderBy('full_name')
            ->get();

        return Inertia::render('admin/students/index', [
            'students' => $students,
            'schools' => School::query()->whereIn('id', $schoolIds)->orderBy('name')->get(['id', 'name']),
            'classes' => SchoolClass::query()->whereIn('school_id', $schoolIds)->orderBy('name')->get(['id', 'name', 'school_id']),
            'filters' => ['school_id' => $schoolId],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator((int) $request->input('school_id')), 403);

        $data = $this->validated($request);

        Student::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Student created.']);

        return back();
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator($student->school_id), 403);
        // The form always submits the student's own school_id (the field
        // isn't editable), but check it matches in case that ever changes —
        // moving a student to a school this admin doesn't run is not a plain edit.
        abort_unless((int) $request->input('school_id') === $student->school_id, 403);

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
     * then optionally grade and stream (a class is named "<grade> <stream>", e.g.
     * "Grade 4 Blue", and is created if the school does not have it yet), or
     * class_name (an existing class, matched by name within the chosen school),
     * and is_active (defaults to true).
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'school_id' => ['required', 'exists:schools,id'],
            'csv' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $schoolId = (int) $request->input('school_id');

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator($schoolId), 403);

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
        $classesCreated = 0;
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
            $grade = trim((string) ($data['grade'] ?? ''));
            $stream = trim((string) ($data['stream'] ?? ''));
            if ($className === '' && $grade !== '') {
                $className = SchoolClass::composeName($grade, $stream);
            }
            if ($grade === '' && $stream !== '' && $className === '') {
                $issues[] = "Row {$rowNumber}: a stream (\"{$stream}\") needs a grade — saved without a class.";
            }

            $classMatch = $className !== '' ? $classesByName->get(Str::lower($className)) : null;
            if ($className !== '' && ! $classMatch) {
                if ($grade !== '') {
                    $classMatch = SchoolClass::create([
                        'school_id' => $schoolId,
                        'name' => $className,
                        'grade' => $grade,
                        'stream' => $stream !== '' ? $stream : null,
                    ]);
                    $classesByName->put(Str::lower($className), $classMatch);
                    $classesCreated++;
                } else {
                    $issues[] = "Row {$rowNumber}: class \"{$className}\" not found in this school — saved without a class.";
                }
            } elseif ($classMatch && $grade !== '' && $classMatch->grade === null) {
                // A class made before grades existed: fill in what the file knows, never overwrite.
                $classMatch->update(['grade' => $grade, 'stream' => $classMatch->stream ?? ($stream !== '' ? $stream : null)]);
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
        if ($classesCreated > 0) {
            $summary .= ", {$classesCreated} class(es) created";
        }
        if (count($issues) > 0) {
            $summary .= ', '.count($issues).' issue(s) — see below.';
        }

        Inertia::flash('toast', ['type' => count($issues) > 0 ? 'warning' : 'success', 'message' => $summary]);
        Inertia::flash('importIssues', $issues);

        return back();
    }

    public function destroy(Request $request, Student $student): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator($student->school_id), 403);

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
            'class_id' => [
                'nullable',
                Rule::exists('classes', 'id')->where('school_id', $request->input('school_id')),
            ],
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
