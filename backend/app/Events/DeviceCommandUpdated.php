<?php

namespace App\Events;

use App\Models\DeviceCommand;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class DeviceCommandUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public string $eventId;

    public function __construct(public DeviceCommand $command)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("classroom.{$this->command->classroom_id}")];
    }

    public function broadcastAs(): string
    {
        return 'device.command.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'occurred_at' => now()->toIso8601String(),
            'command' => [
                'id' => $this->command->uuid,
                'device_id' => $this->command->computer_id,
                'type' => $this->command->type,
                'status' => $this->command->status,
                'result' => $this->command->result,
            ],
        ];
    }
}
