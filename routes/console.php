<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('gallery:index')->daily()->withoutOverlapping();

// Background jobs (Confluence imports) without a separate worker service: drain the queue every
// minute. The overlap lock expires after 5 minutes, so a killed worker can't block the queue.
Schedule::command('queue:work --stop-when-empty --max-time=50 --memory=256')->everyMinute()->withoutOverlapping(5);
