<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 14, part 2: reliable provider events (design 02 §19,
 * 01 "Webhook flow" / "Outbox flow", V7.6, V7.7, V7.8, V7.13).
 *
 * - webhook_inbox: every provider event as received, once per provider and
 *   provider event id, for one organization; processed asynchronously
 *   (received → processing → processed / failed / ignored).
 * - integration_outbox: events written in the same transaction as the change
 *   they announce, delivered by a worker after commit (pending → published /
 *   failed / dead letter); one row per event uuid.
 * - integration_delivery_attempts: each try to deliver an outbox event to a
 *   provider (succeeded / failed / retrying).
 *
 * All three are protected logs kept out of the data change trail (V7.13).
 * Statuses only in status_definitions (V7.6), transitions not enforced (V7.7).
 * Row-level security comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_inbox', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->string('provider_code', 60);
            $table->string('provider_event_id', 190);
            $table->string('event_type', 120);
            $table->jsonb('payload');
            $table->timestampTz('signature_verified_at')->nullable();
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();
            $table->string('status', 20)->default('received');
            $table->smallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('organization_id')->constrained();
            $table->unique(['provider_code', 'provider_event_id']);
            $table->foreign('provider_code')->references('code')->on('integration_providers');
        });

        Schema::create('integration_outbox', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->uuid('event_uuid')->unique();
            $table->string('aggregate_type', 100);
            $table->bigInteger('aggregate_id');
            $table->string('event_type', 120);
            $table->jsonb('payload');
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('published_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreignId('organization_id')->nullable()->constrained();
        });

        Schema::create('integration_delivery_attempts', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('outbox_id')->constrained('integration_outbox');
            $table->string('provider_code', 60);
            $table->string('status', 20);
            $table->string('response_code', 40)->nullable();
            $table->text('response_excerpt')->nullable();
            $table->timestampTz('attempted_at')->useCurrent();
            $table->foreign('provider_code')->references('code')->on('integration_providers');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE webhook_inbox ADD CONSTRAINT webhook_inbox_attempts_check CHECK (attempts >= 0);
            -- The processor picks events still to do (new or failed) in arrival order.
            CREATE INDEX idx_webhook_inbox_pending ON webhook_inbox(received_at)
              WHERE status IN ('received','failed');

            ALTER TABLE integration_outbox ADD CONSTRAINT integration_outbox_attempts_check CHECK (attempts >= 0);
            -- The worker picks unpublished events that are due, oldest first.
            CREATE INDEX idx_integration_outbox_pending ON integration_outbox(available_at, id)
              WHERE published_at IS NULL;
            SQL);

        // Lifecycle policies exactly as design V7.7; statuses as V7.8.
        LifecycleSchema::attach('webhook_inbox', module: 'integrations', class: RecordClass::Log,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'received');
        LifecycleSchema::attach('integration_outbox', module: 'integrations', class: RecordClass::Log,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'pending');
        LifecycleSchema::attach('integration_delivery_attempts', module: 'integrations', class: RecordClass::Log,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'failed');

        DB::unprepared(<<<'SQL'
            -- Logs stay out of the data change trail (V7.13).
            UPDATE lifecycle_entities SET log_data_changes = FALSE
              WHERE entity_name IN ('webhook_inbox', 'integration_outbox', 'integration_delivery_attempts');

            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('webhook_inbox', 'received',   'Received',   'info',    NULL,   true,  false, 10, '{"bn":{"label":"প্রাপ্ত"}}'),
                   ('webhook_inbox', 'processing', 'Processing', 'info',    NULL,  false, false, 20, '{"bn":{"label":"প্রক্রিয়াধীন"}}'),
                   ('webhook_inbox', 'processed',  'Processed',  'success', NULL,  false, true,  30, '{"bn":{"label":"প্রক্রিয়াকৃত"}}'),
                   ('webhook_inbox', 'failed',     'Failed',     'danger',  NULL,  false, false, 40, '{"bn":{"label":"ব্যর্থ"}}'),
                   ('webhook_inbox', 'ignored',    'Ignored',    'muted',   NULL,  false, true,  50, '{"bn":{"label":"উপেক্ষিত"}}'),
                   ('integration_outbox', 'pending',     'Pending',     'warning', NULL,   true,  false, 10, '{"bn":{"label":"অপেক্ষমাণ"}}'),
                   ('integration_outbox', 'published',   'Published',   'success', NULL,  false, true,  20, '{"bn":{"label":"প্রকাশিত"}}'),
                   ('integration_outbox', 'failed',      'Failed',      'danger',  NULL,  false, false, 30, '{"bn":{"label":"ব্যর্থ"}}'),
                   ('integration_outbox', 'dead_letter', 'Dead letter', 'danger',  NULL,  false, true,  40, '{"bn":{"label":"ব্যর্থ (চূড়ান্ত)"}}'),
                   ('integration_delivery_attempts', 'succeeded', 'Succeeded', 'success', NULL,  true,  true,  10, '{"bn":{"label":"সফল"}}'),
                   ('integration_delivery_attempts', 'failed',    'Failed',    'danger',  NULL,  true,  true,  20, '{"bn":{"label":"ব্যর্থ"}}'),
                   ('integration_delivery_attempts', 'retrying',  'Retrying',  'warning', NULL,  true,  false, 30, '{"bn":{"label":"পুনরায় চেষ্টা"}}');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_definitions
              WHERE entity_name IN ('webhook_inbox', 'integration_outbox', 'integration_delivery_attempts');
            DELETE FROM lifecycle_entities
              WHERE entity_name IN ('webhook_inbox', 'integration_outbox', 'integration_delivery_attempts');
            SQL);

        Schema::dropIfExists('integration_delivery_attempts');
        Schema::dropIfExists('integration_outbox');
        Schema::dropIfExists('webhook_inbox');
    }
};
