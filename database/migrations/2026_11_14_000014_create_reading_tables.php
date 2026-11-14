<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 8, part 2: monthly meter readings and their review
 * (design 02 §5, V7.7, V7.9).
 *
 * - reading_periods: one per building and month, open → collecting → review →
 *   approved → locked (or cancelled). Transitions are enforced.
 * - meter_readings: one per period and billed occupancy (or per period and
 *   meter when the meter has no billed occupancy), with the meter factor at
 *   the time and an idempotency key for offline / retried submissions.
 *   Transitions are enforced; a wrong approved reading is superseded.
 * - meter_reading_revisions and reading_approvals: the correction and review
 *   history of a reading.
 *
 * All four are protected and never hidden. Row-level security comes with
 * group 15; meter_reconciliations with group 9.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_periods', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->smallInteger('period_year');
            $table->smallInteger('period_month');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 24)->default('open');
            $table->foreignId('opened_by')->nullable()->constrained('users');
            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['building_id', 'period_year', 'period_month']);
        });

        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('reading_period_id')->constrained();
            $table->foreignId('meter_id')->constrained();
            $table->unsignedBigInteger('meter_assignment_id')->nullable();
            $table->unsignedBigInteger('meter_occupancy_assignment_id')->nullable();
            $table->decimal('previous_reading', 20, 6);
            $table->decimal('current_reading', 20, 6);
            $table->decimal('meter_factor_snapshot', 14, 6);
            $table->decimal('consumption', 20, 6);
            $table->timestampTz('reading_taken_at');
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('evidence_attachment_id')->nullable()->constrained('attachments');
            $table->uuid('idempotency_key')->unique();
            $table->string('status', 24)->default('submitted');
            $table->text('notes')->nullable();
            $table->timestampTz('submitted_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['meter_assignment_id', 'meter_id'], 'fk_meter_reading_meter_assignment')
                ->references(['id', 'meter_id'])->on('meter_assignments');
            $table->foreign(['meter_occupancy_assignment_id', 'meter_assignment_id'], 'fk_meter_reading_occupancy_assignment')
                ->references(['id', 'meter_assignment_id'])->on('meter_occupancy_assignments');
            $table->index(['reading_period_id', 'status'], 'idx_readings_period_status');
        });

        Schema::create('meter_reading_revisions', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('meter_reading_id')->constrained();
            $table->integer('revision_no');
            $table->decimal('previous_reading', 20, 6);
            $table->decimal('current_reading', 20, 6);
            $table->decimal('consumption', 20, 6);
            $table->text('reason');
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['meter_reading_id', 'revision_no']);
        });

        Schema::create('reading_approvals', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('meter_reading_id')->constrained();
            $table->foreignId('reviewer_user_id')->constrained('users');
            $table->string('decision', 30);
            $table->text('reason')->nullable();
            $table->timestampTz('reviewed_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE reading_periods
              ADD CONSTRAINT ck_reading_periods_period_month CHECK (period_month BETWEEN 1 AND 12),
              ADD CONSTRAINT ck_reading_periods_range CHECK (ends_on >= starts_on);

            ALTER TABLE meter_readings
              ADD CONSTRAINT ck_meter_readings_previous_reading CHECK (previous_reading >= 0),
              ADD CONSTRAINT ck_meter_readings_current_reading CHECK (current_reading >= 0),
              ADD CONSTRAINT ck_meter_readings_meter_factor_snapshot CHECK (meter_factor_snapshot > 0),
              ADD CONSTRAINT ck_meter_readings_consumption CHECK (consumption >= 0),
              -- A billed occupancy is always reached through its meter assignment.
              ADD CONSTRAINT ck_meter_readings_assignment_chain
                CHECK (meter_occupancy_assignment_id IS NULL OR meter_assignment_id IS NOT NULL);

            -- One reading per period and billed occupancy; one per period and meter otherwise.
            CREATE UNIQUE INDEX uq_meter_reading_period_occupancy
              ON meter_readings(reading_period_id, meter_occupancy_assignment_id)
              WHERE meter_occupancy_assignment_id IS NOT NULL;
            CREATE UNIQUE INDEX uq_meter_reading_period_unassigned_meter
              ON meter_readings(reading_period_id, meter_id)
              WHERE meter_occupancy_assignment_id IS NULL;
            CREATE INDEX idx_readings_submitted_brin ON meter_readings USING brin(submitted_at);

            ALTER TABLE reading_approvals
              ADD CONSTRAINT ck_reading_approvals_decision
                CHECK (decision IN ('approved','rejected','correction_requested'));
            SQL);

        // Lifecycle policies exactly as design V7.7; transitions as V7.9.
        LifecycleSchema::attach('reading_periods', module: 'metering', class: RecordClass::Workflow,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true, defaultStatus: 'open');
        LifecycleSchema::attach('meter_readings', module: 'metering', class: RecordClass::History,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true, defaultStatus: 'submitted');
        LifecycleSchema::attach('meter_reading_revisions', module: 'metering', class: RecordClass::History, softDelete: SoftDeletePolicy::Protected);
        LifecycleSchema::attach('reading_approvals', module: 'metering', class: RecordClass::History, softDelete: SoftDeletePolicy::Protected);

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('reading_periods', 'open',       'Open',       'info',    NULL,  true,  false, 10, '{"bn":{"label":"খোলা"}}'),
                   ('reading_periods', 'collecting', 'Collecting', 'info',    NULL,  false, false, 20, '{"bn":{"label":"সংগ্রহ চলছে"}}'),
                   ('reading_periods', 'review',     'In review',  'warning', NULL,  false, false, 30, '{"bn":{"label":"পর্যালোচনাধীন"}}'),
                   ('reading_periods', 'approved',   'Approved',   'success', NULL,  false, false, 40, '{"bn":{"label":"অনুমোদিত"}}'),
                   ('reading_periods', 'locked',     'Locked',     'muted',   NULL,  false, true,  50, '{"bn":{"label":"লক করা"}}'),
                   ('reading_periods', 'cancelled',  'Cancelled',  'muted',   false, false, true,  60, '{"bn":{"label":"বাতিল"}}'),
                   ('meter_readings', 'draft',                'Draft',                'neutral', NULL, true,  false, 10, '{"bn":{"label":"খসড়া"}}'),
                   ('meter_readings', 'submitted',            'Submitted',            'info',    NULL, true,  false, 20, '{"bn":{"label":"জমা দেওয়া হয়েছে"}}'),
                   ('meter_readings', 'correction_requested', 'Correction requested', 'warning', NULL, false, false, 30, '{"bn":{"label":"সংশোধন অনুরোধকৃত"}}'),
                   ('meter_readings', 'approved',             'Approved',             'success', NULL, false, false, 40, '{"bn":{"label":"অনুমোদিত"}}'),
                   ('meter_readings', 'rejected',             'Rejected',             'danger',  NULL, false, true,  50, '{"bn":{"label":"প্রত্যাখ্যাত"}}'),
                   ('meter_readings', 'superseded',           'Superseded',           'muted',   NULL, false, true,  60, '{"bn":{"label":"প্রতিস্থাপিত সংস্করণ"}}');

            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('reading_periods', 'open',       'collecting', NULL,                     false),
                   ('reading_periods', 'collecting', 'review',     NULL,                     false),
                   ('reading_periods', 'review',     'collecting', NULL,                     true),
                   ('reading_periods', 'review',     'approved',   'reading_period.approve', false),
                   ('reading_periods', 'approved',   'locked',     'reading_period.lock',    false),
                   ('reading_periods', 'open',       'cancelled',  'reading_period.cancel',  true),
                   ('reading_periods', 'collecting', 'cancelled',  'reading_period.cancel',  true),
                   ('meter_readings', 'draft',                'submitted',            NULL,                    false),
                   ('meter_readings', 'submitted',            'approved',             'meter_reading.approve', false),
                   ('meter_readings', 'submitted',            'rejected',             'meter_reading.approve', true),
                   ('meter_readings', 'submitted',            'correction_requested', 'meter_reading.approve', true),
                   ('meter_readings', 'correction_requested', 'submitted',            NULL,                    false),
                   ('meter_readings', 'approved',             'superseded',           'meter_reading.revise',  true);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_transitions WHERE entity_name IN ('reading_periods', 'meter_readings');
            DELETE FROM status_definitions WHERE entity_name IN ('reading_periods', 'meter_readings');
            DELETE FROM lifecycle_entities
              WHERE entity_name IN ('reading_periods', 'meter_readings', 'meter_reading_revisions', 'reading_approvals');
            SQL);

        Schema::dropIfExists('reading_approvals');
        Schema::dropIfExists('meter_reading_revisions');
        Schema::dropIfExists('meter_readings');
        Schema::dropIfExists('reading_periods');
    }
};
