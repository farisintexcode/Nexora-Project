<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('imports:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('tasks:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('tasks:dispatch-proof')->everyMinute()->withoutOverlapping();
Schedule::command('communications:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('communications:dispatch-receipts')->everyMinute()->withoutOverlapping();
Schedule::command('communications:dispatch-letters')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
