<?php

namespace App\Events;

use App\Models\Computer;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceRevoked implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public Computer $device) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("device.{$this->device->device_uuid}"),
            new PrivateChannel("classroom.{$this->device->classroom_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'device.revoked';
    }
}
