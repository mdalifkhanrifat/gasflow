<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 10, part 1: billing cycles and customer invoices (design 02
 * §6, V5 segments, V7.7, V7.9, V7.16).
 *
 * - billing_cycles: one per building and month, built from the building's
 *   reading period. draft → calculating → review → approved → issued →
 *   locked (or cancelled) is enforced.
 * - invoices: one per billed occupancy (or flat), cycle and vendor segment
 *   (segment_no). Each keeps a snapshot of what it billed: customer, flat,
 *   meter, readings, the gas vendor and its vendor assignment. Its gas lines
 *   carry the tariff versions (V7.16), so the invoice-level tariff is only
 *   the version of the last billed day, for display. draft → approved →
 *   issued → paid / overdue / void / written off is enforced.
 *
 * Both stay inside one building (D42): the cycle's reading period and the
 * invoice's cycle and flat are checked with composite keys on building_id.
 * Both are protected and never hidden. Row-level security comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_cycles', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('reading_period_id')->constrained();
            $table->smallInteger('period_year');
            $table->smallInteger('period_month');
            $table->string('billing_mode', 32);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('due_on');
            $table->string('status', 24)->default('draft');
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['building_id', 'period_year', 'period_month']);
            $table->unique(['id', 'building_id']);
            $table->foreign(['reading_period_id', 'building_id'], 'fk_billing_cycle_reading_period')
                ->references(['id', 'building_id'])->on('reading_periods');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->char('public_id', 26)->unique();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('billing_cycle_id')->constrained();
            $table->foreignId('flat_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->unsignedBigInteger('meter_assignment_id')->nullable();
            $table->unsignedBigInteger('meter_occupancy_assignment_id')->nullable();
            $table->foreignId('gas_vendor_organization_id')->constrained('organizations');
            $table->string('gas_vendor_name_snapshot', 180);
            $table->unsignedBigInteger('building_vendor_assignment_id');
            $table->unsignedBigInteger('tariff_version_id')->nullable();
            $table->string('invoice_no', 100)->unique();
            $table->string('billing_mode', 32);
            $table->string('customer_name_snapshot', 180);
            $table->text('customer_address_snapshot')->nullable();
            $table->string('flat_code_snapshot', 60);
            $table->string('meter_number_snapshot', 100)->nullable();
            $table->string('unit_snapshot', 20);
            $table->decimal('previous_reading', 20, 6)->nullable();
            $table->decimal('current_reading', 20, 6)->nullable();
            $table->decimal('consumption', 20, 6)->default(0);
            $table->decimal('meter_factor_snapshot', 14, 6)->nullable();
            $table->string('tariff_code_snapshot', 60)->nullable();
            $table->integer('tariff_version_snapshot')->nullable();
            $table->decimal('tariff_rate_snapshot', 16, 6)->nullable();
            $table->char('currency', 3)->default('BDT');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('credit_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->smallInteger('segment_no')->default(1);
            $table->string('status', 24)->default('draft');
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['id', 'building_id']);
            $table->foreign(['billing_cycle_id', 'building_id'], 'fk_invoice_billing_cycle')
                ->references(['id', 'building_id'])->on('billing_cycles');
            $table->foreign(['flat_id', 'building_id'], 'fk_invoice_flat')
                ->references(['id', 'building_id'])->on('flats');
            $table->foreign(['meter_assignment_id', 'flat_id'], 'fk_invoice_meter_assignment')
                ->references(['id', 'flat_id'])->on('meter_assignments');
            $table->foreign(['meter_occupancy_assignment_id', 'meter_assignment_id', 'flat_id'], 'fk_invoice_occupancy_assignment')
                ->references(['id', 'meter_assignment_id', 'flat_id'])->on('meter_occupancy_assignments');
            $table->foreign(['building_vendor_assignment_id', 'building_id', 'gas_vendor_organization_id'], 'fk_invoice_vendor_assignment')
                ->references(['id', 'building_id', 'vendor_organization_id'])->on('building_vendor_assignments');
            $table->foreign(['tariff_version_id', 'building_id', 'gas_vendor_organization_id', 'building_vendor_assignment_id'], 'fk_invoice_tariff_version')
                ->references(['id', 'building_id', 'vendor_organization_id', 'building_vendor_assignment_id'])->on('tariff_versions');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE billing_cycles
              ADD CONSTRAINT ck_billing_cycles_period_month CHECK (period_month BETWEEN 1 AND 12),
              ADD CONSTRAINT ck_billing_cycles_billing_mode
                CHECK (billing_mode IN ('authorized_meter_billing','internal_cost_allocation')),
              ADD CONSTRAINT ck_billing_cycles_range CHECK (ends_on >= starts_on);

            ALTER TABLE invoices
              ADD CONSTRAINT ck_invoices_billing_mode
                CHECK (billing_mode IN ('authorized_meter_billing','internal_cost_allocation')),
              ADD CONSTRAINT ck_invoices_consumption CHECK (consumption >= 0),
              ADD CONSTRAINT ck_invoices_segment_no CHECK (segment_no > 0),
              -- A billed occupancy is always reached through its meter assignment.
              ADD CONSTRAINT ck_invoices_assignment_chain
                CHECK (meter_occupancy_assignment_id IS NULL OR meter_assignment_id IS NOT NULL);

            -- One invoice per cycle, billed occupancy (or flat) and vendor segment.
            CREATE UNIQUE INDEX uq_invoice_cycle_occupancy_segment
              ON invoices(billing_cycle_id, meter_occupancy_assignment_id, segment_no)
              WHERE meter_occupancy_assignment_id IS NOT NULL;
            CREATE UNIQUE INDEX uq_invoice_cycle_flat_segment_without_occupancy
              ON invoices(billing_cycle_id, flat_id, segment_no)
              WHERE meter_occupancy_assignment_id IS NULL;
            -- Unpaid invoices of a building, kept small for the receivables screens.
            CREATE INDEX idx_invoices_building_unpaid
              ON invoices(building_id, due_at)
              WHERE status IN ('issued','partially_paid','overdue');
            CREATE INDEX idx_invoices_customer ON invoices(customer_id, issued_at DESC);
            SQL);

        // Lifecycle policies exactly as design V7.7; transitions as V7.9.
        LifecycleSchema::attach('billing_cycles', module: 'billing', class: RecordClass::Workflow,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true, defaultStatus: 'draft');
        LifecycleSchema::attach('invoices', module: 'billing', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true, defaultStatus: 'draft');

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('billing_cycles', 'draft',       'Draft',       'neutral', NULL, true,  false, 10, '{"bn":{"label":"খসড়া"}}'),
                   ('billing_cycles', 'calculating', 'Calculating', 'info',    NULL, false, false, 20, '{"bn":{"label":"হিসাব চলছে"}}'),
                   ('billing_cycles', 'review',      'In review',   'warning', NULL, false, false, 30, '{"bn":{"label":"পর্যালোচনাধীন"}}'),
                   ('billing_cycles', 'approved',    'Approved',    'info',    NULL, false, false, 40, '{"bn":{"label":"অনুমোদিত"}}'),
                   ('billing_cycles', 'issued',      'Issued',      'success', NULL, false, false, 50, '{"bn":{"label":"ইস্যুকৃত"}}'),
                   ('billing_cycles', 'locked',      'Locked',      'muted',   NULL, false, true,  60, '{"bn":{"label":"লক করা"}}'),
                   ('billing_cycles', 'cancelled',   'Cancelled',   'muted',   NULL, false, true,  70, '{"bn":{"label":"বাতিল"}}'),
                   ('invoices', 'draft',          'Draft',          'neutral', NULL, true,  false, 10, '{"bn":{"label":"খসড়া"}}'),
                   ('invoices', 'approved',       'Approved',       'info',    NULL, false, false, 20, '{"bn":{"label":"অনুমোদিত"}}'),
                   ('invoices', 'issued',         'Issued',         'info',    NULL, false, false, 30, '{"bn":{"label":"ইস্যুকৃত"}}'),
                   ('invoices', 'partially_paid', 'Partially paid', 'warning', NULL, false, false, 40, '{"bn":{"label":"আংশিক পরিশোধিত"}}'),
                   ('invoices', 'paid',           'Paid',           'success', NULL, false, true,  50, '{"bn":{"label":"পরিশোধিত"}}'),
                   ('invoices', 'overdue',        'Overdue',        'danger',  NULL, false, false, 60, '{"bn":{"label":"মেয়াদোত্তীর্ণ বকেয়া"}}'),
                   ('invoices', 'void',           'Void',           'muted',   NULL, false, true,  70, '{"bn":{"label":"বাতিলকৃত"}}'),
                   ('invoices', 'written_off',    'Written off',    'muted',   NULL, false, true,  80, '{"bn":{"label":"অবলোপিত"}}');

            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('billing_cycles', 'draft',       'calculating', NULL,                    false),
                   ('billing_cycles', 'calculating', 'review',      NULL,                    false),
                   ('billing_cycles', 'calculating', 'draft',       NULL,                    false),
                   ('billing_cycles', 'review',      'calculating', NULL,                    false),
                   ('billing_cycles', 'review',      'approved',    'billing_cycle.approve', false),
                   ('billing_cycles', 'approved',    'issued',      'invoice.issue',         false),
                   ('billing_cycles', 'issued',      'locked',      'billing_cycle.lock',    false),
                   ('billing_cycles', 'draft',       'cancelled',   'billing_cycle.cancel',  true),
                   ('billing_cycles', 'review',      'cancelled',   'billing_cycle.cancel',  true),
                   ('invoices', 'draft',          'approved',       'invoice.approve',   false),
                   ('invoices', 'draft',          'void',           'invoice.void',      true),
                   ('invoices', 'approved',       'issued',         'invoice.issue',     false),
                   ('invoices', 'approved',       'void',           'invoice.void',      true),
                   ('invoices', 'issued',         'partially_paid', NULL,                false),
                   ('invoices', 'issued',         'paid',           NULL,                false),
                   ('invoices', 'issued',         'overdue',        NULL,                false),
                   ('invoices', 'issued',         'written_off',    'invoice.write_off', true),
                   ('invoices', 'partially_paid', 'paid',           NULL,                false),
                   ('invoices', 'partially_paid', 'overdue',        NULL,                false),
                   ('invoices', 'partially_paid', 'written_off',    'invoice.write_off', true),
                   ('invoices', 'overdue',        'partially_paid', NULL,                false),
                   ('invoices', 'overdue',        'paid',           NULL,                false),
                   ('invoices', 'overdue',        'written_off',    'invoice.write_off', true);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_transitions WHERE entity_name IN ('billing_cycles', 'invoices');
            DELETE FROM status_definitions WHERE entity_name IN ('billing_cycles', 'invoices');
            DELETE FROM lifecycle_entities WHERE entity_name IN ('billing_cycles', 'invoices');
            SQL);

        Schema::dropIfExists('invoices');
        Schema::dropIfExists('billing_cycles');
    }
};
