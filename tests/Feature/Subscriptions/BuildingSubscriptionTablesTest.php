<?php

/**
 * Migration group 6, part 2: building subscriptions, SaaS invoices and SaaS
 * payments (design 02 §3, V7.7).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

describe('saas invoice amounts', function () {
    it('refuse a total that is not the subtotal plus tax', function () {
        // Arrange
        $row = ['subtotal' => 1500, 'tax_total' => 225, 'total' => 1500];

        // Act & Assert
        expect(fn () => TestRecords::saasInvoice($row))->toThrow(QueryException::class, 'ck_saas_invoices_total_sum');
    });

    it('accept a total that is the subtotal plus tax', function () {
        // Arrange
        $row = ['subtotal' => 1500, 'tax_total' => 225, 'total' => 1725];

        // Act
        $id = TestRecords::saasInvoice($row);

        // Assert
        expect(DB::table('saas_invoices')->where('id', $id)->value('total'))->toBe('1725.00');
    });

    it('let a draft change what it bills', function () {
        // Arrange
        $id = TestRecords::saasInvoice(['status' => 'draft']);

        // Act
        DB::table('saas_invoices')->where('id', $id)->update(['subtotal' => 2000, 'total' => 2000]);

        // Assert
        expect(DB::table('saas_invoices')->where('id', $id)->value('total'))->toBe('2000.00');
    });

    it('let a draft get its final amounts in the update that issues it', function () {
        // Arrange
        $id = TestRecords::saasInvoice(['status' => 'draft']);

        // Act
        DB::table('saas_invoices')->where('id', $id)->update(['status' => 'issued', 'subtotal' => 2000, 'total' => 2000]);

        // Assert
        expect(DB::table('saas_invoices')->where('id', $id)->first())
            ->status->toBe('issued')
            ->total->toBe('2000.00');
    });

    it('freeze what an issued invoice bills', function (Closure $change) {
        // Arrange
        $id = TestRecords::saasInvoice();

        // Act & Assert
        expect(fn () => DB::table('saas_invoices')->where('id', $id)->update($change()))
            ->toThrow(QueryException::class, 'void it and issue a new one');
    })->with([
        'amounts' => fn () => ['subtotal' => 2000, 'total' => 2000],
        'number' => fn () => ['invoice_no' => 'SINV-CHANGED'],
        'currency' => fn () => ['currency' => 'USD'],
        'issue time' => fn () => ['issued_at' => '2026-01-01 00:00:00+06'],
        'subscription' => fn () => ['building_subscription_id' => TestRecords::buildingSubscription()],
    ]);

    it('still let an issued invoice be paid or given more time', function () {
        // Arrange
        $id = TestRecords::saasInvoice();

        // Act
        DB::table('saas_invoices')->where('id', $id)->update(['status' => 'paid', 'due_at' => '2026-12-31 23:59:59+06']);

        // Assert
        expect(DB::table('saas_invoices')->where('id', $id)->value('status'))->toBe('paid');
    });

    it('refuse to send an issued invoice back to draft', function () {
        // Arrange
        $id = TestRecords::saasInvoice();

        // Act & Assert
        expect(fn () => DB::table('saas_invoices')->where('id', $id)->update(['status' => 'draft']))
            ->toThrow(QueryException::class, 'cannot return to draft');
    });

    it('let the maintenance override correct an issued invoice', function () {
        // Arrange
        $operator = DB::connection('pgsql_maintenance');
        $operator->beginTransaction();
        $organizationId = $operator->table('organizations')->insertGetId(
            ['public_id' => strtolower((string) Str::ulid()), 'code' => 'ORG-OVR-INV', 'name' => 'Override org', 'organization_type' => 'management'],
        );
        $buildingId = $operator->table('buildings')->insertGetId(
            ['public_id' => strtolower((string) Str::ulid()), 'tenant_organization_id' => $organizationId, 'code' => 'B-OVR', 'name' => 'Override building'],
        );
        $planId = $operator->table('saas_plans')->insertGetId(['code' => 'PLAN_OVR_INV', 'name' => 'Override plan']);
        $versionId = $operator->table('plan_versions')->insertGetId(
            ['saas_plan_id' => $planId, 'version_no' => 1, 'billing_interval' => 'monthly', 'price' => 1500, 'effective_from' => '2026-01-01'],
        );
        $subscriptionId = $operator->table('building_subscriptions')->insertGetId(
            ['building_id' => $buildingId, 'plan_version_id' => $versionId, 'starts_at' => now()],
        );
        $invoiceId = $operator->table('saas_invoices')->insertGetId(
            ['building_subscription_id' => $subscriptionId, 'invoice_no' => 'SINV-OVR-1', 'subtotal' => 1500, 'total' => 1500, 'issued_at' => now()],
        );
        $operator->select(
            "select set_config('app.maintenance_override', 'on', true), set_config('app.lifecycle_reason', ?, true)",
            ['Ticket #42: invoice billed the wrong package price'],
        );

        try {
            // Act
            $operator->table('saas_invoices')->where('id', $invoiceId)->update(['subtotal' => 1200, 'total' => 1200]);

            // Assert
            expect($operator->table('saas_invoices')->where('id', $invoiceId)->value('total'))->toBe('1200.00');
        } finally {
            $operator->rollBack();
        }
    });
});
