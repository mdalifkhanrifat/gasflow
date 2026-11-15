<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A retro adjustment is tied to the issued line it recalculates and to how
 * it was settled (owner decision D46, design V7.19, group 10 question 5).
 *
 * - validate_tariff_retro_adjustment(): the recalculated line is a gas line
 *   of an issued invoice of the same building; the new tariff version
 *   belongs to that invoice's vendor assignment; the days lie inside the
 *   line's days; a settlement line is the retro_adjustment line that
 *   corrects this line at this version; a settlement credit note is on the
 *   line's invoice.
 * - An applied adjustment names its settlement (line or credit note).
 * - Once applied or waived, its numbers and links never change (generic
 *   frozen columns); a correction is a new adjustment or the override.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE tariff_retro_adjustments
              ADD CONSTRAINT ck_tariff_retro_adjustments_applied_settlement
                CHECK (status <> 'applied' OR adjustment_invoice_line_id IS NOT NULL OR credit_note_id IS NOT NULL);

            CREATE OR REPLACE FUNCTION validate_tariff_retro_adjustment() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE original RECORD; tariff_assignment BIGINT; settlement RECORD;
            BEGIN
              SELECT l.line_type, l.segment_from, l.segment_to, i.id AS invoice_id, i.building_id, i.status,
                     i.building_vendor_assignment_id
                INTO original
                FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id WHERE l.id = NEW.original_invoice_line_id;
              -- Missing rows are reported by their foreign keys.
              IF NOT FOUND THEN
                RETURN NEW;
              END IF;
              IF original.line_type <> 'gas_charge' THEN
                RAISE EXCEPTION 'A retro adjustment recalculates a gas line, not a % line', original.line_type;
              END IF;
              IF original.building_id <> NEW.building_id THEN
                RAISE EXCEPTION 'Line % belongs to another building', NEW.original_invoice_line_id;
              END IF;
              IF NOT original.status = ANY (invoice_issued_statuses()) THEN
                RAISE EXCEPTION 'The invoice of line % is %: only issued lines are recalculated',
                  NEW.original_invoice_line_id, original.status;
              END IF;
              SELECT building_vendor_assignment_id INTO tariff_assignment FROM tariff_versions WHERE id = NEW.tariff_version_id;
              IF FOUND AND tariff_assignment <> original.building_vendor_assignment_id THEN
                RAISE EXCEPTION 'Tariff version % is not of the vendor assignment of line %',
                  NEW.tariff_version_id, NEW.original_invoice_line_id;
              END IF;
              IF NEW.segment_from < original.segment_from OR NEW.segment_to > original.segment_to THEN
                RAISE EXCEPTION 'Retro days % to % are outside line % (% to %)',
                  NEW.segment_from, NEW.segment_to, NEW.original_invoice_line_id, original.segment_from, original.segment_to;
              END IF;
              IF NEW.adjustment_invoice_line_id IS NOT NULL THEN
                SELECT line_type, adjusts_invoice_line_id, tariff_version_id INTO settlement
                  FROM invoice_lines WHERE id = NEW.adjustment_invoice_line_id;
                IF FOUND AND (settlement.line_type <> 'retro_adjustment'
                              OR settlement.adjusts_invoice_line_id IS DISTINCT FROM NEW.original_invoice_line_id
                              OR settlement.tariff_version_id IS DISTINCT FROM NEW.tariff_version_id) THEN
                  RAISE EXCEPTION 'Line % is not the retro adjustment of line % at tariff version %',
                    NEW.adjustment_invoice_line_id, NEW.original_invoice_line_id, NEW.tariff_version_id;
                END IF;
              END IF;
              IF NEW.credit_note_id IS NOT NULL AND EXISTS (
                   SELECT 1 FROM credit_notes WHERE id = NEW.credit_note_id AND invoice_id <> original.invoice_id) THEN
                RAISE EXCEPTION 'Credit note % is not on the invoice of line %', NEW.credit_note_id, NEW.original_invoice_line_id;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_validate_tariff_retro_adjustment
            BEFORE INSERT OR UPDATE OF building_id, tariff_version_id, original_invoice_line_id, segment_from, segment_to,
                                       adjustment_invoice_line_id, credit_note_id
            ON tariff_retro_adjustments
            FOR EACH ROW EXECUTE FUNCTION validate_tariff_retro_adjustment();
            SQL);

        LifecycleSchema::freezeColumns('tariff_retro_adjustments',
            ['building_id', 'tariff_version_id', 'original_invoice_line_id', 'segment_from', 'segment_to', 'quantity',
                'billed_amount', 'recalculated_amount', 'difference', 'adjustment_invoice_line_id', 'credit_note_id'],
            editableStatuses: ['pending'], name: 'decided');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_tariff_retro_adjustments_freeze_decided ON tariff_retro_adjustments;
            DROP TRIGGER IF EXISTS trg_validate_tariff_retro_adjustment ON tariff_retro_adjustments;
            DROP FUNCTION IF EXISTS validate_tariff_retro_adjustment();
            ALTER TABLE tariff_retro_adjustments DROP CONSTRAINT IF EXISTS ck_tariff_retro_adjustments_applied_settlement;
            SQL);
    }
};
