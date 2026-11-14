<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration group 3, part 3: devices and access sessions (design V7.10, V7.13).
 *
 * - user_devices: installed PWA today, Flutter apps later (one row per device)
 * - access_sessions: one row per login with device, client, IP place and GPS;
 *   every lifecycle row points at the session that created / last changed it,
 *   so the owner can say "you changed this, then, from this device, there"
 * - v_active_users_now / v_active_user_counts: live users by device and city,
 *   through row-level security (an organization sees only its own)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE user_devices (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              user_id BIGINT NOT NULL REFERENCES users(id),
              device_uuid UUID NOT NULL,
              client_type VARCHAR(16) NOT NULL CHECK (client_type IN ('browser','pwa','flutter')),
              platform VARCHAR(16) NOT NULL CHECK (platform IN ('web','android','ios','desktop')),
              device_kind VARCHAR(16) NOT NULL DEFAULT 'unknown'
                CHECK (device_kind IN ('desktop','mobile','tablet','unknown')),
              os_name VARCHAR(60),
              browser_name VARCHAR(60),
              device_name VARCHAR(120),
              app_version VARCHAR(40),
              locale VARCHAR(8) REFERENCES supported_locales(code),
              push_provider VARCHAR(16) CHECK (push_provider IN ('webpush','fcm','apns')),
              push_token_encrypted TEXT,             -- Laravel-encrypted FCM/APNs token or Web Push subscription
              personal_access_token_id BIGINT REFERENCES personal_access_tokens(id) ON DELETE SET NULL,
              last_ip INET,
              last_seen_at TIMESTAMPTZ,
              UNIQUE (user_id, device_uuid),
              CHECK ((push_provider IS NULL) = (push_token_encrypted IS NULL)),
              CHECK (client_type <> 'flutter' OR platform IN ('android','ios'))
            );
            CREATE INDEX idx_user_devices_push ON user_devices(user_id) WHERE push_provider IS NOT NULL;

            CREATE TABLE access_sessions (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              session_uuid UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
              user_id BIGINT NOT NULL REFERENCES users(id),
              organization_id BIGINT REFERENCES organizations(id),
              user_device_id BIGINT REFERENCES user_devices(id),
              client_type VARCHAR(16) NOT NULL
                CHECK (client_type IN ('browser','pwa','flutter','api','console','maintenance')),
              device_kind VARCHAR(16) NOT NULL DEFAULT 'unknown'
                CHECK (device_kind IN ('desktop','mobile','tablet','server','unknown')),
              os_name VARCHAR(60),
              browser_name VARCHAR(60),
              app_version VARCHAR(40),
              login_method VARCHAR(24) NOT NULL DEFAULT 'password'
                CHECK (login_method IN ('password','otp','magic_link','token','sso','maintenance')),
              ip_address INET,
              user_agent VARCHAR(500),
              ip_country CHAR(2),
              ip_city VARCHAR(120),
              geo_lat NUMERIC(9,6) CHECK (geo_lat BETWEEN -90 AND 90),
              geo_lon NUMERIC(9,6) CHECK (geo_lon BETWEEN -180 AND 180),
              geo_accuracy_m NUMERIC(10,2) CHECK (geo_accuracy_m >= 0),
              geo_source VARCHAR(8) NOT NULL DEFAULT 'none' CHECK (geo_source IN ('gps','network','ip','none')),
              geo_captured_at TIMESTAMPTZ,
              started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
              last_seen_at TIMESTAMPTZ NOT NULL DEFAULT now(),
              ended_at TIMESTAMPTZ,
              end_reason VARCHAR(16) CHECK (end_reason IN ('logout','expired','revoked','replaced')),
              CHECK ((geo_lat IS NULL) = (geo_lon IS NULL)),
              CHECK ((ended_at IS NULL) = (end_reason IS NULL)),
              CHECK (last_seen_at >= started_at)
            );
            CREATE INDEX idx_access_sessions_user ON access_sessions(user_id, started_at DESC);
            CREATE INDEX idx_access_sessions_open ON access_sessions(last_seen_at) WHERE ended_at IS NULL;
            ALTER TABLE access_sessions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE access_sessions FORCE ROW LEVEL SECURITY;
            CREATE POLICY access_sessions_scope ON access_sessions
              USING (organization_id IS NOT DISTINCT FROM app_current_organization_id()
                     OR user_id = app_current_user_id())
              WITH CHECK (organization_id IS NOT DISTINCT FROM app_current_organization_id()
                          OR user_id = app_current_user_id());
            SQL);

        LifecycleSchema::attach('user_devices', module: 'identity', class: RecordClass::Master);
        LifecycleSchema::attach('access_sessions', module: 'identity', class: RecordClass::Log, softDelete: SoftDeletePolicy::Protected);

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('user_devices', 'active',   'Active',   'success', true,  true,  false, 10, '{"bn":{"label":"সক্রিয়"}}'),
                   ('user_devices', 'inactive', 'Inactive', 'muted',   false, false, false, 20, '{"bn":{"label":"নিষ্ক্রিয়"}}'),
                   ('user_devices', 'revoked',  'Revoked',  'danger',  false, false, true,  30, '{"bn":{"label":"প্রত্যাহারকৃত"}}'),
                   ('access_sessions', 'active',  'Active',  'success', true,  true,  false, 10, '{"bn":{"label":"সক্রিয়"}}'),
                   ('access_sessions', 'ended',   'Ended',   'muted',   false, false, true,  20, '{"bn":{"label":"সমাপ্ত"}}'),
                   ('access_sessions', 'expired', 'Expired', 'muted',   false, false, true,  30, '{"bn":{"label":"মেয়াদোত্তীর্ণ"}}'),
                   ('access_sessions', 'revoked', 'Revoked', 'danger',  false, false, true,  40, '{"bn":{"label":"প্রত্যাহারকৃত"}}');

            -- logs are not change-logged themselves; secrets never enter the trail
            UPDATE lifecycle_entities SET log_data_changes = FALSE WHERE entity_name = 'access_sessions';
            UPDATE lifecycle_entities SET redacted_columns = '{push_token_encrypted}' WHERE entity_name = 'user_devices';

            -- Every lifecycle table registered so far now points its session stamps at
            -- access_sessions; tables attached later get the keys from lifecycle_attach().
            DO $$
            DECLARE e RECORD;
            BEGIN
              FOR e IN SELECT entity_name FROM lifecycle_entities WHERE entity_name <> '*' ORDER BY entity_name LOOP
                PERFORM lifecycle_ensure_fk(e.entity_name, 'created_session_id', 'access_sessions');
                PERFORM lifecycle_ensure_fk(e.entity_name, 'updated_session_id', 'access_sessions');
              END LOOP;
            END $$;

            -- Live user counts (security_invoker: callers see only their organization via RLS).
            CREATE OR REPLACE VIEW v_active_users_now WITH (security_invoker = true) AS
            SELECT s.user_id, u.name AS user_name, s.organization_id, s.client_type, s.device_kind,
                   s.os_name, s.browser_name, s.ip_address, s.ip_city, s.ip_country,
                   s.geo_lat, s.geo_lon, s.geo_source, s.started_at, s.last_seen_at
            FROM access_sessions s
            JOIN users u ON u.id = s.user_id
            WHERE s.ended_at IS NULL AND NOT s.is_deleted
              AND s.last_seen_at > now() - INTERVAL '15 minutes';

            CREATE OR REPLACE VIEW v_active_user_counts WITH (security_invoker = true) AS
            SELECT organization_id, client_type, device_kind, COALESCE(ip_city, 'unknown') AS city,
                   count(DISTINCT user_id) AS active_users, count(*) AS active_sessions
            FROM v_active_users_now
            GROUP BY GROUPING SETS ((organization_id), (organization_id, client_type),
                                    (organization_id, device_kind), (organization_id, city),
                                    (organization_id, client_type, device_kind, city));
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP VIEW IF EXISTS v_active_user_counts;
            DROP VIEW IF EXISTS v_active_users_now;
            DO $$
            DECLARE e RECORD;
            BEGIN
              FOR e IN SELECT entity_name FROM lifecycle_entities
                       WHERE entity_name NOT IN ('*', 'access_sessions') LOOP
                EXECUTE format('ALTER TABLE %I DROP CONSTRAINT IF EXISTS %I, DROP CONSTRAINT IF EXISTS %I', e.entity_name,
                               left('fk_' || e.entity_name || '_created_session_id', 63),
                               left('fk_' || e.entity_name || '_updated_session_id', 63));
              END LOOP;
            END $$;
            DELETE FROM status_definitions WHERE entity_name IN ('user_devices', 'access_sessions');
            DELETE FROM lifecycle_entities WHERE entity_name IN ('user_devices', 'access_sessions');
            DROP TABLE IF EXISTS access_sessions;
            DROP TABLE IF EXISTS user_devices;
            SQL);
    }
};
