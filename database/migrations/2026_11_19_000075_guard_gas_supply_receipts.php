<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A gas supply receipt keeps what was bought (owner decision D50, design
 * V7.23, group 13 question 9):
 *
 * - total_cost = round(quantity × unit_cost, 2) (named to be checked after
 *   the single-column checks).
 * - The supply date lies inside its vendor assignment's validity.
 * - Its supply change is of the same vendor assignment: composite key
 *   (supply_change_id, building_id, building_vendor_assignment_id).
 * - What was bought is frozen once recorded (freezeColumns, editable only in
 *   draft); a wrong receipt is cancelled and recorded again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE gas_supply_receipts
              ADD CONSTRAINT ck_gas_supply_receipts_value CHECK (total_cost = round(quantity * unit_cost, 2));

            ALTER TABLE supply_changes
              ADD CONSTRAINT uq_supply_change_assignment UNIQUE (id, building_id, building_vendor_assignment_id);
            ALTER TABLE gas_supply_receipts
              DROP CONSTRAINT fk_gas_supply_receipt_change,
              ADD CONSTRAINT fk_gas_supply_receipt_change FOREIGN KEY (supply_change_id, building_id, building_vendor_assignment_id)
                REFERENCES supply_changes(id, building_id, building_vendor_assignment_id);

            CREATE OR REPLACE FUNCTION validate_gas_supply_receipt_date() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE assignment RECORD;
            BEGIN
              SELECT valid_from, valid_to INTO assignment FROM building_vendor_assignments WHERE id = NEW.building_vendor_assignment_id;
              -- A missing assignment is reported by its foreign key.
              IF FOUND AND (NEW.supply_date < assignment.valid_from
                            OR (assignment.valid_to IS NOT NULL AND NEW.supply_date > assignment.valid_to)) THEN
                RAISE EXCEPTION 'Gas supply % on % is outside its vendor assignment (% to %)',
                  NEW.supply_no, NEW.supply_date, assignment.valid_from, COALESCE(assignment.valid_to::text, 'open');
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_gas_supply_receipts_date
            BEFORE INSERT OR UPDATE OF supply_date, building_vendor_assignment_id ON gas_supply_receipts
            FOR EACH ROW EXECUTE FUNCTION validate_gas_supply_receipt_date();
            SQL);

        LifecycleSchema::freezeColumns('gas_supply_receipts',
            ['building_id', 'vendor_organization_id', 'building_vendor_assignment_id', 'supply_no', 'upstream_supplier_name',
                'supplier_reference', 'supply_date', 'quantity', 'measurement_unit', 'unit_cost', 'total_cost', 'currency',
                'supply_change_id'],
            editableStatuses: ['draft'], name: 'purchase');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_gas_supply_receipts_freeze_purchase ON gas_supply_receipts;
            DROP TRIGGER IF EXISTS trg_gas_supply_receipts_date ON gas_supply_receipts;
            DROP FUNCTION IF EXISTS validate_gas_supply_receipt_date();
            ALTER TABLE gas_supply_receipts
              DROP CONSTRAINT fk_gas_supply_receipt_change,
              ADD CONSTRAINT fk_gas_supply_receipt_change FOREIGN KEY (supply_change_id, building_id)
                REFERENCES supply_changes(id, building_id),
              DROP CONSTRAINT ck_gas_supply_receipts_value;
            ALTER TABLE supply_changes DROP CONSTRAINT uq_supply_change_assignment;
            SQL);
    }
};
