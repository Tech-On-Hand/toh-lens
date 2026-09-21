<?php

namespace App\Events;

use App\Models\Computer;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class DevicePresenceChanged implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public string $eventId;

    public function __construct(public Computer $device, public string $status)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("classroom.{$this->device->classroom_id}")];
    }

    public function broadcastAs(): string
    {
        return 'device.presence.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'occurred_at' => now()->toIso8601String(),
            'device' => [
                'id' => $this->device->id,
                'device_uuid' => $this->device->device_uuid,
                'name' => $this->device->name,
                'status' => $this->status,
                'last_seen_at' => $this->device->last_seen_at?->toIso8601String(),
                'agent_version' => $this->device->agent_version,
            ],
        ];
    }
}
