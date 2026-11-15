<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An invoice agrees with its billing cycle and its customer (owner decision
 * D46, design V7.19, group 10 question 3).
 *
 * - Its billing mode is the cycle's: the cycle key now includes billing_mode
 *   (composite FK, like the building tie of D42).
 * - With a billed occupancy, the customer is that occupancy's customer.
 *   Without one, the customer belongs to an organization that may work in
 *   the building (organization_can_access_building(), the D42 rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE billing_cycles
              ADD CONSTRAINT uq_billing_cycle_building_mode UNIQUE (id, building_id, billing_mode);
            ALTER TABLE invoices
              DROP CONSTRAINT fk_invoice_billing_cycle,
              ADD CONSTRAINT fk_invoice_billing_cycle FOREIGN KEY (billing_cycle_id, building_id, billing_mode)
                REFERENCES billing_cycles(id, building_id, billing_mode);

            CREATE OR REPLACE FUNCTION validate_invoice_customer() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE occupant BIGINT; customer_org BIGINT;
            BEGIN
              IF NEW.meter_occupancy_assignment_id IS NOT NULL THEN
                SELECT o.customer_id INTO occupant
                  FROM meter_occupancy_assignments m JOIN flat_occupancies o ON o.id = m.flat_occupancy_id
                  WHERE m.id = NEW.meter_occupancy_assignment_id;
                -- A missing occupancy is reported by its foreign key.
                IF occupant IS NOT NULL AND occupant <> NEW.customer_id THEN
                  RAISE EXCEPTION 'Invoice customer % is not customer %, the occupant billed through meter occupancy assignment %',
                    NEW.customer_id, occupant, NEW.meter_occupancy_assignment_id;
                END IF;
              ELSE
                SELECT tenant_organization_id INTO customer_org FROM customers WHERE id = NEW.customer_id;
                IF customer_org IS NOT NULL AND NOT organization_can_access_building(customer_org, NEW.building_id) THEN
                  RAISE EXCEPTION 'The invoice customer belongs to an organization without access to this building';
                END IF;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_validate_invoice_customer
            BEFORE INSERT OR UPDATE OF customer_id, meter_occupancy_assignment_id, building_id ON invoices
            FOR EACH ROW EXECUTE FUNCTION validate_invoice_customer();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_validate_invoice_customer ON invoices;
            DROP FUNCTION IF EXISTS validate_invoice_customer();
            ALTER TABLE invoices
              DROP CONSTRAINT fk_invoice_billing_cycle,
              ADD CONSTRAINT fk_invoice_billing_cycle FOREIGN KEY (billing_cycle_id, building_id)
                REFERENCES billing_cycles(id, building_id);
            ALTER TABLE billing_cycles DROP CONSTRAINT uq_billing_cycle_building_mode;
            SQL);
    }
};
