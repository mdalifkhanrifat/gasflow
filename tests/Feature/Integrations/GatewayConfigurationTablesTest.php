<?php

/**
 * Migration group 13, part 3: payment and SMS gateway configurations (design
 * 02 §13, V7.7, V7.9, V7.12).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * Creates a gateway of the given kind.
 *
 * @param  array<string, mixed>  $overrides
 */
function gatewayOf(string $kind, array $overrides = []): int
{
    return $kind === 'payment' ? TestRecords::paymentGateway($overrides) : TestRecords::smsGateway($overrides);
}

describe('lifecycle registration', function () {
    it('registers each table as integration configuration that may be hidden', function (string $table) {
        // Arrange: migration 2026_11_19_000064 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe(['integrations', 'configuration', 'allowed', 'forbidden', false, 'inactive']);
    })->with(['organization_payment_gateways', 'organization_sms_gateways']);

    it('never write secrets into the activity trail', function (string $table, Closure $create, array $secrets) {
        // Arrange: a new organization

        // Act
        $id = $create();

        // Assert
        $values = json_decode((string) DB::table('data_change_logs')
            ->where(['entity_name' => $table, 'record_key' => (string) $id])
            ->value('new_values'), true);
        expect(array_map(fn (string $column) => $values[$column], $secrets))->each->toBe('[redacted]');
    })->with([
        'payment' => ['organization_payment_gateways', fn () => TestRecords::paymentGateway(['webhook_secret_encrypted' => 'eyJzZWNyZXQiOjF9']),
            ['credentials_encrypted', 'webhook_secret_encrypted']],
        'sms' => ['organization_sms_gateways', fn () => TestRecords::smsGateway(), ['credentials_encrypted']],
    ]);
});

describe('gateway configurations', function () {
    it('start inactive and not the default', function (string $kind, string $table) {
        // Arrange
        $id = gatewayOf($kind);

        // Act
        $row = DB::table($table)->where('id', $id)->first();

        // Assert
        expect([$row->status, $row->is_default])->toBe(['inactive', false]);
    })->with([
        ['payment', 'organization_payment_gateways'],
        ['sms', 'organization_sms_gateways'],
    ]);

    it('belong to an organization or a building, never both or neither', function (string $kind, string $constraint, Closure $scope) {
        // Arrange
        $row = $scope();

        // Act & Assert
        expect(fn () => gatewayOf($kind, $row))->toThrow(QueryException::class, $constraint);
    })->with([
        'payment, both' => ['payment', 'ck_organization_payment_gateways_scope',
            fn () => ['organization_id' => TestRecords::organization(), 'building_id' => TestRecords::building()]],
        'payment, neither' => ['payment', 'ck_organization_payment_gateways_scope', fn () => ['organization_id' => null]],
        'sms, both' => ['sms', 'ck_organization_sms_gateways_scope',
            fn () => ['organization_id' => TestRecords::organization(), 'building_id' => TestRecords::building()]],
        'sms, neither' => ['sms', 'ck_organization_sms_gateways_scope', fn () => ['organization_id' => null]],
    ]);

    it('refuse a provider outside the catalogue', function (string $kind, string $constraint) {
        // Arrange
        $row = ['provider' => 'paypal'];

        // Act & Assert
        expect(fn () => gatewayOf($kind, $row))->toThrow(QueryException::class, $constraint);
    })->with([
        ['payment', 'fk_org_payment_gateway_provider'],
        ['sms', 'fk_org_sms_gateway_provider'],
    ]);

    it('refuse an unknown payment environment', function () {
        // Arrange
        $row = ['environment' => 'staging'];

        // Act & Assert
        expect(fn () => TestRecords::paymentGateway($row))->toThrow(QueryException::class, 'ck_organization_payment_gateways_environment');
    });

    it('refuse a second configuration of the same provider in a scope', function (string $kind, string $column, Closure $owner, string $index) {
        // Arrange
        $row = [$column => $owner()];
        gatewayOf($kind, $row);

        // Act & Assert
        expect(fn () => gatewayOf($kind, $row))->toThrow(QueryException::class, $index);
    })->with([
        'payment, organization' => ['payment', 'organization_id', fn () => TestRecords::organization(), 'uq_org_payment_gateway_provider'],
        'payment, building' => ['payment', 'building_id', fn () => TestRecords::building(), 'uq_building_payment_gateway_provider'],
        'sms, organization' => ['sms', 'organization_id', fn () => TestRecords::organization(), 'uq_org_sms_gateway_provider'],
        'sms, building' => ['sms', 'building_id', fn () => TestRecords::building(), 'uq_building_sms_gateway_provider'],
    ]);

    it('let a payment provider have a sandbox and a production configuration', function () {
        // Arrange
        $organizationId = TestRecords::organization();
        TestRecords::paymentGateway(['organization_id' => $organizationId]);

        // Act
        TestRecords::paymentGateway(['organization_id' => $organizationId, 'environment' => 'production']);

        // Assert
        expect(DB::table('organization_payment_gateways')->where('organization_id', $organizationId)->count())->toBe(2);
    });

    it('refuse a second default in a scope', function (string $kind, string $column, Closure $owner, string $index) {
        // Arrange
        $ownerId = $owner();
        DB::table('integration_providers')->insert(['code' => 'sms_backup', 'provider_type' => 'sms', 'display_name' => 'Backup SMS',
            'driver_class' => 'App\\Services\\Integrations\\Drivers\\GenericSmsDriver']);
        gatewayOf($kind, [$column => $ownerId, 'is_default' => true, 'status' => 'active']);

        // Act & Assert
        expect(fn () => gatewayOf($kind, [$column => $ownerId, 'is_default' => true, 'status' => 'active', 'provider' => $kind === 'payment' ? 'nagad' : 'sms_backup']))
            ->toThrow(QueryException::class, $index);
    })->with([
        'payment, organization' => ['payment', 'organization_id', fn () => TestRecords::organization(), 'uq_org_payment_gateway_default'],
        'payment, building' => ['payment', 'building_id', fn () => TestRecords::building(), 'uq_building_payment_gateway_default'],
        'sms, organization' => ['sms', 'organization_id', fn () => TestRecords::organization(), 'uq_org_sms_gateway_default'],
        'sms, building' => ['sms', 'building_id', fn () => TestRecords::building(), 'uq_building_sms_gateway_default'],
    ]);

    it('let a configuration be hidden', function (string $kind, string $table) {
        // Arrange
        $id = gatewayOf($kind);

        // Act
        DB::table($table)->where('id', $id)->delete();

        // Assert
        expect(DB::table($table)->where('id', $id)->value('is_deleted'))->toBeTrue();
    })->with([
        ['payment', 'organization_payment_gateways'],
        ['sms', 'organization_sms_gateways'],
    ]);
});

