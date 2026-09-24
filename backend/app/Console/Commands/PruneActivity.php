<?php

namespace App\Console\Commands;

use App\Models\AppActivity;
use App\Models\BrowserActivity;
use Illuminate\Console\Command;

class PruneActivity extends Command
{
    protected $signature = 'activity:prune {--days= : Keep this many days (default: TOH_ACTIVITY_RETENTION_DAYS, 90)}';

    protected $description = 'Delete browsing history and application activity older than the retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('toh.activity_retention_days'));
        if ($days < 1) {
            $this->error('Retention must be at least 1 day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $apps = AppActivity::query()->where('started_at', '<', $cutoff)->delete();
        $browsing = BrowserActivity::query()->where('occurred_at', '<', $cutoff)->delete();

        $this->info("Deleted {$apps} application and {$browsing} browsing records older than {$days} days.");

        return self::SUCCESS;
    }
}
