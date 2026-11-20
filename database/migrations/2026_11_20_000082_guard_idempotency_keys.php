<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An idempotency key answers a retried request with what was stored the
 * first time (owner decision D51, design V7.24, group 14 question 7):
 *
 * - one key per organization and operation, platform keys (no
 *   organization) included (NULLS NOT DISTINCT).
 * - organization, key, operation and request hash frozen from the first
 *   write; the response is written once, code and body together.
 * - the request hash is a SHA-256 hex digest; a key expires after it was
 *   made.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE idempotency_keys
              DROP CONSTRAINT idempotency_keys_organization_id_key_operation_unique,
              ADD CONSTRAINT idempotency_keys_organization_id_key_operation_unique
                UNIQUE NULLS NOT DISTINCT (organization_id, key, operation),
              ADD CONSTRAINT ck_idempotency_keys_request_hash CHECK (request_hash ~ '^[0-9a-f]{64}$'),
              ADD CONSTRAINT ck_idempotency_keys_response CHECK ((response_code IS NULL) = (response_body IS NULL)),
              ADD CONSTRAINT ck_idempotency_keys_expires_at CHECK (expires_at IS NULL OR expires_at > created_at);
            SQL);

        LifecycleSchema::freezeColumns('idempotency_keys', ['organization_id', 'key', 'operation', 'request_hash'],
            editableStatuses: [], name: 'request');
        LifecycleSchema::writeOnce('idempotency_keys', ['response_code', 'response_body'], name: 'response');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_idempotency_keys_freeze_request ON idempotency_keys;
            DROP TRIGGER IF EXISTS trg_idempotency_keys_write_once_response ON idempotency_keys;
            ALTER TABLE idempotency_keys
              DROP CONSTRAINT ck_idempotency_keys_request_hash,
              DROP CONSTRAINT ck_idempotency_keys_response,
              DROP CONSTRAINT ck_idempotency_keys_expires_at,
              DROP CONSTRAINT idempotency_keys_organization_id_key_operation_unique,
              ADD CONSTRAINT idempotency_keys_organization_id_key_operation_unique UNIQUE (organization_id, key, operation);
            SQL);
    }
};
