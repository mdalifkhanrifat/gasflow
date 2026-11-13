<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The trails can only be written by the lifecycle triggers, never directly.
 *
 * Before: the triggers ran as the caller, so gasflow_app needed INSERT on the
 * trail tables and an app bug could have inserted fake trail rows.
 * After:
 *   - lifecycle_log_change() and lifecycle_data_change_log() run as the owner
 *     (SECURITY DEFINER, fixed search_path); they never check the caller's role.
 *   - The runtime roles lose INSERT / UPDATE / DELETE on status_change_logs and
 *     data_change_logs (including its partitions).
 *   - maintenance_override_logs keeps INSERT only for gasflow_maintenance members,
 *     the only callers for which lifecycle_override_audit() writes a row.
 *
 * Design: gasflow-design-v7/08-record-lifecycle-standard.md §8-9 (2026-11-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER FUNCTION lifecycle_log_change() SECURITY DEFINER SET search_path = public, pg_temp;
            ALTER FUNCTION lifecycle_data_change_log() SECURITY DEFINER SET search_path = public, pg_temp;

            REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON status_change_logs FROM gasflow_app, gasflow_maintenance;
            REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON data_change_logs FROM gasflow_app, gasflow_maintenance;
            REVOKE ALL ON data_change_logs_default FROM gasflow_app, gasflow_maintenance;
            REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON maintenance_override_logs FROM gasflow_app;
            REVOKE UPDATE, DELETE, TRUNCATE ON maintenance_override_logs FROM gasflow_maintenance;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            GRANT INSERT, UPDATE, DELETE ON status_change_logs, data_change_logs, maintenance_override_logs
                TO gasflow_app, gasflow_maintenance;
            GRANT SELECT, INSERT, UPDATE, DELETE ON data_change_logs_default TO gasflow_app, gasflow_maintenance;

            ALTER FUNCTION lifecycle_data_change_log() SECURITY INVOKER RESET search_path;
            ALTER FUNCTION lifecycle_log_change() SECURITY INVOKER RESET search_path;
            SQL);
    }
};