describe('payment attempts', function () {
    it('may name the gateway configuration they went through', function () {
        // Arrange
        $paymentId = TestRecords::payment();
        $collectorId = DB::table('payments')->where('id', $paymentId)->value('payee_organization_id');
        $gatewayId = TestRecords::paymentGateway(['organization_id' => $collectorId, 'status' => 'active']);

        // Act
        $id = TestRecords::paymentAttempt($paymentId, ['gateway_configuration_id' => $gatewayId]);

        // Assert
        expect(DB::table('payment_attempts')->where('id', $id)->value('gateway_configuration_id'))->toBe($gatewayId);
    });

    it('refuse a gateway configuration that does not exist', function () {
        // Arrange
        $row = ['gateway_configuration_id' => 999999];

        // Act & Assert
        expect(fn () => TestRecords::paymentAttempt(overrides: $row))
            ->toThrow(QueryException::class, 'fk_payment_attempt_gateway_configuration');
    });
});

/**
 * A new internal cost allocation payment and its collector (the building's
 * owner).
 *
 * @return array{payment: int, collector: int, building: int}
 */
function collectedPayment(): array
{
    $payment = DB::table('payments')->where('id', TestRecords::payment())->first();

    return ['payment' => $payment->id, 'collector' => $payment->payee_organization_id, 'building' => $payment->building_id];
}

describe('which gateway a provider may run', function () {
    it('refuses a provider of the other kind', function (string $kind) {
        // Arrange
        $row = ['provider' => $kind === 'payment' ? 'sms_generic' : 'bkash'];

        // Act & Assert
        expect(fn () => gatewayOf($kind, $row))->toThrow(QueryException::class, $kind === 'payment' ? 'fk_org_payment_gateway_provider' : 'fk_org_sms_gateway_provider');
    })->with(['payment', 'sms']);

    it('refuses a default that is not active', function (string $kind, string $constraint) {
        // Arrange
        $row = ['is_default' => true];

        // Act & Assert
        expect(fn () => gatewayOf($kind, $row))->toThrow(QueryException::class, $constraint);
    })->with([
        ['payment', 'ck_organization_payment_gateways_default_active'],
        ['sms', 'ck_organization_sms_gateways_default_active'],
    ]);
});

