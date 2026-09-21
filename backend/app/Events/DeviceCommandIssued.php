<?php

namespace App\Events;

use App\Models\DeviceCommand;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A nudge only: the durable command is fetched over REST so a missed event loses nothing. */
class DeviceCommandIssued implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public DeviceCommand $command) {}

    public function broadcastOn(): array
    {
        $this->command->loadMissing('computer:id,device_uuid');

        return [new PrivateChannel("device.{$this->command->computer->device_uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'device.command.issued';
    }

    public function broadcastWith(): array
    {
        return ['command_id' => $this->command->uuid, 'expires_at' => $this->command->expires_at->toIso8601String()];
    }
}
