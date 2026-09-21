<?php

namespace App\Events;

use App\Models\LoginSession;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class StudentSessionChanged implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public string $eventId;

    public function __construct(public LoginSession $session)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("classroom.{$this->session->classroom_id}")];
    }

    public function broadcastAs(): string
    {
        return $this->session->status === 'active' ? 'student.session.started' : 'student.session.ended';
    }

    public function broadcastWith(): array
    {
        $this->session->loadMissing('student:id,full_name,admission_number');

        return [
            'event_id' => $this->eventId,
            'occurred_at' => now()->toIso8601String(),
            'session' => [
                'uuid' => $this->session->uuid,
                'device_id' => $this->session->computer_id,
                'student' => $this->session->student,
                'status' => $this->session->status,
                'started_at' => $this->session->login_time->toIso8601String(),
                'ended_at' => $this->session->logout_time?->toIso8601String(),
            ],
        ];
    }
}
