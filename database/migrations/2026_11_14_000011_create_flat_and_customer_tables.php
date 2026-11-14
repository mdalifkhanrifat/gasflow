<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 7, part 1: flats, customers and who lives in which flat
 * (design 02 §4 and §14, V7.7, V7.10).
 *
 * - flats belong to a building; codes are unique per building.
 * - customers belong to a tenant organization; codes are unique per tenant,
 *   and each customer has a language for SMS, e-mail and invoices.
 * - flat_occupancies is history (never hidden). One primary contact per flat
 *   at a time is enforced by an exclusion constraint.
 *
 * `customers.tenant_organization_id` and the per-tenant code come with the
 * table (as D40), not later with the RLS policies of group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flats', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->string('code', 60);
            $table->string('floor_label', 40)->nullable();
            $table->decimal('area_sqft', 10, 2)->nullable();
            $table->string('status', 24)->default('active');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['building_id', 'code']);
            $table->unique(['id', 'building_id']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_organization_id')->constrained('organizations');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('customer_code', 80);
            $table->string('full_name', 180);
            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->text('address')->nullable();
            $table->string('preferred_locale', 8)->default('bn');
            $table->foreign('preferred_locale')->references('code')->on('supported_locales');
            $table->string('status', 24)->default('active');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['tenant_organization_id', 'customer_code'], 'uq_customer_code_tenant');
        });

        Schema::create('flat_occupancies', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('flat_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->string('occupancy_type', 24);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_primary_contact')->default(false);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['id', 'flat_id']);
            $table->index(['flat_id', 'valid_from', 'valid_to'], 'idx_flat_occupancies_history');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE flats
              ADD CONSTRAINT ck_flats_area_sqft CHECK (area_sqft IS NULL OR area_sqft >= 0);

            CREATE INDEX idx_customers_phone ON customers(phone) WHERE phone IS NOT NULL;

            ALTER TABLE flat_occupancies
              ADD CONSTRAINT ck_flat_occupancies_occupancy_type
                CHECK (occupancy_type IN ('owner','tenant','authorized_contact')),
              ADD CONSTRAINT ck_flat_occupancies_valid_range
                CHECK (valid_to IS NULL OR valid_to >= valid_from),
              -- One primary contact per flat at a time (design §14); valid_to is inclusive.
              ADD CONSTRAINT ex_flat_primary_occupancy_no_overlap
                EXCLUDE USING gist (
                  flat_id WITH =,
                  daterange(valid_from, CASE WHEN valid_to IS NULL THEN NULL ELSE valid_to + 1 END, '[)') WITH &&
                ) WHERE (is_primary_contact);
            SQL);

        // Lifecycle policies exactly as design V7.7.
        LifecycleSchema::attach('flats', module: 'buildings', class: RecordClass::Master);
        LifecycleSchema::attach('customers', module: 'customers', class: RecordClass::Master);
        LifecycleSchema::attach('flat_occupancies', module: 'customers', class: RecordClass::History, softDelete: SoftDeletePolicy::Protected);

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('flats', 'active',            'Active',            'success', true,  true,  false, 10, '{"bn":{"label":"সক্রিয়"}}'),
                   ('flats', 'inactive',          'Inactive',          'muted',   false, true,  false, 20, '{"bn":{"label":"নিষ্ক্রিয়"}}'),
                   ('flats', 'under_maintenance', 'Under maintenance', 'warning', NULL,  false, false, 30, '{"bn":{"label":"রক্ষণাবেক্ষণাধীন"}}'),
                   ('customers', 'active',   'Active',   'success', true,  true,  false, 10, '{"bn":{"label":"সক্রিয়"}}'),
                   ('customers', 'inactive', 'Inactive', 'muted',   false, true,  false, 20, '{"bn":{"label":"নিষ্ক্রিয়"}}'),
                   ('customers', 'blocked',  'Blocked',  'danger',  false, false, false, 30, '{"bn":{"label":"অবরুদ্ধ"}}');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_definitions WHERE entity_name IN ('flats', 'customers');
            DELETE FROM lifecycle_entities WHERE entity_name IN ('flats', 'customers', 'flat_occupancies');
            SQL);

        Schema::dropIfExists('flat_occupancies');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('flats');
    }
};
