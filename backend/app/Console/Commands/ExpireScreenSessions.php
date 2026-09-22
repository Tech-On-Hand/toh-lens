<?php

namespace App\Console\Commands;

use App\Models\ScreenSession;
use Illuminate\Console\Command;

class ExpireScreenSessions extends Command
{
    protected $signature = 'screen-sessions:expire';

    protected $description = 'End screen-watch requests the device never answered';

    /** The device polls every few seconds; 30s with no answer means it is unreachable. */
    private const PENDING_TIMEOUT_SECONDS = 30;

    public function handle(): int
    {
        ScreenSession::query()
            ->where('status', 'pending')
            ->where('started_at', '<=', now()->subSeconds(self::PENDING_TIMEOUT_SECONDS))
            ->update(['status' => 'ended', 'ended_at' => now(), 'end_reason' => 'expired']);

        return self::SUCCESS;
    }
}
