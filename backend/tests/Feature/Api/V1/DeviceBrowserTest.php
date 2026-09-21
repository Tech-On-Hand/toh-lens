<?php

namespace Tests\Feature\Api\V1;

use App\Events\BrowserTabsChanged;
use App\Models\BrowserActivity;
use App\Models\BrowserTab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClassroomFixtures;
use Tests\TestCase;

class DeviceBrowserTest extends TestCase
{
    use BuildsClassroomFixtures, RefreshDatabase;

    private function tab(int $id, string $url, bool $active = false, string $title = 'Page'): array
    {
        return ['tab_id' => $id, 'window_id' => 1, 'url' => $url, 'title' => $title, 'active' => $active];
    }

    private function snapshot(array $tabs, string $observedAt = '2026-09-22T08:00:00Z', ?string $session = null): array
    {
        return ['browser' => 'chrome', 'snapshot' => ['observed_at' => $observedAt, 'session_uuid' => $session, 'tabs' => $tabs]];
    }

    public function test_a_snapshot_replaces_the_devices_tabs_and_broadcasts_the_active_tab(): void
    {
        Event::fake();
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $headers = $this->deviceHeaders($device);

        $this->withHeaders($headers)->postJson('/api/v1/device/browser/events', $this->snapshot([
            $this->tab(1, 'https://a.example/'), $this->tab(2, 'https://b.example/', true),
        ]))->assertOk()->assertJsonPath('data.snapshot_applied', true);

        $this->withHeaders($headers)->postJson('/api/v1/device/browser/events', $this->snapshot([
            $this->tab(3, 'https://c.example/', true),
        ], '2026-09-22T08:00:05Z'))->assertOk();

        $this->assertSame([3], BrowserTab::where('computer_id', $device->id)->pluck('browser_tab_id')->all());
        Event::assertDispatched(BrowserTabsChanged::class, fn ($e) => $e->activeTab['url'] === 'https://c.example/' && $e->tabCount === 1);
    }

    public function test_an_older_snapshot_arriving_late_is_ignored(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $headers = $this->deviceHeaders($device);

        $this->withHeaders($headers)->postJson('/api/v1/device/browser/events', $this->snapshot([$this->tab(1, 'https://new.example/', true)], '2026-09-22T08:00:10Z'))->assertOk();
        $response = $this->withHeaders($headers)->postJson('/api/v1/device/browser/events', $this->snapshot([$this->tab(9, 'https://old.example/', true)], '2026-09-22T08:00:00Z'));

        $response->assertJsonPath('data.snapshot_applied', false);
        $this->assertSame('https://new.example/', BrowserTab::where('computer_id', $device->id)->value('url'));
    }

    public function test_an_unchanged_snapshot_does_not_rebroadcast(): void
    {
        Event::fake();
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $headers = $this->deviceHeaders($device);
        $tabs = [$this->tab(1, 'https://a.example/', true)];

        $this->withHeaders($headers)->postJson('/api/v1/device/browser/events', $this->snapshot($tabs, '2026-09-22T08:00:00Z'))->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/device/browser/events', $this->snapshot($tabs, '2026-09-22T08:00:30Z'))->assertOk();

        Event::assertDispatchedTimes(BrowserTabsChanged::class, 1);
    }

    public function test_an_empty_snapshot_clears_the_tabs(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $headers = $this->deviceHeaders($device);

        $this->withHeaders($headers)->postJson('/api/v1/device/browser/events', $this->snapshot([$this->tab(1, 'https://a.example/', true)]))->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/device/browser/events', $this->snapshot([], '2026-09-22T08:01:00Z'))->assertOk();

        $this->assertSame(0, BrowserTab::where('computer_id', $device->id)->count());
    }

    public function test_activity_events_are_idempotent_and_attributed_to_the_reported_session(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $first = $this->signInStudent($device, '1001');
        $first->update(['status' => 'ended', 'logout_time' => now()]);
        $second = $this->signInStudent($device, '1002');
        $eventId = (string) Str::uuid();
        $payload = ['browser' => 'edge', 'events' => [[
            'uuid' => $eventId, 'type' => 'navigated', 'url' => 'https://Khan.Example/lesson?x=1', 'title' => 'Lesson',
            'session_uuid' => $first->uuid, 'occurred_at' => '2026-09-22T07:59:00Z',
        ]]];

        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/browser/events', $payload)->assertOk()->assertJsonPath('data.events_recorded', 1);
        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/browser/events', $payload)->assertOk()->assertJsonPath('data.events_recorded', 0);

        $activity = BrowserActivity::where('uuid', $eventId)->first();
        $this->assertSame($first->id, $activity->login_session_id);
        $this->assertSame($first->student_id, $activity->student_id);
        $this->assertNotSame($second->id, $activity->login_session_id);
        $this->assertSame('khan.example', $activity->domain);
        $this->assertSame(1, BrowserActivity::count());
    }

    public function test_a_session_from_another_device_is_not_trusted_for_attribution(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $other = $this->makeDevice($school, $classroom);
        $foreign = $this->signInStudent($other, '2001');

        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/browser/events', ['browser' => 'chrome', 'events' => [[
            'uuid' => (string) Str::uuid(), 'type' => 'activated', 'url' => 'https://a.example/', 'title' => 'A',
            'session_uuid' => $foreign->uuid, 'occurred_at' => '2026-09-22T08:00:00Z',
        ]]])->assertOk();

        $this->assertNull(BrowserActivity::first()->login_session_id);
    }

    public function test_unknown_browsers_are_rejected(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);

        $this->withHeaders($this->deviceHeaders($device))->postJson('/api/v1/device/browser/events', ['browser' => 'netscape'])->assertStatus(422);
    }
}
