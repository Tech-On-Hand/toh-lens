<?php

namespace App\Console\Commands;

use App\Events\DevicePresenceChanged;
use App\Models\Computer;
use Illuminate\Console\Command;

class MarkOfflineDevices extends Command
{
    protected $signature = 'devices:mark-offline';

    protected $description = 'Mark devices offline after 90 seconds without a heartbeat';

    public function handle(): int
    {
        Computer::query()
            ->where('presence_status', 'online')
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query
                ->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', now()->subSeconds(90)))
            ->eachById(function (Computer $device) {
                $device->update(['presence_status' => 'offline']);
                $device->endCurrentScreenSession('device_offline');
                DevicePresenceChanged::dispatch($device, 'offline');
            });

        return self::SUCCESS;
    }
}
