<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 10, part 5: retroactive rate differences (design 02 V7.16,
 * D44).
 *
 * When a tariff version with a past start is approved, every issued gas line
 * whose days it now prices is recalculated with the tariff timeline over the
 * line's own days and quantity. The issued invoice never changes: the
 * difference (recalculated − billed) is recorded here once per line and
 * version, then billed on a supplementary invoice of the same vendor
 * assignment (a retro_adjustment line) or credited (a credit note), never
 * both. pending → applied or waived (with a reason) is enforced.
 *
 * The new tariff version must be of the same building (composite key, D42).
 * Protected money records, never hidden. Row-level security comes with
 * group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE tariff_versions ADD CONSTRAINT uq_tariff_version_building UNIQUE (id, building_id);');

        Schema::create('tariff_retro_adjustments', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('tariff_version_id')->constrained();
            $table->foreignId('original_invoice_line_id')->constrained('invoice_lines');
            $table->date('segment_from');
            $table->date('segment_to');
            $table->decimal('quantity', 20, 6);
            $table->decimal('billed_amount', 14, 2);
            $table->decimal('recalculated_amount', 14, 2);
            $table->decimal('difference', 14, 2);
            $table->foreignId('adjustment_invoice_line_id')->nullable()->constrained('invoice_lines');
            $table->foreignId('credit_note_id')->nullable()->constrained();
            $table->string('status', 24)->default('pending');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['original_invoice_line_id', 'tariff_version_id'], 'uq_tariff_retro_adjustment_line_version');
            $table->foreign(['tariff_version_id', 'building_id'], 'fk_tariff_retro_adjustment_tariff')
                ->references(['id', 'building_id'])->on('tariff_versions');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE tariff_retro_adjustments
              ADD CONSTRAINT ck_tariff_retro_adjustments_range CHECK (segment_to >= segment_from),
              ADD CONSTRAINT ck_tariff_retro_adjustments_quantity CHECK (quantity >= 0),
              ADD CONSTRAINT ck_tariff_retro_adjustments_difference
                CHECK (difference = recalculated_amount - billed_amount),
              -- Billed on a supplementary invoice or credited, never both.
              ADD CONSTRAINT ck_tariff_retro_adjustments_settlement
                CHECK (adjustment_invoice_line_id IS NULL OR credit_note_id IS NULL);
            SQL);

        // Lifecycle policy and transitions exactly as design V7.16.
        LifecycleSchema::attach('tariff_retro_adjustments', module: 'billing', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true, defaultStatus: 'pending');

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('tariff_retro_adjustments', 'pending', 'Pending', 'warning', NULL, true,  false, 10, '{"bn":{"label":"অপেক্ষমাণ"}}'),
                   ('tariff_retro_adjustments', 'applied', 'Applied', 'success', NULL, false, true,  20, '{"bn":{"label":"প্রয়োগকৃত"}}'),
                   ('tariff_retro_adjustments', 'waived',  'Waived',  'muted',   NULL, false, true,  30, '{"bn":{"label":"মওকুফ"}}');

            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('tariff_retro_adjustments', 'pending', 'applied', 'tariff.retro', false),
                   ('tariff_retro_adjustments', 'pending', 'waived',  'tariff.retro', true);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_transitions WHERE entity_name = 'tariff_retro_adjustments';
            DELETE FROM status_definitions WHERE entity_name = 'tariff_retro_adjustments';
            DELETE FROM lifecycle_entities WHERE entity_name = 'tariff_retro_adjustments';
            DROP TABLE IF EXISTS tariff_retro_adjustments;
            ALTER TABLE tariff_versions DROP CONSTRAINT IF EXISTS uq_tariff_version_building;
            SQL);
    }
};
