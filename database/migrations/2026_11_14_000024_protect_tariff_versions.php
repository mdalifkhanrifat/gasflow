<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A tariff version is the rate history invoices are calculated from (design
 * 02 §6, V7.16, D43/D44).
 *
 * - Once it leaves draft, what it charges and who charges it never change:
 *   a new rate is a new version.
 * - effective_to may still move while the version is approved or active. This
 *   is how a rate ends and how a retroactive change is entered: end the old
 *   version the day before, then approve the new one from that day. Issued
 *   invoices keep the rate they snapshotted; the difference is billed as a
 *   retro adjustment (group 10). A retired version is fully frozen.
 * - A version must start inside its vendor assignment. Days after the
 *   assignment ends are never priced with it: the tariff timeline only uses
 *   the vendor assignment in force on each day.
 */
return new class extends Migration
{
    public function up(): void
    {
        LifecycleSchema::freezeColumns(
            'tariff_versions',
            ['building_id', 'vendor_organization_id', 'building_vendor_assignment_id', 'tariff_code', 'version_no',
                'name', 'customer_category', 'unit', 'rate', 'currency', 'effective_from',
                'approval_reference', 'approved_by', 'approved_at'],
            editableStatuses: ['draft'],
            name: 'rate',
        );
        LifecycleSchema::freezeColumns('tariff_versions', ['effective_to'], editableStatuses: ['draft', 'approved', 'active'], name: 'end');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_tariff_vendor_assignment_period() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE assignment building_vendor_assignments%ROWTYPE;
            BEGIN
              SELECT * INTO assignment FROM building_vendor_assignments WHERE id = NEW.building_vendor_assignment_id;
              -- A missing assignment is reported by its foreign key.
              IF FOUND AND (NEW.effective_from < assignment.valid_from
                            OR (assignment.valid_to IS NOT NULL AND NEW.effective_from > assignment.valid_to)) THEN
                RAISE EXCEPTION 'Tariff starts on %, outside its vendor assignment (% to %)',
                  NEW.effective_from, assignment.valid_from, COALESCE(assignment.valid_to::text, 'open');
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_validate_tariff_vendor_assignment_period
            BEFORE INSERT OR UPDATE OF effective_from, building_vendor_assignment_id ON tariff_versions
            FOR EACH ROW EXECUTE FUNCTION validate_tariff_vendor_assignment_period();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_validate_tariff_vendor_assignment_period ON tariff_versions;
            DROP FUNCTION IF EXISTS validate_tariff_vendor_assignment_period();
            DROP TRIGGER IF EXISTS trg_tariff_versions_freeze_end ON tariff_versions;
            DROP TRIGGER IF EXISTS trg_tariff_versions_freeze_rate ON tariff_versions;
            SQL);
    }
};
