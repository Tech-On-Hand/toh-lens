<?php

namespace App\Console\Commands;

use App\Events\DeviceCommandUpdated;
use App\Models\DeviceCommand;
use Illuminate\Console\Command;

class ExpireDeviceCommands extends Command
{
    protected $signature = 'device-commands:expire';

    protected $description = 'Expire device commands that were not completed before their deadline';

    public function handle(): int
    {
        DeviceCommand::query()
            ->whereIn('status', ['pending', 'delivered'])
            ->where('expires_at', '<=', now())
            ->eachById(function (DeviceCommand $command) {
                $command->update(['status' => 'expired', 'result' => ['error' => 'EXPIRED'], 'completed_at' => now()]);
                DeviceCommandUpdated::dispatch($command);
            });

        return self::SUCCESS;
    }
}
