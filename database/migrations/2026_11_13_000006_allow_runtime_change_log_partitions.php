<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The scheduler runs as gasflow_app, which cannot CREATE TABLE. The monthly
 * partition helper therefore runs as the owner (SECURITY DEFINER). Each new
 * partition gets no direct rights for the runtime roles, the same as the
 * default partition: rows only arrive through the parent table's trigger path.
 *
 * Design: gasflow-design-v7/08-record-lifecycle-standard.md §9 (2026-11-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION data_change_logs_ensure_partition(p_month DATE)
            RETURNS VOID LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
            DECLARE
              from_d DATE := date_trunc('month', p_month);
              part TEXT := 'data_change_logs_' || to_char(date_trunc('month', p_month), 'YYYY_MM');
              runtime_role TEXT;
            BEGIN
              IF to_regclass(part) IS NOT NULL THEN
                RETURN;
              END IF;
              EXECUTE format('CREATE TABLE %I PARTITION OF data_change_logs FOR VALUES FROM (%L) TO (%L)',
                             part, from_d, (from_d + INTERVAL '1 month')::date);
              FOREACH runtime_role IN ARRAY ARRAY['gasflow_app', 'gasflow_maintenance'] LOOP
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = runtime_role) THEN
                  EXECUTE format('REVOKE ALL ON %I FROM %I', part, runtime_role);
                END IF;
              END LOOP;
            END; $$;

            REVOKE EXECUTE ON FUNCTION data_change_logs_ensure_partition(DATE) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION data_change_logs_ensure_partition(DATE) TO gasflow_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION data_change_logs_ensure_partition(p_month DATE)
            RETURNS VOID LANGUAGE plpgsql SECURITY INVOKER AS $$
            DECLARE from_d DATE := date_trunc('month', p_month); part TEXT;
            BEGIN
              part := 'data_change_logs_' || to_char(from_d, 'YYYY_MM');
              IF to_regclass(part) IS NULL THEN
                EXECUTE format('CREATE TABLE %I PARTITION OF data_change_logs FOR VALUES FROM (%L) TO (%L)',
                               part, from_d, (from_d + INTERVAL '1 month')::date);
              END IF;
            END; $$;
            ALTER FUNCTION data_change_logs_ensure_partition(DATE) RESET search_path;
            GRANT EXECUTE ON FUNCTION data_change_logs_ensure_partition(DATE) TO PUBLIC;
            SQL);
    }
};
