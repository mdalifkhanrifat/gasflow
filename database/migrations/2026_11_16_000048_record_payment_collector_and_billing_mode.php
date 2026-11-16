<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Who collects a payment, and for which kind of bill (owner decision D47,
 * design V7.20, group 11 question 1 and the owner's two payment streams).
 *
 * Money is collected in two separate streams that must never mix:
 *
 * - authorized_meter_billing: the gas vendor bills the customers it supplies
 *   and collects their payments (the common stream on the platform). The
 *   collector is a gas vendor of the building.
 * - internal_cost_allocation: the building's owner shares the gas cost among
 *   the tenants who use its meters and may collect from them in its own way
 *   (cash, its own gateway). The collector is the building's owner
 *   organization.
 *
 * payments.billing_mode and payments.payee_organization_id say which stream
 * a payment belongs to and who received it (later: whose books and gateway).
 * validate_payment_parties() checks the collector against the building and,
 * as for an invoice without a billed occupancy, that the customer belongs to
 * an organization with access to the building (organization_can_access_building()).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
              ADD COLUMN billing_mode VARCHAR(32) NOT NULL,
              ADD COLUMN payee_organization_id BIGINT NOT NULL,
              ADD CONSTRAINT ck_payments_billing_mode
                CHECK (billing_mode IN ('authorized_meter_billing','internal_cost_allocation')),
              ADD CONSTRAINT fk_payments_payee_organization
                FOREIGN KEY (payee_organization_id) REFERENCES organizations(id);
            CREATE INDEX idx_payments_payee ON payments(payee_organization_id, received_at DESC);

            CREATE OR REPLACE FUNCTION validate_payment_parties() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE owner_org BIGINT; customer_org BIGINT;
            BEGIN
              SELECT tenant_organization_id INTO owner_org FROM buildings WHERE id = NEW.building_id;
              -- A missing building or customer is reported by its foreign key.
              IF NOT FOUND THEN
                RETURN NEW;
              END IF;
              IF NEW.billing_mode = 'internal_cost_allocation' AND NEW.payee_organization_id <> owner_org THEN
                RAISE EXCEPTION 'Payment % shares the gas cost of building %, so only its owner organization % may collect it, not %',
                  NEW.payment_no, NEW.building_id, owner_org, NEW.payee_organization_id;
              END IF;
              IF NEW.billing_mode = 'authorized_meter_billing' AND NOT EXISTS (
                   SELECT 1 FROM building_vendor_assignments a
                   WHERE a.building_id = NEW.building_id
                     AND a.vendor_organization_id = NEW.payee_organization_id
                     AND a.assignment_type = 'gas_vendor'
                     AND NOT a.is_deleted) THEN
                RAISE EXCEPTION 'Payment % is a gas bill payment, so it is collected by a gas vendor of building %, not organization %',
                  NEW.payment_no, NEW.building_id, NEW.payee_organization_id;
              END IF;
              SELECT tenant_organization_id INTO customer_org FROM customers WHERE id = NEW.customer_id;
              IF customer_org IS NOT NULL AND NOT organization_can_access_building(customer_org, NEW.building_id) THEN
                RAISE EXCEPTION 'The customer of payment % belongs to an organization without access to building %',
                  NEW.payment_no, NEW.building_id;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_validate_payment_parties
            BEFORE INSERT OR UPDATE OF building_id, customer_id, billing_mode, payee_organization_id ON payments
            FOR EACH ROW EXECUTE FUNCTION validate_payment_parties();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_validate_payment_parties ON payments;
            DROP FUNCTION IF EXISTS validate_payment_parties();
            DROP INDEX IF EXISTS idx_payments_payee;
            ALTER TABLE payments
              DROP CONSTRAINT fk_payments_payee_organization,
              DROP CONSTRAINT ck_payments_billing_mode,
              DROP COLUMN payee_organization_id,
              DROP COLUMN billing_mode;
            SQL);
    }
};
