<?php

namespace App\Events;

use App\Models\FocusSession;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class FocusSessionChanged implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public string $eventId;

    public function __construct(public FocusSession $session, public string $state)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("classroom.{$this->session->classroom_id}")];
    }

    public function broadcastAs(): string
    {
        return 'classroom.focus.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'occurred_at' => now()->toIso8601String(),
            'state' => $this->state,
            'focus' => $this->session->toSummary(),
        ];
    }
}
