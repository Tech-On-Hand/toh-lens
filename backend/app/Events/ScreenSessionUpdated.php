<?php

namespace App\Events;

use App\Models\ScreenSession;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A nudge only, fired from the device's side of the exchange (answered, added a
 * candidate, or ended) so the teacher's already-open socket knows to re-fetch rather
 * than poll. The device itself never listens on a socket, so nothing broadcasts to it.
 */
class ScreenSessionUpdated implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public ScreenSession $session) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("classroom.{$this->session->classroom_id}")];
    }

    public function broadcastAs(): string
    {
        return 'device.screen.updated';
    }

    public function broadcastWith(): array
    {
        return ['session_id' => $this->session->uuid, 'computer_id' => $this->session->computer_id];
    }
}
