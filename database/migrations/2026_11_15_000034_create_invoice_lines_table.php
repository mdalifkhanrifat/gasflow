<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 10, part 3: invoice lines (design 02 §6, V7.16, V7.17).
 *
 * - One gas line per rate segment: its tariff version, its first and last
 *   day and how its quantity was split (split_method, as returned by
 *   metered_charge_segments(): single_rate, own_meter, main_meter or days).
 *   A bill therefore shows which rate applied from which date to which date.
 * - A retro_adjustment line bills the difference of a back-dated rate for an
 *   issued line (adjusts_invoice_line_id) with the new tariff version.
 * - A line's tariff version must belong to its invoice's vendor assignment.
 * - Lines are frozen with their invoice: none can be added, changed or
 *   re-decided once the invoice leaves draft (only the maintenance override
 *   may still correct one).
 *
 * Protected money records, never hidden. Row-level security comes with
 * group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('invoice_id')->constrained();
            $table->string('line_type', 32);
            $table->string('description', 255);
            $table->decimal('quantity', 20, 6)->default(1);
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_rate', 16, 6)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->jsonb('calculation_snapshot')->default('{}');
            $table->foreignId('tariff_version_id')->nullable()->constrained();
            $table->date('segment_from')->nullable();
            $table->date('segment_to')->nullable();
            $table->string('split_method', 16)->nullable();
            $table->foreignId('adjusts_invoice_line_id')->nullable()->constrained('invoice_lines');
            $table->timestampTz('created_at')->useCurrent();
            $table->index('invoice_id', 'idx_invoice_lines_invoice');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE invoice_lines
              ADD CONSTRAINT ck_invoice_lines_line_type
                CHECK (line_type IN ('gas_charge','retro_adjustment','service_charge','installation_emi','adjustment',
                                     'tax','discount','credit','previous_balance')),
              ADD CONSTRAINT ck_invoice_lines_segment
                CHECK (segment_from IS NULL OR segment_to IS NULL OR segment_to >= segment_from),
              ADD CONSTRAINT ck_invoice_lines_split_method
                CHECK (split_method IS NULL OR split_method IN ('single_rate','own_meter','main_meter','days')),
              -- A gas line says which rate it used, for which days, and how it was split.
              ADD CONSTRAINT ck_invoice_lines_gas_charge_tariff
                CHECK (line_type <> 'gas_charge'
                       OR (tariff_version_id IS NOT NULL AND segment_from IS NOT NULL AND segment_to IS NOT NULL)),
              ADD CONSTRAINT ck_invoice_lines_gas_charge_split
                CHECK (line_type <> 'gas_charge' OR split_method IS NOT NULL),
              -- A retro line names the new rate and the issued line it corrects.
              ADD CONSTRAINT ck_invoice_lines_retro_adjustment
                CHECK (line_type <> 'retro_adjustment'
                       OR (tariff_version_id IS NOT NULL AND adjusts_invoice_line_id IS NOT NULL));

            CREATE OR REPLACE FUNCTION validate_invoice_line_tariff() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.tariff_version_id IS NOT NULL AND NOT EXISTS (
                   SELECT 1 FROM invoices i
                   JOIN tariff_versions t ON t.building_vendor_assignment_id = i.building_vendor_assignment_id
                   WHERE i.id = NEW.invoice_id AND t.id = NEW.tariff_version_id) THEN
                RAISE EXCEPTION 'Tariff version % does not belong to the vendor assignment of invoice %',
                  NEW.tariff_version_id, NEW.invoice_id;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_validate_invoice_line_tariff
            BEFORE INSERT OR UPDATE OF invoice_id, tariff_version_id ON invoice_lines
            FOR EACH ROW EXECUTE FUNCTION validate_invoice_line_tariff();
            SQL);

        // Lifecycle policy exactly as design V7.7 (generic statuses, default active).
        LifecycleSchema::attach('invoice_lines', module: 'billing', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected);

        LifecycleSchema::freezeWhenParentClosed(
            'invoice_lines',
            parent: 'invoices',
            foreignKey: 'invoice_id',
            closedStatuses: ['approved', 'issued', 'partially_paid', 'paid', 'overdue', 'void', 'written_off'],
        );
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM lifecycle_entities WHERE entity_name = 'invoice_lines';
            DROP TABLE IF EXISTS invoice_lines;
            DROP FUNCTION IF EXISTS validate_invoice_line_tariff();
            SQL);
    }
};
