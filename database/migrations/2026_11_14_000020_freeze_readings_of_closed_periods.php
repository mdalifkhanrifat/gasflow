<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Meter readings stop changing once their reading period is approved, locked
 * or cancelled (design 02 §5, V7.15): billing relies on an approved period's
 * readings, so no reading may be added, corrected or re-decided afterwards.
 * Only the maintenance override (with its reason and log) may correct one.
 *
 * Revisions and approvals are not guarded on their own: each one changes its
 * reading in the same transaction, and that change is refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        LifecycleSchema::freezeWhenParentClosed(
            'meter_readings',
            parent: 'reading_periods',
            foreignKey: 'reading_period_id',
            closedStatuses: ['approved', 'locked', 'cancelled'],
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_meter_readings_closed_parent ON meter_readings;');
    }
};
