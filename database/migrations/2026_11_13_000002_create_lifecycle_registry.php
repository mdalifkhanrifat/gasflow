<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration group 1b: the lifecycle registry (data that drives the generic
 * lifecycle triggers) and the generic "*" status set.
 *
 * Design source: gasflow-design-v7/02-postgresql-schema.sql V7.1, V7.7 ("*"),
 * V7.8 (generic statuses), V7.10 (translations). See 08-record-lifecycle-standard.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- One row per lifecycle table: its delete policy, transitions and logging.
            CREATE TABLE lifecycle_entities (
              entity_name VARCHAR(63) PRIMARY KEY,               -- table name, or '*' for the generic fallback
              module VARCHAR(40) NOT NULL,
              record_class VARCHAR(24) NOT NULL
                CHECK (record_class IN ('generic','master','configuration','pivot','workflow','history','financial','log')),
              soft_delete_policy VARCHAR(16) NOT NULL DEFAULT 'allowed'
                CHECK (soft_delete_policy IN ('allowed','protected')),
              hard_delete_policy VARCHAR(16) NOT NULL DEFAULT 'forbidden'
                CHECK (hard_delete_policy IN ('allowed','forbidden')),
              enforce_transitions BOOLEAN NOT NULL DEFAULT FALSE,
              log_lifecycle_changes BOOLEAN NOT NULL DEFAULT TRUE,
              override_allowed BOOLEAN NOT NULL DEFAULT TRUE,     -- FALSE = not even the maintenance override may change rows
              log_data_changes BOOLEAN NOT NULL DEFAULT TRUE,     -- write every insert/update to data_change_logs
              redacted_columns TEXT[] NOT NULL DEFAULT '{}',      -- never copied into logs (passwords, secrets, tokens)
              default_status VARCHAR(32) NOT NULL DEFAULT 'active',
              is_system BOOLEAN NOT NULL DEFAULT TRUE,
              description TEXT,
              created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE status_definitions (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              entity_name VARCHAR(63) NOT NULL REFERENCES lifecycle_entities(entity_name) ON UPDATE CASCADE,
              code VARCHAR(32) NOT NULL CHECK (code ~ '^[a-z][a-z0-9_]*$'),
              label VARCHAR(80) NOT NULL,
              tone VARCHAR(16) NOT NULL DEFAULT 'neutral'
                CHECK (tone IN ('neutral','info','success','warning','danger','muted')),
              implies_active BOOLEAN,                             -- NULL = do not touch is_active
              is_initial BOOLEAN NOT NULL DEFAULT FALSE,          -- allowed on INSERT when transitions are enforced
              is_terminal BOOLEAN NOT NULL DEFAULT FALSE,
              sort_order SMALLINT NOT NULL DEFAULT 0,
              is_system BOOLEAN NOT NULL DEFAULT TRUE,
              is_active BOOLEAN NOT NULL DEFAULT TRUE,
              description TEXT,
              created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
              translations JSONB NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(translations) = 'object'),
              UNIQUE (entity_name, code)
            );

            CREATE TABLE status_transitions (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              entity_name VARCHAR(63) NOT NULL,
              from_code VARCHAR(32) NOT NULL,
              to_code VARCHAR(32) NOT NULL,
              permission_code VARCHAR(120),                       -- optional Laravel permission checked by the app layer
              requires_reason BOOLEAN NOT NULL DEFAULT FALSE,
              is_system BOOLEAN NOT NULL DEFAULT TRUE,
              is_active BOOLEAN NOT NULL DEFAULT TRUE,
              created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
              UNIQUE (entity_name, from_code, to_code),
              CHECK (from_code <> to_code),
              FOREIGN KEY (entity_name, from_code) REFERENCES status_definitions(entity_name, code),
              FOREIGN KEY (entity_name, to_code) REFERENCES status_definitions(entity_name, code)
            );

            -- Generic fallback status set for tables without their own statuses.
            INSERT INTO lifecycle_entities(entity_name, module, record_class, description)
            VALUES ('*', 'shared', 'generic', 'Fallback status set for tables without their own statuses');

            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES
              ('*', 'active',   'Active',   'success', true,  true,  false, 10, '{"bn":{"label":"সক্রিয়"}}'),
              ('*', 'inactive', 'Inactive', 'muted',   false, true,  false, 20, '{"bn":{"label":"নিষ্ক্রিয়"}}'),
              ('*', 'archived', 'Archived', 'muted',   false, false, true,  30, '{"bn":{"label":"আর্কাইভকৃত"}}');

            -- The registry decides delete protection, so the runtime roles may only read it.
            -- Changes go through migrations (owner role).
            REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON lifecycle_entities FROM gasflow_app, gasflow_maintenance;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS status_transitions;
            DROP TABLE IF EXISTS status_definitions;
            DROP TABLE IF EXISTS lifecycle_entities;
            SQL);
    }
};
