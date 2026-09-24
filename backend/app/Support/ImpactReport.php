<?php

namespace App\Support;

use App\Models\AppActivity;
use App\Models\BrowserActivity;
use App\Models\Computer;
use App\Models\DeviceActivityDay;
use App\Models\LoginSession;
use App\Models\School;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Aggregate numbers about how the donated computers are being used, for a school or a
 * whole organization. It is meant to be shown outside the school (to donors, a board),
 * so it never contains a student's name or anything about one student: only counts,
 * hours and percentages, and an application or website is listed only where at least
 * MIN_GROUP different students used it, so nobody's own habits can be picked out.
 *
 * It measures USE. It cannot say whether students learned anything; that needs the
 * school's own results alongside it.
 */
class ImpactReport
{
    /** An application or site is only shown when this many different students used it. */
    public const MIN_GROUP = 5;

    private const SESSION_CAP_HOURS = 8;
    private const STALE_OPEN_HOURS = 12;
    private const OUT_OF_SERVICE_DAYS = 14;
    private const UNUSED_AFTER_DAYS = 7;
    private const TOP = 8;
    /** The desktop shell is where the student is between programs, not something they used. */
    private const NOT_A_TOOL = ['explorer.exe'];

    private Carbon $from;

    private Carbon $to;

    /**
     * @param  Collection<int, int>  $schoolIds
     *
     * The dates are copied into mutable Carbon instances: the loops below step them forward.
     */
    public function __construct(private Collection $schoolIds, \DateTimeInterface $from, \DateTimeInterface $to)
    {
        $this->from = Carbon::instance($from);
        $this->to = Carbon::instance($to);
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $sessions = LoginSession::query()
            ->whereIn('school_id', $this->schoolIds)
            ->whereBetween('login_time', [$this->from, $this->to])
            ->get(['id', 'school_id', 'computer_id', 'student_id', 'login_time', 'logout_time']);

        $computers = Computer::query()->whereIn('school_id', $this->schoolIds)->get(['id', 'school_id', 'enrolled_at', 'created_at', 'last_seen_at', 'revoked_at']);
        $current = $computers->whereNull('revoked_at')->filter(fn (Computer $c) => ($c->enrolled_at ?? $c->created_at)?->lte($this->to));

        $intervals = AppActivity::query()->whereIn('school_id', $this->schoolIds)->whereBetween('started_at', [$this->from, $this->to])
            ->get(['school_id', 'student_id', 'process', 'is_idle', 'started_at', 'ended_at']);

        $seconds = $sessions->mapWithKeys(fn (LoginSession $session) => [$session->id => $this->sessionSeconds($session)]);
        $studentsReached = $sessions->pluck('student_id')->filter()->unique()->count();
        $roster = Student::query()->whereIn('school_id', $this->schoolIds)->where('is_active', true)->count();

        return [
            'period' => ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'school_days' => $this->schoolDays()],
            'computers' => $this->computerFigures($current, $sessions),
            'students' => [
                'reached' => $studentsReached,
                'on_roster' => $roster,
                'reach_percent' => $roster > 0 ? (int) round(100 * $studentsReached / $roster) : null,
            ],
            'usage' => $this->usage($sessions, $seconds, $intervals),
            'availability' => $this->availability($computers, $sessions),
            'weekly' => $this->weekly($sessions, $seconds),
            'tools' => ['min_students' => self::MIN_GROUP, 'apps' => $this->topApps($intervals), 'sites' => $this->topSites()],
            'schools' => $this->perSchool($current, $sessions, $seconds),
            'notes' => $this->notes($sessions, $intervals),
        ];
    }

    private function schoolDays(): int
    {
        $days = 0;
        for ($day = $this->from->copy()->startOfDay(); $day->lte($this->to); $day->addDay()) {
            $days += $day->isWeekday() ? 1 : 0;
        }

        return $days;
    }

    /** Time signed in, capped, and not guessed for a session that was never closed and is long gone. */
    private function sessionSeconds(LoginSession $session): int
    {
        $end = $session->logout_time;
        if (! $end) {
            if ($session->login_time->lt(now()->subHours(self::STALE_OPEN_HOURS))) {
                return 0;
            }
            $end = now();
        }

        return (int) min(max(0, $session->login_time->diffInSeconds($end)), self::SESSION_CAP_HOURS * 3600);
    }

    /** @param  Collection<int, Computer>  $current */
    private function computerFigures(Collection $current, Collection $sessions): array
    {
        $usedIds = $sessions->pluck('computer_id')->unique();
        $unusedCutoff = $this->to->copy()->subDays(self::UNUSED_AFTER_DAYS);
        $silentCutoff = now()->subDays(self::OUT_OF_SERVICE_DAYS);

        return [
            'enrolled' => $current->count(),
            'used' => $current->whereIn('id', $usedIds)->count(),
            // Enrolled for a week or more in this period and never used once.
            'never_used' => $current->filter(fn (Computer $c) => ! $usedIds->contains($c->id) && ($c->enrolled_at ?? $c->created_at)->lte($unusedCutoff))->count(),
            // Not heard from for two weeks: broken, unplugged or gone, and the donation is idle.
            'out_of_service' => $current->filter(fn (Computer $c) => ($c->last_seen_at ?? $c->enrolled_at ?? $c->created_at)->lt($silentCutoff))->count(),
        ];
    }

    private function usage(Collection $sessions, Collection $seconds, Collection $intervals): array
    {
        $length = fn (Collection $rows) => (int) $rows->sum(fn (AppActivity $i) => max(0, $i->started_at->diffInSeconds($i->ended_at)));
        $counted = $seconds->filter(fn (int $s) => $s > 0);

        return [
            'sessions' => $sessions->count(),
            'signed_in_hours' => round($seconds->sum() / 3600, 1),
            'active_hours' => round($length($intervals->where('is_idle', false)) / 3600, 1),
            'idle_hours' => round($length($intervals->where('is_idle', true)) / 3600, 1),
            'average_session_minutes' => $counted->isEmpty() ? null : (int) round($counted->avg() / 60),
            'days_with_use' => $sessions->map(fn (LoginSession $s) => $s->login_time->toDateString())->unique()->count(),
        ];
    }

    /**
     * Of the school days a computer was switched on, the share on which a student used
     * it. Only days the server has a record of the computer being on are compared, so
     * history from before this was collected cannot skew it.
     */
    private function availability(Collection $computers, Collection $sessions): array
    {
        $online = DeviceActivityDay::query()
            ->whereIn('computer_id', $computers->pluck('id'))
            ->whereBetween('day', [$this->from->toDateString(), $this->to->toDateString()])
            ->get(['computer_id', 'day'])
            ->toBase()
            ->filter(fn (DeviceActivityDay $d) => $d->day->isWeekday())
            ->map(fn (DeviceActivityDay $d) => $d->computer_id.'|'.$d->day->toDateString())
            ->unique();

        $used = $sessions->toBase()->filter(fn (LoginSession $s) => $s->login_time->isWeekday())
            ->map(fn (LoginSession $s) => $s->computer_id.'|'.$s->login_time->toDateString())
            ->unique();

        $both = $online->intersect($used)->count();

        return [
            'computer_days_on' => $online->count(),
            'computer_days_used' => $both,
            'used_percent' => $online->isEmpty() ? null : (int) round(100 * $both / $online->count()),
        ];
    }

    /** @return list<array<string, mixed>> Every week in the period, including quiet ones. */
    private function weekly(Collection $sessions, Collection $seconds): array
    {
        $byWeek = $sessions->groupBy(fn (LoginSession $s) => $s->login_time->copy()->startOfWeek()->toDateString());
        $weeks = [];
        for ($week = $this->from->copy()->startOfWeek(); $week->lte($this->to); $week->addWeek()) {
            $rows = $byWeek->get($week->toDateString(), collect());
            $weeks[] = [
                'week_start' => $week->toDateString(),
                'sessions' => $rows->count(),
                'students' => $rows->pluck('student_id')->filter()->unique()->count(),
                'computers_used' => $rows->pluck('computer_id')->unique()->count(),
                'hours' => round($rows->sum(fn (LoginSession $s) => $seconds[$s->id] ?? 0) / 3600, 1),
            ];
        }

        return $weeks;
    }

    /** @return list<array{name: string, hours: float, students: int}> */
    private function topApps(Collection $intervals): array
    {
        return $intervals->where('is_idle', false)
            ->reject(fn (AppActivity $i) => in_array(strtolower($i->process), self::NOT_A_TOOL, true))
            ->groupBy(fn (AppActivity $i) => AppNames::friendly($i->process))
            ->map(fn (Collection $group, string $name) => [
                'name' => $name,
                'hours' => round($group->sum(fn (AppActivity $i) => max(0, $i->started_at->diffInSeconds($i->ended_at))) / 3600, 1),
                'students' => $group->pluck('student_id')->filter()->unique()->count(),
            ])
            ->filter(fn (array $app) => $app['students'] >= self::MIN_GROUP && $app['hours'] > 0)
            ->sortByDesc('hours')->take(self::TOP)->values()->all();
    }

    /** @return list<array{domain: string, visits: int, students: int}> */
    private function topSites(): array
    {
        return BrowserActivity::query()
            ->whereIn('school_id', $this->schoolIds)->where('event_type', 'navigated')->whereNotNull('domain')
            ->whereBetween('occurred_at', [$this->from, $this->to])
            ->get(['domain', 'student_id'])
            ->groupBy('domain')
            ->map(fn (Collection $group, string $domain) => [
                'domain' => $domain,
                'visits' => $group->count(),
                'students' => $group->pluck('student_id')->filter()->unique()->count(),
            ])
            ->filter(fn (array $site) => $site['students'] >= self::MIN_GROUP)
            ->sortByDesc('visits')->take(self::TOP)->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function perSchool(Collection $current, Collection $sessions, Collection $seconds): array
    {
        return School::query()->whereIn('id', $this->schoolIds)->orderBy('name')->get(['id', 'name'])->map(function (School $school) use ($current, $sessions, $seconds) {
            $mine = $sessions->where('school_id', $school->id);
            $computers = $current->where('school_id', $school->id);

            return [
                'name' => $school->name,
                'computers' => $computers->count(),
                'computers_used' => $computers->whereIn('id', $mine->pluck('computer_id')->unique())->count(),
                'students' => $mine->pluck('student_id')->filter()->unique()->count(),
                'sessions' => $mine->count(),
                'hours' => round($mine->sum(fn (LoginSession $s) => $seconds[$s->id] ?? 0) / 3600, 1),
            ];
        })->values()->all();
    }

    /** @return list<string> What a reader needs to know before trusting the numbers. */
    private function notes(Collection $sessions, Collection $intervals): array
    {
        $notes = ['Hours signed in are approximate: a session counts for at most '.self::SESSION_CAP_HOURS.' hours, and one that was never closed and is more than '.self::STALE_OPEN_HOURS.' hours old is not counted.'];

        if ($sessions->isNotEmpty() && $intervals->isEmpty()) {
            $notes[] = 'Active and idle time are only recorded by computers running a version of the software that tracks applications, so they are missing here.';
        }
        $notes[] = 'This shows how much the computers were used, not what students learned. Pair it with the school\'s own results and teacher feedback.';

        return $notes;
    }
}
