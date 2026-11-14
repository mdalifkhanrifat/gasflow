<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates this month's and the next three months' activity-trail partitions
 * before any later migration writes to a lifecycle table (D30).
 *
 * On a new database (fresh install, test database) the scheduled
 * audit:change-log-partitions has never run. A migration that inserts rows,
 * such as the bn/en locales, would otherwise put trail rows into the default
 * partition, and that month's partition could then never be created.
 * Idempotent: existing partitions are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select(<<<'SQL'
            SELECT data_change_logs_ensure_partition((date_trunc('month', current_date) + make_interval(months => m))::date)
            FROM generate_series(0, 3) AS m
            SQL);
    }

    public function down(): void
    {
        // Partitions hold trail rows; they are never dropped by a rollback.
    }
};
