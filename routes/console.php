<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// DB-IP publishes the City Lite database at the start of each month.
Schedule::command('audit:geoip-update')->monthlyOn(3, '04:00')->onOneServer()->withoutOverlapping();
