<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Billing cycle status rules (owner decision D46, design V7.19, group 10
 * questions 1 and 2), declared with the generic parent/children rules:
 *
 * - A cycle calculates only from approved readings: it may leave draft only
 *   while its reading period is approved or locked.
 * - No invoice is added once the cycle is approved, issued or locked. Its
 *   existing invoices still change (issued, paid, overdue).
 * - A cycle is cancelled only after its invoices are void, and the invoices
 *   of a cancelled cycle are then frozen.
 */
return new class extends Migration
{
    public function up(): void
    {
        LifecycleSchema::requireParentStatus('billing_cycles', parent: 'reading_periods', foreignKey: 'reading_period_id',
            parentStatuses: ['approved', 'locked'], whenStatuses: ['calculating', 'review', 'approved', 'issued', 'locked']);
        LifecycleSchema::refuseNewChildrenWhenParentClosed('invoices', parent: 'billing_cycles', foreignKey: 'billing_cycle_id',
            closedStatuses: ['approved', 'issued', 'locked']);
        LifecycleSchema::requireChildrenStatus('billing_cycles', child: 'invoices', foreignKey: 'billing_cycle_id',
            whenStatuses: ['cancelled'], childStatuses: ['void']);
        LifecycleSchema::freezeWhenParentClosed('invoices', parent: 'billing_cycles', foreignKey: 'billing_cycle_id',
            closedStatuses: ['cancelled']);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_invoices_closed_parent ON invoices;
            DROP TRIGGER IF EXISTS trg_billing_cycles_children_invoices ON billing_cycles;
            DROP TRIGGER IF EXISTS trg_invoices_closed_parent_insert ON invoices;
            DROP TRIGGER IF EXISTS trg_billing_cycles_parent_reading_periods ON billing_cycles;
            SQL);
    }
};
