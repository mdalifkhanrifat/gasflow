<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Activity-trail partitions must exist before their month starts (rows of a month
// without one land in the default partition). Daily and idempotent, months ahead.
Schedule::command('audit:change-log-partitions')->dailyAt('03:10')->onOneServer()->withoutOverlapping();

// DB-IP publishes the City Lite database at the start of each month.
Schedule::command('audit:geoip-update')->monthlyOn(3, '04:00')->onOneServer()->withoutOverlapping();
