<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 9, part 1: what gas and extra charges cost in a building
 * (design 02 §6 and §14, V7.7, V7.9).
 *
 * - tariff_versions: a gas vendor's rate for a building, versioned by
 *   effective date. The vendor must be the building's gas vendor through the
 *   given vendor assignment (composite FK). draft → approved → active →
 *   retired is enforced; approved and active versions of the same tariff and
 *   customer category never overlap. Protected history: never hidden.
 * - building_charge_rules: fixed, per-unit or percentage charges of a
 *   building; active rules with the same code never overlap.
 *
 * Row-level security for both comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariff_versions', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('vendor_organization_id')->constrained('organizations');
            $table->unsignedBigInteger('building_vendor_assignment_id');
            $table->string('tariff_code', 60);
            $table->integer('version_no');
            $table->string('name', 150);
            $table->string('customer_category', 80)->nullable();
            $table->string('unit', 20);
            $table->decimal('rate', 16, 6);
            $table->char('currency', 3)->default('BDT');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('approval_reference', 150)->nullable();
            $table->string('status', 24)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['building_id', 'vendor_organization_id', 'tariff_code', 'version_no'], 'uq_tariff_version_no');
            $table->unique(['id', 'building_id', 'vendor_organization_id', 'building_vendor_assignment_id'], 'uq_tariff_version_scope');
            $table->foreign(['building_vendor_assignment_id', 'building_id', 'vendor_organization_id'], 'fk_tariff_vendor_assignment')
                ->references(['id', 'building_id', 'vendor_organization_id'])->on('building_vendor_assignments');
        });

        Schema::create('building_charge_rules', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->string('charge_code', 60);
            $table->string('name', 150);
            $table->string('calculation_type', 24);
            $table->decimal('amount', 16, 6);
            $table->char('currency', 3)->default('BDT');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE tariff_versions
              ADD CONSTRAINT ck_tariff_versions_rate CHECK (rate >= 0),
              ADD CONSTRAINT ck_tariff_versions_effective_range
                CHECK (effective_to IS NULL OR effective_to >= effective_from);
            -- Tariff resolution: the approved or active rate of a day.
            CREATE INDEX idx_tariff_effective
              ON tariff_versions(building_id, vendor_organization_id, tariff_code, customer_category, effective_from, effective_to)
              WHERE status IN ('approved','active');

            ALTER TABLE building_charge_rules
              ADD CONSTRAINT ck_building_charge_rules_calculation_type
                CHECK (calculation_type IN ('fixed_per_invoice','per_unit','percentage')),
              ADD CONSTRAINT ck_building_charge_rules_amount CHECK (amount >= 0),
              ADD CONSTRAINT ck_building_charge_rules_effective_range
                CHECK (effective_to IS NULL OR effective_to >= effective_from);
            SQL);

        // Lifecycle policies exactly as design V7.7; tariff transitions as V7.9.
        LifecycleSchema::attach('tariff_versions', module: 'tariffs', class: RecordClass::History,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true, defaultStatus: 'draft');
        LifecycleSchema::attach('building_charge_rules', module: 'tariffs', class: RecordClass::Configuration);

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('tariff_versions', 'draft',    'Draft',    'neutral', NULL, true,  false, 10, '{"bn":{"label":"খসড়া"}}'),
                   ('tariff_versions', 'approved', 'Approved', 'info',    NULL, false, false, 20, '{"bn":{"label":"অনুমোদিত"}}'),
                   ('tariff_versions', 'active',   'Active',   'success', NULL, false, false, 30, '{"bn":{"label":"সক্রিয়"}}'),
                   ('tariff_versions', 'retired',  'Retired',  'muted',   NULL, false, true,  40, '{"bn":{"label":"অবসরপ্রাপ্ত"}}'),
                   ('building_charge_rules', 'draft',   'Draft',   'neutral', NULL,  true,  false, 10, '{"bn":{"label":"খসড়া"}}'),
                   ('building_charge_rules', 'active',  'Active',  'success', true,  true,  false, 20, '{"bn":{"label":"সক্রিয়"}}'),
                   ('building_charge_rules', 'retired', 'Retired', 'muted',   false, false, true,  30, '{"bn":{"label":"অবসরপ্রাপ্ত"}}');

            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('tariff_versions', 'draft',    'approved', 'tariff.approve', false),
                   ('tariff_versions', 'approved', 'active',   'tariff.approve', false),
                   ('tariff_versions', 'approved', 'retired',  'tariff.approve', true),
                   ('tariff_versions', 'active',   'retired',  'tariff.approve', true),
                   ('tariff_versions', 'draft',    'retired',  NULL,             true);

            -- Approved and active versions of one tariff (per customer category) never
            -- overlap (design §14); effective_to is inclusive.
            ALTER TABLE tariff_versions
              ADD CONSTRAINT ex_tariff_versions_no_overlap
                EXCLUDE USING gist (
                  building_id WITH =,
                  vendor_organization_id WITH =,
                  tariff_code WITH =,
                  (COALESCE(customer_category, '*')) WITH =,
                  daterange(effective_from, CASE WHEN effective_to IS NULL THEN NULL ELSE effective_to + 1 END, '[)') WITH &&
                ) WHERE (status IN ('approved','active'));

            ALTER TABLE building_charge_rules
              ADD CONSTRAINT ex_building_charge_rules_no_overlap
                EXCLUDE USING gist (
                  building_id WITH =,
                  charge_code WITH =,
                  daterange(effective_from, CASE WHEN effective_to IS NULL THEN NULL ELSE effective_to + 1 END, '[)') WITH &&
                ) WHERE (status = 'active');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_transitions WHERE entity_name = 'tariff_versions';
            DELETE FROM status_definitions WHERE entity_name IN ('tariff_versions', 'building_charge_rules');
            DELETE FROM lifecycle_entities WHERE entity_name IN ('tariff_versions', 'building_charge_rules');
            SQL);

        Schema::dropIfExists('building_charge_rules');
        Schema::dropIfExists('tariff_versions');
    }
};
