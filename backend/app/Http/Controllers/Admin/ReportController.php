<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginSession;
use App\Models\SchoolClass;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /**
     * A per-class, per-period usage summary (SRS FR-5.3), computed entirely
     * from login_sessions. "Most-used application" is deliberately absent —
     * it requires LanSchool Air's activity export (SRS Phase 2/7), which
     * doesn't exist yet. Everything else here is real and available today.
     */
    public function index(Request $request): Response
    {
        $classId = $request->integer('class_id') ?: null;
        $from = Carbon::parse($request->input('from') ?: now()->startOfMonth()->toDateString())->startOfDay();
        $to = Carbon::parse($request->input('to') ?: now()->toDateString())->endOfDay();

        return Inertia::render('admin/reports/index', [
            'classes' => SchoolClass::query()
                ->with('school:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'school_id']),
            'filters' => [
                'class_id' => $classId,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'report' => $classId ? $this->buildReport($classId, $from, $to) : null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildReport(int $classId, Carbon $from, Carbon $to): ?array
    {
        $class = SchoolClass::with('school:id,name')->find($classId);

        if (! $class) {
            return null;
        }

        $students = Student::query()
            ->where('class_id', $classId)
            ->where('is_active', true)
            ->orderBy('full_name')
            ->get(['id', 'admission_number', 'full_name']);

        $sessions = LoginSession::query()
            ->whereIn('student_id', $students->pluck('id'))
            ->whereBetween('login_time', [$from, $to])
            ->get(['student_id', 'login_time', 'logout_time']);

        $activeStudentIds = $sessions->pluck('student_id')->filter()->unique();

        $completed = $sessions->whereNotNull('logout_time');
        $averageDurationMinutes = $completed->isEmpty()
            ? null
            : round($completed->avg(fn (LoginSession $session) => $session->login_time->diffInMinutes($session->logout_time)), 1);

        $noUsageStudents = $students->whereNotIn('id', $activeStudentIds)->values();

        return [
            'class' => [
                'id' => $class->id,
                'name' => $class->name,
                'school_name' => $class->school?->name,
            ],
            'total_students' => $students->count(),
            'active_students' => $activeStudentIds->count(),
            'average_duration_minutes' => $averageDurationMinutes,
            'total_sessions' => $sessions->count(),
            'no_usage_students' => $noUsageStudents->map(fn (Student $s) => [
                'admission_number' => $s->admission_number,
                'full_name' => $s->full_name,
            ])->all(),
        ];
    }
}
