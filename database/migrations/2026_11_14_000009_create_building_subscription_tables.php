<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 6, part 2: what a building pays GasFlow (design 02 §3, V7.7).
 *
 * - building_subscriptions: a building on a plan version, from trial to
 *   cancelled or expired (protected workflow).
 * - saas_invoices and saas_payments: money records; never hidden or deleted.
 *
 * Row-level security for all three comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('building_subscriptions', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('plan_version_id')->constrained();
            $table->string('status', 24)->default('trial');
            $table->timestampTz('starts_at');
            $table->timestampTz('current_period_start')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('grace_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('saas_invoices', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_subscription_id')->constrained();
            $table->string('invoice_no', 90)->unique();
            $table->char('currency', 3)->default('BDT');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->string('status', 24)->default('issued');
            $table->timestampTz('issued_at');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('saas_payments', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('saas_invoice_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('BDT');
            $table->string('method', 30);
            $table->string('provider_reference', 180)->nullable()->unique();
            $table->string('status', 24)->default('pending');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE building_subscriptions
              ADD CONSTRAINT ck_building_subscriptions_period
                CHECK (current_period_end IS NULL OR current_period_start IS NULL OR current_period_end > current_period_start);
            -- Renewal job: subscriptions that can still renew, by period end.
            CREATE INDEX idx_building_subscriptions_renewal
              ON building_subscriptions(current_period_end)
              WHERE status IN ('trial','active','past_due','grace');

            ALTER TABLE saas_invoices
              ADD CONSTRAINT ck_saas_invoices_subtotal CHECK (subtotal >= 0),
              ADD CONSTRAINT ck_saas_invoices_tax_total CHECK (tax_total >= 0),
              ADD CONSTRAINT ck_saas_invoices_total CHECK (total >= 0);

            ALTER TABLE saas_payments
              ADD CONSTRAINT ck_saas_payments_amount CHECK (amount > 0);
            SQL);

        // Lifecycle policies exactly as design V7.7.
        LifecycleSchema::attach(
            'building_subscriptions',
            module: 'subscriptions',
            class: RecordClass::Workflow,
            softDelete: SoftDeletePolicy::Protected,
            defaultStatus: 'trial',
        );
        LifecycleSchema::attach(
            'saas_invoices',
            module: 'subscriptions',
            class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected,
            defaultStatus: 'issued',
        );
        LifecycleSchema::attach(
            'saas_payments',
            module: 'subscriptions',
            class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected,
            defaultStatus: 'pending',
        );

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('building_subscriptions', 'trial',     'Trial',        'info',    true,  true,  false, 10, '{"bn":{"label":"ট্রায়াল"}}'),
                   ('building_subscriptions', 'active',    'Active',       'success', true,  true,  false, 20, '{"bn":{"label":"সক্রিয়"}}'),
                   ('building_subscriptions', 'past_due',  'Past due',     'danger',  true,  false, false, 30, '{"bn":{"label":"বকেয়া"}}'),
                   ('building_subscriptions', 'grace',     'Grace period', 'warning', true,  false, false, 40, '{"bn":{"label":"অতিরিক্ত সময়"}}'),
                   ('building_subscriptions', 'cancelled', 'Cancelled',    'muted',   false, false, true,  50, '{"bn":{"label":"বাতিল"}}'),
                   ('building_subscriptions', 'expired',   'Expired',      'muted',   false, false, true,  60, '{"bn":{"label":"মেয়াদোত্তীর্ণ"}}'),
                   ('saas_invoices', 'draft',          'Draft',          'neutral', NULL, true,  false, 10, '{"bn":{"label":"খসড়া"}}'),
                   ('saas_invoices', 'issued',         'Issued',         'info',    NULL, true,  false, 20, '{"bn":{"label":"ইস্যুকৃত"}}'),
                   ('saas_invoices', 'partially_paid', 'Partially paid', 'warning', NULL, false, false, 30, '{"bn":{"label":"আংশিক পরিশোধিত"}}'),
                   ('saas_invoices', 'paid',           'Paid',           'success', NULL, false, true,  40, '{"bn":{"label":"পরিশোধিত"}}'),
                   ('saas_invoices', 'overdue',        'Overdue',        'danger',  NULL, false, false, 50, '{"bn":{"label":"মেয়াদোত্তীর্ণ বকেয়া"}}'),
                   ('saas_invoices', 'void',           'Void',           'muted',   NULL, false, true,  60, '{"bn":{"label":"বাতিলকৃত"}}'),
                   ('saas_payments', 'pending',   'Pending',   'warning', NULL, true,  false, 10, '{"bn":{"label":"অপেক্ষমাণ"}}'),
                   ('saas_payments', 'succeeded', 'Succeeded', 'success', NULL, true,  true,  20, '{"bn":{"label":"সফল"}}'),
                   ('saas_payments', 'failed',    'Failed',    'danger',  NULL, false, true,  30, '{"bn":{"label":"ব্যর্থ"}}'),
                   ('saas_payments', 'refunded',  'Refunded',  'muted',   NULL, false, true,  40, '{"bn":{"label":"ফেরতকৃত"}}');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_definitions WHERE entity_name IN ('building_subscriptions', 'saas_invoices', 'saas_payments');
            DELETE FROM lifecycle_entities WHERE entity_name IN ('building_subscriptions', 'saas_invoices', 'saas_payments');
            SQL);

        Schema::dropIfExists('saas_payments');
        Schema::dropIfExists('saas_invoices');
        Schema::dropIfExists('building_subscriptions');
    }
};
