<?php

namespace App\Events;

use App\Models\Computer;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class DeviceConfigurationUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public string $eventId;

    public function __construct(public Computer $device)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("device.{$this->device->device_uuid}"),
            new PrivateChannel("classroom.{$this->device->classroom_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'device.configuration.updated';
    }
}
