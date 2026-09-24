<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AppActivity;
use App\Models\BrowserActivity;
use App\Models\Classroom;
use App\Models\LoginSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TeacherReportController extends ApiController
{
    private const MAX_DAYS = 31;
    private const TOP = 5;

    /**
     * What each student did in this classroom over a date range: how long they were
     * signed in, how much of that was active versus idle, the desktop applications
     * they used (by process name) and the sites they visited. Dates are read in the
     * server's timezone.
     */
    public function activity(Request $request, Classroom $classroom): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canViewClassroom($classroom), 403);

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = Carbon::parse($data['from'] ?? now()->toDateString())->startOfDay();
        $to = Carbon::parse($data['to'] ?? $from->toDateString())->endOfDay();
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            return $this->error('RANGE_TOO_LONG', 'Choose a range of at most '.self::MAX_DAYS.' days.', 422);
        }

        $sessions = LoginSession::query()
            ->with(['student:id,full_name,admission_number', 'computer:id,name'])
            ->where('classroom_id', $classroom->id)
            ->whereBetween('login_time', [$from, $to])
            ->orderBy('login_time')
            ->get();

        $ids = $sessions->pluck('id');
        $apps = AppActivity::query()->whereIn('login_session_id', $ids)->get()->groupBy('login_session_id');
        $browsing = BrowserActivity::query()->whereIn('login_session_id', $ids)->whereNotNull('domain')->get()->groupBy('login_session_id');

        $rows = $sessions->map(function (LoginSession $session) use ($apps, $browsing) {
            $end = $session->logout_time ?? now();
            $intervals = $apps->get($session->id, collect());
            $events = $browsing->get($session->id, collect());

            return [
                'session_uuid' => $session->uuid,
                'student' => $session->student ? ['id' => $session->student->id, 'name' => $session->student->full_name] : null,
                'device' => $session->computer?->name,
                'login_time' => $session->login_time->toIso8601String(),
                'logout_time' => $session->logout_time?->toIso8601String(),
                'signed_in_minutes' => $this->minutes($session->login_time->diffInSeconds($end)),
                'active_minutes' => $this->minutes($this->seconds($intervals->where('is_idle', false))),
                'idle_minutes' => $this->minutes($this->seconds($intervals->where('is_idle', true))),
                'apps' => $this->topApps($intervals),
                'sites' => $this->topSites($events),
                'blocked_attempts' => $events->where('event_type', 'blocked')->count(),
            ];
        })->values();

        $allApps = AppActivity::query()->whereIn('login_session_id', $ids)->get();

        return $this->success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'sessions' => $rows,
            'classroom_apps' => $this->topApps($allApps, 10),
        ]);
    }

    /** @param  Collection<int, AppActivity>  $intervals */
    private function seconds(Collection $intervals): int
    {
        return (int) $intervals->sum(fn (AppActivity $interval) => max(0, $interval->started_at->diffInSeconds($interval->ended_at)));
    }

    private function minutes(int|float $seconds): int
    {
        return (int) round($seconds / 60);
    }

    /**
     * Active time per application, most used first. Idle time is deliberately left
     * out: it belongs to nobody's application.
     *
     * @param  Collection<int, AppActivity>  $intervals
     * @return list<array{process: string, name: string, minutes: int}>
     */
    private function topApps(Collection $intervals, int $limit = self::TOP): array
    {
        return $intervals->where('is_idle', false)
            ->groupBy('process')
            ->map(fn (Collection $group, string $process) => [
                'process' => $process,
                'name' => $this->friendlyName($process),
                'seconds' => $this->seconds($group),
            ])
            ->filter(fn (array $app) => $app['seconds'] >= 30)
            ->sortByDesc('seconds')->take($limit)
            ->map(fn (array $app) => ['process' => $app['process'], 'name' => $app['name'], 'minutes' => max(1, $this->minutes($app['seconds']))])
            ->values()->all();
    }

    /**
     * @param  Collection<int, BrowserActivity>  $events
     * @return list<array{domain: string, visits: int}>
     */
    private function topSites(Collection $events): array
    {
        return $events->where('event_type', 'navigated')
            ->groupBy('domain')
            ->map(fn (Collection $group, string $domain) => ['domain' => $domain, 'visits' => $group->count()])
            ->sortByDesc('visits')->take(self::TOP)->values()->all();
    }

    private function friendlyName(string $process): string
    {
        $known = [
            'winword.exe' => 'Word', 'excel.exe' => 'Excel', 'powerpnt.exe' => 'PowerPoint', 'onenote.exe' => 'OneNote',
            'outlook.exe' => 'Outlook', 'chrome.exe' => 'Chrome', 'msedge.exe' => 'Edge', 'firefox.exe' => 'Firefox',
            'notepad.exe' => 'Notepad', 'calc.exe' => 'Calculator', 'mspaint.exe' => 'Paint', 'explorer.exe' => 'File Explorer',
            'code.exe' => 'VS Code', 'teams.exe' => 'Teams', 'zoom.exe' => 'Zoom', 'acrobat.exe' => 'Acrobat',
            'acrord32.exe' => 'Acrobat Reader', 'wordpad.exe' => 'WordPad', 'cmd.exe' => 'Command Prompt',
            'powershell.exe' => 'PowerShell', 'taskmgr.exe' => 'Task Manager', 'scratch.exe' => 'Scratch',
        ];

        return $known[strtolower($process)] ?? $process;
    }
}
