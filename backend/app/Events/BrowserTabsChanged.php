<?php

namespace App\Events;

use App\Models\Computer;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class BrowserTabsChanged implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public string $eventId;

    /** @param array<string, mixed>|null $activeTab */
    public function __construct(public Computer $device, public ?array $activeTab, public int $tabCount)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("classroom.{$this->device->classroom_id}")];
    }

    public function broadcastAs(): string
    {
        return 'device.browser.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'occurred_at' => now()->toIso8601String(),
            'device_id' => $this->device->id,
            'device_uuid' => $this->device->device_uuid,
            'active_tab' => $this->activeTab,
            'tab_count' => $this->tabCount,
        ];
    }
}
