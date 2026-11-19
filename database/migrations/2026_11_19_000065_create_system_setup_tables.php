<?php

use App\Enums\Lifecycle\RecordClass;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 13, part 4: system setup of a building (feature
 * module.system_setup; design 02 V7.4, V7.7, V7.9).
 *
 * - setup_area_catalog: the setup areas (meter topology, pipeline, riser and
 *   valves, safety inspection, billing configuration, payment gateway),
 *   seeded with Bangla names.
 * - pipeline_zones: named zones of a building's pipeline; a meter may sit in
 *   a zone of its own building (composite key).
 * - building_setup_tasks: a setup task of a building in one area, optionally
 *   in one of its zones, with who did it and when (both or neither).
 *
 * Catalogue and zones are configuration and master data; tasks are workflow
 * rows (pending → in progress → blocked / completed / cancelled, not enforced
 * by the design). All may be hidden. Row-level security comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setup_area_catalog', function (Blueprint $table) {
            $table->string('code', 40)->primary();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->jsonb('translations')->default('{}');
        });

        Schema::create('pipeline_zones', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unique(['building_id', 'code']);
            $table->unique(['id', 'building_id']);
        });

        Schema::create('building_setup_tasks', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->string('setup_area_code', 40);
            $table->unsignedBigInteger('pipeline_zone_id')->nullable();
            $table->string('title', 180);
            $table->jsonb('configuration')->default('{}');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users');
            $table->date('due_date')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->foreign('setup_area_code')->references('code')->on('setup_area_catalog');
            $table->foreign(['pipeline_zone_id', 'building_id'], 'fk_building_setup_task_pipeline_zone')
                ->references(['id', 'building_id'])->on('pipeline_zones');
            $table->index(['building_id', 'setup_area_code'], 'idx_building_setup_tasks_building');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE setup_area_catalog
              ADD CONSTRAINT ck_setup_area_catalog_code CHECK (code ~ '^[a-z][a-z0-9_]*$'),
              ADD CONSTRAINT ck_setup_area_catalog_translations CHECK (jsonb_typeof(translations) = 'object');

            ALTER TABLE building_setup_tasks
              -- Who completed a task and when are recorded together.
              ADD CONSTRAINT ck_building_setup_tasks_completion CHECK ((completed_at IS NULL) = (completed_by IS NULL));

            ALTER TABLE meters
              ADD COLUMN pipeline_zone_id BIGINT,
              ADD CONSTRAINT fk_meters_pipeline_zone FOREIGN KEY (pipeline_zone_id, building_id)
                REFERENCES pipeline_zones(id, building_id);
            SQL);

        // Lifecycle policies exactly as design V7.7; statuses as V7.9.
        LifecycleSchema::attach('setup_area_catalog', module: 'installations', class: RecordClass::Configuration);
        LifecycleSchema::attach('pipeline_zones', module: 'installations', class: RecordClass::Master);
        LifecycleSchema::attach('building_setup_tasks', module: 'installations', class: RecordClass::Workflow,
            defaultStatus: 'pending');

        DB::unprepared(<<<'SQL'
            INSERT INTO setup_area_catalog(code, name, sort_order, translations) VALUES
             ('meter_topology', 'Meter topology', 10, '{"bn":{"name":"মিটার বিন্যাস"}}'),
             ('pipeline', 'Pipeline setup', 20, '{"bn":{"name":"পাইপলাইন সেটআপ"}}'),
             ('riser_valves', 'Riser & valves', 30, '{"bn":{"name":"রাইজার ও ভাল্ভ"}}'),
             ('safety_inspection', 'Safety inspection', 40, '{"bn":{"name":"নিরাপত্তা পরিদর্শন"}}'),
             ('billing_configuration', 'Billing configuration', 50, '{"bn":{"name":"বিলিং কনফিগারেশন"}}'),
             ('payment_gateway', 'Payment gateway', 60, '{"bn":{"name":"পেমেন্ট গেটওয়ে"}}');

            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('building_setup_tasks', 'pending',     'Pending',     'warning', NULL,  true,  false, 10, '{"bn":{"label":"অপেক্ষমাণ"}}'),
                   ('building_setup_tasks', 'in_progress', 'In progress', 'info',    NULL,  true,  false, 20, '{"bn":{"label":"চলমান"}}'),
                   ('building_setup_tasks', 'blocked',     'Blocked',     'danger',  NULL,  false, false, 30, '{"bn":{"label":"আটকে আছে"}}'),
                   ('building_setup_tasks', 'completed',   'Completed',   'success', NULL,  false, true,  40, '{"bn":{"label":"সম্পন্ন"}}'),
                   ('building_setup_tasks', 'cancelled',   'Cancelled',   'muted',   false, false, true,  50, '{"bn":{"label":"বাতিল"}}');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE meters
              DROP CONSTRAINT fk_meters_pipeline_zone,
              DROP COLUMN pipeline_zone_id;
            DELETE FROM status_definitions WHERE entity_name = 'building_setup_tasks';
            DELETE FROM lifecycle_entities WHERE entity_name IN ('setup_area_catalog', 'pipeline_zones', 'building_setup_tasks');
            SQL);

        Schema::dropIfExists('building_setup_tasks');
        Schema::dropIfExists('pipeline_zones');
        Schema::dropIfExists('setup_area_catalog');
    }
};
