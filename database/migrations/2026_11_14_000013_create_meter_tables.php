<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 8, part 1: meters and who is billed for them (design 02 §5,
 * §14 and the catalogue FK, V7.7).
 *
 * - meters belong to a building; numbers are unique per building and the type
 *   comes from meter_type_catalog.
 * - meter_assignments: where a meter physically sits (a flat, or none for a
 *   main / common-area meter). One assignment per meter at a time.
 * - meter_occupancy_assignments: which occupancy of that flat pays for the
 *   meter, with opening and closing readings at each handover. One billing-
 *   responsible occupancy per meter assignment at a time.
 * - meter_events: installation, replacement, reset, rollover and fault history.
 *
 * Assignments and events are history and are never hidden. Row-level security
 * comes with group 15; meters.pipeline_zone_id with group 13.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meters', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->string('meter_number', 100);
            $table->string('serial_number', 100)->nullable();
            $table->string('meter_type', 40);
            $table->foreign('meter_type', 'fk_meters_meter_type_catalog')->references('code')->on('meter_type_catalog');
            $table->string('measurement_unit', 20);
            $table->decimal('meter_factor', 14, 6)->default(1);
            $table->decimal('initial_reading', 20, 6)->default(0);
            $table->decimal('rollover_max', 20, 6)->nullable();
            $table->date('installed_at')->nullable();
            $table->date('last_verified_at')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['building_id', 'meter_number']);
            $table->unique(['id', 'building_id']);
        });

        Schema::create('meter_assignments', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('meter_id')->constrained();
            $table->foreignId('flat_id')->nullable()->constrained();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->decimal('opening_reading', 20, 6)->nullable();
            $table->decimal('closing_reading', 20, 6)->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['id', 'meter_id']);
            $table->unique(['id', 'flat_id']);
            $table->index(['meter_id', 'valid_from', 'valid_to'], 'idx_meter_assignments_meter_dates');
            $table->index(['flat_id', 'valid_from', 'valid_to'], 'idx_meter_assignments_flat_dates');
        });

        Schema::create('meter_occupancy_assignments', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->unsignedBigInteger('meter_assignment_id');
            $table->unsignedBigInteger('flat_occupancy_id');
            $table->unsignedBigInteger('flat_id');
            $table->boolean('is_billing_responsible')->default(true);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->decimal('opening_reading', 20, 6);
            $table->decimal('closing_reading', 20, 6)->nullable();
            $table->text('handover_note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['meter_assignment_id', 'flat_id'], 'fk_meter_occupancy_meter_assignment')
                ->references(['id', 'flat_id'])->on('meter_assignments');
            $table->foreign(['flat_occupancy_id', 'flat_id'], 'fk_meter_occupancy_flat_occupancy')
                ->references(['id', 'flat_id'])->on('flat_occupancies');
            $table->unique(['id', 'meter_assignment_id']);
            $table->unique(['id', 'meter_assignment_id', 'flat_id'], 'uq_meter_occupancy_assignment_flat');
            $table->index(['meter_assignment_id', 'valid_from', 'valid_to'], 'idx_meter_occupancy_assignment_dates');
            $table->index(['flat_occupancy_id', 'valid_from', 'valid_to'], 'idx_meter_occupancy_occupancy_dates');
        });

        Schema::create('meter_events', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('meter_id')->constrained();
            $table->string('event_type', 30);
            $table->timestampTz('event_at');
            $table->decimal('reading_at_event', 20, 6)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('evidence_attachment_id')->nullable()->constrained('attachments');
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE meters
              ADD CONSTRAINT ck_meters_meter_factor CHECK (meter_factor > 0),
              ADD CONSTRAINT ck_meters_initial_reading CHECK (initial_reading >= 0);

            ALTER TABLE meter_assignments
              ADD CONSTRAINT ck_meter_assignments_valid_range
                CHECK (valid_to IS NULL OR valid_to >= valid_from),
              -- One physical place per meter at a time (design §14); valid_to is inclusive.
              ADD CONSTRAINT ex_meter_physical_assignment_no_overlap
                EXCLUDE USING gist (
                  meter_id WITH =,
                  daterange(valid_from, CASE WHEN valid_to IS NULL THEN NULL ELSE valid_to + 1 END, '[)') WITH &&
                );

            ALTER TABLE meter_occupancy_assignments
              ADD CONSTRAINT ck_meter_occupancy_assignments_opening_reading
                CHECK (opening_reading >= 0),
              ADD CONSTRAINT ck_meter_occupancy_assignments_closing_reading
                CHECK (closing_reading IS NULL OR closing_reading >= 0),
              ADD CONSTRAINT ck_meter_occupancy_assignments_valid_range
                CHECK (valid_to IS NULL OR valid_to >= valid_from),
              -- One billing-responsible occupancy per meter assignment at a time (design §5).
              ADD CONSTRAINT ex_meter_occupancy_responsibility_no_overlap
                EXCLUDE USING gist (
                  meter_assignment_id WITH =,
                  daterange(valid_from, COALESCE(valid_to, 'infinity'::date), '[]') WITH &&
                ) WHERE (is_billing_responsible);

            ALTER TABLE meter_events
              ADD CONSTRAINT ck_meter_events_event_type
                CHECK (event_type IN ('installed','replaced','reset','rollover','verified','fault_reported','removed'));
            SQL);

        // Lifecycle policies exactly as design V7.7.
        LifecycleSchema::attach('meters', module: 'metering', class: RecordClass::Master);
        LifecycleSchema::attach('meter_assignments', module: 'metering', class: RecordClass::History, softDelete: SoftDeletePolicy::Protected);
        LifecycleSchema::attach('meter_occupancy_assignments', module: 'metering', class: RecordClass::History, softDelete: SoftDeletePolicy::Protected);
        LifecycleSchema::attach('meter_events', module: 'metering', class: RecordClass::History, softDelete: SoftDeletePolicy::Protected);

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('meters', 'active',   'Active',   'success', true,  true,  false, 10, '{"bn":{"label":"সক্রিয়"}}'),
                   ('meters', 'inactive', 'Inactive', 'muted',   false, true,  false, 20, '{"bn":{"label":"নিষ্ক্রিয়"}}'),
                   ('meters', 'faulty',   'Faulty',   'danger',  NULL,  false, false, 30, '{"bn":{"label":"ত্রুটিপূর্ণ"}}'),
                   ('meters', 'removed',  'Removed',  'muted',   false, false, true,  40, '{"bn":{"label":"অপসারিত"}}'),
                   ('meters', 'replaced', 'Replaced', 'muted',   false, false, true,  50, '{"bn":{"label":"প্রতিস্থাপিত"}}');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_definitions WHERE entity_name = 'meters';
            DELETE FROM lifecycle_entities
              WHERE entity_name IN ('meters', 'meter_assignments', 'meter_occupancy_assignments', 'meter_events');
            SQL);

        Schema::dropIfExists('meter_events');
        Schema::dropIfExists('meter_occupancy_assignments');
        Schema::dropIfExists('meter_assignments');
        Schema::dropIfExists('meters');
    }
};
