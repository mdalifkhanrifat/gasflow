<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Integrity checks see every row they compare, whatever row-level security
 * hides from the caller (owner decision D52, design V7.25, group 15 question 1).
 *
 * Under row-level security a trigger runs with the caller's visibility: a row
 * of another tenant is invisible to it, the foreign key (which ignores RLS)
 * accepts the id, and a refusal turns into an acceptance. So:
 *
 * - every trigger function that reads another table, directly or through a
 *   helper, runs as gasflow_integrity (NOLOGIN, BYPASSRLS, reads only):
 *   SECURITY DEFINER with a fixed search_path. adopt_integrity_check() does it
 *   for one function; later migrations call it for new checks. The helpers
 *   themselves (balances, access rules) stay SECURITY INVOKER: inside a check
 *   they run as the integrity role, called by the app they follow its policies.
 * - functions that write (the generic lifecycle guard, the trail writers) are
 *   not integrity checks and keep their owner.
 * - a check that locks rows (FOR SHARE / FOR UPDATE) needs UPDATE privilege on
 *   them: the integrity role gets UPDATE on the id column of exactly the tables
 *   it locks. The generic parent and children rules grant it for their table
 *   (lifecycle_allow_integrity_lock()).
 * - the maintenance override checks the login role (session_user): inside a
 *   check current_user is gasflow_integrity.
 */
