<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The gas supply change register (design 02 V7.17, D45).
 *
 * A building's gas can run out and be replaced by another company's gas at
 * another price at any moment: two days with vendor A at 50, then vendor B at
 * 49, then fifty days with vendor C at 60, across month ends. Each change is
 * recorded with the moment, the new vendor and price, and meter readings
 * taken at that moment, so consumption can be priced by readings instead of
 * by the daily average.
 *
 * - supply_changes: when (changed_at; effective_on is the first day priced at
 *   the new supply), which vendor assignment and tariff version (composite
 *   FK: the tariff belongs to that vendor and building), why, evidence. The
 *   vendor and the rate must be in force on effective_on. One change per
 *   building and day. recorded → verified (needs a main meter reading) or
 *   cancelled; frozen once verified.
 * - supply_change_readings: the readings taken at the change. The main
 *   meter's is required to verify; flat meters' are optional and make their
 *   split exact. Frozen with their change.
 *
 * The Phase 8 "record a gas change" action writes the vendor assignment, the
 * tariff version and this register in one transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_changes', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->unsignedBigInteger('vendor_organization_id');
            $table->unsignedBigInteger('building_vendor_assignment_id');
            $table->unsignedBigInteger('tariff_version_id');
            $table->timestampTz('changed_at');
            $table->date('effective_on');
            $table->string('reason', 30);
            $table->foreignId('evidence_attachment_id')->nullable()->constrained('attachments');
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestampTz('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('recorded');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['id', 'building_id']);
            $table->foreign(['tariff_version_id', 'building_id', 'vendor_organization_id', 'building_vendor_assignment_id'], 'fk_supply_change_tariff')
                ->references(['id', 'building_id', 'vendor_organization_id', 'building_vendor_assignment_id'])->on('tariff_versions');
        });

        Schema::create('supply_change_readings', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->unsignedBigInteger('supply_change_id');
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('meter_id');
            $table->decimal('reading', 20, 6);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['supply_change_id', 'meter_id']);
            $table->foreign(['supply_change_id', 'building_id'], 'fk_supply_change_reading_change')
                ->references(['id', 'building_id'])->on('supply_changes');
            $table->foreign(['meter_id', 'building_id'], 'fk_supply_change_reading_meter')
                ->references(['id', 'building_id'])->on('meters');
            $table->index('meter_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supply_changes
              ADD CONSTRAINT ck_supply_changes_reason
                CHECK (reason IN ('supply_started','supply_exhausted','vendor_changed','price_changed','other'));
            -- One change per building and day (the tariff timeline prices whole days).
            CREATE UNIQUE INDEX uq_supply_change_building_day
              ON supply_changes(building_id, effective_on) WHERE status <> 'cancelled';

            ALTER TABLE supply_change_readings
              ADD CONSTRAINT ck_supply_change_readings_reading CHECK (reading >= 0);

            CREATE OR REPLACE FUNCTION validate_supply_change() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE local_day DATE; tariff tariff_versions%ROWTYPE; assignment building_vendor_assignments%ROWTYPE;
            BEGIN
              SELECT (NEW.changed_at AT TIME ZONE b.timezone)::date INTO local_day FROM buildings b WHERE b.id = NEW.building_id;
              IF NEW.effective_on NOT IN (local_day, local_day + 1) THEN
                RAISE EXCEPTION 'A supply change at % (building day %) takes effect that day or the next, not on %',
                  NEW.changed_at, local_day, NEW.effective_on;
              END IF;
              SELECT * INTO tariff FROM tariff_versions WHERE id = NEW.tariff_version_id;
              IF FOUND AND (tariff.status NOT IN ('approved','active') OR tariff.effective_from > NEW.effective_on
                            OR (tariff.effective_to IS NOT NULL AND tariff.effective_to < NEW.effective_on)) THEN
                RAISE EXCEPTION 'Tariff version % is not an approved rate on %', NEW.tariff_version_id, NEW.effective_on;
              END IF;
              SELECT * INTO assignment FROM building_vendor_assignments WHERE id = NEW.building_vendor_assignment_id;
              IF FOUND AND (assignment.status NOT IN ('active','ended') OR assignment.valid_from > NEW.effective_on
                            OR (assignment.valid_to IS NOT NULL AND assignment.valid_to < NEW.effective_on)) THEN
                RAISE EXCEPTION 'Vendor assignment % does not supply the building on %', NEW.building_vendor_assignment_id, NEW.effective_on;
              END IF;
              IF NEW.status = 'verified' AND OLD.status IS DISTINCT FROM 'verified' AND NOT EXISTS (
                   SELECT 1 FROM supply_change_readings r JOIN meters m ON m.id = r.meter_id
                   WHERE r.supply_change_id = NEW.id AND m.meter_type = 'main') THEN
                RAISE EXCEPTION 'Supply change % needs a main meter reading before it is verified', NEW.id;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_validate_supply_change
            BEFORE INSERT OR UPDATE ON supply_changes
            FOR EACH ROW EXECUTE FUNCTION validate_supply_change();
            SQL);

        // Protected history; the review is enforced.
        LifecycleSchema::attach('supply_changes', module: 'supply', class: RecordClass::History,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true, defaultStatus: 'recorded');
        LifecycleSchema::attach('supply_change_readings', module: 'supply', class: RecordClass::History, softDelete: SoftDeletePolicy::Protected);

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('supply_changes', 'recorded',  'Recorded',  'info',    NULL,  true,  false, 10, '{"bn":{"label":"লিপিবদ্ধ"}}'),
                   ('supply_changes', 'verified',  'Verified',  'success', NULL,  false, true,  20, '{"bn":{"label":"যাচাইকৃত"}}'),
                   ('supply_changes', 'cancelled', 'Cancelled', 'muted',   false, false, true,  30, '{"bn":{"label":"বাতিল"}}');

            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('supply_changes', 'recorded', 'verified',  'supply_change.verify', false),
                   ('supply_changes', 'recorded', 'cancelled', 'supply_change.verify', true);
            SQL);

        LifecycleSchema::freezeColumns(
            'supply_changes',
            ['building_id', 'vendor_organization_id', 'building_vendor_assignment_id', 'tariff_version_id',
                'changed_at', 'effective_on', 'reason', 'evidence_attachment_id', 'recorded_by', 'verified_by', 'verified_at', 'notes'],
            editableStatuses: ['recorded'],
        );
        LifecycleSchema::freezeWhenParentClosed('supply_change_readings', parent: 'supply_changes',
            foreignKey: 'supply_change_id', closedStatuses: ['verified', 'cancelled']);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_transitions WHERE entity_name = 'supply_changes';
            DELETE FROM status_definitions WHERE entity_name = 'supply_changes';
            DELETE FROM lifecycle_entities WHERE entity_name IN ('supply_changes', 'supply_change_readings');
            SQL);

        Schema::dropIfExists('supply_change_readings');
        Schema::dropIfExists('supply_changes');

        DB::unprepared('DROP FUNCTION IF EXISTS validate_supply_change();');
    }
};
