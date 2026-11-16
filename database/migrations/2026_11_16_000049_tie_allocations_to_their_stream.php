<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A payment settles only invoices of its own customer, stream, collector and
 * currency, and an allocation is never edited (owner decision D47, design
 * V7.20, group 11 questions 2, 5 and 6).
 *
 * - payment_allocations carries customer_id and billing_mode; the composite
 *   keys fk_allocation_payment_building and fk_allocation_invoice_building
 *   (design §17) now include them, so both sides have the same customer and
 *   billing mode.
 * - invoice_payee_organization_id(): who collects an invoice's money: its
 *   gas vendor for authorized billing, the building's owner for internal
 *   cost allocation. validate_payment_allocation_stream() checks that the
 *   payment's collector is the invoice's, and that both use one currency.
 * - Allocations get their own statuses: active → reversed (enforced, with a
 *   reason), and every column is frozen from the first write. A mistake or a
 *   bounced payment is undone by reversing the allocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_allocations
              ADD COLUMN customer_id BIGINT,
              ADD COLUMN billing_mode VARCHAR(32);
            UPDATE payment_allocations a
              SET customer_id = p.customer_id, billing_mode = p.billing_mode
              FROM payments p WHERE p.id = a.payment_id;

            ALTER TABLE payments
              ADD CONSTRAINT uq_payment_building_customer_mode UNIQUE (id, building_id, customer_id, billing_mode);
            ALTER TABLE invoices
              ADD CONSTRAINT uq_invoice_building_customer_mode UNIQUE (id, building_id, customer_id, billing_mode);

            ALTER TABLE payment_allocations
              ALTER COLUMN customer_id SET NOT NULL,
              ALTER COLUMN billing_mode SET NOT NULL,
              ADD CONSTRAINT fk_payment_allocations_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
              DROP CONSTRAINT fk_allocation_payment_building,
              DROP CONSTRAINT fk_allocation_invoice_building,
              ADD CONSTRAINT fk_allocation_payment_building
                FOREIGN KEY (payment_id, building_id, customer_id, billing_mode)
                REFERENCES payments(id, building_id, customer_id, billing_mode),
              ADD CONSTRAINT fk_allocation_invoice_building
                FOREIGN KEY (invoice_id, building_id, customer_id, billing_mode)
                REFERENCES invoices(id, building_id, customer_id, billing_mode);

            CREATE OR REPLACE FUNCTION invoice_payee_organization_id(p_invoice_id BIGINT) RETURNS BIGINT
            LANGUAGE sql STABLE AS $$
              SELECT CASE WHEN i.billing_mode = 'authorized_meter_billing' THEN i.gas_vendor_organization_id
                          ELSE b.tenant_organization_id END
              FROM invoices i JOIN buildings b ON b.id = i.building_id
              WHERE i.id = p_invoice_id
            $$;

            CREATE OR REPLACE FUNCTION validate_payment_allocation_stream() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE payment RECORD; invoice_payee BIGINT; invoice_currency CHAR(3);
            BEGIN
              SELECT payee_organization_id, currency INTO payment FROM payments WHERE id = NEW.payment_id;
              SELECT invoice_payee_organization_id(id), currency INTO invoice_payee, invoice_currency
                FROM invoices WHERE id = NEW.invoice_id;
              -- A missing payment or invoice is reported by its foreign key.
              IF payment IS NULL OR invoice_payee IS NULL THEN
                RETURN NEW;
              END IF;
              IF payment.payee_organization_id <> invoice_payee THEN
                RAISE EXCEPTION 'Payment % was collected by organization %, but invoice % is collected by organization %',
                  NEW.payment_id, payment.payee_organization_id, NEW.invoice_id, invoice_payee;
              END IF;
              IF payment.currency <> invoice_currency THEN
                RAISE EXCEPTION 'Payment % in % cannot settle invoice % in %',
                  NEW.payment_id, payment.currency, NEW.invoice_id, invoice_currency;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_validate_payment_allocation_stream
            BEFORE INSERT OR UPDATE OF payment_id, invoice_id ON payment_allocations
            FOR EACH ROW EXECUTE FUNCTION validate_payment_allocation_stream();
            SQL);

        LifecycleSchema::attach('payment_allocations', module: 'payments', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true);

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('payment_allocations', 'active',   'Active',   'success', NULL, true,  false, 10, '{"bn":{"label":"সক্রিয়"}}'),
                   ('payment_allocations', 'reversed', 'Reversed', 'muted',   NULL, false, true,  20, '{"bn":{"label":"রিভার্সকৃত"}}');

            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('payment_allocations', 'active', 'reversed', 'payment.reverse', true);
            SQL);

        LifecycleSchema::freezeColumns('payment_allocations',
            ['payment_id', 'invoice_id', 'building_id', 'customer_id', 'billing_mode', 'amount', 'allocated_at', 'allocated_by'],
            editableStatuses: [], name: 'written');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_payment_allocations_freeze_written ON payment_allocations;
            DELETE FROM status_transitions WHERE entity_name = 'payment_allocations';
            DELETE FROM status_definitions WHERE entity_name = 'payment_allocations';
            SQL);

        LifecycleSchema::attach('payment_allocations', module: 'payments', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_validate_payment_allocation_stream ON payment_allocations;
            DROP FUNCTION IF EXISTS validate_payment_allocation_stream();
            DROP FUNCTION IF EXISTS invoice_payee_organization_id(BIGINT);
            ALTER TABLE payment_allocations
              DROP CONSTRAINT fk_allocation_payment_building,
              DROP CONSTRAINT fk_allocation_invoice_building,
              DROP CONSTRAINT fk_payment_allocations_customer,
              DROP COLUMN billing_mode,
              DROP COLUMN customer_id,
              ADD CONSTRAINT fk_allocation_payment_building
                FOREIGN KEY (payment_id, building_id) REFERENCES payments(id, building_id),
              ADD CONSTRAINT fk_allocation_invoice_building
                FOREIGN KEY (invoice_id, building_id) REFERENCES invoices(id, building_id);
            ALTER TABLE invoices DROP CONSTRAINT uq_invoice_building_customer_mode;
            ALTER TABLE payments DROP CONSTRAINT uq_payment_building_customer_mode;
            SQL);
    }
};
