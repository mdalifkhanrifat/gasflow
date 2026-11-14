<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A reconciliation is a reviewed fact (design 02 §6, V7.16, D43):
 *
 * - its variance is always main consumption minus the sub-meter total;
 * - its main meter is a meter of type "main" in the same building (composite
 *   FK through a fixed main_meter_type column, so a referenced main meter can
 *   no longer be retyped either);
 * - it is reviewed once: pending_review → accepted, adjusted or rejected
 *   (enforced), and after the decision its numbers and notes are frozen.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE meters ADD CONSTRAINT uq_meter_building_type UNIQUE (id, building_id, meter_type);

            ALTER TABLE meter_reconciliations
              ADD COLUMN main_meter_type VARCHAR(40) NOT NULL DEFAULT 'main',
              ADD CONSTRAINT ck_meter_reconciliations_main_meter_type CHECK (main_meter_type = 'main'),
              DROP CONSTRAINT fk_meter_reconciliation_main_meter_building,
              ADD CONSTRAINT fk_meter_reconciliation_main_meter
                FOREIGN KEY (main_meter_id, building_id, main_meter_type) REFERENCES meters(id, building_id, meter_type),
              ADD CONSTRAINT ck_meter_reconciliations_variance
                CHECK (variance = main_consumption - submeter_total);
            SQL);

        LifecycleSchema::attach('meter_reconciliations', module: 'reconciliation', class: RecordClass::History,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true, defaultStatus: 'pending_review');

        DB::unprepared(<<<'SQL'
            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('meter_reconciliations', 'pending_review', 'accepted', 'reconciliation.review', false),
                   ('meter_reconciliations', 'pending_review', 'adjusted', 'reconciliation.review', true),
                   ('meter_reconciliations', 'pending_review', 'rejected', 'reconciliation.review', true);
            SQL);

        LifecycleSchema::freezeColumns(
            'meter_reconciliations',
            ['building_id', 'reading_period_id', 'main_meter_id', 'main_consumption', 'submeter_total', 'variance',
                'variance_reason', 'resolution_note', 'allocation_policy_reference', 'reviewed_by', 'reviewed_at'],
            editableStatuses: ['pending_review'],
        );
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_meter_reconciliations_freeze_fields ON meter_reconciliations;
            DELETE FROM status_transitions WHERE entity_name = 'meter_reconciliations';
            SQL);

        LifecycleSchema::attach('meter_reconciliations', module: 'reconciliation', class: RecordClass::History,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'pending_review');

        DB::unprepared(<<<'SQL'
            ALTER TABLE meter_reconciliations
              DROP CONSTRAINT ck_meter_reconciliations_variance,
              DROP CONSTRAINT fk_meter_reconciliation_main_meter,
              ADD CONSTRAINT fk_meter_reconciliation_main_meter_building
                FOREIGN KEY (main_meter_id, building_id) REFERENCES meters(id, building_id),
              DROP COLUMN main_meter_type;

            ALTER TABLE meters DROP CONSTRAINT uq_meter_building_type;
            SQL);
    }
};
