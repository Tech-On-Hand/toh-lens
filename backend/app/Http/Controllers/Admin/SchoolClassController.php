<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\KenyaCbc;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            // The Kenya CBC grades the "Seed" panel offers, and which levels start ticked.
            'cbcLevels' => collect(KenyaCbc::levels())->map(fn (array $level, string $key) => [
                'key' => $key,
                'label' => $level['label'],
                'grades' => $level['grades'],
                'default' => in_array($key, KenyaCbc::DEFAULT_LEVELS, true),
            ])->values(),
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

    /**
     * Creates the standard Kenya CBC classes (PP1 .. Grade 12, by the levels
     * ticked) for a school in one go, optionally one per stream (a colour, say)
     * within each grade. Safe to repeat: a class whose name the school already
     * has is skipped and never changed.
     */
    public function seedCbc(Request $request): RedirectResponse
    {
        $levels = KenyaCbc::levels();
        $data = $request->validate([
            'school_id' => ['required', 'exists:schools,id'],
            'levels' => ['required', 'array', 'min:1'],
            'levels.*' => ['string', Rule::in(array_keys($levels))],
            'streams' => ['nullable', 'string', 'max:500'],
        ], ['levels.required' => 'Tick at least one level.', 'levels.min' => 'Tick at least one level.']);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator((int) $data['school_id']), 403);

        $streams = KenyaCbc::parseStreams($data['streams'] ?? null);
        if (count($streams) > KenyaCbc::MAX_STREAMS) {
            throw ValidationException::withMessages(['streams' => 'Use at most '.KenyaCbc::MAX_STREAMS.' streams.']);
        }
        foreach ($streams as $stream) {
            if (mb_strlen($stream) > 50) {
                throw ValidationException::withMessages(['streams' => "\"{$stream}\" is too long for a stream name (50 characters at most)."]);
            }
        }

        $existing = SchoolClass::query()->where('school_id', $data['school_id'])->pluck('name')->map(fn (string $n) => Str::lower($n))->flip();

        $created = 0;
        $skipped = 0;
        foreach (array_keys($levels) as $key) {
            if (! in_array($key, $data['levels'], true)) {
                continue;
            }
            foreach ($levels[$key]['grades'] as $grade) {
                foreach ($streams ?: [null] as $stream) {
                    $name = SchoolClass::composeName($grade, $stream);
                    if ($existing->has(Str::lower($name))) {
                        $skipped++;

                        continue;
                    }

                    SchoolClass::create(['school_id' => $data['school_id'], 'name' => $name, 'grade' => $grade, 'stream' => $stream]);
                    $created++;
                }
            }
        }

        $message = "{$created} class(es) created";
        if ($skipped > 0) {
            $message .= ", {$skipped} already existed and were left as they are";
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message.'.']);

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
