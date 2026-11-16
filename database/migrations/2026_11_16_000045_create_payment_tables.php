<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 11, part 1: payments and payment attempts (design 02 §7,
 * §16, §17, V7.7, V7.9).
 *
 * - payments: money received from a customer in a building, by a method of
 *   the payment method catalogue. initiated / pending → succeeded or failed,
 *   then a succeeded payment may be reversed or refunded; enforced. The
 *   (id, building_id) key lets allocations stay inside the payment's
 *   building.
 * - payment_attempts: each try through a payment provider, with its own
 *   idempotency key and the provider's transaction id (unique per
 *   provider). Transitions are not enforced (design V7.7).
 *
 * Both are protected money records, never hidden. The gateway configuration
 * of an attempt comes with organization_payment_gateways (group 13);
 * row-level security comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->char('public_id', 26)->unique();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->string('payment_no', 100)->unique();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('BDT');
            $table->string('method', 60);
            $table->string('status', 24)->default('initiated');
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestampTz('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['id', 'building_id'], 'uq_payment_id_building');
            $table->foreign('method', 'fk_payments_method_catalog')
                ->references('code')->on('payment_method_catalog');
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('payment_id')->constrained();
            $table->string('provider', 60);
            $table->string('provider_transaction_id', 180)->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->string('request_reference', 180)->nullable();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('BDT');
            $table->string('status', 24)->default('initiated');
            $table->timestampTz('verified_at')->nullable();
            $table->jsonb('provider_metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['provider', 'provider_transaction_id']);
            $table->foreign('provider', 'fk_payment_attempt_provider')
                ->references('code')->on('integration_providers');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
              ADD CONSTRAINT ck_payments_amount CHECK (amount > 0);
            ALTER TABLE payment_attempts
              ADD CONSTRAINT ck_payment_attempts_amount CHECK (amount > 0);

            CREATE INDEX idx_payments_building_date ON payments(building_id, received_at DESC);
            SQL);

        // Lifecycle policies exactly as design V7.7; payment transitions as V7.9.
        LifecycleSchema::attach('payments', module: 'payments', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true, defaultStatus: 'initiated');
        LifecycleSchema::attach('payment_attempts', module: 'payments', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'initiated');

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('payments', 'initiated', 'Initiated', 'neutral', NULL, true,  false, 10, '{"bn":{"label":"শুরু হয়েছে"}}'),
                   ('payments', 'pending',   'Pending',   'warning', NULL, true,  false, 20, '{"bn":{"label":"অপেক্ষমাণ"}}'),
                   ('payments', 'succeeded', 'Succeeded', 'success', NULL, true,  false, 30, '{"bn":{"label":"সফল"}}'),
                   ('payments', 'failed',    'Failed',    'danger',  NULL, false, true,  40, '{"bn":{"label":"ব্যর্থ"}}'),
                   ('payments', 'reversed',  'Reversed',  'muted',   NULL, false, true,  50, '{"bn":{"label":"রিভার্সকৃত"}}'),
                   ('payments', 'refunded',  'Refunded',  'muted',   NULL, false, true,  60, '{"bn":{"label":"ফেরতকৃত"}}'),
                   ('payment_attempts', 'initiated', 'Initiated', 'neutral', NULL, true,  false, 10, '{"bn":{"label":"শুরু হয়েছে"}}'),
                   ('payment_attempts', 'pending',   'Pending',   'warning', NULL, true,  false, 20, '{"bn":{"label":"অপেক্ষমাণ"}}'),
                   ('payment_attempts', 'succeeded', 'Succeeded', 'success', NULL, false, true,  30, '{"bn":{"label":"সফল"}}'),
                   ('payment_attempts', 'failed',    'Failed',    'danger',  NULL, false, true,  40, '{"bn":{"label":"ব্যর্থ"}}'),
                   ('payment_attempts', 'cancelled', 'Cancelled', 'muted',   NULL, false, true,  50, '{"bn":{"label":"বাতিল"}}'),
                   ('payment_attempts', 'expired',   'Expired',   'muted',   NULL, false, true,  60, '{"bn":{"label":"মেয়াদোত্তীর্ণ"}}');

            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('payments', 'initiated', 'pending',   NULL,              false),
                   ('payments', 'initiated', 'succeeded', NULL,              false),
                   ('payments', 'initiated', 'failed',    NULL,              false),
                   ('payments', 'pending',   'succeeded', NULL,              false),
                   ('payments', 'pending',   'failed',    NULL,              false),
                   ('payments', 'succeeded', 'reversed',  'payment.reverse', true),
                   ('payments', 'succeeded', 'refunded',  'payment.refund',  true);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_transitions WHERE entity_name = 'payments';
            DELETE FROM status_definitions WHERE entity_name IN ('payments', 'payment_attempts');
            DELETE FROM lifecycle_entities WHERE entity_name IN ('payments', 'payment_attempts');
            SQL);

        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payments');
    }
};
