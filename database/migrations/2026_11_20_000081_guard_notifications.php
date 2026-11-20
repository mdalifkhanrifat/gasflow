<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Templates and notifications keep to what was sent and to whom (owner
 * decision D51, design V7.24, group 14 questions 3 to 6):
 *
 * - templates: event, channel, locale, scope, subject and body frozen from
 *   the first write (a change is a new version_no); email needs a subject.
 * - notifications have exactly one recipient (user or customer); in-app and
 *   push need a user. The customer's tenant is the organization or has
 *   access to the building; the user is an active member of the
 *   organization or may access the building (user_can_access_building()).
 * - a notification matches its template (composite key on event, channel
 *   and locale), and the template is a platform one or of the
 *   organization (for a building: of an organization with access).
 * - statuses: queued → sending → sent → delivered; queued / sending →
 *   failed / cancelled; failed → queued (a retry, so failed is no longer
 *   final). sent_at ⇔ sent or delivered; failed needs an error message.
 *   Scope, recipient, channel, template and text frozen after queued.
 */
return new class extends Migration
{
    private const TEMPLATE_CONTENT = ['organization_id', 'event_code', 'channel', 'locale', 'subject', 'body', 'version_no'];

    private const MESSAGE = ['organization_id', 'building_id', 'user_id', 'customer_id', 'channel', 'event_code',
        'notification_template_id', 'locale', 'subject', 'message'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE notification_templates
              ADD CONSTRAINT ck_notification_templates_email_subject CHECK (channel <> 'email' OR subject IS NOT NULL),
              ADD CONSTRAINT uq_notification_templates_content UNIQUE (id, event_code, channel, locale);

            ALTER TABLE notifications
              ADD CONSTRAINT ck_notifications_one_recipient
                CHECK ((user_id IS NOT NULL)::int + (customer_id IS NOT NULL)::int = 1),
              ADD CONSTRAINT ck_notifications_user_channel CHECK (channel NOT IN ('in_app','push') OR user_id IS NOT NULL),
              ADD CONSTRAINT ck_notifications_template_locale CHECK (notification_template_id IS NULL OR locale IS NOT NULL),
              ADD CONSTRAINT fk_notifications_template_content FOREIGN KEY (notification_template_id, event_code, channel, locale)
                REFERENCES notification_templates(id, event_code, channel, locale),
              ADD CONSTRAINT ck_notifications_sent_at CHECK ((status IN ('sent','delivered')) = (sent_at IS NOT NULL)),
              ADD CONSTRAINT ck_notifications_failed_error CHECK (status <> 'failed' OR error_message IS NOT NULL);

            CREATE OR REPLACE FUNCTION validate_notification_scope() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE customer_tenant BIGINT; template_organization BIGINT;
            BEGIN
              -- A missing or double scope is reported by ck_notifications_scope_xor.
              IF (NEW.organization_id IS NULL) = (NEW.building_id IS NULL) THEN
                RETURN NEW;
              END IF;
              IF NEW.customer_id IS NOT NULL THEN
                SELECT tenant_organization_id INTO customer_tenant FROM customers WHERE id = NEW.customer_id;
                IF customer_tenant IS NOT NULL AND NOT (
                     customer_tenant IS NOT DISTINCT FROM NEW.organization_id
                     OR (NEW.building_id IS NOT NULL AND organization_can_access_building(customer_tenant, NEW.building_id))) THEN
                  RAISE EXCEPTION 'Customer % is not a customer of this notification''s organization or building', NEW.customer_id;
                END IF;
              END IF;
              IF NEW.user_id IS NOT NULL AND NOT (
                   (NEW.building_id IS NOT NULL AND user_can_access_building(NEW.user_id, NEW.building_id))
                   OR EXISTS (SELECT 1 FROM organization_memberships m
                              WHERE m.user_id = NEW.user_id AND m.organization_id = NEW.organization_id
                                AND m.status = 'active' AND NOT m.is_deleted)) THEN
                RAISE EXCEPTION 'User % is not a member of this notification''s organization or building', NEW.user_id;
              END IF;
              IF NEW.notification_template_id IS NOT NULL THEN
                SELECT organization_id INTO template_organization FROM notification_templates WHERE id = NEW.notification_template_id;
                IF template_organization IS NOT NULL AND NOT (
                     template_organization IS NOT DISTINCT FROM NEW.organization_id
                     OR (NEW.building_id IS NOT NULL AND organization_can_access_building(template_organization, NEW.building_id))) THEN
                  RAISE EXCEPTION 'Template % belongs to organization %, which this notification may not use',
                    NEW.notification_template_id, template_organization;
                END IF;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_notifications_scope
            BEFORE INSERT OR UPDATE OF organization_id, building_id, user_id, customer_id, notification_template_id ON notifications
            FOR EACH ROW EXECUTE FUNCTION validate_notification_scope();

            UPDATE lifecycle_entities SET enforce_transitions = true WHERE entity_name = 'notifications';
            UPDATE status_definitions SET is_terminal = false WHERE entity_name = 'notifications' AND code = 'failed';
            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('notifications', 'queued',  'sending',   NULL, false),
                   ('notifications', 'sending', 'sent',      NULL, false),
                   ('notifications', 'sent',    'delivered', NULL, false),
                   ('notifications', 'queued',  'failed',    NULL, false),
                   ('notifications', 'sending', 'failed',    NULL, false),
                   ('notifications', 'queued',  'cancelled', NULL, false),
                   ('notifications', 'sending', 'cancelled', NULL, false),
                   ('notifications', 'failed',  'queued',    NULL, false);
            SQL);

        LifecycleSchema::freezeColumns('notification_templates', self::TEMPLATE_CONTENT, editableStatuses: [], name: 'content');
        LifecycleSchema::freezeColumns('notifications', self::MESSAGE, editableStatuses: ['queued'], name: 'message');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_notification_templates_freeze_content ON notification_templates;
            DROP TRIGGER IF EXISTS trg_notifications_freeze_message ON notifications;
            DELETE FROM status_transitions WHERE entity_name = 'notifications';
            UPDATE status_definitions SET is_terminal = true WHERE entity_name = 'notifications' AND code = 'failed';
            UPDATE lifecycle_entities SET enforce_transitions = false WHERE entity_name = 'notifications';
            DROP TRIGGER IF EXISTS trg_notifications_scope ON notifications;
            DROP FUNCTION IF EXISTS validate_notification_scope();
            ALTER TABLE notifications
              DROP CONSTRAINT ck_notifications_one_recipient,
              DROP CONSTRAINT ck_notifications_user_channel,
              DROP CONSTRAINT ck_notifications_template_locale,
              DROP CONSTRAINT fk_notifications_template_content,
              DROP CONSTRAINT ck_notifications_sent_at,
              DROP CONSTRAINT ck_notifications_failed_error;
            ALTER TABLE notification_templates
              DROP CONSTRAINT ck_notification_templates_email_subject,
              DROP CONSTRAINT uq_notification_templates_content;
            SQL);
    }
};
