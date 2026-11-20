<?php

/**
 * Migration group 14, part 2: webhook inbox, integration outbox and delivery
 * attempts (design 02 §19, 01 webhook/outbox flow, V7.6, V7.7, V7.8, V7.13).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TestRecords;

/**
 * A bKash event for a new organization unless the row says otherwise.
 *
 * @param  array<string, mixed>  $overrides
 */
function webhookEvent(array $overrides = []): int
{
    return (int) DB::table('webhook_inbox')->insertGetId($overrides + [
        'organization_id' => TestRecords::organization(), 'provider_code' => 'bkash',
        'provider_event_id' => (string) Str::uuid(), 'event_type' => 'payment.completed', 'payload' => '{"amount":"500.00"}',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function outboxEvent(array $overrides = []): int
{
    return (int) DB::table('integration_outbox')->insertGetId($overrides + [
        'organization_id' => TestRecords::organization(), 'event_uuid' => (string) Str::uuid(),
        'aggregate_type' => 'payment', 'aggregate_id' => 1, 'event_type' => 'payment.received', 'payload' => '{}',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function deliveryAttempt(?int $outboxId = null, array $overrides = []): int
{
    return (int) DB::table('integration_delivery_attempts')->insertGetId($overrides + [
        'outbox_id' => $outboxId ?? outboxEvent(), 'provider_code' => 'sms_generic', 'status' => 'failed',
    ]);
}

describe('lifecycle registration', function () {
    it('registers each table as a protected log of the design', function (string $table, string $defaultStatus) {
        // Arrange: migration 2026_11_20_000078 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status, $entity->log_data_changes])
            ->toBe(['integrations', 'log', 'protected', 'forbidden', false, $defaultStatus, false]);
    })->with([
        ['webhook_inbox', 'received'],
        ['integration_outbox', 'pending'],
        ['integration_delivery_attempts', 'failed'],
    ]);

    it('never lets a row be deleted', function (Closure $insert, string $table) {
        // Arrange
        $id = $insert();

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))
            ->toThrow(QueryException::class, "Records in {$table} are protected");
    })->with([
        'webhook event' => [fn () => webhookEvent(), 'webhook_inbox'],
        'outbox event' => [fn () => outboxEvent(), 'integration_outbox'],
        'delivery attempt' => [fn () => deliveryAttempt(), 'integration_delivery_attempts'],
    ]);

    it('keeps the events out of the data change trail', function () {
        // Arrange
        $id = webhookEvent();

        // Act
        $logged = DB::table('data_change_logs')->where(['entity_name' => 'webhook_inbox', 'record_key' => (string) $id])->exists();

        // Assert
        expect($logged)->toBeFalse();
    });

    it('seeds the statuses of the design, neutral to is_active', function () {
        // Arrange: migration 2026_11_20_000078 has run

        // Act
        $statuses = DB::table('status_definitions')
            ->whereIn('entity_name', ['webhook_inbox', 'integration_outbox', 'integration_delivery_attempts'])
            ->orderBy('entity_name', 'desc')->orderBy('sort_order')
            ->get(['entity_name', 'code', 'implies_active', 'is_initial', 'is_terminal'])
            ->map(fn ($s) => [$s->entity_name, $s->code, $s->implies_active, $s->is_initial, $s->is_terminal])->all();

        // Assert
        expect($statuses)->toBe([
            ['webhook_inbox', 'received', null, true, false],
            ['webhook_inbox', 'processing', null, false, false],
            ['webhook_inbox', 'processed', null, false, true],
            ['webhook_inbox', 'failed', null, false, false],
            ['webhook_inbox', 'ignored', null, false, true],
            ['integration_outbox', 'pending', null, true, false],
            ['integration_outbox', 'published', null, false, true],
            ['integration_outbox', 'failed', null, false, false],
            ['integration_outbox', 'dead_letter', null, false, true],
            ['integration_delivery_attempts', 'succeeded', null, true, true],
            ['integration_delivery_attempts', 'failed', null, true, true],
            ['integration_delivery_attempts', 'retrying', null, true, false],
        ]);
    });
});

describe('webhook inbox', function () {
    it('starts an event as received with no attempts', function () {
        // Arrange
        $id = webhookEvent();

        // Act
        $row = DB::table('webhook_inbox')->where('id', $id)->first();

        // Assert
        expect([$row->status, $row->attempts, $row->is_active])->toBe(['received', 0, true]);
    });

    it('keeps one row per provider event, so a duplicate webhook is refused', function () {
        // Arrange
        $eventId = 'TRX-1001';
        webhookEvent(['provider_event_id' => $eventId]);

        // Act & Assert
        expect(fn () => webhookEvent(['provider_event_id' => $eventId]))
            ->toThrow(QueryException::class, 'webhook_inbox_provider_code_provider_event_id_unique');
    });

    it('lets another provider use the same event id', function () {
        // Arrange
        webhookEvent(['provider_event_id' => 'TRX-1001']);

        // Act
        $id = webhookEvent(['provider_event_id' => 'TRX-1001', 'provider_code' => 'nagad']);

        // Assert
        expect($id)->toBeGreaterThan(0);
    });

    it('refuses an event without an organization, of an unknown provider or with negative attempts', function (array $row, string $error) {
        // Arrange: the row of the dataset

        // Act & Assert
        expect(fn () => webhookEvent($row))->toThrow(QueryException::class, $error);
    })->with([
        'organization' => [['organization_id' => null], 'null value in column "organization_id"'],
        'provider' => [['provider_code' => 'paypal'], 'webhook_inbox_provider_code_foreign'],
        'attempts' => [['attempts' => -1], 'webhook_inbox_attempts_check'],
    ]);

    it('takes only the statuses of the catalogue', function () {
        // Arrange
        $id = webhookEvent();

        // Act & Assert
        expect(fn () => DB::table('webhook_inbox')->where('id', $id)->update(['status' => 'done']))
            ->toThrow(QueryException::class, 'Status "done" is not defined for webhook_inbox');
    });
});

describe('integration outbox', function () {
    it('starts an event as pending, available now', function () {
        // Arrange
        $id = outboxEvent();

        // Act
        $row = DB::table('integration_outbox')->where('id', $id)->first();

        // Assert
        expect([$row->status, $row->attempts, $row->published_at, $row->available_at !== null])->toBe(['pending', 0, null, true]);
    });

    it('keeps one row per event uuid', function () {
        // Arrange
        $uuid = (string) Str::uuid();
        outboxEvent(['event_uuid' => $uuid]);

        // Act & Assert
        expect(fn () => outboxEvent(['event_uuid' => $uuid]))->toThrow(QueryException::class, 'integration_outbox_event_uuid_unique');
    });

    it('refuses negative attempts', function () {
        // Arrange
        $row = ['attempts' => -1];

        // Act & Assert
        expect(fn () => outboxEvent($row))->toThrow(QueryException::class, 'integration_outbox_attempts_check');
    });
});

describe('integration delivery attempts', function () {
    it('belong to an outbox event and a known provider', function (array $row, string $error) {
        // Arrange: the row of the dataset

        // Act & Assert
        expect(fn () => deliveryAttempt(overrides: $row))->toThrow(QueryException::class, $error);
    })->with([
        'outbox event' => [['outbox_id' => 999999999], 'integration_delivery_attempts_outbox_id_foreign'],
        'provider' => [['provider_code' => 'paypal'], 'integration_delivery_attempts_provider_code_foreign'],
    ]);

    it('take only the statuses of the catalogue', function () {
        // Arrange
        $row = ['status' => 'timeout'];

        // Act & Assert
        expect(fn () => deliveryAttempt(overrides: $row))
            ->toThrow(QueryException::class, 'Status "timeout" is not defined for integration_delivery_attempts');
    });

    it('record a retry as it happened', function () {
        // Arrange
        $outboxId = outboxEvent();

        // Act
        $id = deliveryAttempt($outboxId, ['status' => 'retrying', 'response_code' => '503']);

        // Assert
        expect(DB::table('integration_delivery_attempts')->where('id', $id)->value('status'))->toBe('retrying');
    });
});
