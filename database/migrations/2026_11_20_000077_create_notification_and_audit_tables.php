<?php

use App\Enums\Lifecycle\HardDeletePolicy;
use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 14, part 1: notifications, the audit log and idempotency
 * keys (design 02 §9, §18, V7.7, V7.8, V7.10, V7.12, V7.13).
 *
 * - notification_templates: localised message templates per event, channel,
 *   locale and version; platform defaults have no organization, tenants may
 *   override them (one per scope, event, channel, locale and version).
 * - notifications: one message to a user or customer through one channel,
 *   for exactly one organization or one building, optionally from a template.
 * - audit_logs: business audit entries (who did what to which record); the
 *   maintenance override may not change them (V7.12).
 * - idempotency_keys: the stored response of a request key per organization
 *   and operation, so a retried request is answered, not repeated.
 *
 * Templates are configuration; the other three are protected logs, kept out of
 * the data change trail (V7.13). Statuses as V7.8, without enforced
 * transitions (V7.7). Row-level security comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('organization_id')->nullable()->constrained();
            $table->string('event_code', 80);
            $table->string('channel', 16);
            $table->string('locale', 8);
            $table->string('subject', 180)->nullable();
            $table->text('body');
            $table->integer('version_no')->default(1);
            $table->foreign('locale')->references('code')->on('supported_locales');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('organization_id')->nullable()->constrained();
            $table->foreignId('building_id')->nullable()->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->string('channel', 16);
            $table->string('event_code', 80);
            $table->string('subject', 180)->nullable();
            $table->text('message');
            $table->string('status', 20)->default('queued');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->string('provider_reference', 180)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->string('locale', 8)->nullable();
            $table->foreignId('notification_template_id')->nullable()->constrained();
            $table->foreign('locale')->references('code')->on('supported_locales');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('organization_id')->nullable()->constrained();
            $table->foreignId('building_id')->nullable()->constrained();
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->string('action', 100);
            $table->string('subject_type', 120);
            $table->bigInteger('subject_id');
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id'], 'idx_audit_subject');
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('organization_id')->nullable()->constrained();
            $table->uuid('key');
            $table->string('operation', 100);
            $table->char('request_hash', 64);
            $table->smallInteger('response_code')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'key', 'operation']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE notification_templates
              ADD CONSTRAINT notification_templates_channel_check CHECK (channel IN ('in_app','sms','email','push')),
              ADD CONSTRAINT notification_templates_version_no_check CHECK (version_no > 0);
            -- One template per scope (platform = no organization), event, channel, locale and version.
            CREATE UNIQUE INDEX uq_notification_templates_scope
              ON notification_templates(COALESCE(organization_id, 0), event_code, channel, locale, version_no);

            ALTER TABLE notifications
              ADD CONSTRAINT notifications_channel_check CHECK (channel IN ('in_app','sms','email','push')),
              -- A notification belongs to one organization or one building, never both.
              ADD CONSTRAINT ck_notifications_scope_xor
                CHECK ((organization_id IS NOT NULL)::int + (building_id IS NOT NULL)::int = 1);
            -- The sender picks queued notifications by their scheduled time.
            CREATE INDEX idx_notifications_queued ON notifications(scheduled_at) WHERE status = 'queued';

            CREATE INDEX idx_audit_created_brin ON audit_logs USING brin(created_at);
            SQL);

        // Lifecycle policies exactly as design V7.7 / V7.10; statuses as V7.8.
        LifecycleSchema::attach('notification_templates', module: 'notifications', class: RecordClass::Configuration);
        LifecycleSchema::attach('notifications', module: 'notifications', class: RecordClass::Log,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'queued');
        LifecycleSchema::attach('audit_logs', module: 'audit', class: RecordClass::Log,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'recorded');
        LifecycleSchema::attach('idempotency_keys', module: 'shared', class: RecordClass::Log,
            softDelete: SoftDeletePolicy::Protected, hardDelete: HardDeletePolicy::Allowed);

        DB::unprepared(<<<'SQL'
            -- Logs stay out of the data change trail (V7.13); the audit trail is
            -- beyond even the maintenance override (V7.12).
            UPDATE lifecycle_entities SET log_data_changes = FALSE
              WHERE entity_name IN ('notifications', 'audit_logs', 'idempotency_keys');
            UPDATE lifecycle_entities SET override_allowed = FALSE WHERE entity_name = 'audit_logs';

            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('notifications', 'queued',    'Queued',    'warning', NULL,   true,  false, 10, '{"bn":{"label":"সারিতে"}}'),
                   ('notifications', 'sending',   'Sending',   'info',    NULL,  false, false, 20, '{"bn":{"label":"পাঠানো হচ্ছে"}}'),
                   ('notifications', 'sent',      'Sent',      'success', NULL,  false, false, 30, '{"bn":{"label":"পাঠানো হয়েছে"}}'),
                   ('notifications', 'delivered', 'Delivered', 'success', NULL,  false, true,  40, '{"bn":{"label":"পৌঁছেছে"}}'),
                   ('notifications', 'failed',    'Failed',    'danger',  NULL,  false, true,  50, '{"bn":{"label":"ব্যর্থ"}}'),
                   ('notifications', 'cancelled', 'Cancelled', 'muted',   NULL,  false, true,  60, '{"bn":{"label":"বাতিল"}}'),
                   ('audit_logs',    'recorded',  'Recorded',  'neutral', NULL,   true,  true,  10, '{"bn":{"label":"লিপিবদ্ধ"}}');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_definitions WHERE entity_name IN ('notifications', 'audit_logs');
            DELETE FROM lifecycle_entities
              WHERE entity_name IN ('notification_templates', 'notifications', 'audit_logs', 'idempotency_keys');
            SQL);

        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_templates');
    }
};
