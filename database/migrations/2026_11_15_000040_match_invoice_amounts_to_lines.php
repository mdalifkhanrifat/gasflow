<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An invoice's amounts are the sums of its lines (owner decision D46, design
 * V7.19, group 10 question 3).
 *
 * - invoice_line_totals(invoice): one place that adds up the lines, used by
 *   this check, by the billing action that fills a draft (Phase 8) and by
 *   reports:
 *   - subtotal: gas, retro adjustment, service, installation EMI and
 *     adjustment lines
 *   - tax: tax lines
 *   - discount and credit: the discount and credit lines, which are stored
 *     as negative line totals (they lower the bill) and summed as positive
 *     amounts, like the invoice columns
 *   - previous_balance lines only show arrears on the bill: the arrears stay
 *     receivable on their own invoices, so they are never billed twice
 * - When an invoice leaves draft (to approved), its subtotal, tax, discount
 *   and credit must equal those sums. Lines are frozen from then on, so the
 *   check keeps holding. Voiding a draft needs no match.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE invoice_lines
              ADD CONSTRAINT ck_invoice_lines_deduction_sign
                CHECK (line_type NOT IN ('discount','credit') OR line_total <= 0);

            CREATE OR REPLACE FUNCTION invoice_line_totals(p_invoice_id BIGINT)
            RETURNS TABLE (subtotal NUMERIC(14,2), tax_total NUMERIC(14,2), discount_total NUMERIC(14,2),
                           credit_total NUMERIC(14,2), total NUMERIC(14,2))
            LANGUAGE sql STABLE AS $$
              WITH sums AS (
                SELECT
                  COALESCE(sum(line_total) FILTER (WHERE line_type IN
                    ('gas_charge','retro_adjustment','service_charge','installation_emi','adjustment')), 0) AS subtotal,
                  COALESCE(sum(line_total) FILTER (WHERE line_type = 'tax'), 0) AS tax_total,
                  COALESCE(-sum(line_total) FILTER (WHERE line_type = 'discount'), 0) AS discount_total,
                  COALESCE(-sum(line_total) FILTER (WHERE line_type = 'credit'), 0) AS credit_total
                FROM invoice_lines
                WHERE invoice_id = p_invoice_id AND NOT is_deleted
              )
              SELECT subtotal, tax_total, discount_total, credit_total,
                     subtotal + tax_total - discount_total - credit_total
              FROM sums
            $$;

            CREATE OR REPLACE FUNCTION validate_invoice_amounts_match_lines() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE lines RECORD;
            BEGIN
              IF OLD.status <> 'draft' OR NEW.status IN ('draft','void') THEN
                RETURN NEW;
              END IF;
              SELECT * INTO lines FROM invoice_line_totals(NEW.id);
              IF (NEW.subtotal, NEW.tax_total, NEW.discount_total, NEW.credit_total)
                 IS DISTINCT FROM (lines.subtotal, lines.tax_total, lines.discount_total, lines.credit_total) THEN
                RAISE EXCEPTION 'Invoice % does not match its lines: subtotal %, tax %, discount %, credit % but the lines give %, %, %, %',
                  NEW.invoice_no, NEW.subtotal, NEW.tax_total, NEW.discount_total, NEW.credit_total,
                  lines.subtotal, lines.tax_total, lines.discount_total, lines.credit_total
                  USING HINT = 'Fill the amounts from invoice_line_totals() before approving.';
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_validate_invoice_amounts_match_lines
            BEFORE UPDATE OF status ON invoices
            FOR EACH ROW EXECUTE FUNCTION validate_invoice_amounts_match_lines();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_validate_invoice_amounts_match_lines ON invoices;
            DROP FUNCTION IF EXISTS validate_invoice_amounts_match_lines();
            DROP FUNCTION IF EXISTS invoice_line_totals(BIGINT);
            ALTER TABLE invoice_lines DROP CONSTRAINT IF EXISTS ck_invoice_lines_deduction_sign;
            SQL);
    }
};
