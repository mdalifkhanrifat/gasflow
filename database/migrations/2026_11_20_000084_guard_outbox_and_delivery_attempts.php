<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Outbox events and their delivery attempts stay as they happened (owner
 * decision D51, design V7.24, group 14 questions 10 and 11):
 *
 * - an event's uuid, aggregate, type, payload, organization and time are
 *   frozen from the first write; published_at ⇔ published; it is not
 *   available before it occurred; attempts never go down.
 * - statuses: pending → published / failed / dead letter; failed → pending
 *   / dead letter; published and dead letter are final.
 * - an attempt is frozen from the first write (its status too: transitions
 *   are enforced and there are none); no new attempt once its event is
 *   published or dead letter; it is not made before its event occurred.
 * - at COMMIT: an event's attempts counter equals its attempt rows, and a
 *   published event has exactly one succeeded attempt (others none).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE integration_outbox
              ADD CONSTRAINT ck_integration_outbox_published_at CHECK ((status = 'published') = (published_at IS NOT NULL)),
              ADD CONSTRAINT ck_integration_outbox_available_at CHECK (available_at >= occurred_at);

            CREATE OR REPLACE TRIGGER trg_integration_outbox_attempts BEFORE UPDATE OF attempts ON integration_outbox
            FOR EACH ROW EXECUTE FUNCTION attempts_never_decrease();

            UPDATE lifecycle_entities SET enforce_transitions = true
              WHERE entity_name IN ('integration_outbox', 'integration_delivery_attempts');
            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('integration_outbox', 'pending', 'published',   NULL, false),
                   ('integration_outbox', 'pending', 'failed',      NULL, false),
                   ('integration_outbox', 'pending', 'dead_letter', NULL, false),
                   ('integration_outbox', 'failed',  'pending',     NULL, false),
                   ('integration_outbox', 'failed',  'dead_letter', NULL, false);

            CREATE OR REPLACE FUNCTION validate_delivery_attempt_time() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE event_time TIMESTAMPTZ;
            BEGIN
              SELECT occurred_at INTO event_time FROM integration_outbox WHERE id = NEW.outbox_id;
              IF NEW.attempted_at < event_time THEN
                RAISE EXCEPTION 'Delivery attempt at % is before outbox event % occurred (%)', NEW.attempted_at, NEW.outbox_id, event_time;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_integration_delivery_attempts_time BEFORE INSERT ON integration_delivery_attempts
            FOR EACH ROW EXECUTE FUNCTION validate_delivery_attempt_time();

            -- At COMMIT: the counter and the succeeded attempt agree with the attempt rows.
            CREATE OR REPLACE FUNCTION assert_outbox_delivery(p_outbox_id BIGINT) RETURNS VOID LANGUAGE plpgsql AS $$
            DECLARE event RECORD; tried INTEGER; succeeded INTEGER;
            BEGIN
              SELECT status, attempts INTO event FROM integration_outbox WHERE id = p_outbox_id;
              SELECT count(*), count(*) FILTER (WHERE status = 'succeeded') INTO tried, succeeded
                FROM integration_delivery_attempts WHERE outbox_id = p_outbox_id;
              IF event.attempts <> tried THEN
                RAISE EXCEPTION 'Outbox event % counts % attempts but has % attempt rows', p_outbox_id, event.attempts, tried;
              END IF;
              IF succeeded <> (event.status = 'published')::int THEN
                RAISE EXCEPTION 'Outbox event % is % with % succeeded attempts; a published event has exactly one, others none',
                  p_outbox_id, event.status, succeeded;
              END IF;
            END; $$;

            CREATE OR REPLACE FUNCTION check_outbox_delivery() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_TABLE_NAME = 'integration_outbox' THEN
                PERFORM assert_outbox_delivery(NEW.id);
              ELSE
                PERFORM assert_outbox_delivery(NEW.outbox_id);
              END IF;
              RETURN NULL;
            END; $$;

            CREATE CONSTRAINT TRIGGER trg_integration_outbox_delivery
            AFTER INSERT OR UPDATE OF status, attempts ON integration_outbox
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION check_outbox_delivery();
            CREATE CONSTRAINT TRIGGER trg_integration_delivery_attempts_delivery
            AFTER INSERT ON integration_delivery_attempts
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION check_outbox_delivery();
            SQL);

        LifecycleSchema::freezeColumns('integration_outbox',
            ['event_uuid', 'aggregate_type', 'aggregate_id', 'event_type', 'payload', 'organization_id', 'occurred_at'],
            editableStatuses: [], name: 'event');
        LifecycleSchema::freezeColumns('integration_delivery_attempts',
            ['outbox_id', 'provider_code', 'response_code', 'response_excerpt', 'attempted_at'],
            editableStatuses: [], name: 'attempt');
        LifecycleSchema::refuseNewChildrenWhenParentClosed('integration_delivery_attempts', parent: 'integration_outbox',
            foreignKey: 'outbox_id', closedStatuses: ['published', 'dead_letter']);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_integration_delivery_attempts_closed_parent_insert ON integration_delivery_attempts;
            DROP TRIGGER IF EXISTS trg_integration_delivery_attempts_freeze_attempt ON integration_delivery_attempts;
            DROP TRIGGER IF EXISTS trg_integration_outbox_freeze_event ON integration_outbox;
            DROP TRIGGER IF EXISTS trg_integration_delivery_attempts_delivery ON integration_delivery_attempts;
            DROP TRIGGER IF EXISTS trg_integration_outbox_delivery ON integration_outbox;
            DROP FUNCTION IF EXISTS check_outbox_delivery();
            DROP FUNCTION IF EXISTS assert_outbox_delivery(BIGINT);
            DROP TRIGGER IF EXISTS trg_integration_delivery_attempts_time ON integration_delivery_attempts;
            DROP FUNCTION IF EXISTS validate_delivery_attempt_time();
            DELETE FROM status_transitions WHERE entity_name = 'integration_outbox';
            UPDATE lifecycle_entities SET enforce_transitions = false
              WHERE entity_name IN ('integration_outbox', 'integration_delivery_attempts');
            DROP TRIGGER IF EXISTS trg_integration_outbox_attempts ON integration_outbox;
            ALTER TABLE integration_outbox
              DROP CONSTRAINT ck_integration_outbox_published_at,
              DROP CONSTRAINT ck_integration_outbox_available_at;
            SQL);
    }
};
