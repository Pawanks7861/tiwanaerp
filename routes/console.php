<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('inventory:scan-low-stock')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('planning:flag-delays')->dailyAt('06:30')->withoutOverlapping();
Schedule::command('uploads:cleanup')->hourly()->withoutOverlapping();
