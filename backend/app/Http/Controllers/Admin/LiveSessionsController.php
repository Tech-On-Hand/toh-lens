<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginSession;
use App\Models\School;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LiveSessionsController extends Controller
{
    /**
     * Every currently-open kiosk session (no logout_time yet), across all
     * schools/computers. There was previously no way to see "who's logged
     * in right now" anywhere — this is derived from existing data (an open
     * session is just one with a null logout_time), just not surfaced
     * until now.
     */
    public function index(Request $request): Response
    {
        $schoolId = $request->integer('school_id') ?: null;

        $sessions = LoginSession::query()
            ->whereNull('logout_time')
            ->when($schoolId, fn ($query) => $query->where('school_id', $schoolId))
            ->with([
                'school:id,name',
                'computer:id,name',
                'student:id,full_name,class_id',
                'student.schoolClass:id,name',
            ])
            ->orderBy('login_time')
            ->get()
            ->map(fn (LoginSession $session) => [
                'id' => $session->id,
                'admission_number' => $session->admission_number,
                'full_name' => $session->student?->full_name,
                'class_name' => $session->student?->schoolClass?->name,
                'school_name' => $session->school?->name,
                'computer_name' => $session->computer?->name,
                'login_time' => $session->login_time->toIso8601String(),
                'minutes_logged_in' => $session->login_time->diffInMinutes(now()),
            ]);

        return Inertia::render('admin/live/index', [
            'sessions' => $sessions,
            'schools' => School::query()->orderBy('name')->get(['id', 'name']),
            'filters' => ['school_id' => $schoolId],
        ]);
    }
}
