<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration group 10, part 2: customer invoice amounts (owner decision D41,
 * as applied to SaaS invoices in V7.14).
 *
 * - The total is always subtotal + tax − discount − credit. Discount and
 *   credit are stored as positive amounts.
 * - Once an invoice leaves draft, what it bills is frozen: cycle, flat,
 *   customer, meter, vendor and tariff, number, every snapshot, the readings
 *   and the amounts. The issue time is set once, when an approved invoice is
 *   issued. Status and due date may still change (paid, overdue, void, an
 *   extended due date). It can never return to draft (no such transition).
 *   A mistake is fixed by voiding the invoice and issuing a new one; only the
 *   maintenance override may correct a frozen invoice in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE invoices
              ADD CONSTRAINT ck_invoices_discount_total CHECK (discount_total >= 0),
              ADD CONSTRAINT ck_invoices_credit_total CHECK (credit_total >= 0),
              ADD CONSTRAINT ck_invoices_total_sum
                CHECK (total = subtotal + tax_total - discount_total - credit_total);
            SQL);

        LifecycleSchema::freezeColumns(
            'invoices',
            ['public_id', 'building_id', 'billing_cycle_id', 'flat_id', 'customer_id', 'meter_assignment_id',
                'meter_occupancy_assignment_id', 'gas_vendor_organization_id', 'gas_vendor_name_snapshot',
                'building_vendor_assignment_id', 'tariff_version_id', 'invoice_no', 'billing_mode', 'segment_no',
                'customer_name_snapshot', 'customer_address_snapshot', 'flat_code_snapshot', 'meter_number_snapshot',
                'unit_snapshot', 'previous_reading', 'current_reading', 'consumption', 'meter_factor_snapshot',
                'tariff_code_snapshot', 'tariff_version_snapshot', 'tariff_rate_snapshot', 'currency',
                'subtotal', 'tax_total', 'discount_total', 'credit_total', 'total'],
            editableStatuses: ['draft'],
            name: 'billed',
        );
        LifecycleSchema::freezeColumns('invoices', ['issued_at'], editableStatuses: ['draft', 'approved'], name: 'issue');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_invoices_freeze_issue ON invoices;
            DROP TRIGGER IF EXISTS trg_invoices_freeze_billed ON invoices;
            ALTER TABLE invoices
              DROP CONSTRAINT IF EXISTS ck_invoices_total_sum,
              DROP CONSTRAINT IF EXISTS ck_invoices_credit_total,
              DROP CONSTRAINT IF EXISTS ck_invoices_discount_total;
            SQL);
    }
};
