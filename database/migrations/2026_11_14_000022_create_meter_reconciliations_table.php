<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 9, part 2: main meter against the sum of the sub-meters,
 * once per building and reading period (design 02 §6, V7.7, V7.15).
 *
 * The reading period and the main meter must belong to the reconciliation's
 * building (composite FKs, created with the table as D40). A reconciliation
 * is reviewed once: pending_review → accepted, adjusted or rejected.
 * Protected history: never hidden. Row-level security comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meter_reconciliations', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->unsignedBigInteger('reading_period_id');
            $table->unsignedBigInteger('main_meter_id');
            $table->decimal('main_consumption', 20, 6);
            $table->decimal('submeter_total', 20, 6);
            $table->decimal('variance', 20, 6);
            $table->string('variance_reason', 80)->nullable();
            $table->text('resolution_note')->nullable();
            $table->string('allocation_policy_reference', 120)->nullable();
            $table->string('status', 24)->default('pending_review');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['building_id', 'reading_period_id']);
            $table->foreign(['reading_period_id', 'building_id'], 'fk_meter_reconciliation_period_building')
                ->references(['id', 'building_id'])->on('reading_periods');
            $table->foreign(['main_meter_id', 'building_id'], 'fk_meter_reconciliation_main_meter_building')
                ->references(['id', 'building_id'])->on('meters');
        });

        // Lifecycle policy exactly as design V7.7.
        LifecycleSchema::attach('meter_reconciliations', module: 'reconciliation', class: RecordClass::History,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'pending_review');

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('meter_reconciliations', 'pending_review', 'Pending review', 'warning', NULL, true,  false, 10, '{"bn":{"label":"পর্যালোচনা অপেক্ষমাণ"}}'),
                   ('meter_reconciliations', 'accepted',       'Accepted',       'success', NULL, false, true,  20, '{"bn":{"label":"গৃহীত"}}'),
                   ('meter_reconciliations', 'adjusted',       'Adjusted',       'info',    NULL, false, true,  30, '{"bn":{"label":"সমন্বিত"}}'),
                   ('meter_reconciliations', 'rejected',       'Rejected',       'danger',  NULL, false, true,  40, '{"bn":{"label":"প্রত্যাখ্যাত"}}');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_definitions WHERE entity_name = 'meter_reconciliations';
            DELETE FROM lifecycle_entities WHERE entity_name = 'meter_reconciliations';
            SQL);

        Schema::dropIfExists('meter_reconciliations');
    }
};
