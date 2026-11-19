<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 13, part 5: upstream gas bought for a building (feature
 * module.gas_supply; design 02 V7.4, V7.7, V7.9, V7.17).
 *
 * - gas_supply_receipts: one purchase of a building's gas vendor under its
 *   vendor assignment (composite key with building and vendor), with the
 *   upstream supplier, quantity, unit cost and total; supply_no is unique per
 *   building. A receipt may name the supply change it started (V7.17), which
 *   holds the meter readings at that moment, of the same building.
 *
 * Financial and protected, never hidden. draft → recorded → verified (or
 * cancelled, with a reason) is enforced. Row-level security comes with
 * group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gas_supply_receipts', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('vendor_organization_id')->constrained('organizations');
            $table->unsignedBigInteger('building_vendor_assignment_id');
            $table->string('supply_no', 40);
            $table->string('upstream_supplier_name', 160);
            $table->string('supplier_reference', 120)->nullable();
            $table->date('supply_date');
            $table->decimal('quantity', 14, 3);
            $table->string('measurement_unit', 16)->default('m3');
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('total_cost', 14, 2);
            $table->char('currency', 3)->default('BDT');
            $table->unsignedBigInteger('supply_change_id')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->unique(['building_id', 'supply_no']);
            $table->foreign(['building_vendor_assignment_id', 'building_id', 'vendor_organization_id'], 'fk_gas_supply_receipt_vendor_assignment')
                ->references(['id', 'building_id', 'vendor_organization_id'])->on('building_vendor_assignments');
            $table->foreign(['supply_change_id', 'building_id'], 'fk_gas_supply_receipt_change')
                ->references(['id', 'building_id'])->on('supply_changes');
            $table->index(['building_id', 'supply_date'], 'idx_gas_supply_receipts_building_date');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE gas_supply_receipts
              ADD CONSTRAINT ck_gas_supply_receipts_quantity CHECK (quantity > 0),
              ADD CONSTRAINT ck_gas_supply_receipts_unit_cost CHECK (unit_cost >= 0),
              ADD CONSTRAINT ck_gas_supply_receipts_total_cost CHECK (total_cost >= 0);
            SQL);

        // Lifecycle policy exactly as design V7.7; transitions as V7.9.
        LifecycleSchema::attach('gas_supply_receipts', module: 'supply', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true, defaultStatus: 'draft');

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('gas_supply_receipts', 'draft',     'Draft',     'neutral', NULL, true,  false, 10, '{"bn":{"label":"খসড়া"}}'),
                   ('gas_supply_receipts', 'recorded',  'Recorded',  'info',    NULL, true,  false, 20, '{"bn":{"label":"রেকর্ডকৃত"}}'),
                   ('gas_supply_receipts', 'verified',  'Verified',  'success', NULL, false, true,  30, '{"bn":{"label":"যাচাইকৃত"}}'),
                   ('gas_supply_receipts', 'cancelled', 'Cancelled', 'muted',   NULL, false, true,  40, '{"bn":{"label":"বাতিল"}}');

            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('gas_supply_receipts', 'draft',    'recorded',  NULL,                false),
                   ('gas_supply_receipts', 'recorded', 'verified',  'gas_supply.verify', false),
                   ('gas_supply_receipts', 'draft',    'cancelled', NULL,                true),
                   ('gas_supply_receipts', 'recorded', 'cancelled', 'gas_supply.cancel', true);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_transitions WHERE entity_name = 'gas_supply_receipts';
            DELETE FROM status_definitions WHERE entity_name = 'gas_supply_receipts';
            DELETE FROM lifecycle_entities WHERE entity_name = 'gas_supply_receipts';
            SQL);

        Schema::dropIfExists('gas_supply_receipts');
    }
};
