<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Payment attempts follow their payment and keep what the provider
 * confirmed (owner decision D47, design V7.20, group 11 questions 5 and 6;
 * changes design V7.7, where attempt transitions were not enforced).
 *
 * - An attempt is for its payment's amount and currency: composite key
 *   (payment_id, amount, currency) to a new payments key.
 * - Transitions are enforced: initiated → pending, succeeded, failed,
 *   cancelled or expired; pending → succeeded, failed, cancelled or expired.
 * - A succeeded attempt has its provider transaction id and verification
 *   time.
 * - Once decided, the attempt's payment, provider, transaction, keys,
 *   amount and verification are frozen (the provider metadata may still
 *   grow, for example with later webhooks).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
              ADD CONSTRAINT uq_payment_amount_currency UNIQUE (id, amount, currency);
            ALTER TABLE payment_attempts
              ADD CONSTRAINT fk_payment_attempt_payment_amount FOREIGN KEY (payment_id, amount, currency)
                REFERENCES payments(id, amount, currency),
              ADD CONSTRAINT ck_payment_attempts_succeeded
                CHECK (status <> 'succeeded' OR (verified_at IS NOT NULL AND provider_transaction_id IS NOT NULL));
            SQL);

        LifecycleSchema::attach('payment_attempts', module: 'payments', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true, defaultStatus: 'initiated');

        DB::unprepared(<<<'SQL'
            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('payment_attempts', 'initiated', 'pending',   NULL, false),
                   ('payment_attempts', 'initiated', 'succeeded', NULL, false),
                   ('payment_attempts', 'initiated', 'failed',    NULL, false),
                   ('payment_attempts', 'initiated', 'cancelled', NULL, false),
                   ('payment_attempts', 'initiated', 'expired',   NULL, false),
                   ('payment_attempts', 'pending',   'succeeded', NULL, false),
                   ('payment_attempts', 'pending',   'failed',    NULL, false),
                   ('payment_attempts', 'pending',   'cancelled', NULL, false),
                   ('payment_attempts', 'pending',   'expired',   NULL, false);
            SQL);

        LifecycleSchema::freezeColumns('payment_attempts',
            ['payment_id', 'provider', 'provider_transaction_id', 'idempotency_key', 'request_reference', 'amount', 'currency', 'verified_at'],
            editableStatuses: ['initiated', 'pending'], name: 'decided');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_payment_attempts_freeze_decided ON payment_attempts;
            DELETE FROM status_transitions WHERE entity_name = 'payment_attempts';
            SQL);

        LifecycleSchema::attach('payment_attempts', module: 'payments', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'initiated');

        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_attempts
              DROP CONSTRAINT ck_payment_attempts_succeeded,
              DROP CONSTRAINT fk_payment_attempt_payment_amount;
            ALTER TABLE payments DROP CONSTRAINT uq_payment_amount_currency;
            SQL);
    }
};
