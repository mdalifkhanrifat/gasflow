<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Columns that are written once (owner decision D51, design V7.24): a column
 * may go from NULL to a value, and from then on never changes (a sent time,
 * a processed time, a stored response). Only the maintenance override may
 * still correct it.
 *
 * lifecycle_write_once(table, name, columns) checks the table and columns and
 * creates the trigger trg_<table>_write_once_<name> on the generic
 * lifecycle_guard_write_once(). Calling it again replaces the rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION lifecycle_guard_write_once() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE
              old_row JSONB := to_jsonb(OLD);
              new_row JSONB := to_jsonb(NEW);
              changed TEXT;
            BEGIN
              IF lifecycle_override_active() THEN
                RETURN NEW;
              END IF;
              SELECT string_agg(c, ', ' ORDER BY n) INTO changed
                FROM unnest(TG_ARGV) WITH ORDINALITY AS t(c, n)
                WHERE old_row -> c <> 'null'::jsonb AND old_row -> c IS DISTINCT FROM new_row -> c;
              IF changed IS NOT NULL THEN
                RAISE EXCEPTION '% %: % is written once and can no longer change', TG_TABLE_NAME, COALESCE(old_row ->> 'id', '?'), changed
                  USING HINT = 'Create a new record (or correct it through the maintenance override).';
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE FUNCTION lifecycle_write_once(p_table TEXT, p_name TEXT, p_columns TEXT[])
            RETURNS VOID LANGUAGE plpgsql AS $$
            DECLARE problem TEXT;
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM lifecycle_entities WHERE entity_name = p_table) THEN
                RAISE EXCEPTION 'Table % is not registered in lifecycle_entities', p_table;
              END IF;
              IF p_name !~ '^[a-z][a-z0-9_]*$' THEN
                RAISE EXCEPTION 'Write-once name "%" must be lower_snake_case', p_name;
              END IF;
              IF COALESCE(cardinality(p_columns), 0) = 0 THEN
                RAISE EXCEPTION 'Name the write-once columns of %', p_table;
              END IF;
              SELECT string_agg(c, ', ') INTO problem FROM unnest(p_columns) c
                WHERE NOT EXISTS (SELECT 1 FROM information_schema.columns
                                  WHERE table_schema = current_schema() AND table_name = p_table AND column_name = c);
              IF problem IS NOT NULL THEN
                RAISE EXCEPTION 'Unknown column of %: %', p_table, problem;
              END IF;
              SELECT string_agg(c, ', ') INTO problem FROM unnest(p_columns) c
                WHERE c IN ('status','is_active','is_deleted','deleted_at','created_at','updated_at',
                            'created_by','updated_by','created_session_id','updated_session_id');
              IF problem IS NOT NULL THEN
                RAISE EXCEPTION 'Lifecycle columns follow the lifecycle rules and cannot be written once: %', problem;
              END IF;

              EXECUTE format(
                'CREATE OR REPLACE TRIGGER %I BEFORE UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION lifecycle_guard_write_once(%s)',
                'trg_' || p_table || '_write_once_' || p_name, p_table,
                (SELECT string_agg(quote_literal(c), ', ' ORDER BY n) FROM unnest(p_columns) WITH ORDINALITY AS t(c, n))
              );
            END; $$;

            REVOKE EXECUTE ON FUNCTION lifecycle_write_once(TEXT, TEXT, TEXT[]) FROM PUBLIC, gasflow_app, gasflow_maintenance;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS lifecycle_write_once(TEXT, TEXT, TEXT[]);
            DROP FUNCTION IF EXISTS lifecycle_guard_write_once();
            SQL);
    }
};
