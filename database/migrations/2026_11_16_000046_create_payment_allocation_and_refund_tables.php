<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 11, part 2: payment allocations and refunds (design 02
 * §7, §17, V7.7, V7.9).
 *
 * - payment_allocations: the part of a payment that settles an invoice, at
 *   most one row per payment and invoice. It carries building_id with
 *   composite keys to its payment and its invoice (design §17), so a payment
 *   of one building never settles an invoice of another. Generic statuses
 *   (design V7.7).
 * - refunds: money given back from a payment. requested → approved →
 *   processing → completed (or failed and retried), or rejected; enforced.
 *
 * Both are protected money records, never hidden. Row-level security comes
 * with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('payment_id')->constrained();
            $table->foreignId('invoice_id')->constrained();
            $table->foreignId('building_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->timestampTz('allocated_at')->useCurrent();
            $table->foreignId('allocated_by')->nullable()->constrained('users');
            $table->unique(['payment_id', 'invoice_id']);
            $table->index('invoice_id', 'idx_allocations_invoice');
            $table->foreign(['payment_id', 'building_id'], 'fk_allocation_payment_building')
                ->references(['id', 'building_id'])->on('payments');
            $table->foreign(['invoice_id', 'building_id'], 'fk_allocation_invoice_building')
                ->references(['id', 'building_id'])->on('invoices');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('payment_id')->constrained();
            $table->string('refund_no', 100)->unique();
            $table->decimal('amount', 14, 2);
            $table->text('reason')->nullable();
            $table->string('status', 24)->default('requested');
            $table->string('provider_reference', 180)->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_allocations
              ADD CONSTRAINT ck_payment_allocations_amount CHECK (amount > 0);
            ALTER TABLE refunds
              ADD CONSTRAINT ck_refunds_amount CHECK (amount > 0);
            SQL);

        // Lifecycle policies exactly as design V7.7; refund transitions as V7.9.
        LifecycleSchema::attach('payment_allocations', module: 'payments', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected);
        LifecycleSchema::attach('refunds', module: 'payments', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true, defaultStatus: 'requested');

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('refunds', 'requested',  'Requested',  'warning', NULL, true,  false, 10, '{"bn":{"label":"অনুরোধকৃত"}}'),
                   ('refunds', 'approved',   'Approved',   'info',    NULL, false, false, 20, '{"bn":{"label":"অনুমোদিত"}}'),
                   ('refunds', 'processing', 'Processing', 'info',    NULL, false, false, 30, '{"bn":{"label":"প্রক্রিয়াধীন"}}'),
                   ('refunds', 'completed',  'Completed',  'success', NULL, false, true,  40, '{"bn":{"label":"সম্পন্ন"}}'),
                   ('refunds', 'failed',     'Failed',     'danger',  NULL, false, false, 50, '{"bn":{"label":"ব্যর্থ"}}'),
                   ('refunds', 'rejected',   'Rejected',   'muted',   NULL, false, true,  60, '{"bn":{"label":"প্রত্যাখ্যাত"}}');

            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('refunds', 'requested',  'approved',   'payment.refund', false),
                   ('refunds', 'requested',  'rejected',   'payment.refund', true),
                   ('refunds', 'approved',   'processing', NULL,             false),
                   ('refunds', 'processing', 'completed',  NULL,             false),
                   ('refunds', 'processing', 'failed',     NULL,             false),
                   ('refunds', 'failed',     'processing', NULL,             false);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_transitions WHERE entity_name = 'refunds';
            DELETE FROM status_definitions WHERE entity_name = 'refunds';
            DELETE FROM lifecycle_entities WHERE entity_name IN ('payment_allocations', 'refunds');
            SQL);

        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_allocations');
    }
};
