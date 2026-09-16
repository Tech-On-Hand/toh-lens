<?php

namespace App\Console\Commands;

use App\Models\Computer;
use Illuminate\Console\Command;

class IssueComputerToken extends Command
{
    protected $signature = 'computer:issue-token {computer_id} {--name=kiosk-token}';

    protected $description = 'Issue a new Sanctum API token for a kiosk computer';

    public function handle(): int
    {
        $computer = Computer::find($this->argument('computer_id'));

        if (! $computer) {
            $this->error("Computer #{$this->argument('computer_id')} not found.");

            return self::FAILURE;
        }

        $token = $computer->createToken($this->option('name'))->plainTextToken;

        $this->info("Token for computer #{$computer->id} ({$computer->name}):");
        $this->line($token);
        $this->warn('This token is shown only once. Store it securely.');

        return self::SUCCESS;
    }
}
