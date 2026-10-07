<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginSession;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /**
     * A per-class, or per-grade, per-period usage summary (SRS FR-5.3),
     * computed entirely from login_sessions. A grade report adds up every
     * class (stream) of that grade in one school and breaks the numbers down
     * by class. "Most-used application" is deliberately absent — it requires
     * LanSchool Air's activity export (SRS Phase 2/7), which doesn't exist
     * yet. Everything else here is real and available today.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $schoolIds = $user->administeredSchoolIds();

        $classId = $request->integer('class_id') ?: null;
        $gradeSchoolId = $request->integer('school_id') ?: null;
        $grade = trim((string) $request->input('grade')) ?: null;
        $from = Carbon::parse($request->input('from') ?: now()->startOfMonth()->toDateString())->startOfDay();
        $to = Carbon::parse($request->input('to') ?: now()->toDateString())->endOfDay();

        $classes = SchoolClass::query()
            ->whereIn('school_id', $schoolIds)
            ->with('school:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'school_id', 'grade', 'stream']);

        return Inertia::render('admin/reports/index', [
            'classes' => $classes,
            // One entry per grade per school, so a whole grade can be picked as one report.
            'grades' => $classes->filter(fn (SchoolClass $c) => $c->grade !== null)
                ->groupBy(fn (SchoolClass $c) => $c->school_id.'|'.Str::lower($c->grade))
                ->map(fn (Collection $group) => [
                    'school_id' => $group->first()->school_id,
                    'school_name' => $group->first()->school?->name,
                    'grade' => $group->first()->grade,
                    'classes_count' => $group->count(),
                ])
                ->sortBy(fn (array $g) => [$g['school_name'], $g['grade']])
                ->values(),
            'filters' => [
                'class_id' => $classId,
                'school_id' => $gradeSchoolId,
                'grade' => $grade,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'report' => $this->buildReport($classId, $gradeSchoolId, $grade, $schoolIds, $from, $to),
        ]);
    }

    /**
     * @param  Collection<int, int>  $schoolIds
     * @return array<string, mixed>|null
     */
    private function buildReport(?int $classId, ?int $gradeSchoolId, ?string $grade, Collection $schoolIds, Carbon $from, Carbon $to): ?array
    {
        if ($classId) {
            $class = SchoolClass::with('school:id,name')->find($classId);
            if (! $class || ! $schoolIds->contains($class->school_id)) {
                return null;
            }

            $classes = collect([$class]);
            $title = ['id' => $class->id, 'name' => $class->name, 'school_name' => $class->school?->name];
            $scope = 'class';
        } elseif ($grade !== null && $gradeSchoolId && $schoolIds->contains($gradeSchoolId)) {
            $classes = SchoolClass::with('school:id,name')
                ->where('school_id', $gradeSchoolId)
                ->whereRaw('LOWER(grade) = ?', [Str::lower($grade)])
                ->orderBy('stream')->orderBy('name')
                ->get();
            if ($classes->isEmpty()) {
                return null;
            }

            $title = ['id' => null, 'name' => $classes->first()->grade.' — all classes', 'school_name' => $classes->first()->school?->name];
            $scope = 'grade';
        } else {
            return null;
        }

        $students = Student::query()
            ->whereIn('class_id', $classes->pluck('id'))
            ->where('is_active', true)
            ->orderBy('full_name')
            ->get(['id', 'class_id', 'admission_number', 'full_name']);

        $sessions = LoginSession::query()
            ->whereIn('student_id', $students->pluck('id'))
            ->whereBetween('login_time', [$from, $to])
            ->get(['student_id', 'login_time', 'logout_time']);

        $totals = $this->summarise($students, $sessions);
        $activeStudentIds = $sessions->pluck('student_id')->filter()->unique();

        return [
            'scope' => $scope,
            'class' => $title,
            ...$totals,
            // Only a grade report is split up; for one class it would repeat the totals.
            'breakdown' => $scope === 'grade'
                ? $classes->map(fn (SchoolClass $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'stream' => $c->stream,
                    ...$this->summarise(
                        $students->where('class_id', $c->id),
                        $sessions->whereIn('student_id', $students->where('class_id', $c->id)->pluck('id')),
                    ),
                ])->values()->all()
                : [],
            'no_usage_students' => $students->whereNotIn('id', $activeStudentIds)->values()->map(fn (Student $s) => [
                'admission_number' => $s->admission_number,
                'full_name' => $s->full_name,
            ])->all(),
        ];
    }

    /**
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, LoginSession>  $sessions
     * @return array{total_students: int, active_students: int, average_duration_minutes: float|null, total_sessions: int}
     */
    private function summarise(Collection $students, Collection $sessions): array
    {
        $completed = $sessions->whereNotNull('logout_time');

        return [
            'total_students' => $students->count(),
            'active_students' => $sessions->pluck('student_id')->filter()->unique()->count(),
            'average_duration_minutes' => $completed->isEmpty()
                ? null
                : round($completed->avg(fn (LoginSession $session) => $session->login_time->diffInMinutes($session->logout_time)), 1),
            'total_sessions' => $sessions->count(),
        ];
    }
}
