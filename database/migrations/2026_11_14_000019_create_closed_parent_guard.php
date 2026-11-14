<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Freeze child rows once their parent is closed (design 08 §V7.15).
 *
 * Some records are closed by their parent's status: meter readings of an
 * approved or locked reading period, and later invoices of a closed billing
 * cycle. The rule is the same each time, so it lives in two generic functions:
 *
 * - lifecycle_guard_closed_parent(): the trigger. Arguments: parent table,
 *   foreign-key column, then the parent statuses that close it. It refuses
 *   INSERT and UPDATE of a child whose (old or new) parent has one of those
 *   statuses. The parent row is read FOR SHARE, so a parent cannot be closed
 *   while a child change is still in flight. The maintenance override (with
 *   its reason and log) may still correct a closed record.
 * - lifecycle_freeze_with_parent(): the migration entry point (through
 *   LifecycleSchema::freezeWhenParentClosed()). It checks that the parent is
 *   registered, the column exists and every status is known, then creates the
 *   trigger trg_<table>_closed_parent. Owner only, like lifecycle_attach().
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION lifecycle_guard_closed_parent() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE
              parent_table TEXT := TG_ARGV[0];
              fk_column TEXT := TG_ARGV[1];
              closed_statuses TEXT[] := TG_ARGV[2:array_upper(TG_ARGV, 1)];
              parent_ids BIGINT[] := ARRAY[(to_jsonb(NEW) ->> fk_column)::BIGINT];
              parent_id BIGINT;
              parent_status TEXT;
            BEGIN
              IF lifecycle_override_active() THEN
                RETURN NEW;
              END IF;
              IF TG_OP = 'UPDATE' THEN
                parent_ids := parent_ids || (to_jsonb(OLD) ->> fk_column)::BIGINT;
              END IF;
              FOREACH parent_id IN ARRAY parent_ids LOOP
                CONTINUE WHEN parent_id IS NULL;
                EXECUTE format('SELECT status FROM %I WHERE id = $1 FOR SHARE', parent_table)
                  INTO parent_status USING parent_id;
                IF parent_status = ANY (closed_statuses) THEN
                  RAISE EXCEPTION '% % is %: its % can no longer change', parent_table, parent_id, parent_status, TG_TABLE_NAME
                    USING HINT = 'Correct a closed record through the maintenance override.';
                END IF;
              END LOOP;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE FUNCTION lifecycle_freeze_with_parent(
              p_table TEXT, p_parent TEXT, p_column TEXT, p_closed_statuses TEXT[]
            ) RETURNS VOID LANGUAGE plpgsql AS $$
            DECLARE unknown TEXT;
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM lifecycle_entities WHERE entity_name = p_parent) THEN
                RAISE EXCEPTION 'Parent table % is not registered in lifecycle_entities', p_parent;
              END IF;
              IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                             WHERE table_schema = current_schema() AND table_name = p_table AND column_name = p_column) THEN
                RAISE EXCEPTION 'Column %.% does not exist', p_table, p_column;
              END IF;
              IF COALESCE(cardinality(p_closed_statuses), 0) = 0 THEN
                RAISE EXCEPTION 'Name at least one status of % that closes %', p_parent, p_table;
              END IF;
              SELECT string_agg(s, ', ') INTO unknown
                FROM unnest(p_closed_statuses) s
                WHERE NOT EXISTS (SELECT 1 FROM status_definitions d WHERE d.entity_name = p_parent AND d.code = s);
              IF unknown IS NOT NULL THEN
                RAISE EXCEPTION 'Unknown status of %: %', p_parent, unknown;
              END IF;

              EXECUTE format(
                'CREATE OR REPLACE TRIGGER %I BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION lifecycle_guard_closed_parent(%s)',
                'trg_' || p_table || '_closed_parent',
                p_table,
                (SELECT string_agg(quote_literal(arg), ', ' ORDER BY n)
                   FROM unnest(ARRAY[p_parent, p_column] || p_closed_statuses) WITH ORDINALITY AS t(arg, n))
              );
            END; $$;

            REVOKE EXECUTE ON FUNCTION lifecycle_freeze_with_parent(TEXT, TEXT, TEXT, TEXT[])
              FROM PUBLIC, gasflow_app, gasflow_maintenance;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS lifecycle_freeze_with_parent(TEXT, TEXT, TEXT, TEXT[]);
            DROP FUNCTION IF EXISTS lifecycle_guard_closed_parent();
            SQL);
    }
};
