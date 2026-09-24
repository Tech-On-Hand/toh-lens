<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('devices:mark-offline')->everyMinute()->withoutOverlapping();
Schedule::command('device-commands:expire')->everyMinute()->withoutOverlapping();

Schedule::command('focus-sessions:expire')->everyMinute()->withoutOverlapping();
Schedule::command('screen-sessions:expire')->everyMinute()->withoutOverlapping();
Schedule::command('activity:prune')->dailyAt('02:30')->withoutOverlapping();
