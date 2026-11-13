<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration group 1a: extensions, database roles check and request context.
 *
 * Design source: gasflow-design-v7/02-postgresql-schema.sql (extensions,
 * app_current_organization_id, V7.1 app_current_user_id, V7.2 app_context).
 */
return new class extends Migration
{
    /**
     * Roles created by docker/postgres/init. Migrations run as the owner role,
     * which may not create roles, so a missing role is a setup error.
     *
     * @var list<string>
     */
    private array $requiredRoles = ['gasflow_app', 'gasflow_maintenance'];

    public function up(): void
    {
        $existing = collect(DB::select('select rolname from pg_roles'))->pluck('rolname');
        $missing = array_values(array_diff($this->requiredRoles, $existing->all()));

        if ($missing !== []) {
            throw new RuntimeException(
                'Missing database roles: '.implode(', ', $missing).'. Create them with docker/postgres/init/01-gasflow-roles.sh.',
            );
        }

        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS pgcrypto;
            CREATE EXTENSION IF NOT EXISTS btree_gist;

            -- Request-scoped context written by Laravel with set_config(..., true).
            -- Never trust a client-supplied organization id as authorization input.
            CREATE OR REPLACE FUNCTION app_current_organization_id()
            RETURNS BIGINT LANGUAGE sql STABLE PARALLEL SAFE AS $$
              SELECT NULLIF(current_setting('app.organization_id', true), '')::BIGINT
            $$;

            CREATE OR REPLACE FUNCTION app_current_user_id()
            RETURNS BIGINT LANGUAGE sql STABLE PARALLEL SAFE AS $$
              SELECT NULLIF(current_setting('app.user_id', true), '')::BIGINT
            $$;

            -- Any other request value: app.session_id, app.device_id, app.request_id,
            -- app.client_type, app.device_kind, app.ip, app.ip_country, app.ip_city,
            -- app.geo_lat, app.geo_lon, app.geo_accuracy_m, app.geo_source,
            -- app.lifecycle_reason, app.maintenance_override.
            CREATE OR REPLACE FUNCTION app_context(p_key TEXT)
            RETURNS TEXT LANGUAGE sql STABLE PARALLEL SAFE AS $$
              SELECT NULLIF(current_setting('app.' || p_key, true), '')
            $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS app_context(TEXT);
            DROP FUNCTION IF EXISTS app_current_user_id();
            DROP FUNCTION IF EXISTS app_current_organization_id();
            SQL);
    }
};
