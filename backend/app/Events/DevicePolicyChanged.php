<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** A nudge only: devices fetch the policy over REST, so a missed event loses nothing. */
class DevicePolicyChanged implements ShouldBroadcast
{
    use Dispatchable;

    /** @param list<string> $deviceUuids */
    public function __construct(public array $deviceUuids) {}

    public function broadcastOn(): array
    {
        return array_map(fn (string $uuid) => new PrivateChannel("device.{$uuid}"), $this->deviceUuids);
    }

    public function broadcastAs(): string
    {
        return 'device.policy.changed';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
