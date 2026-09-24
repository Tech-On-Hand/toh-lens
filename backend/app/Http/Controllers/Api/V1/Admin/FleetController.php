<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Computer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FleetController extends ApiController
{
    /** Offline this long is worth a school administrator's attention, not just a switched-off PC. */
    private const OFFLINE_ATTENTION_HOURS = 24;
    private const SYNC_BACKLOG = 5;

    /**
     * Every device in a school with what needs attention, worst first. An issue is
     * only raised for a fact the device reported itself or the server can see: no
     * heartbeat for a day, never seen at all, an agent older than the newest one in
     * the school, a Windows build that cannot capture the screen, or sessions it has
     * not managed to sync.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['school_id' => ['required', 'exists:schools,id']]);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator((int) $data['school_id']), 403);

        $devices = Computer::query()
            ->with('classroom:id,name')
            ->where('school_id', $data['school_id'])
            ->whereNull('revoked_at')
            ->orderBy('name')
            ->get();

        $newest = $devices->pluck('agent_version')->filter()->reduce(
            fn (?string $best, string $version) => $best === null || version_compare($version, $best, '>') ? $version : $best,
        );

        $rows = $devices->map(function (Computer $device) use ($newest) {
            $issues = $this->issues($device, $newest);

            return [
                'id' => $device->id,
                'name' => $device->name,
                'classroom' => $device->classroom?->name,
                'status' => $device->isOnline() ? 'online' : 'offline',
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'agent_version' => $device->agent_version,
                'operating_system' => $device->operating_system,
                'hostname' => $device->hostname,
                'issues' => $issues,
            ];
        })->sortBy([fn ($a, $b) => count($b['issues']) <=> count($a['issues']), fn ($a, $b) => strcmp($a['name'], $b['name'])])->values();

        return $this->success([
            'summary' => [
                'devices' => $rows->count(),
                'online' => $rows->where('status', 'online')->count(),
                'needing_attention' => $rows->filter(fn (array $row) => $row['issues'] !== [])->count(),
                'newest_agent_version' => $newest,
            ],
            'devices' => $rows,
        ]);
    }

    /** @return list<array{code: string, message: string}> */
    private function issues(Computer $device, ?string $newest): array
    {
        $issues = [];
        $health = $device->health ?? [];

        if ($device->last_seen_at === null) {
            $issues[] = ['code' => 'never_seen', 'message' => 'Enrolled but has never connected.'];
        } elseif (! $device->isOnline() && $device->last_seen_at->lt(now()->subHours(self::OFFLINE_ATTENTION_HOURS))) {
            $issues[] = ['code' => 'offline', 'message' => 'Not seen for '.$device->last_seen_at->diffForHumans(syntax: true).'.'];
        }

        if ($device->agent_version && $newest && version_compare($device->agent_version, $newest, '<')) {
            $issues[] = ['code' => 'outdated_agent', 'message' => "Running agent {$device->agent_version}; the newest in this school is {$newest}."];
        }

        if ($device->isOnline()) {
            if (($health['screen_capture_supported'] ?? null) === false) {
                $issues[] = ['code' => 'screen_capture', 'message' => "This PC's Windows cannot capture the screen, so screen watch and broadcast will not work on it."];
            }
            if (($health['unsynced_sessions'] ?? 0) >= self::SYNC_BACKLOG) {
                $issues[] = ['code' => 'sync_backlog', 'message' => "{$health['unsynced_sessions']} login sessions are waiting to sync."];
            }
        }

        return $issues;
    }
}
