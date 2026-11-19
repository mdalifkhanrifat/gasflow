<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Freeze some columns of a child once its parent reaches a status (owner
 * decision D50, design 08 §2.3d, V7.23).
 *
 * freezeColumns() freezes columns by the row's own status, and
 * freezeWhenParentClosed() freezes a whole child by its parent's status. An
 * installment needs the middle: its amount and due date stop changing once
 * its agreement is active, while its status and paid amount still move.
 *
 * - lifecycle_guard_parent_frozen_columns(): the trigger. Arguments: parent
 *   table, foreign-key column, the closing statuses (comma separated), then
 *   the columns. It refuses an UPDATE that changes one of the columns while
 *   the row's (old) parent has a closing status. The parent row is read FOR
 *   SHARE. The maintenance override passes.
 * - lifecycle_freeze_columns_with_parent(): the migration entry point
 *   (through LifecycleSchema::freezeColumnsWithParent()). It checks the
 *   parent, the columns (no lifecycle columns) and the statuses, then
 *   creates trg_<table>_parent_freeze_<name>. Calling it again with the same
 *   name replaces the rule. Owner only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION lifecycle_guard_parent_frozen_columns() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE
              parent_table TEXT := TG_ARGV[0];
              fk_column TEXT := TG_ARGV[1];
              closed_statuses TEXT[] := string_to_array(TG_ARGV[2], ',');
              old_row JSONB := to_jsonb(OLD);
              new_row JSONB := to_jsonb(NEW);
              changed TEXT;
              parent_id BIGINT;
              parent_status TEXT;
            BEGIN
              IF lifecycle_override_active() THEN
                RETURN NEW;
              END IF;
              SELECT string_agg(c, ', ') INTO changed
                FROM unnest(TG_ARGV[3:array_upper(TG_ARGV, 1)]) c
                WHERE old_row -> c IS DISTINCT FROM new_row -> c;
              IF changed IS NULL THEN
                RETURN NEW;
              END IF;
              parent_id := (old_row ->> fk_column)::BIGINT;
              IF parent_id IS NULL THEN
                RETURN NEW;
              END IF;
              EXECUTE format('SELECT status FROM %I WHERE id = $1 FOR SHARE', parent_table)
                INTO parent_status USING parent_id;
              IF parent_status = ANY (closed_statuses) THEN
                RAISE EXCEPTION '% % is %: % of % % can no longer change',
                  parent_table, parent_id, parent_status, changed, TG_TABLE_NAME, COALESCE(old_row ->> 'id', '?')
                  USING HINT = 'Correct a closed record through the maintenance override.';
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE FUNCTION lifecycle_freeze_columns_with_parent(
              p_table TEXT, p_name TEXT, p_parent TEXT, p_column TEXT, p_columns TEXT[], p_closed_statuses TEXT[]
            ) RETURNS VOID LANGUAGE plpgsql AS $$
            DECLARE problem TEXT;
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM lifecycle_entities WHERE entity_name = p_parent) THEN
                RAISE EXCEPTION 'Parent table % is not registered in lifecycle_entities', p_parent;
              END IF;
              IF p_name !~ '^[a-z][a-z0-9_]*$' THEN
                RAISE EXCEPTION 'Freeze name "%" must be lower_snake_case', p_name;
              END IF;
              IF COALESCE(cardinality(p_columns), 0) = 0 OR COALESCE(cardinality(p_closed_statuses), 0) = 0 THEN
                RAISE EXCEPTION 'Name the columns of % to freeze and at least one status of % that freezes them', p_table, p_parent;
              END IF;
              SELECT string_agg(c, ', ') INTO problem FROM unnest(p_columns || p_column) c
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
              SELECT string_agg(s, ', ') INTO problem FROM unnest(p_closed_statuses) s
                WHERE NOT EXISTS (SELECT 1 FROM status_definitions d
                                  WHERE d.entity_name = lifecycle_status_set(p_parent) AND d.code = s);
              IF problem IS NOT NULL THEN
                RAISE EXCEPTION 'Unknown status of %: %', p_parent, problem;
              END IF;

              EXECUTE format(
                'CREATE OR REPLACE TRIGGER %I BEFORE UPDATE OF %s ON %I FOR EACH ROW EXECUTE FUNCTION lifecycle_guard_parent_frozen_columns(%s)',
                left('trg_' || p_table || '_parent_freeze_' || p_name, 63),
                (SELECT string_agg(quote_ident(c), ', ') FROM unnest(p_columns) c),
                p_table,
                (SELECT string_agg(quote_literal(arg), ', ' ORDER BY n)
                   FROM unnest(ARRAY[p_parent, p_column, array_to_string(p_closed_statuses, ',')] || p_columns)
                        WITH ORDINALITY AS t(arg, n))
              );
            END; $$;

            REVOKE EXECUTE ON FUNCTION lifecycle_freeze_columns_with_parent(TEXT, TEXT, TEXT, TEXT, TEXT[], TEXT[])
              FROM PUBLIC, gasflow_app, gasflow_maintenance;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS lifecycle_freeze_columns_with_parent(TEXT, TEXT, TEXT, TEXT, TEXT[], TEXT[]);
            DROP FUNCTION IF EXISTS lifecycle_guard_parent_frozen_columns();
            SQL);
    }
};
