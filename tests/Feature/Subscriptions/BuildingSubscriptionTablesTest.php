<?php

/**
 * Migration group 6, part 2: building subscriptions, SaaS invoices and SaaS
 * payments (design 02 §3, V7.7).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, string $class, string $defaultStatus) {
        // Arrange: migration group 6 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->default_status])
            ->toBe(['subscriptions', $class, 'protected', 'forbidden', $defaultStatus]);
    })->with([
        ['building_subscriptions', 'workflow', 'trial'],
        ['saas_invoices', 'financial', 'issued'],
        ['saas_payments', 'financial', 'pending'],
    ]);

    it('never lets a row be deleted', function (string $table, Closure $record) {
        // Arrange
        $id = $record();

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))
            ->toThrow(QueryException::class);
        expect(DB::table($table)->where('id', $id)->value('is_deleted'))->toBeFalse();
    })->with([
        'subscription' => ['building_subscriptions', fn () => TestRecords::buildingSubscription()],
        'invoice' => ['saas_invoices', fn () => TestRecords::saasInvoice()],
        'payment' => ['saas_payments', fn () => DB::table('saas_payments')->insertGetId(
            ['saas_invoice_id' => TestRecords::saasInvoice(), 'amount' => 1500, 'method' => 'bkash'],
        )],
    ]);
});

describe('building subscriptions', function () {
    it('start on trial', function () {
        // Arrange
        $id = TestRecords::buildingSubscription();

        // Act
        $subscription = DB::table('building_subscriptions')->find($id);

        // Assert
        expect([$subscription->status, $subscription->is_active])->toBe(['trial', true]);
    });

    it('keep the building working while payment is late', function (string $status) {
        // Arrange
        $id = TestRecords::buildingSubscription(['status' => 'active']);

        // Act
        DB::table('building_subscriptions')->where('id', $id)->update(['status' => $status]);

        // Assert
        expect(DB::table('building_subscriptions')->where('id', $id)->value('is_active'))->toBeTrue();
    })->with(['past_due', 'grace']);

    it('stop the building once cancelled or expired', function (string $status) {
        // Arrange
        $id = TestRecords::buildingSubscription(['status' => 'active']);

        // Act
        DB::table('building_subscriptions')->where('id', $id)->update(['status' => $status]);

        // Assert
        expect(DB::table('building_subscriptions')->where('id', $id)->value('is_active'))->toBeFalse();
    })->with(['cancelled', 'expired']);

    it('refuse a period that does not end after it starts', function () {
        // Arrange
        $row = ['current_period_start' => '2026-02-01 00:00:00+06', 'current_period_end' => '2026-02-01 00:00:00+06'];

        // Act & Assert
        expect(fn () => TestRecords::buildingSubscription($row))->toThrow(QueryException::class, 'ck_building_subscriptions_period');
    });
});

describe('saas invoices', function () {
    it('start issued', function () {
        // Arrange
        $id = TestRecords::saasInvoice();

        // Act
        $status = DB::table('saas_invoices')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('issued');
    });

    it('refuse a negative amount', function (string $column) {
        // Arrange
        $row = [$column => -1];

        // Act & Assert
        expect(fn () => TestRecords::saasInvoice($row))->toThrow(QueryException::class, "ck_saas_invoices_{$column}");
    })->with(['subtotal', 'tax_total', 'total']);

    it('refuse an invoice number that is already used', function () {
        // Arrange
        TestRecords::saasInvoice(['invoice_no' => 'SINV-2026-0001']);

        // Act & Assert
        expect(fn () => TestRecords::saasInvoice(['invoice_no' => 'SINV-2026-0001']))
            ->toThrow(QueryException::class, 'saas_invoices_invoice_no_unique');
    });
});

describe('saas payments', function () {
    it('start pending', function () {
        // Arrange
        $id = DB::table('saas_payments')->insertGetId(['saas_invoice_id' => TestRecords::saasInvoice(), 'amount' => 1500, 'method' => 'bkash']);

        // Act
        $status = DB::table('saas_payments')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('pending');
    });

    it('refuse a payment of zero', function () {
        // Arrange
        $row = ['saas_invoice_id' => TestRecords::saasInvoice(), 'amount' => 0, 'method' => 'cash'];

        // Act & Assert
        expect(fn () => DB::table('saas_payments')->insert($row))->toThrow(QueryException::class, 'ck_saas_payments_amount');
    });

    it('refuse a gateway reference that was already recorded', function () {
        // Arrange
        $invoiceId = TestRecords::saasInvoice();
        $row = ['saas_invoice_id' => $invoiceId, 'amount' => 500, 'method' => 'bkash', 'provider_reference' => 'TRX-9KQ2'];
        DB::table('saas_payments')->insert($row);

        // Act & Assert
        expect(fn () => DB::table('saas_payments')->insert($row))
            ->toThrow(QueryException::class, 'saas_payments_provider_reference_unique');
    });

    it('accept several payments without a gateway reference', function () {
        // Arrange
        $invoiceId = TestRecords::saasInvoice();
        DB::table('saas_payments')->insert(['saas_invoice_id' => $invoiceId, 'amount' => 500, 'method' => 'cash']);

        // Act
        DB::table('saas_payments')->insert(['saas_invoice_id' => $invoiceId, 'amount' => 1000, 'method' => 'cash']);

        // Assert
        expect(DB::table('saas_payments')->where('saas_invoice_id', $invoiceId)->count())->toBe(2);
    });
});
