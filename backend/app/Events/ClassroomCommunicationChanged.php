<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A nudge only: a help request was raised, cancelled or resolved, or an announcement
 * was read. The teacher's already-open socket re-fetches over REST; the device never
 * listens on a socket, so nothing broadcasts to it.
 */
class ClassroomCommunicationChanged implements ShouldBroadcast
{
    use Dispatchable;

    /** @param  'help'|'announcement'  $kind */
    public function __construct(public int $classroomId, public string $kind) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("classroom.{$this->classroomId}")];
    }

    public function broadcastAs(): string
    {
        return 'classroom.communication.changed';
    }

    public function broadcastWith(): array
    {
        return ['kind' => $this->kind];
    }
}
