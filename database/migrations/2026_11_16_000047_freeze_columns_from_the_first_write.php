<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Columns that are frozen from the first write (owner decision D47, design
 * V7.20, group 11 question 6).
 *
 * LifecycleSchema::freezeColumns() now also accepts no editable status: the
 * columns can then never change once the row is written (a payment
 * allocation's payment, invoice and amount; later the ledger lines). The
 * generic trigger lifecycle_guard_frozen_columns() already works that way
 * for an empty list, so only the entry point lifecycle_freeze_columns()
 * changes. A correction is a new record or a status change (an allocation
 * is reversed), or the maintenance override.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared($this->freezeColumnsFunction(
            "IF COALESCE(cardinality(p_columns), 0) = 0 THEN
                RAISE EXCEPTION 'Name the columns to freeze of %', p_table;
              END IF;
              p_editable_statuses := COALESCE(p_editable_statuses, '{}');",
        ));
    }

    public function down(): void
    {
        DB::unprepared($this->freezeColumnsFunction(
            "IF COALESCE(cardinality(p_columns), 0) = 0 OR COALESCE(cardinality(p_editable_statuses), 0) = 0 THEN
                RAISE EXCEPTION 'Name the columns to freeze and at least one editable status of %', p_table;
              END IF;",
        ));
    }

    /**
     * lifecycle_freeze_columns() as in 2026_11_14_000023, with the given
     * check of its column and status lists.
     *
     * @param  literal-string  $listCheck
     * @return literal-string
     */
    private function freezeColumnsFunction(string $listCheck): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION lifecycle_freeze_columns(
              p_table TEXT, p_name TEXT, p_columns TEXT[], p_editable_statuses TEXT[]
            ) RETURNS VOID LANGUAGE plpgsql AS \$\$
            DECLARE problem TEXT;
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM lifecycle_entities WHERE entity_name = p_table) THEN
                RAISE EXCEPTION 'Table % is not registered in lifecycle_entities', p_table;
              END IF;
              IF p_name !~ '^[a-z][a-z0-9_]*$' THEN
                RAISE EXCEPTION 'Freeze name "%" must be lower_snake_case', p_name;
              END IF;
              {$listCheck}
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
            END; \$\$;

            REVOKE EXECUTE ON FUNCTION lifecycle_freeze_columns(TEXT, TEXT, TEXT[], TEXT[])
              FROM PUBLIC, gasflow_app, gasflow_maintenance;
            SQL;
    }
};
