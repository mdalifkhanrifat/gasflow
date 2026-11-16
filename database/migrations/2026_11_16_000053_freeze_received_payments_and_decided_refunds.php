<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Money that was received or given back keeps its facts (owner decision
 * D47, design V7.20, group 11 question 6). Only the generic rules are used.
 *
 * - A payment keeps what it received once it leaves initiated / pending:
 *   building, customer, number, amount, currency, method, stream, collector
 *   and who received it when. A received payment always has its receive
 *   time. A mistake is a reversal and a new payment.
 * - A refund keeps its payment, number, amount and reason once it leaves
 *   requested, and its provider reference and processing time once it is
 *   completed or rejected. A completed refund always has its processing
 *   time.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
              ADD CONSTRAINT ck_payments_received
                CHECK (status NOT IN ('succeeded','reversed','refunded') OR received_at IS NOT NULL);
            ALTER TABLE refunds
              ADD CONSTRAINT ck_refunds_completed
                CHECK (status <> 'completed' OR processed_at IS NOT NULL);
            SQL);

        LifecycleSchema::freezeColumns('payments',
            ['public_id', 'building_id', 'customer_id', 'payment_no', 'amount', 'currency', 'method',
                'billing_mode', 'payee_organization_id', 'received_by', 'received_at'],
            editableStatuses: ['initiated', 'pending'], name: 'received');
        LifecycleSchema::freezeColumns('refunds', ['payment_id', 'refund_no', 'amount', 'reason'],
            editableStatuses: ['requested'], name: 'decided');
        LifecycleSchema::freezeColumns('refunds', ['provider_reference', 'processed_at'],
            editableStatuses: ['requested', 'approved', 'processing', 'failed'], name: 'processed');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_refunds_freeze_processed ON refunds;
            DROP TRIGGER IF EXISTS trg_refunds_freeze_decided ON refunds;
            DROP TRIGGER IF EXISTS trg_payments_freeze_received ON payments;
            ALTER TABLE refunds DROP CONSTRAINT ck_refunds_completed;
            ALTER TABLE payments DROP CONSTRAINT ck_payments_received;
            SQL);
    }
};
