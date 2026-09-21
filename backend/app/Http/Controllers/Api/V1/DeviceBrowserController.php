<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\BrowserTabsChanged;
use App\Models\BrowserActivity;
use App\Models\BrowserTab;
use App\Models\Computer;
use App\Models\LoginSession;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceBrowserController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'browser' => ['required', 'in:chrome,edge'],
            'snapshot' => ['nullable', 'array'],
            'snapshot.observed_at' => ['required_with:snapshot', 'date'],
            'snapshot.session_uuid' => ['nullable', 'uuid'],
            'snapshot.tabs' => ['present_with:snapshot', 'array', 'max:200'],
            'snapshot.tabs.*.tab_id' => ['required', 'integer', 'min:0'],
            'snapshot.tabs.*.window_id' => ['nullable', 'integer'],
            'snapshot.tabs.*.url' => ['nullable', 'string', 'max:2048'],
            'snapshot.tabs.*.title' => ['nullable', 'string', 'max:500'],
            'snapshot.tabs.*.active' => ['required', 'boolean'],
            'events' => ['nullable', 'array', 'max:200'],
            'events.*.uuid' => ['required', 'uuid'],
            'events.*.type' => ['required', 'in:navigated,activated,blocked'],
            'events.*.url' => ['nullable', 'string', 'max:2048'],
            'events.*.title' => ['nullable', 'string', 'max:500'],
            'events.*.session_uuid' => ['nullable', 'uuid'],
            'events.*.occurred_at' => ['required', 'date'],
        ]);

        /** @var Computer $device */
        $device = $request->user();
        $sessions = [];
        $resolveSession = function (?string $uuid) use ($device, &$sessions): ?LoginSession {
            if ($uuid === null) {
                return null;
            }

            return $sessions[$uuid] ??= LoginSession::query()
                ->where('uuid', $uuid)
                ->where('computer_id', $device->id)
                ->first();
        };

        $snapshotApplied = isset($data['snapshot'])
            && $this->applySnapshot($device, $data['browser'], $data['snapshot'], $resolveSession);

        $recorded = 0;
        foreach ($data['events'] ?? [] as $event) {
            $session = $resolveSession($event['session_uuid'] ?? null);
            $activity = BrowserActivity::query()->firstOrCreate(['uuid' => $event['uuid']], [
                'school_id' => $device->school_id,
                'computer_id' => $device->id,
                'classroom_id' => $device->classroom_id,
                'login_session_id' => $session?->id,
                'student_id' => $session?->student_id,
                'browser' => $data['browser'],
                'event_type' => $event['type'],
                'url' => $event['url'] ?? null,
                'domain' => $this->domainOf($event['url'] ?? null),
                'title' => $event['title'] ?? null,
                'occurred_at' => Carbon::parse($event['occurred_at']),
            ]);
            $recorded += $activity->wasRecentlyCreated ? 1 : 0;
        }

        return $this->success(['snapshot_applied' => $snapshotApplied, 'events_recorded' => $recorded]);
    }

    private function applySnapshot(Computer $device, string $browser, array $snapshot, callable $resolveSession): bool
    {
        $observedAt = Carbon::parse($snapshot['observed_at']);
        $session = $resolveSession($snapshot['session_uuid'] ?? null);

        return DB::transaction(function () use ($device, $browser, $snapshot, $observedAt, $session) {
            $existing = BrowserTab::query()->where('computer_id', $device->id)->where('browser', $browser)->get();

            if ($existing->isNotEmpty() && $existing->max('observed_at')->gt($observedAt)) {
                return false;
            }

            $before = $this->digest($existing->map(fn (BrowserTab $tab) => [
                $tab->browser_tab_id, $tab->url, $tab->title, $tab->is_active,
            ])->all());

            BrowserTab::query()->where('computer_id', $device->id)->where('browser', $browser)->delete();

            $rows = collect($snapshot['tabs'])->unique('tab_id')->map(fn (array $tab) => [
                'computer_id' => $device->id,
                'classroom_id' => $device->classroom_id,
                'login_session_id' => $session?->id,
                'browser' => $browser,
                'browser_tab_id' => $tab['tab_id'],
                'window_id' => $tab['window_id'] ?? null,
                'url' => $tab['url'] ?? null,
                'title' => $tab['title'] ?? null,
                'is_active' => $tab['active'],
                'observed_at' => $observedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ])->values();

            BrowserTab::query()->insert($rows->all());

            $after = $this->digest($rows->map(fn (array $row) => [
                $row['browser_tab_id'], $row['url'], $row['title'], $row['is_active'],
            ])->all());

            if ($before !== $after) {
                $active = $rows->firstWhere('is_active', true);
                BrowserTabsChanged::dispatch($device, $active ? [
                    'tab_id' => $active['browser_tab_id'],
                    'url' => $active['url'],
                    'title' => $active['title'],
                ] : null, $rows->count());
            }

            return true;
        });
    }

    private function digest(array $tabs): string
    {
        usort($tabs, fn ($a, $b) => $a[0] <=> $b[0]);

        return sha1(json_encode($tabs));
    }

    private function domainOf(?string $url): ?string
    {
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) ? mb_strtolower($host) : null;
    }
}
