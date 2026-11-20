<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A provider event is kept as received and processed only once verified
 * (owner decision D51, design V7.24, group 14 questions 8 and 9):
 *
 * - provider, event id, type, payload, organization and received time are
 *   frozen from the first write; the signature time is written once.
 * - processing and processed need a verified signature; processed_at ⇔
 *   processed or ignored; failed needs last_error; attempts never go down
 *   (attempts_never_decrease(), shared with the outbox).
 * - statuses: received → processing / ignored; processing → processed /
 *   failed; failed → processing.
 * - organization_has_provider_gateway(organization, provider): the
 *   organization has a payment or SMS gateway of the provider, its own or
 *   one of a building it owns (D50). An event's organization must have one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION organization_has_provider_gateway(p_organization_id BIGINT, p_provider TEXT)
            RETURNS BOOLEAN LANGUAGE sql STABLE AS $$
              SELECT EXISTS (
                SELECT 1 FROM (
                  SELECT organization_id, building_id FROM organization_payment_gateways WHERE provider = p_provider AND NOT is_deleted
                  UNION ALL
                  SELECT organization_id, building_id FROM organization_sms_gateways WHERE provider = p_provider AND NOT is_deleted
                ) g
                LEFT JOIN buildings b ON b.id = g.building_id
                WHERE g.organization_id = p_organization_id OR b.tenant_organization_id = p_organization_id)
            $$;

            CREATE OR REPLACE FUNCTION attempts_never_decrease() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.attempts < OLD.attempts AND NOT lifecycle_override_active() THEN
                RAISE EXCEPTION '% %: attempts cannot go down (% -> %)', TG_TABLE_NAME, OLD.id, OLD.attempts, NEW.attempts;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE FUNCTION validate_webhook_inbox_provider() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              -- A missing organization or unknown provider is reported by its NOT NULL or foreign key.
              IF NEW.organization_id IS NULL OR NOT EXISTS (SELECT 1 FROM integration_providers WHERE code = NEW.provider_code) THEN
                RETURN NEW;
              END IF;
              IF NOT organization_has_provider_gateway(NEW.organization_id, NEW.provider_code) THEN
                RAISE EXCEPTION 'Organization % has no % gateway for this webhook', NEW.organization_id, NEW.provider_code;
              END IF;
              RETURN NEW;
            END; $$;

            ALTER TABLE webhook_inbox
              ADD CONSTRAINT ck_webhook_inbox_verified CHECK (status NOT IN ('processing','processed') OR signature_verified_at IS NOT NULL),
              ADD CONSTRAINT ck_webhook_inbox_processed_at CHECK ((status IN ('processed','ignored')) = (processed_at IS NOT NULL)),
              ADD CONSTRAINT ck_webhook_inbox_failed_error CHECK (status <> 'failed' OR last_error IS NOT NULL);

            CREATE OR REPLACE TRIGGER trg_webhook_inbox_provider BEFORE INSERT ON webhook_inbox
            FOR EACH ROW EXECUTE FUNCTION validate_webhook_inbox_provider();
            CREATE OR REPLACE TRIGGER trg_webhook_inbox_attempts BEFORE UPDATE OF attempts ON webhook_inbox
            FOR EACH ROW EXECUTE FUNCTION attempts_never_decrease();

            UPDATE lifecycle_entities SET enforce_transitions = true WHERE entity_name = 'webhook_inbox';
            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('webhook_inbox', 'received',   'processing', NULL, false),
                   ('webhook_inbox', 'received',   'ignored',    NULL, false),
                   ('webhook_inbox', 'processing', 'processed',  NULL, false),
                   ('webhook_inbox', 'processing', 'failed',     NULL, false),
                   ('webhook_inbox', 'failed',     'processing', NULL, false);
            SQL);

        LifecycleSchema::freezeColumns('webhook_inbox',
            ['provider_code', 'provider_event_id', 'event_type', 'payload', 'organization_id', 'received_at'],
            editableStatuses: [], name: 'evidence');
        LifecycleSchema::writeOnce('webhook_inbox', ['signature_verified_at'], name: 'signature');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_webhook_inbox_freeze_evidence ON webhook_inbox;
            DROP TRIGGER IF EXISTS trg_webhook_inbox_write_once_signature ON webhook_inbox;
            DELETE FROM status_transitions WHERE entity_name = 'webhook_inbox';
            UPDATE lifecycle_entities SET enforce_transitions = false WHERE entity_name = 'webhook_inbox';
            DROP TRIGGER IF EXISTS trg_webhook_inbox_attempts ON webhook_inbox;
            DROP TRIGGER IF EXISTS trg_webhook_inbox_provider ON webhook_inbox;
            ALTER TABLE webhook_inbox
              DROP CONSTRAINT ck_webhook_inbox_verified,
              DROP CONSTRAINT ck_webhook_inbox_processed_at,
              DROP CONSTRAINT ck_webhook_inbox_failed_error;
            DROP FUNCTION IF EXISTS validate_webhook_inbox_provider();
            DROP FUNCTION IF EXISTS attempts_never_decrease();
            DROP FUNCTION IF EXISTS organization_has_provider_gateway(BIGINT, TEXT);
            SQL);
    }
};
