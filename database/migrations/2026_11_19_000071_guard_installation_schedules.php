<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An installation agreement and its installments agree (owner decision D50,
 * design V7.23, group 13 questions 2 and 3):
 *
 * - installation_schedule_totals(agreement): its parts and their sum. An
 *   agreement leaves draft (or starts outside draft) only when it has
 *   installment_count parts adding up to principal_amount; a cancelled draft
 *   needs nothing. A part is due on or after the agreement's start_date.
 * - Enforced transitions. Agreements: draft → active → completed /
 *   defaulted / cancelled, defaulted → active, draft → cancelled. Parts:
 *   scheduled → invoiced → partially paid → paid, overdue while something is
 *   due, waived instead of paid.
 * - installment_status_matches_payment(): a part's status says what was
 *   paid (scheduled and invoiced: nothing, partially paid: some, paid: all,
 *   overdue: not all).
 * - Generic rules: the agreement's terms are frozen after draft
 *   (freezeColumns); parts are added and their schedule changed only while
 *   the agreement is a draft (refuseNewChildrenWhenParentClosed,
 *   freezeColumnsWithParent); a part is billed only on an approved agreement
 *   (requireParentStatus); an agreement is completed only when every part is
 *   paid or waived (requireChildrenStatus).
 */
return new class extends Migration
{
    /** Agreement statuses after draft. */
    private const APPROVED = ['active', 'completed', 'defaulted', 'cancelled'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION installation_schedule_totals(p_agreement_id BIGINT)
            RETURNS TABLE(parts INTEGER, amount NUMERIC(14,2)) LANGUAGE sql STABLE AS $$
              SELECT count(*)::INTEGER, COALESCE(sum(i.amount), 0)::NUMERIC(14,2)
              FROM installation_installments i
              WHERE i.installation_agreement_id = p_agreement_id AND NOT i.is_deleted
            $$;

            CREATE OR REPLACE FUNCTION validate_installation_schedule() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE t RECORD;
            BEGIN
              IF NEW.status IN ('draft','cancelled') OR (TG_OP = 'UPDATE' AND OLD.status <> 'draft') THEN
                RETURN NEW;
              END IF;
              SELECT * INTO t FROM installation_schedule_totals(NEW.id);
              IF t.parts <> NEW.installment_count OR t.amount <> NEW.principal_amount THEN
                RAISE EXCEPTION 'Installation agreement % has % parts of %, not % parts of %',
                  NEW.agreement_no, t.parts, t.amount, NEW.installment_count, NEW.principal_amount
                  USING HINT = 'Schedule every part while the agreement is a draft.';
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_installation_agreements_schedule
            BEFORE INSERT OR UPDATE OF status ON installation_agreements
            FOR EACH ROW EXECUTE FUNCTION validate_installation_schedule();

            CREATE OR REPLACE FUNCTION validate_installment_due_date() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE starts DATE;
            BEGIN
              SELECT start_date INTO starts FROM installation_agreements WHERE id = NEW.installation_agreement_id;
              IF NEW.due_date < starts THEN
                RAISE EXCEPTION 'Installment % is due on %, before its agreement starts on %', NEW.installment_no, NEW.due_date, starts;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_installation_installments_due_date
            BEFORE INSERT OR UPDATE OF due_date, installation_agreement_id ON installation_installments
            FOR EACH ROW EXECUTE FUNCTION validate_installment_due_date();

            CREATE OR REPLACE FUNCTION installment_status_matches_payment(p_status TEXT, p_amount NUMERIC, p_paid NUMERIC)
            RETURNS BOOLEAN LANGUAGE sql IMMUTABLE AS $$
              SELECT CASE p_status
                       WHEN 'scheduled' THEN p_paid = 0
                       WHEN 'invoiced' THEN p_paid = 0
                       WHEN 'partially_paid' THEN p_paid > 0 AND p_paid < p_amount
                       WHEN 'paid' THEN p_paid = p_amount
                       WHEN 'overdue' THEN p_paid < p_amount
                       ELSE true
                     END
            $$;
            ALTER TABLE installation_installments
              ADD CONSTRAINT ck_installation_installments_status_payment
                CHECK (installment_status_matches_payment(status, amount, paid_amount));

            UPDATE lifecycle_entities SET enforce_transitions = true
              WHERE entity_name IN ('installation_agreements', 'installation_installments');
            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('installation_agreements', 'draft',     'active',    'installation.approve', false),
                   ('installation_agreements', 'draft',     'cancelled', 'installation.cancel',  true),
                   ('installation_agreements', 'active',    'completed', NULL,                   false),
                   ('installation_agreements', 'active',    'defaulted', 'installation.default', true),
                   ('installation_agreements', 'active',    'cancelled', 'installation.cancel',  true),
                   ('installation_agreements', 'defaulted', 'active',    'installation.default', true),
                   ('installation_installments', 'scheduled',      'invoiced',       NULL,                 false),
                   ('installation_installments', 'scheduled',      'waived',         'installation.waive', true),
                   ('installation_installments', 'invoiced',       'partially_paid', NULL,                 false),
                   ('installation_installments', 'invoiced',       'paid',           NULL,                 false),
                   ('installation_installments', 'invoiced',       'overdue',        NULL,                 false),
                   ('installation_installments', 'invoiced',       'waived',         'installation.waive', true),
                   ('installation_installments', 'partially_paid', 'paid',           NULL,                 false),
                   ('installation_installments', 'partially_paid', 'overdue',        NULL,                 false),
                   ('installation_installments', 'partially_paid', 'waived',         'installation.waive', true),
                   ('installation_installments', 'overdue',        'partially_paid', NULL,                 false),
                   ('installation_installments', 'overdue',        'paid',           NULL,                 false),
                   ('installation_installments', 'overdue',        'waived',         'installation.waive', true);
            SQL);

        LifecycleSchema::freezeColumns('installation_agreements',
            ['building_id', 'customer_id', 'agreement_no', 'principal_amount', 'currency', 'installment_count', 'start_date'],
            editableStatuses: ['draft'], name: 'terms');
        LifecycleSchema::refuseNewChildrenWhenParentClosed('installation_installments', parent: 'installation_agreements',
            foreignKey: 'installation_agreement_id', closedStatuses: self::APPROVED);
        LifecycleSchema::freezeColumnsWithParent('installation_installments', parent: 'installation_agreements',
            foreignKey: 'installation_agreement_id',
            columns: ['installation_agreement_id', 'building_id', 'installment_no', 'due_date', 'amount'],
            closedStatuses: self::APPROVED, name: 'schedule');
        LifecycleSchema::requireParentStatus('installation_installments', parent: 'installation_agreements',
            foreignKey: 'installation_agreement_id', parentStatuses: ['active', 'defaulted', 'completed'],
            whenStatuses: ['invoiced', 'partially_paid', 'paid', 'overdue'], name: 'billing');
        LifecycleSchema::requireChildrenStatus('installation_agreements', child: 'installation_installments',
            foreignKey: 'installation_agreement_id', whenStatuses: ['completed'], childStatuses: ['paid', 'waived'], name: 'completion');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_installation_agreements_children_completion ON installation_agreements;
            DROP TRIGGER IF EXISTS trg_installation_installments_parent_billing ON installation_installments;
            DROP TRIGGER IF EXISTS trg_installation_installments_parent_freeze_schedule ON installation_installments;
            DROP TRIGGER IF EXISTS trg_installation_installments_closed_parent_insert ON installation_installments;
            DROP TRIGGER IF EXISTS trg_installation_agreements_freeze_terms ON installation_agreements;
            DELETE FROM status_transitions WHERE entity_name IN ('installation_agreements', 'installation_installments');
            UPDATE lifecycle_entities SET enforce_transitions = false
              WHERE entity_name IN ('installation_agreements', 'installation_installments');
            ALTER TABLE installation_installments DROP CONSTRAINT ck_installation_installments_status_payment;
            DROP FUNCTION IF EXISTS installment_status_matches_payment(TEXT, NUMERIC, NUMERIC);
            DROP TRIGGER IF EXISTS trg_installation_installments_due_date ON installation_installments;
            DROP FUNCTION IF EXISTS validate_installment_due_date();
            DROP TRIGGER IF EXISTS trg_installation_agreements_schedule ON installation_agreements;
            DROP FUNCTION IF EXISTS validate_installation_schedule();
            DROP FUNCTION IF EXISTS installation_schedule_totals(BIGINT);
            SQL);
    }
};