return new class extends Migration
{
    /**
     * Trigger functions that read another table (found from pg_proc; the
     * IntegrityChecksTest keeps this list complete).
     *
     * @var list<string>
     */
    private const CHECKS = [
        'assert_posted_journal_balanced', 'assert_posted_journal_has_transaction', 'assert_reversal_mirrors_original',
        'check_outbox_delivery', 'check_settled_invoice_status',
        'enforce_building_role_scope', 'enforce_membership_role_scope', 'enforce_platform_role_scope',
        'guard_billing_setting_timeline', 'guard_invoice_balance', 'guard_payment_balance',
        'lifecycle_guard_children_status', 'lifecycle_guard_closed_parent',
        'lifecycle_guard_parent_frozen_columns', 'lifecycle_guard_parent_status',
        'prevent_posted_journal_mutation',
        'validate_attachment_scope', 'validate_audit_log_scope',
        'validate_billing_cycle_reading_period', 'validate_billing_cycle_schedule',
        'validate_building_membership_access', 'validate_credit_note_amount', 'validate_delivery_attempt_time',
        'validate_financial_transaction_books', 'validate_financial_transaction_posting',
        'validate_financial_transaction_reversal', 'validate_flat_occupancy_customer',
        'validate_gas_supply_receipt_date', 'validate_installation_agreement_customer',
        'validate_installation_schedule', 'validate_installment_due_date', 'validate_installment_invoice_line',
        'validate_invoice_amounts_match_lines', 'validate_invoice_customer', 'validate_invoice_line_adjustment',
        'validate_invoice_line_tariff', 'validate_invoice_status_balance', 'validate_journal_line_context',
        'validate_ledger_building', 'validate_license_activation', 'validate_license_signing_key',
        'validate_notification_scope', 'validate_payment_allocation_stream', 'validate_payment_attempt_gateway',
        'validate_payment_parties', 'validate_payment_refunded', 'validate_setup_task_assignee',
        'validate_supply_change', 'validate_tariff_retro_adjustment',
        'validate_tariff_vendor_assignment_period', 'validate_webhook_inbox_provider',
    ];

    /**
     * Tables whose rows the checks lock: the parents and children named by the
     * generic rules so far, and the invoices, payments and licenses locked by
     * the balance and activation checks.
     *
     * @var list<string>
     */
    private const LOCKED_TABLES = [
        'billing_cycles', 'financial_journals', 'installation_agreements', 'installation_installments',
        'integration_outbox', 'invoices', 'licenses', 'payment_allocations', 'payments', 'reading_periods',
        'supply_changes',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION lifecycle_override_active() RETURNS BOOLEAN LANGUAGE plpgsql STABLE AS $$
            BEGIN
              IF COALESCE(current_setting('app.maintenance_override', true), '') <> 'on' THEN
                RETURN FALSE;
              END IF;
              -- session_user: the login role. Inside an integrity check current_user is gasflow_integrity.
              IF NOT pg_has_role(session_user, 'gasflow_maintenance', 'MEMBER') THEN
                RAISE EXCEPTION 'Maintenance override refused: role % is not a member of gasflow_maintenance', session_user;
              END IF;
              IF NULLIF(current_setting('app.lifecycle_reason', true), '') IS NULL THEN
                RAISE EXCEPTION 'Maintenance override refused: set app.lifecycle_reason (why this manual change is made)';
              END IF;
              RETURN TRUE;
            END; $$;

            CREATE OR REPLACE FUNCTION adopt_integrity_check(p_function REGPROCEDURE) RETURNS VOID LANGUAGE plpgsql AS $$
            BEGIN
              IF (SELECT prorettype FROM pg_proc WHERE oid = p_function) <> 'trigger'::regtype THEN
                RAISE EXCEPTION '% is not a trigger function: only checks run as gasflow_integrity', p_function;
              END IF;
              EXECUTE format('ALTER FUNCTION %s OWNER TO gasflow_integrity', p_function);
              EXECUTE format('ALTER FUNCTION %s SECURITY DEFINER SET search_path = public, pg_temp', p_function);
            END; $$;

            CREATE OR REPLACE FUNCTION lifecycle_allow_integrity_lock(p_table TEXT) RETURNS VOID LANGUAGE plpgsql AS $$
            BEGIN
              EXECUTE format('GRANT UPDATE (id) ON %I TO gasflow_integrity', p_table);
            END; $$;

            REVOKE EXECUTE ON FUNCTION adopt_integrity_check(REGPROCEDURE), lifecycle_allow_integrity_lock(TEXT)
              FROM PUBLIC, gasflow_app, gasflow_maintenance;
            SQL);

        DB::unprepared($this->ruleFunctions());

        foreach (self::LOCKED_TABLES as $table) {
            DB::select('select lifecycle_allow_integrity_lock(?)', [$table]);
        }

        foreach (self::CHECKS as $function) {
            DB::select('select adopt_integrity_check(?::regprocedure)', ["{$function}()"]);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            DECLARE f REGPROCEDURE; t REGCLASS;
            BEGIN
              FOR f IN SELECT oid::regprocedure FROM pg_proc WHERE proowner = 'gasflow_integrity'::regrole LOOP
                EXECUTE format('ALTER FUNCTION %s SECURITY INVOKER RESET search_path', f);
                EXECUTE format('ALTER FUNCTION %s OWNER TO gasflow_owner', f);
              END LOOP;
              FOR t IN SELECT DISTINCT format('%I', table_name)::regclass FROM information_schema.column_privileges
                       WHERE grantee = 'gasflow_integrity' AND privilege_type = 'UPDATE' AND table_schema = 'public' LOOP
                EXECUTE format('REVOKE UPDATE (id) ON %s FROM gasflow_integrity', t);
              END LOOP;
            END $$;
            SQL);

        /** @var literal-string $rules the rule functions as they were, without the lock grant */
        $rules = preg_replace('/^.*lifecycle_allow_integrity_lock.*\n/m', '', $this->ruleFunctions());
        DB::unprepared($rules);

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS lifecycle_allow_integrity_lock(TEXT);
            DROP FUNCTION IF EXISTS adopt_integrity_check(REGPROCEDURE);

            CREATE OR REPLACE FUNCTION lifecycle_override_active() RETURNS BOOLEAN LANGUAGE plpgsql STABLE AS $$
            BEGIN
              IF COALESCE(current_setting('app.maintenance_override', true), '') <> 'on' THEN
                RETURN FALSE;
              END IF;
              IF NOT pg_has_role(current_user, 'gasflow_maintenance', 'MEMBER') THEN
                RAISE EXCEPTION 'Maintenance override refused: role % is not a member of gasflow_maintenance', current_user;
              END IF;
              IF NULLIF(current_setting('app.lifecycle_reason', true), '') IS NULL THEN
                RAISE EXCEPTION 'Maintenance override refused: set app.lifecycle_reason (why this manual change is made)';
              END IF;
              RETURN TRUE;
            END; $$;
            SQL);
    }

    /**
     * The five generic parent and children rules, each granting the lock on
     * the table its trigger locks (the line down() removes).
     *
     * @return literal-string
     */
    private function ruleFunctions(): string
    {
        return <<<'SQL'
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
              PERFORM lifecycle_allow_integrity_lock(p_parent);

              EXECUTE format(
                'CREATE OR REPLACE TRIGGER %I BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION lifecycle_guard_closed_parent(%s)',
                'trg_' || p_table || '_closed_parent',
                p_table,
                (SELECT string_agg(quote_literal(arg), ', ' ORDER BY n)
                   FROM unnest(ARRAY[p_parent, p_column] || p_closed_statuses) WITH ORDINALITY AS t(arg, n))
              );
            END; $$;

            CREATE OR REPLACE FUNCTION lifecycle_refuse_children_of_closed_parent(
              p_table TEXT, p_parent TEXT, p_column TEXT, p_closed_statuses TEXT[]
            ) RETURNS VOID LANGUAGE plpgsql AS $$
            BEGIN
              IF COALESCE(cardinality(p_closed_statuses), 0) = 0 THEN
                RAISE EXCEPTION 'Name at least one status of % that closes %', p_parent, p_table;
              END IF;
              PERFORM lifecycle_assert_status_link(p_table, p_parent, p_column, '{}', p_closed_statuses, 'closed_parent');
              PERFORM lifecycle_allow_integrity_lock(p_parent);
              EXECUTE format(
                'CREATE OR REPLACE TRIGGER %I BEFORE INSERT OR UPDATE OF %I ON %I FOR EACH ROW EXECUTE FUNCTION lifecycle_guard_closed_parent(%s)',
                'trg_' || p_table || '_closed_parent_insert', p_column, p_table,
                (SELECT string_agg(quote_literal(arg), ', ' ORDER BY n)
                   FROM unnest(ARRAY[p_parent, p_column] || p_closed_statuses) WITH ORDINALITY AS t(arg, n))
              );
            END; $$;

            CREATE OR REPLACE FUNCTION lifecycle_require_parent_status(
              p_table TEXT, p_name TEXT, p_parent TEXT, p_column TEXT, p_parent_statuses TEXT[], p_child_statuses TEXT[]
            ) RETURNS VOID LANGUAGE plpgsql AS $$
            BEGIN
              IF COALESCE(cardinality(p_parent_statuses), 0) = 0 THEN
                RAISE EXCEPTION 'Name at least one status of % that % needs', p_parent, p_table;
              END IF;
              PERFORM lifecycle_assert_status_link(p_table, p_parent, p_column, COALESCE(p_child_statuses, '{}'), p_parent_statuses, p_name);
              PERFORM lifecycle_allow_integrity_lock(p_parent);
              EXECUTE format(
                'CREATE OR REPLACE TRIGGER %I BEFORE INSERT OR UPDATE OF status, %I ON %I FOR EACH ROW EXECUTE FUNCTION lifecycle_guard_parent_status(%L, %L, %L, %L)',
                'trg_' || p_table || '_parent_' || p_name, p_column, p_table,
                p_parent, p_column, array_to_string(p_parent_statuses, ','),
                CASE WHEN COALESCE(cardinality(p_child_statuses), 0) = 0 THEN '*' ELSE array_to_string(p_child_statuses, ',') END
              );
            END; $$;

            CREATE OR REPLACE FUNCTION lifecycle_require_children_status(
              p_parent TEXT, p_name TEXT, p_child TEXT, p_column TEXT, p_when_statuses TEXT[], p_child_statuses TEXT[]
            ) RETURNS VOID LANGUAGE plpgsql AS $$
            BEGIN
              IF COALESCE(cardinality(p_when_statuses), 0) = 0 OR COALESCE(cardinality(p_child_statuses), 0) = 0 THEN
                RAISE EXCEPTION 'Name the statuses of % that need it and at least one allowed status of %', p_parent, p_child;
              END IF;
              PERFORM lifecycle_assert_status_link(p_child, p_parent, p_column, p_child_statuses, p_when_statuses, p_name);
              PERFORM lifecycle_allow_integrity_lock(p_child);
              EXECUTE format(
                'CREATE OR REPLACE TRIGGER %I BEFORE UPDATE OF status ON %I FOR EACH ROW EXECUTE FUNCTION lifecycle_guard_children_status(%L, %L, %L, %L)',
                'trg_' || p_parent || '_children_' || p_name, p_parent,
                p_child, p_column, array_to_string(p_when_statuses, ','), array_to_string(p_child_statuses, ',')
              );
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
              PERFORM lifecycle_allow_integrity_lock(p_parent);

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
            SQL;
    }
};
