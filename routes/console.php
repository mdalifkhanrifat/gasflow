<?php

use App\Modules\Licensing\LicenseState;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Activity-trail partitions must exist before their month starts (rows of a month
// without one land in the default partition). Daily and idempotent, months ahead.
// Runs even without a valid license: a missed month could never get its partition.
Schedule::command('audit:change-log-partitions')->dailyAt('03:10')->onOneServer()->withoutOverlapping();

// Everything else is background work a locked installation does not do (design 09 §3).
Schedule::when(fn (): bool => app(LicenseState::class)->allowsOperation())->group(function (): void {
    // DB-IP publishes the City Lite database at the start of each month.
    Schedule::command('audit:geoip-update')->monthlyOn(3, '04:00')->onOneServer()->withoutOverlapping();
});
