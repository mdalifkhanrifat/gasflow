<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Generic status rules between a parent and its children (design 08 §2.3c,
 * V7.19, D46). Each is declared with one LifecycleSchema call per table and
 * can be changed later by calling it again with other statuses: the call
 * replaces its named trigger.
 *
 * - No new children once the parent is closed
 *   (LifecycleSchema::refuseNewChildrenWhenParentClosed()): an INSERT, or a
 *   move to another parent, is refused while the parent has one of the given
 *   statuses; existing children may still change (an invoice of a locked
 *   cycle can still be paid). It reuses lifecycle_guard_closed_parent() on
 *   INSERT and on UPDATE of the foreign key only. Trigger
 *   trg_<table>_closed_parent_insert.
 * - A child needs its parent in certain statuses
 *   (LifecycleSchema::requireParentStatus()): lifecycle_guard_parent_status()
 *   refuses writing a child in one of the given statuses (or in any status)
 *   while its parent is not in the required ones, for example a billing
 *   cycle may only calculate once its reading period is approved. It checks
 *   when the child is written or changes status or parent. Trigger
 *   trg_<table>_parent_<name>.
 * - A parent needs all its children in certain statuses
 *   (LifecycleSchema::requireChildrenStatus()):
 *   lifecycle_guard_children_status() refuses moving the parent into one of
 *   the given statuses while a visible child is in another status, for
 *   example a billing cycle is cancelled only after its invoices are void.
 *   Trigger trg_<parent>_children_<name>.
 *
 * All three read the other row with FOR SHARE, so the rule cannot be broken
 * by two transactions at once, and let the maintenance override through.
 * The entry points check tables, column and statuses and are owner only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Shared argument checks of the entry points.
            CREATE OR REPLACE FUNCTION lifecycle_assert_status_link(
              p_child TEXT, p_parent TEXT, p_column TEXT,
              p_child_statuses TEXT[], p_parent_statuses TEXT[], p_name TEXT
            ) RETURNS VOID LANGUAGE plpgsql AS $$
            DECLARE unknown TEXT; t TEXT;
            BEGIN
              FOREACH t IN ARRAY ARRAY[p_child, p_parent] LOOP
                IF NOT EXISTS (SELECT 1 FROM lifecycle_entities WHERE entity_name = t) THEN
                  RAISE EXCEPTION 'Table % is not registered in lifecycle_entities', t;
                END IF;
              END LOOP;
              IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                             WHERE table_schema = current_schema() AND table_name = p_child AND column_name = p_column) THEN
                RAISE EXCEPTION 'Column %.% does not exist', p_child, p_column;
              END IF;
              IF p_name !~ '^[a-z][a-z0-9_]*$' THEN
                RAISE EXCEPTION 'Rule name "%" must be lower_snake_case', p_name;
              END IF;
              SELECT string_agg(s, ', ') INTO unknown FROM unnest(p_child_statuses) s
                WHERE NOT EXISTS (SELECT 1 FROM status_definitions d WHERE d.entity_name = lifecycle_status_set(p_child) AND d.code = s);
              IF unknown IS NOT NULL THEN
                RAISE EXCEPTION 'Unknown status of %: %', p_child, unknown;
              END IF;
              SELECT string_agg(s, ', ') INTO unknown FROM unnest(p_parent_statuses) s
                WHERE NOT EXISTS (SELECT 1 FROM status_definitions d WHERE d.entity_name = lifecycle_status_set(p_parent) AND d.code = s);
              IF unknown IS NOT NULL THEN
                RAISE EXCEPTION 'Unknown status of %: %', p_parent, unknown;
              END IF;
            END; $$;

            -- (1) No new children of a closed parent.
            CREATE OR REPLACE FUNCTION lifecycle_refuse_children_of_closed_parent(
              p_table TEXT, p_parent TEXT, p_column TEXT, p_closed_statuses TEXT[]
            ) RETURNS VOID LANGUAGE plpgsql AS $$
            BEGIN
              IF COALESCE(cardinality(p_closed_statuses), 0) = 0 THEN
                RAISE EXCEPTION 'Name at least one status of % that closes %', p_parent, p_table;
              END IF;
              PERFORM lifecycle_assert_status_link(p_table, p_parent, p_column, '{}', p_closed_statuses, 'closed_parent');
              EXECUTE format(
                'CREATE OR REPLACE TRIGGER %I BEFORE INSERT OR UPDATE OF %I ON %I FOR EACH ROW EXECUTE FUNCTION lifecycle_guard_closed_parent(%s)',
                'trg_' || p_table || '_closed_parent_insert', p_column, p_table,
                (SELECT string_agg(quote_literal(arg), ', ' ORDER BY n)
                   FROM unnest(ARRAY[p_parent, p_column] || p_closed_statuses) WITH ORDINALITY AS t(arg, n))
              );
            END; $$;

            -- (2) A child in some statuses needs its parent in others.
            -- Arguments: parent table, foreign key, required parent statuses (comma-separated),
            -- child statuses that need them (comma-separated, '*' = every status).
            CREATE OR REPLACE FUNCTION lifecycle_guard_parent_status() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE
              parent_table TEXT := TG_ARGV[0];
              fk_column TEXT := TG_ARGV[1];
              parent_statuses TEXT[] := string_to_array(TG_ARGV[2], ',');
              child_statuses TEXT[] := string_to_array(TG_ARGV[3], ',');
              parent_id BIGINT := (to_jsonb(NEW) ->> fk_column)::BIGINT;
              parent_status TEXT;
            BEGIN
              IF lifecycle_override_active() OR parent_id IS NULL THEN
                RETURN NEW;
              END IF;
              IF NOT ('*' = ANY (child_statuses) OR NEW.status = ANY (child_statuses)) THEN
                RETURN NEW;
              END IF;
              IF TG_OP = 'UPDATE' AND NEW.status IS NOT DISTINCT FROM OLD.status
                 AND (to_jsonb(OLD) ->> fk_column) IS NOT DISTINCT FROM (to_jsonb(NEW) ->> fk_column) THEN
                RETURN NEW;
              END IF;
              EXECUTE format('SELECT status FROM %I WHERE id = $1 FOR SHARE', parent_table)
                INTO parent_status USING parent_id;
              -- A missing parent is reported by its foreign key.
              IF parent_status IS NOT NULL AND NOT parent_status = ANY (parent_statuses) THEN
                RAISE EXCEPTION '% % is %: % in status % needs it to be %',
                  parent_table, parent_id, parent_status, TG_TABLE_NAME, NEW.status, array_to_string(parent_statuses, ' or ')
                  USING HINT = 'Move the parent on first (or correct it through the maintenance override).';
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE FUNCTION lifecycle_require_parent_status(
              p_table TEXT, p_name TEXT, p_parent TEXT, p_column TEXT, p_parent_statuses TEXT[], p_child_statuses TEXT[]
            ) RETURNS VOID LANGUAGE plpgsql AS $$
            BEGIN
              IF COALESCE(cardinality(p_parent_statuses), 0) = 0 THEN
                RAISE EXCEPTION 'Name at least one status of % that % needs', p_parent, p_table;
              END IF;
              PERFORM lifecycle_assert_status_link(p_table, p_parent, p_column, COALESCE(p_child_statuses, '{}'), p_parent_statuses, p_name);
              EXECUTE format(
                'CREATE OR REPLACE TRIGGER %I BEFORE INSERT OR UPDATE OF status, %I ON %I FOR EACH ROW EXECUTE FUNCTION lifecycle_guard_parent_status(%L, %L, %L, %L)',
                'trg_' || p_table || '_parent_' || p_name, p_column, p_table,
                p_parent, p_column, array_to_string(p_parent_statuses, ','),
                CASE WHEN COALESCE(cardinality(p_child_statuses), 0) = 0 THEN '*' ELSE array_to_string(p_child_statuses, ',') END
              );
            END; $$;

            -- (3) A parent entering some statuses needs all its visible children in others.
            -- Arguments: child table, foreign key, parent statuses that need it (comma-separated),
            -- allowed child statuses (comma-separated).
            CREATE OR REPLACE FUNCTION lifecycle_guard_children_status() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE
              child_table TEXT := TG_ARGV[0];
              fk_column TEXT := TG_ARGV[1];
              when_statuses TEXT[] := string_to_array(TG_ARGV[2], ',');
              child_statuses TEXT[] := string_to_array(TG_ARGV[3], ',');
              blocking BIGINT;
              found_statuses TEXT;
            BEGIN
              IF lifecycle_override_active() OR NOT NEW.status = ANY (when_statuses)
                 OR NEW.status IS NOT DISTINCT FROM OLD.status THEN
                RETURN NEW;
              END IF;
              EXECUTE format(
                'SELECT count(*), string_agg(DISTINCT status, '', '') FROM (SELECT status FROM %I WHERE %I = $1 AND NOT is_deleted AND NOT (status = ANY ($2)) FOR SHARE) c',
                child_table, fk_column)
                INTO blocking, found_statuses USING NEW.id, child_statuses;
              IF blocking > 0 THEN
                RAISE EXCEPTION '% % cannot become %: % of its % are % (must be %)',
                  TG_TABLE_NAME, NEW.id, NEW.status, blocking, child_table, found_statuses, array_to_string(child_statuses, ' or ')
                  USING HINT = 'Move those records on first (or correct them through the maintenance override).';
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE FUNCTION lifecycle_require_children_status(
              p_parent TEXT, p_name TEXT, p_child TEXT, p_column TEXT, p_when_statuses TEXT[], p_child_statuses TEXT[]
            ) RETURNS VOID LANGUAGE plpgsql AS $$
            BEGIN
              IF COALESCE(cardinality(p_when_statuses), 0) = 0 OR COALESCE(cardinality(p_child_statuses), 0) = 0 THEN
                RAISE EXCEPTION 'Name the statuses of % that need it and at least one allowed status of %', p_parent, p_child;
              END IF;
              PERFORM lifecycle_assert_status_link(p_child, p_parent, p_column, p_child_statuses, p_when_statuses, p_name);
              EXECUTE format(
                'CREATE OR REPLACE TRIGGER %I BEFORE UPDATE OF status ON %I FOR EACH ROW EXECUTE FUNCTION lifecycle_guard_children_status(%L, %L, %L, %L)',
                'trg_' || p_parent || '_children_' || p_name, p_parent,
                p_child, p_column, array_to_string(p_when_statuses, ','), array_to_string(p_child_statuses, ',')
              );
            END; $$;

            REVOKE EXECUTE ON FUNCTION lifecycle_assert_status_link(TEXT, TEXT, TEXT, TEXT[], TEXT[], TEXT),
                                       lifecycle_refuse_children_of_closed_parent(TEXT, TEXT, TEXT, TEXT[]),
                                       lifecycle_require_parent_status(TEXT, TEXT, TEXT, TEXT, TEXT[], TEXT[]),
                                       lifecycle_require_children_status(TEXT, TEXT, TEXT, TEXT, TEXT[], TEXT[])
              FROM PUBLIC, gasflow_app, gasflow_maintenance;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS lifecycle_require_children_status(TEXT, TEXT, TEXT, TEXT, TEXT[], TEXT[]);
            DROP FUNCTION IF EXISTS lifecycle_guard_children_status();
            DROP FUNCTION IF EXISTS lifecycle_require_parent_status(TEXT, TEXT, TEXT, TEXT, TEXT[], TEXT[]);
            DROP FUNCTION IF EXISTS lifecycle_guard_parent_status();
            DROP FUNCTION IF EXISTS lifecycle_refuse_children_of_closed_parent(TEXT, TEXT, TEXT, TEXT[]);
            DROP FUNCTION IF EXISTS lifecycle_assert_status_link(TEXT, TEXT, TEXT, TEXT[], TEXT[], TEXT);
            SQL);
    }
};
