<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Freeze chosen columns once a row leaves its editable statuses (design 08
 * §2.3b, V7.16).
 *
 * An approved tariff keeps its rate, a reviewed reconciliation keeps its
 * numbers, an issued invoice keeps what it bills. The rule is the same each
 * time, so it lives in two generic functions:
 *
 * - lifecycle_guard_frozen_columns(): the trigger. Arguments: the editable
 *   statuses (comma-separated), then the frozen columns. It refuses an UPDATE
 *   that changes one of those columns while the row's current (old) status is
 *   not editable, so the update that leaves the editable status may still set
 *   them. Status changes themselves stay with the transition rules. The
 *   maintenance override may still correct a frozen row.
 * - lifecycle_freeze_columns(): the migration entry point (through
 *   LifecycleSchema::freezeColumns()). It checks the table is registered,
 *   every column exists and is not a lifecycle column, and every status is
 *   known, then creates trg_<table>_freeze_<name>. Owner only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION lifecycle_guard_frozen_columns() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE
              editable_statuses TEXT[] := string_to_array(TG_ARGV[0], ',');
              frozen_columns TEXT[] := TG_ARGV[1:array_upper(TG_ARGV, 1)];
              old_row JSONB := to_jsonb(OLD);
              new_row JSONB := to_jsonb(NEW);
              changed TEXT;
            BEGIN
              IF OLD.status = ANY (editable_statuses) OR lifecycle_override_active() THEN
                RETURN NEW;
              END IF;
              SELECT string_agg(c, ', ' ORDER BY n) INTO changed
                FROM unnest(frozen_columns) WITH ORDINALITY AS t(c, n)
                WHERE old_row -> c IS DISTINCT FROM new_row -> c;
              IF changed IS NOT NULL THEN
                RAISE EXCEPTION '% % is %: % can no longer change', TG_TABLE_NAME, COALESCE(old_row ->> 'id', '?'), OLD.status, changed
                  USING HINT = 'Create a new record (or correct it through the maintenance override).';
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE FUNCTION lifecycle_freeze_columns(
              p_table TEXT, p_name TEXT, p_columns TEXT[], p_editable_statuses TEXT[]
            ) RETURNS VOID LANGUAGE plpgsql AS $$
            DECLARE problem TEXT;
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM lifecycle_entities WHERE entity_name = p_table) THEN
                RAISE EXCEPTION 'Table % is not registered in lifecycle_entities', p_table;
              END IF;
              IF p_name !~ '^[a-z][a-z0-9_]*$' THEN
                RAISE EXCEPTION 'Freeze name "%" must be lower_snake_case', p_name;
              END IF;
              IF COALESCE(cardinality(p_columns), 0) = 0 OR COALESCE(cardinality(p_editable_statuses), 0) = 0 THEN
                RAISE EXCEPTION 'Name the columns to freeze and at least one editable status of %', p_table;
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
                RAISE EXCEPTION 'Lifecycle columns follow the lifecycle rules and cannot be frozen: %', problem;
              END IF;
              SELECT string_agg(s, ', ') INTO problem FROM unnest(p_editable_statuses) s
                WHERE NOT EXISTS (SELECT 1 FROM status_definitions d
                                  WHERE d.entity_name = lifecycle_status_set(p_table) AND d.code = s);
              IF problem IS NOT NULL THEN
                RAISE EXCEPTION 'Unknown status of %: %', p_table, problem;
              END IF;

              EXECUTE format(
                'CREATE OR REPLACE TRIGGER %I BEFORE UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION lifecycle_guard_frozen_columns(%s)',
                'trg_' || p_table || '_freeze_' || p_name,
                p_table,
                (SELECT string_agg(quote_literal(arg), ', ' ORDER BY n)
                   FROM unnest(ARRAY[array_to_string(p_editable_statuses, ',')] || p_columns) WITH ORDINALITY AS t(arg, n))
              );
            END; $$;

            REVOKE EXECUTE ON FUNCTION lifecycle_freeze_columns(TEXT, TEXT, TEXT[], TEXT[])
              FROM PUBLIC, gasflow_app, gasflow_maintenance;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS lifecycle_freeze_columns(TEXT, TEXT, TEXT[], TEXT[]);
            DROP FUNCTION IF EXISTS lifecycle_guard_frozen_columns();
            SQL);
    }
};
