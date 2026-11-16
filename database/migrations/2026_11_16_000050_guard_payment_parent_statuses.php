<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Payments, allocations, refunds and attempts follow their parent's status
 * (owner decision D47, design V7.20, group 11 questions 3 and 7). Only the
 * generic declarative rules of D46 are used:
 *
 * - an active allocation needs an issued, partially paid or overdue invoice
 *   (never a draft, approved, paid, void or written-off one) and a succeeded
 *   payment; reversing an allocation is always possible
 * - a refund is raised, approved and processed only from a succeeded payment
 * - a payment takes no new attempt once it has succeeded, failed, been
 *   reversed or refunded
 * - a payment is reversed or refunded only after all its allocations are
 *   reversed, so an invoice is never left settled by money that went back
 */
return new class extends Migration
{
    public function up(): void
    {
        LifecycleSchema::requireParentStatus('payment_allocations', parent: 'invoices', foreignKey: 'invoice_id',
            parentStatuses: ['issued', 'partially_paid', 'overdue'], whenStatuses: ['active']);
        LifecycleSchema::requireParentStatus('payment_allocations', parent: 'payments', foreignKey: 'payment_id',
            parentStatuses: ['succeeded'], whenStatuses: ['active']);
        LifecycleSchema::requireParentStatus('refunds', parent: 'payments', foreignKey: 'payment_id',
            parentStatuses: ['succeeded'], whenStatuses: ['requested', 'approved', 'processing']);
        LifecycleSchema::refuseNewChildrenWhenParentClosed('payment_attempts', parent: 'payments', foreignKey: 'payment_id',
            closedStatuses: ['succeeded', 'failed', 'reversed', 'refunded']);
        LifecycleSchema::requireChildrenStatus('payments', child: 'payment_allocations', foreignKey: 'payment_id',
            whenStatuses: ['reversed', 'refunded'], childStatuses: ['reversed']);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_payments_children_payment_allocations ON payments;
            DROP TRIGGER IF EXISTS trg_payment_attempts_closed_parent_insert ON payment_attempts;
            DROP TRIGGER IF EXISTS trg_refunds_parent_payments ON refunds;
            DROP TRIGGER IF EXISTS trg_payment_allocations_parent_payments ON payment_allocations;
            DROP TRIGGER IF EXISTS trg_payment_allocations_parent_invoices ON payment_allocations;
            SQL);
    }
};
