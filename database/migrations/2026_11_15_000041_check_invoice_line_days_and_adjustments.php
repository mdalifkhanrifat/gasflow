<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Invoice lines are checked against their rate, their cycle and the line
 * they correct (owner decision D46, design V7.19, group 10 questions 4 and 5).
 *
 * - invoice_issued_statuses(): the statuses of an invoice that has been
 *   issued (issued, partially paid, paid, overdue, written off). Retro rules
 *   use this one list.
 * - validate_invoice_line_tariff() now also checks that the rate is
 *   approved, active or retired (never a draft), that the line's days lie
 *   inside the rate's effective range, and that a gas line's days lie inside
 *   its billing cycle.
 * - validate_invoice_line_adjustment(): a retro_adjustment line corrects a
 *   gas line of the same flat and vendor assignment on an issued invoice,
 *   and its days lie inside that line's days. Only retro lines name a
 *   corrected line.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoice_issued_statuses() RETURNS TEXT[] LANGUAGE sql IMMUTABLE AS $$
              SELECT ARRAY['issued','partially_paid','paid','overdue','written_off']
            $$;

            ALTER TABLE invoice_lines
              ADD CONSTRAINT ck_invoice_lines_adjusts_only_retro
                CHECK (line_type = 'retro_adjustment' OR adjusts_invoice_line_id IS NULL);

            CREATE OR REPLACE FUNCTION validate_invoice_line_tariff() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE invoice RECORD; tariff RECORD;
            BEGIN
              SELECT i.building_vendor_assignment_id, c.starts_on, c.ends_on INTO invoice
                FROM invoices i JOIN billing_cycles c ON c.id = i.billing_cycle_id WHERE i.id = NEW.invoice_id;
              -- A missing invoice or tariff version is reported by its foreign key.
              IF NOT FOUND THEN
                RETURN NEW;
              END IF;
              IF NEW.tariff_version_id IS NOT NULL THEN
                SELECT building_vendor_assignment_id, status, effective_from, effective_to INTO tariff
                  FROM tariff_versions WHERE id = NEW.tariff_version_id;
                IF FOUND THEN
                  IF tariff.building_vendor_assignment_id IS DISTINCT FROM invoice.building_vendor_assignment_id THEN
                    RAISE EXCEPTION 'Tariff version % does not belong to the vendor assignment of invoice %',
                      NEW.tariff_version_id, NEW.invoice_id;
                  END IF;
                  IF tariff.status NOT IN ('approved','active','retired') THEN
                    RAISE EXCEPTION 'Tariff version % is %: only an approved, active or retired rate can price a line',
                      NEW.tariff_version_id, tariff.status;
                  END IF;
                  IF NEW.segment_from < tariff.effective_from
                     OR (tariff.effective_to IS NOT NULL AND NEW.segment_to > tariff.effective_to) THEN
                    RAISE EXCEPTION 'Line days % to % are outside tariff version % (% to %)',
                      NEW.segment_from, NEW.segment_to, NEW.tariff_version_id, tariff.effective_from,
                      COALESCE(tariff.effective_to::text, 'open');
                  END IF;
                END IF;
              END IF;
              IF NEW.line_type = 'gas_charge' AND (NEW.segment_from < invoice.starts_on OR NEW.segment_to > invoice.ends_on) THEN
                RAISE EXCEPTION 'Gas line days % to % are outside the billing cycle (% to %)',
                  NEW.segment_from, NEW.segment_to, invoice.starts_on, invoice.ends_on;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_validate_invoice_line_tariff
            BEFORE INSERT OR UPDATE OF invoice_id, line_type, tariff_version_id, segment_from, segment_to ON invoice_lines
            FOR EACH ROW EXECUTE FUNCTION validate_invoice_line_tariff();

            CREATE OR REPLACE FUNCTION validate_invoice_line_adjustment() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE corrected RECORD; own RECORD;
            BEGIN
              IF NEW.line_type <> 'retro_adjustment' THEN
                RETURN NEW;
              END IF;
              SELECT l.line_type, l.segment_from, l.segment_to, i.flat_id, i.building_vendor_assignment_id, i.status
                INTO corrected
                FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id WHERE l.id = NEW.adjusts_invoice_line_id;
              SELECT flat_id, building_vendor_assignment_id INTO own FROM invoices WHERE id = NEW.invoice_id;
              -- Missing rows are reported by their foreign keys.
              IF corrected IS NULL OR own IS NULL THEN
                RETURN NEW;
              END IF;
              IF corrected.line_type <> 'gas_charge' THEN
                RAISE EXCEPTION 'A retro adjustment corrects a gas line, not a % line', corrected.line_type;
              END IF;
              IF (corrected.flat_id, corrected.building_vendor_assignment_id) IS DISTINCT FROM (own.flat_id, own.building_vendor_assignment_id) THEN
                RAISE EXCEPTION 'Line % belongs to another flat or vendor assignment than invoice %',
                  NEW.adjusts_invoice_line_id, NEW.invoice_id;
              END IF;
              IF NOT corrected.status = ANY (invoice_issued_statuses()) THEN
                RAISE EXCEPTION 'The invoice of line % is %: only issued lines get a retro adjustment',
                  NEW.adjusts_invoice_line_id, corrected.status;
              END IF;
              IF NEW.segment_from < corrected.segment_from OR NEW.segment_to > corrected.segment_to THEN
                RAISE EXCEPTION 'Retro days % to % are outside the corrected line (% to %)',
                  NEW.segment_from, NEW.segment_to, corrected.segment_from, corrected.segment_to;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_validate_invoice_line_adjustment
            BEFORE INSERT OR UPDATE OF invoice_id, line_type, adjusts_invoice_line_id, segment_from, segment_to ON invoice_lines
            FOR EACH ROW EXECUTE FUNCTION validate_invoice_line_adjustment();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_validate_invoice_line_adjustment ON invoice_lines;
            DROP FUNCTION IF EXISTS validate_invoice_line_adjustment();

            -- The tariff check of migration 000034.
            CREATE OR REPLACE FUNCTION validate_invoice_line_tariff() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.tariff_version_id IS NOT NULL AND NOT EXISTS (
                   SELECT 1 FROM invoices i
                   JOIN tariff_versions t ON t.building_vendor_assignment_id = i.building_vendor_assignment_id
                   WHERE i.id = NEW.invoice_id AND t.id = NEW.tariff_version_id) THEN
                RAISE EXCEPTION 'Tariff version % does not belong to the vendor assignment of invoice %',
                  NEW.tariff_version_id, NEW.invoice_id;
              END IF;
              RETURN NEW;
            END; $$;
            CREATE OR REPLACE TRIGGER trg_validate_invoice_line_tariff
            BEFORE INSERT OR UPDATE OF invoice_id, tariff_version_id ON invoice_lines
            FOR EACH ROW EXECUTE FUNCTION validate_invoice_line_tariff();

            ALTER TABLE invoice_lines DROP CONSTRAINT IF EXISTS ck_invoice_lines_adjusts_only_retro;
            DROP FUNCTION IF EXISTS invoice_issued_statuses();
            SQL);
    }
};
