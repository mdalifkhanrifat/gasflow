<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration group 6, part 3: SaaS invoice amounts (owner decision D41).
 *
 * - The total is always the subtotal plus tax.
 * - Once an invoice has left draft, what it bills is frozen: subscription,
 *   number, currency, amounts and issue time. Its status and due date may
 *   still change (paid, overdue, void, an extended due date). A mistake is
 *   fixed by voiding the invoice and issuing a new one; only the backend
 *   maintenance override may correct a frozen invoice in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE saas_invoices
              ADD CONSTRAINT ck_saas_invoices_total_sum CHECK (total = subtotal + tax_total);

            CREATE OR REPLACE FUNCTION protect_issued_saas_invoice() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              IF OLD.status = 'draft' OR lifecycle_override_active() THEN
                RETURN NEW;
              END IF;
              IF NEW.status = 'draft' THEN
                RAISE EXCEPTION 'SaaS invoice % has been issued and cannot return to draft', OLD.invoice_no;
              END IF;
              IF (NEW.building_subscription_id, NEW.invoice_no, NEW.currency, NEW.subtotal, NEW.tax_total, NEW.total, NEW.issued_at)
                 IS DISTINCT FROM
                 (OLD.building_subscription_id, OLD.invoice_no, OLD.currency, OLD.subtotal, OLD.tax_total, OLD.total, OLD.issued_at) THEN
                RAISE EXCEPTION 'SaaS invoice % has been issued; void it and issue a new one instead of changing it', OLD.invoice_no;
              END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER trg_protect_issued_saas_invoice
            BEFORE UPDATE ON saas_invoices
            FOR EACH ROW EXECUTE FUNCTION protect_issued_saas_invoice();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_protect_issued_saas_invoice ON saas_invoices;
            DROP FUNCTION IF EXISTS protect_issued_saas_invoice();
            ALTER TABLE saas_invoices DROP CONSTRAINT IF EXISTS ck_saas_invoices_total_sum;
            SQL);
    }
};
