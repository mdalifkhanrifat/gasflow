<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Money never exceeds its source, and invoice and payment statuses say what
 * the balance says (owner decision D47, design V7.20, group 11 questions 4
 * and 7).
 *
 * Two reusable functions give the numbers to the database, the payment
 * actions and the reports, like invoice_line_totals():
 *
 * - payment_balance(payment): amount, allocated (active allocations),
 *   refunded (refunds not rejected), refunded_completed, unallocated =
 *   amount − allocated − refunded. Unallocated money is the customer's
 *   credit (an overpayment) and can settle a later invoice.
 * - invoice_balance(invoice): total, credited (approved and applied credit
 *   notes), paid (active allocations), due = total − credited − paid.
 *
 * Guards (the parent row is locked, so two writes cannot pass at once; the
 * maintenance override passes):
 *
 * - a payment's unallocated money never goes below 0 (allocations, refunds)
 * - an invoice's due never goes below 0 (allocations, credit notes); this
 *   replaces the credit-note-only sum check of 2026_11_15_000042, which now
 *   only refuses a single note above the invoice total
 * - invoice_status_matches_balance(): paid needs due = 0, partially paid
 *   needs something paid and something due, overdue needs something due,
 *   issued needs nothing paid. Checked when an invoice changes status, and
 *   again at COMMIT after an allocation or credit note changed, so the
 *   payment action must move the invoice in the same transaction.
 * - a payment is refunded only when its completed refunds equal its amount
 * - an invoice may go back from paid or partially paid (permission
 *   payment.reverse, with a reason) when a payment is reversed or refunded
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payment_balance(p_payment_id BIGINT)
            RETURNS TABLE(amount NUMERIC(14,2), allocated NUMERIC(14,2), refunded NUMERIC(14,2),
                          refunded_completed NUMERIC(14,2), unallocated NUMERIC(14,2))
            LANGUAGE sql STABLE AS $$
              SELECT p.amount, a.total, r.total, r.completed, p.amount - a.total - r.total
              FROM payments p
              CROSS JOIN LATERAL (
                SELECT COALESCE(sum(x.amount), 0)::NUMERIC(14,2) AS total FROM payment_allocations x
                WHERE x.payment_id = p.id AND x.status = 'active' AND NOT x.is_deleted) a
              CROSS JOIN LATERAL (
                SELECT COALESCE(sum(x.amount), 0)::NUMERIC(14,2) AS total,
                       COALESCE(sum(x.amount) FILTER (WHERE x.status = 'completed'), 0)::NUMERIC(14,2) AS completed
                FROM refunds x
                WHERE x.payment_id = p.id AND x.status <> 'rejected' AND NOT x.is_deleted) r
              WHERE p.id = p_payment_id
            $$;

            CREATE OR REPLACE FUNCTION invoice_balance(p_invoice_id BIGINT)
            RETURNS TABLE(total NUMERIC(14,2), credited NUMERIC(14,2), paid NUMERIC(14,2), due NUMERIC(14,2))
            LANGUAGE sql STABLE AS $$
              SELECT i.total, c.total, a.total, i.total - c.total - a.total
              FROM invoices i
              CROSS JOIN LATERAL (
                SELECT COALESCE(sum(x.amount), 0)::NUMERIC(14,2) AS total FROM credit_notes x
                WHERE x.invoice_id = i.id AND x.status IN ('approved','applied') AND NOT x.is_deleted) c
              CROSS JOIN LATERAL (
                SELECT COALESCE(sum(x.amount), 0)::NUMERIC(14,2) AS total FROM payment_allocations x
                WHERE x.invoice_id = i.id AND x.status = 'active' AND NOT x.is_deleted) a
              WHERE i.id = p_invoice_id
            $$;

            CREATE OR REPLACE FUNCTION invoice_status_matches_balance(p_status TEXT, p_paid NUMERIC, p_due NUMERIC)
            RETURNS BOOLEAN LANGUAGE sql IMMUTABLE AS $$
              SELECT CASE p_status
                       WHEN 'paid' THEN p_due = 0
                       WHEN 'partially_paid' THEN p_paid > 0 AND p_due > 0
                       WHEN 'overdue' THEN p_due > 0
                       WHEN 'issued' THEN p_paid = 0
                       ELSE true
                     END
            $$;

            -- Unallocated money of a payment never below 0.
            CREATE OR REPLACE FUNCTION guard_payment_balance() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE b RECORD;
            BEGIN
              IF lifecycle_override_active() THEN
                RETURN NULL;
              END IF;
              PERFORM 1 FROM payments WHERE id = NEW.payment_id FOR UPDATE;
              SELECT * INTO b FROM payment_balance(NEW.payment_id);
              IF b.unallocated < 0 THEN
                RAISE EXCEPTION 'Payment % has only % to give: % allocated and % refunded would exceed it',
                  NEW.payment_id, b.amount, b.allocated, b.refunded
                  USING HINT = 'Reverse an allocation first, or allocate or refund less.';
              END IF;
              RETURN NULL;
            END; $$;

            -- Due of an invoice never below 0.
            CREATE OR REPLACE FUNCTION guard_invoice_balance() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE b RECORD;
            BEGIN
              IF lifecycle_override_active() THEN
                RETURN NULL;
              END IF;
              PERFORM 1 FROM invoices WHERE id = NEW.invoice_id FOR UPDATE;
              SELECT * INTO b FROM invoice_balance(NEW.invoice_id);
              IF b.due < 0 THEN
                RAISE EXCEPTION 'Invoice % of % would be settled beyond its total: % credited and % paid',
                  NEW.invoice_id, b.total, b.credited, b.paid
                  USING HINT = 'Keep the rest on the payment as the customer''s credit.';
              END IF;
              RETURN NULL;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_payment_allocations_payment_balance
            AFTER INSERT OR UPDATE OF status ON payment_allocations
            FOR EACH ROW EXECUTE FUNCTION guard_payment_balance();
            CREATE OR REPLACE TRIGGER trg_refunds_payment_balance
            AFTER INSERT OR UPDATE OF status, amount ON refunds
            FOR EACH ROW EXECUTE FUNCTION guard_payment_balance();
            CREATE OR REPLACE TRIGGER trg_payment_allocations_invoice_balance
            AFTER INSERT OR UPDATE OF status ON payment_allocations
            FOR EACH ROW EXECUTE FUNCTION guard_invoice_balance();
            CREATE OR REPLACE TRIGGER trg_credit_notes_invoice_balance
            AFTER INSERT OR UPDATE OF invoice_id, amount, status ON credit_notes
            FOR EACH ROW EXECUTE FUNCTION guard_invoice_balance();

            -- A single credit note above the invoice total; the sums are guarded above.
            CREATE OR REPLACE FUNCTION validate_credit_note_amount() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE invoice_total NUMERIC(14,2);
            BEGIN
              SELECT total INTO invoice_total FROM invoices WHERE id = NEW.invoice_id;
              -- A missing invoice is reported by its foreign key.
              IF FOUND AND NEW.amount > invoice_total THEN
                RAISE EXCEPTION 'Credit note % of % is larger than the invoice total %', NEW.credit_note_no, NEW.amount, invoice_total;
              END IF;
              RETURN NEW;
            END; $$;

            -- An invoice's status says what its balance says.
            CREATE OR REPLACE FUNCTION assert_invoice_status_matches_balance(p_invoice_id BIGINT, p_status TEXT)
            RETURNS VOID LANGUAGE plpgsql AS $$
            DECLARE b RECORD;
            BEGIN
              IF lifecycle_override_active() THEN
                RETURN;
              END IF;
              SELECT * INTO b FROM invoice_balance(p_invoice_id);
              IF FOUND AND NOT invoice_status_matches_balance(p_status, b.paid, b.due) THEN
                RAISE EXCEPTION 'Invoice % cannot be %: % paid and % due of %', p_invoice_id, p_status, b.paid, b.due, b.total
                  USING HINT = 'Move the invoice to the status its payments and credit notes give, in the same transaction.';
              END IF;
            END; $$;

            CREATE OR REPLACE FUNCTION validate_invoice_status_balance() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.status IS DISTINCT FROM OLD.status THEN
                PERFORM assert_invoice_status_matches_balance(NEW.id, NEW.status);
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_validate_invoice_status_balance
            BEFORE UPDATE OF status ON invoices
            FOR EACH ROW EXECUTE FUNCTION validate_invoice_status_balance();

            -- At COMMIT: an allocation or credit note left its invoice's status true.
            CREATE OR REPLACE FUNCTION check_settled_invoice_status() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              PERFORM assert_invoice_status_matches_balance(i.id, i.status) FROM invoices i WHERE i.id = NEW.invoice_id;
              RETURN NULL;
            END; $$;

            DROP TRIGGER IF EXISTS trg_payment_allocations_settled_invoice ON payment_allocations;
            CREATE CONSTRAINT TRIGGER trg_payment_allocations_settled_invoice
            AFTER INSERT OR UPDATE OF status ON payment_allocations
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION check_settled_invoice_status();
            DROP TRIGGER IF EXISTS trg_credit_notes_settled_invoice ON credit_notes;
            CREATE CONSTRAINT TRIGGER trg_credit_notes_settled_invoice
            AFTER INSERT OR UPDATE OF status ON credit_notes
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION check_settled_invoice_status();

            -- A payment is refunded only when all of it was given back.
            CREATE OR REPLACE FUNCTION validate_payment_refunded() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE b RECORD;
            BEGIN
              IF NEW.status = 'refunded' AND OLD.status IS DISTINCT FROM 'refunded' AND NOT lifecycle_override_active() THEN
                SELECT * INTO b FROM payment_balance(NEW.id);
                IF b.refunded_completed <> b.amount THEN
                  RAISE EXCEPTION 'Payment % cannot be refunded: % of % was given back', NEW.id, b.refunded_completed, b.amount
                    USING HINT = 'A partly refunded payment stays succeeded.';
                END IF;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_validate_payment_refunded
            BEFORE UPDATE OF status ON payments
            FOR EACH ROW EXECUTE FUNCTION validate_payment_refunded();

            -- After a reversal or refund an invoice shows its debt again.
            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('invoices', 'paid',           'partially_paid', 'payment.reverse', true),
                   ('invoices', 'paid',           'overdue',        'payment.reverse', true),
                   ('invoices', 'paid',           'issued',         'payment.reverse', true),
                   ('invoices', 'partially_paid', 'issued',         'payment.reverse', true);
            UPDATE status_definitions SET is_terminal = false WHERE entity_name = 'invoices' AND code = 'paid';
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            UPDATE status_definitions SET is_terminal = true WHERE entity_name = 'invoices' AND code = 'paid';
            DELETE FROM status_transitions
              WHERE entity_name = 'invoices' AND permission_code = 'payment.reverse';

            DROP TRIGGER IF EXISTS trg_validate_payment_refunded ON payments;
            DROP FUNCTION IF EXISTS validate_payment_refunded();
            DROP TRIGGER IF EXISTS trg_credit_notes_settled_invoice ON credit_notes;
            DROP TRIGGER IF EXISTS trg_payment_allocations_settled_invoice ON payment_allocations;
            DROP FUNCTION IF EXISTS check_settled_invoice_status();
            DROP TRIGGER IF EXISTS trg_validate_invoice_status_balance ON invoices;
            DROP FUNCTION IF EXISTS validate_invoice_status_balance();
            DROP FUNCTION IF EXISTS assert_invoice_status_matches_balance(BIGINT, TEXT);
            DROP TRIGGER IF EXISTS trg_credit_notes_invoice_balance ON credit_notes;
            DROP TRIGGER IF EXISTS trg_payment_allocations_invoice_balance ON payment_allocations;
            DROP TRIGGER IF EXISTS trg_refunds_payment_balance ON refunds;
            DROP TRIGGER IF EXISTS trg_payment_allocations_payment_balance ON payment_allocations;
            DROP FUNCTION IF EXISTS guard_invoice_balance();
            DROP FUNCTION IF EXISTS guard_payment_balance();
            DROP FUNCTION IF EXISTS invoice_status_matches_balance(TEXT, NUMERIC, NUMERIC);
            DROP FUNCTION IF EXISTS invoice_balance(BIGINT);
            DROP FUNCTION IF EXISTS payment_balance(BIGINT);

            -- The credit note check of 2026_11_15_000042.
            CREATE OR REPLACE FUNCTION validate_credit_note_amount() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE invoice_total NUMERIC(14,2); credited NUMERIC(14,2);
            BEGIN
              SELECT total INTO invoice_total FROM invoices WHERE id = NEW.invoice_id FOR UPDATE;
              -- A missing invoice is reported by its foreign key.
              IF NOT FOUND THEN
                RETURN NEW;
              END IF;
              IF NEW.amount > invoice_total THEN
                RAISE EXCEPTION 'Credit note % of % is larger than the invoice total %', NEW.credit_note_no, NEW.amount, invoice_total;
              END IF;
              IF NEW.status IN ('approved','applied') THEN
                SELECT COALESCE(sum(amount), 0) INTO credited FROM credit_notes
                  WHERE invoice_id = NEW.invoice_id AND id <> NEW.id AND status IN ('approved','applied');
                IF credited + NEW.amount > invoice_total THEN
                  RAISE EXCEPTION 'Credit note % would credit % of invoice total % (% already credited)',
                    NEW.credit_note_no, credited + NEW.amount, invoice_total, credited;
                END IF;
              END IF;
              RETURN NEW;
            END; $$;
            SQL);
    }
};
