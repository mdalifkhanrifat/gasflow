<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Credit notes are limited by their invoice (owner decision D46, design
 * V7.19, group 10 question 6).
 *
 * - Only an issued invoice (issued, partially paid, paid, overdue) gets a
 *   credit note, and only while it is so may the note be approved or
 *   applied; a pending note may still be rejected or voided whatever the
 *   invoice became (generic parent-status rule).
 * - Once decided, a note keeps its invoice, number, amount, reason and
 *   approval (generic frozen columns).
 * - A note is never larger than its invoice's total, and the approved and
 *   applied notes of an invoice together never exceed it. The invoice row
 *   is locked while this is checked, so two approvals cannot pass at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        LifecycleSchema::requireParentStatus('credit_notes', parent: 'invoices', foreignKey: 'invoice_id',
            parentStatuses: ['issued', 'partially_paid', 'paid', 'overdue'],
            whenStatuses: ['pending_approval', 'approved', 'applied']);
        LifecycleSchema::freezeColumns('credit_notes',
            ['invoice_id', 'credit_note_no', 'amount', 'reason', 'approved_by', 'approved_at'],
            editableStatuses: ['pending_approval'], name: 'decided');

        DB::unprepared(<<<'SQL'
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

            CREATE OR REPLACE TRIGGER trg_validate_credit_note_amount
            BEFORE INSERT OR UPDATE OF invoice_id, amount, status ON credit_notes
            FOR EACH ROW EXECUTE FUNCTION validate_credit_note_amount();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_validate_credit_note_amount ON credit_notes;
            DROP FUNCTION IF EXISTS validate_credit_note_amount();
            DROP TRIGGER IF EXISTS trg_credit_notes_freeze_decided ON credit_notes;
            DROP TRIGGER IF EXISTS trg_credit_notes_parent_invoices ON credit_notes;
            SQL);
    }
};
