<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 4, part 1: buildings, their ownership history and their gas
 * vendor assignments (design 02 §2 and §14, V7.7).
 *
 * A building belongs to one tenant organization. Ownership and vendor
 * assignments are history: they are never hidden or deleted, only ended.
 * One active gas vendor per building at a time is enforced by an exclusion
 * constraint. The ownership evidence FK needs `attachments` and comes with
 * group 7; row-level security comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_organization_id')->constrained('organizations');
            $table->string('code', 60);
            $table->string('name', 180);
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->char('country_code', 2)->default('BD');
            $table->string('timezone', 60)->default('Asia/Dhaka');
            $table->char('currency', 3)->default('BDT');
            $table->string('billing_mode', 32)->default('internal_cost_allocation');
            $table->string('utility_account_reference', 120)->nullable();
            $table->string('status', 24)->default('active');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['tenant_organization_id', 'code']);
            $table->unique(['id', 'tenant_organization_id']);
        });

        Schema::create('building_ownerships', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('owner_organization_id')->nullable()->constrained('organizations');
            $table->string('owner_name', 180);
            $table->string('contact_phone', 30)->nullable();
            $table->decimal('ownership_share', 7, 4)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->unsignedBigInteger('evidence_attachment_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('building_vendor_assignments', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('vendor_organization_id')->constrained('organizations');
            $table->string('assignment_type', 40)->default('gas_vendor');
            $table->string('agreement_reference', 120)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['id', 'building_id', 'vendor_organization_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE buildings
              ADD CONSTRAINT ck_buildings_billing_mode
                CHECK (billing_mode IN ('authorized_meter_billing','internal_cost_allocation'));
            CREATE INDEX idx_buildings_tenant_active ON buildings(tenant_organization_id) WHERE status = 'active';

            ALTER TABLE building_ownerships
              ADD CONSTRAINT ck_building_ownerships_share
                CHECK (ownership_share IS NULL OR ownership_share BETWEEN 0 AND 100),
              ADD CONSTRAINT ck_building_ownerships_valid_range
                CHECK (valid_to IS NULL OR valid_to >= valid_from);

            ALTER TABLE building_vendor_assignments
              ADD CONSTRAINT ck_building_vendor_assignments_valid_range
                CHECK (valid_to IS NULL OR valid_to >= valid_from),
              -- Initial release: one active gas supplier per building at a time (design §14).
              -- valid_to is inclusive, so the range ends the day after it.
              ADD CONSTRAINT ex_building_single_gas_vendor_no_overlap
                EXCLUDE USING gist (
                  building_id WITH =,
                  assignment_type WITH =,
                  daterange(valid_from, CASE WHEN valid_to IS NULL THEN NULL ELSE valid_to + 1 END, '[)') WITH &&
                ) WHERE (status = 'active' AND assignment_type = 'gas_vendor');
            SQL);

        // Lifecycle policies exactly as design V7.7.
        LifecycleSchema::attach('buildings', module: 'buildings', class: RecordClass::Master);
        LifecycleSchema::attach('building_ownerships', module: 'buildings', class: RecordClass::History, softDelete: SoftDeletePolicy::Protected);
        LifecycleSchema::attach('building_vendor_assignments', module: 'buildings', class: RecordClass::History, softDelete: SoftDeletePolicy::Protected);

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('buildings', 'draft',     'Draft',     'neutral', NULL,  true,  false, 10, '{"bn":{"label":"খসড়া"}}'),
                   ('buildings', 'active',    'Active',    'success', true,  true,  false, 20, '{"bn":{"label":"সক্রিয়"}}'),
                   ('buildings', 'suspended', 'Suspended', 'warning', false, false, false, 30, '{"bn":{"label":"স্থগিত"}}'),
                   ('buildings', 'archived',  'Archived',  'muted',   false, false, true,  40, '{"bn":{"label":"আর্কাইভকৃত"}}'),
                   ('building_vendor_assignments', 'active',    'Active',    'success', true,  true,  false, 10, '{"bn":{"label":"সক্রিয়"}}'),
                   ('building_vendor_assignments', 'ended',     'Ended',     'muted',   false, false, true,  20, '{"bn":{"label":"সমাপ্ত"}}'),
                   ('building_vendor_assignments', 'cancelled', 'Cancelled', 'danger',  false, false, true,  30, '{"bn":{"label":"বাতিল"}}');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_definitions WHERE entity_name IN ('buildings', 'building_vendor_assignments');
            DELETE FROM lifecycle_entities WHERE entity_name IN ('buildings', 'building_ownerships', 'building_vendor_assignments');
            SQL);

        Schema::dropIfExists('building_vendor_assignments');
        Schema::dropIfExists('building_ownerships');
        Schema::dropIfExists('buildings');
    }
};
