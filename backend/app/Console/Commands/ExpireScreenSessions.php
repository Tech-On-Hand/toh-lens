<?php

namespace App\Console\Commands;

use App\Models\ScreenSession;
use Illuminate\Console\Command;

class ExpireScreenSessions extends Command
{
    protected $signature = 'screen-sessions:expire';

    protected $description = 'End screen-watch requests the device never answered, and active ones the viewer went quiet on';

    /** The device polls every few seconds; 30s with no answer means it is unreachable. */
    private const PENDING_TIMEOUT_SECONDS = 30;

    /**
     * The viewer polls roughly once a second while watching (see
     * TeacherScreenSessionController::show, which touches the session on every
     * poll); 30s of silence means their side is gone — app closed, crashed, or
     * lost its connection — not just a slow tick. Nothing else ends an active
     * session on its own: the device stays online and keeps capturing for
     * a viewer that no longer exists unless this catches it.
     */
    private const ACTIVE_VIEWER_TIMEOUT_SECONDS = 30;

    public function handle(): int
    {
        ScreenSession::query()
            ->where('status', 'pending')
            ->where('started_at', '<=', now()->subSeconds(self::PENDING_TIMEOUT_SECONDS))
            ->update(['status' => 'ended', 'ended_at' => now(), 'end_reason' => 'expired']);

        ScreenSession::query()
            ->where('status', 'active')
            ->where('updated_at', '<=', now()->subSeconds(self::ACTIVE_VIEWER_TIMEOUT_SECONDS))
            ->update(['status' => 'ended', 'ended_at' => now(), 'end_reason' => 'viewer_lost']);

        return self::SUCCESS;
    }
}