describe('the gateway of a payment attempt', function () {
    it('may be a building gateway for the building\'s internal cost allocation', function () {
        // Arrange
        ['payment' => $paymentId, 'building' => $buildingId] = collectedPayment();
        $gatewayId = TestRecords::paymentGateway(['building_id' => $buildingId, 'status' => 'active']);

        // Act
        $id = TestRecords::paymentAttempt($paymentId, ['gateway_configuration_id' => $gatewayId]);

        // Assert
        expect(DB::table('payment_attempts')->where('id', $id)->value('gateway_configuration_id'))->toBe($gatewayId);
    });

    it('refuses a gateway of an organization that does not collect the payment', function () {
        // Arrange
        ['payment' => $paymentId] = collectedPayment();
        $gatewayId = TestRecords::paymentGateway(['status' => 'active']);

        // Act & Assert
        expect(fn () => TestRecords::paymentAttempt($paymentId, ['gateway_configuration_id' => $gatewayId]))
            ->toThrow(QueryException::class, 'that collects payment');
    });

    it('refuses a gateway of another building', function () {
        // Arrange
        ['payment' => $paymentId] = collectedPayment();
        $gatewayId = TestRecords::paymentGateway(['building_id' => TestRecords::building(), 'status' => 'active']);

        // Act & Assert
        expect(fn () => TestRecords::paymentAttempt($paymentId, ['gateway_configuration_id' => $gatewayId]))
            ->toThrow(QueryException::class, 'serves only the internal cost allocation of building');
    });

    it('refuses a building gateway for an authorized meter billing payment', function () {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        $paymentId = TestRecords::receivedPayment($invoiceId, ['status' => 'pending', 'received_at' => null]);
        $buildingId = DB::table('payments')->where('id', $paymentId)->value('building_id');
        $gatewayId = TestRecords::paymentGateway(['building_id' => $buildingId, 'status' => 'active']);

        // Act & Assert
        expect(fn () => TestRecords::paymentAttempt($paymentId, ['gateway_configuration_id' => $gatewayId]))
            ->toThrow(QueryException::class, 'not a authorized_meter_billing payment');
    });

    it('refuses another provider than the gateway\'s', function () {
        // Arrange
        ['payment' => $paymentId, 'collector' => $collectorId] = collectedPayment();
        $gatewayId = TestRecords::paymentGateway(['organization_id' => $collectorId, 'provider' => 'nagad', 'status' => 'active']);

        // Act & Assert
        expect(fn () => TestRecords::paymentAttempt($paymentId, ['gateway_configuration_id' => $gatewayId]))
            ->toThrow(QueryException::class, "uses bkash, not the gateway's provider nagad");
    });

    it('refuses a gateway that is not active', function (string $status) {
        // Arrange
        ['payment' => $paymentId, 'collector' => $collectorId] = collectedPayment();
        $gatewayId = TestRecords::paymentGateway(['organization_id' => $collectorId, 'status' => $status]);

        // Act & Assert
        expect(fn () => TestRecords::paymentAttempt($paymentId, ['gateway_configuration_id' => $gatewayId]))
            ->toThrow(QueryException::class, 'only an active gateway takes new attempts');
    })->with(['inactive', 'disabled']);

    it('keeps an attempt whose gateway is disabled later, even when the attempt is written again', function () {
        // Arrange
        ['payment' => $paymentId, 'collector' => $collectorId] = collectedPayment();
        $gatewayId = TestRecords::paymentGateway(['organization_id' => $collectorId, 'status' => 'active']);
        $id = TestRecords::paymentAttempt($paymentId, ['gateway_configuration_id' => $gatewayId]);
        DB::table('organization_payment_gateways')->where('id', $gatewayId)->update(['status' => 'disabled']);

        // Act
        DB::table('payment_attempts')->where('id', $id)->update(['status' => 'pending', 'provider' => 'bkash']);

        // Assert
        expect(DB::table('payment_attempts')->where('id', $id)->value('status'))->toBe('pending');
    });
});
