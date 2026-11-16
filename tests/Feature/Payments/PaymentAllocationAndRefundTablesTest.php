<?php

/**
 * Migration group 11, part 2: payment allocations and refunds (design 02 §7,
 * §17, V7.7, V7.9).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * Moves a refund along the given statuses, one enforced transition at a time.
 *
 * @param  list<string>  $statuses
 */
function moveRefund(int $id, array $statuses): void
{
    foreach ($statuses as $status) {
        DB::table('refunds')->where('id', $id)->update(['status' => $status]);
    }
}

/**
 * A second building of the invoice's owner, supplied by the invoice's gas
 * vendor, so a payment or invoice there has the same collector and only the
 * building differs.
 */
function siblingBuildingOf(int $invoiceId): int
{
    $invoice = DB::table('invoices')->join('buildings', 'buildings.id', '=', 'invoices.building_id')
        ->where('invoices.id', $invoiceId)->first(['buildings.tenant_organization_id', 'invoices.gas_vendor_organization_id']);
    $buildingId = TestRecords::building($invoice->tenant_organization_id);
    TestRecords::vendorAssignment($buildingId, '2026-01-01', ['vendor_organization_id' => $invoice->gas_vendor_organization_id]);

    return $buildingId;
}

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, array $expected) {
        // Arrange: migration group 11 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe($expected);
    })->with([
        ['payment_allocations', ['payments', 'financial', 'protected', 'forbidden', true, 'active']],
        ['refunds', ['payments', 'financial', 'protected', 'forbidden', true, 'requested']],
    ]);

    it('can never delete or hide a row', function (string $table, Closure $create) {
        // Arrange
        $id = $create();

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))
            ->toThrow(QueryException::class, "Records in {$table} are protected")
            ->and(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update(['is_deleted' => true])))
            ->toThrow(QueryException::class, "Records in {$table} are protected");
    })->with([
        'payment allocations' => ['payment_allocations', fn () => TestRecords::paymentAllocation()],
        'refunds' => ['refunds', fn () => TestRecords::refund()],
    ]);
});

describe('payment allocations', function () {
    it('start active', function () {
        // Arrange
        $id = TestRecords::paymentAllocation();

        // Act
        $status = DB::table('payment_allocations')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('active');
    });

    it('refuse an amount that is not positive', function (int $amount) {
        // Arrange
        $row = ['amount' => $amount];

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation(overrides: $row))
            ->toThrow(QueryException::class, 'ck_payment_allocations_amount');
    })->with([0, -100]);

    it('refuse a second allocation of a payment to the same invoice', function () {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        $paymentId = TestRecords::receivedPayment($invoiceId);
        TestRecords::paymentAllocation($invoiceId, $paymentId);

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation($invoiceId, $paymentId))
            ->toThrow(QueryException::class, 'payment_allocations_payment_id_invoice_id_unique');
    });

    it('refuse a payment of another building (design §17)', function () {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        $invoicePaymentId = TestRecords::receivedPayment($invoiceId);
        $otherPaymentId = TestRecords::payment(siblingBuildingOf($invoiceId), collect((array) DB::table('payments')->where('id', $invoicePaymentId)
            ->first(['customer_id', 'billing_mode', 'payee_organization_id', 'status', 'received_at']))->all());

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation($invoiceId, $otherPaymentId))
            ->toThrow(QueryException::class, 'fk_allocation_payment_building');
    });

    it('refuse an invoice of another building (design §17)', function () {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        $paymentId = TestRecords::receivedPayment($invoiceId);
        $customerId = DB::table('invoices')->where('id', $invoiceId)->value('customer_id');
        $otherInvoiceId = TestRecords::issuedInvoice(TestRecords::billingCycle(TestRecords::approvedReadingPeriod(siblingBuildingOf($invoiceId))), ['customer_id' => $customerId]);
        $row = (array) DB::table('payments')->where('id', $paymentId)->first(['building_id', 'customer_id']);

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation($otherInvoiceId, $paymentId, $row))
            ->toThrow(QueryException::class, 'fk_allocation_invoice_building');
    });

    it('let one payment settle several invoices of its building', function () {
        // Arrange
        $firstInvoiceId = TestRecords::issuedInvoice();
        $cycleId = (int) DB::table('invoices')->where('id', $firstInvoiceId)->value('billing_cycle_id');
        $customerId = (int) DB::table('invoices')->where('id', $firstInvoiceId)->value('customer_id');
        $secondInvoiceId = TestRecords::issuedInvoice($cycleId, ['customer_id' => $customerId]);
        $paymentId = TestRecords::receivedPayment($firstInvoiceId);
        TestRecords::paymentAllocation($firstInvoiceId, $paymentId);

        // Act
        TestRecords::paymentAllocation($secondInvoiceId, $paymentId);

        // Assert
        expect(DB::table('payment_allocations')->where('payment_id', $paymentId)->count())->toBe(2);
    });

    it('let several payments settle one invoice', function () {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        TestRecords::paymentAllocation($invoiceId);

        // Act
        TestRecords::paymentAllocation($invoiceId);

        // Assert
        expect(DB::table('payment_allocations')->where('invoice_id', $invoiceId)->count())->toBe(2);
    });
});

describe('refunds', function () {
    it('start as requested', function () {
        // Arrange
        $id = TestRecords::refund();

        // Act
        $status = DB::table('refunds')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('requested');
    });

    it('refuse an amount that is not positive', function (int $amount) {
        // Arrange
        $row = ['amount' => $amount];

        // Act & Assert
        expect(fn () => TestRecords::refund(overrides: $row))
            ->toThrow(QueryException::class, 'ck_refunds_amount');
    })->with([0, -100]);

    it('refuse a number that is already used', function () {
        // Arrange
        TestRecords::refund(overrides: ['refund_no' => 'RF-2026-0001']);

        // Act & Assert
        expect(fn () => TestRecords::refund(overrides: ['refund_no' => 'RF-2026-0001']))
            ->toThrow(QueryException::class, 'refunds_refund_no_unique');
    });

    it('cannot be raised as already approved', function () {
        // Arrange
        $row = ['status' => 'approved'];

        // Act & Assert
        expect(fn () => TestRecords::refund(overrides: $row))
            ->toThrow(QueryException::class, 'Status "approved" is not an initial status for refunds');
    });

    it('complete through approval and processing', function () {
        // Arrange
        $id = TestRecords::refund();

        // Act
        moveRefund($id, ['approved', 'processing', 'completed']);

        // Assert
        expect(DB::table('refunds')->where('id', $id)->value('status'))->toBe('completed');
    });

    it('may be processed again after a failure', function () {
        // Arrange
        $id = TestRecords::refund();
        moveRefund($id, ['approved', 'processing', 'failed']);

        // Act
        moveRefund($id, ['processing', 'completed']);

        // Assert
        expect(DB::table('refunds')->where('id', $id)->value('status'))->toBe('completed');
    });

    it('cannot be processed without approval', function () {
        // Arrange
        $id = TestRecords::refund();

        // Act & Assert
        expect(fn () => moveRefund($id, ['processing']))
            ->toThrow(QueryException::class, 'Status transition requested -> processing is not allowed');
    });

    it('cannot be approved once rejected', function () {
        // Arrange
        $id = TestRecords::refund();
        moveRefund($id, ['rejected']);

        // Act & Assert
        expect(fn () => moveRefund($id, ['approved']))
            ->toThrow(QueryException::class, 'Status transition rejected -> approved is not allowed');
    });
});

describe('allocations stay in their stream (D47)', function () {
    it('refuse a payment of another customer of the building', function () {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        $tenantId = DB::table('invoices')->join('buildings', 'buildings.id', '=', 'invoices.building_id')
            ->where('invoices.id', $invoiceId)->value('buildings.tenant_organization_id');
        $paymentId = TestRecords::receivedPayment($invoiceId, ['customer_id' => TestRecords::customer($tenantId)]);

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation($invoiceId, $paymentId))
            ->toThrow(QueryException::class, 'fk_allocation_payment_building');
    });

    it('refuse a customer other than the invoice\'s, even with a payment of that customer', function () {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        $tenantId = DB::table('invoices')->join('buildings', 'buildings.id', '=', 'invoices.building_id')
            ->where('invoices.id', $invoiceId)->value('buildings.tenant_organization_id');
        $customerId = TestRecords::customer($tenantId);
        $paymentId = TestRecords::receivedPayment($invoiceId, ['customer_id' => $customerId]);

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation($invoiceId, $paymentId, ['customer_id' => $customerId]))
            ->toThrow(QueryException::class, 'fk_allocation_invoice_building');
    });

    it('refuse a payment of the owner\'s internal stream for a gas bill, even when the owner is also the vendor', function () {
        // Arrange
        $ownerId = TestRecords::organization();
        $buildingId = TestRecords::building($ownerId);
        TestRecords::vendorAssignment($buildingId, '2026-01-01', ['vendor_organization_id' => $ownerId]);
        $invoiceId = TestRecords::issuedInvoice(TestRecords::billingCycle(TestRecords::approvedReadingPeriod($buildingId)));
        $paymentId = TestRecords::receivedPayment($invoiceId, ['billing_mode' => 'internal_cost_allocation']);

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation($invoiceId, $paymentId))
            ->toThrow(QueryException::class, 'fk_allocation_payment_building');
    });

    it('refuse an allocation that names the payment\'s stream for an invoice of the other stream', function () {
        // Arrange
        $ownerId = TestRecords::organization();
        $buildingId = TestRecords::building($ownerId);
        TestRecords::vendorAssignment($buildingId, '2026-01-01', ['vendor_organization_id' => $ownerId]);
        $invoiceId = TestRecords::issuedInvoice(TestRecords::billingCycle(TestRecords::approvedReadingPeriod($buildingId)));
        $paymentId = TestRecords::receivedPayment($invoiceId, ['billing_mode' => 'internal_cost_allocation']);

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation($invoiceId, $paymentId, ['billing_mode' => 'internal_cost_allocation']))
            ->toThrow(QueryException::class, 'fk_allocation_invoice_building');
    });

    it('refuse a payment collected by another gas vendor of the building', function () {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        $buildingId = (int) DB::table('invoices')->where('id', $invoiceId)->value('building_id');
        $formerVendorId = TestRecords::organization();
        TestRecords::vendorAssignment($buildingId, '2025-01-01', ['vendor_organization_id' => $formerVendorId, 'valid_to' => '2025-12-31']);
        $paymentId = TestRecords::receivedPayment($invoiceId, ['payee_organization_id' => $formerVendorId]);

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation($invoiceId, $paymentId))
            ->toThrow(QueryException::class, "was collected by organization {$formerVendorId}, but invoice {$invoiceId} is collected by organization");
    });

    it('refuse a payment in another currency', function () {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        $paymentId = TestRecords::receivedPayment($invoiceId, ['currency' => 'USD']);

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation($invoiceId, $paymentId))
            ->toThrow(QueryException::class, "in USD cannot settle invoice {$invoiceId} in BDT");
    });

    it('settle an internal cost allocation invoice with a payment collected by the building\'s owner', function () {
        // Arrange
        $periodId = TestRecords::approvedReadingPeriod();
        $cycleId = TestRecords::billingCycle($periodId, ['billing_mode' => 'internal_cost_allocation']);
        $invoiceId = TestRecords::issuedInvoice($cycleId, ['billing_mode' => 'internal_cost_allocation']);

        // Act
        $id = TestRecords::paymentAllocation($invoiceId);

        // Assert
        expect(DB::table('payments')->join('payment_allocations', 'payment_allocations.payment_id', '=', 'payments.id')
            ->where('payment_allocations.id', $id)->value('payments.payee_organization_id'))
            ->toBe(DB::table('buildings')->join('invoices', 'invoices.building_id', '=', 'buildings.id')
                ->where('invoices.id', $invoiceId)->value('buildings.tenant_organization_id'));
    });
});

describe('allocation status and changes (D47)', function () {
    it('can be reversed', function () {
        // Arrange
        $id = TestRecords::paymentAllocation();

        // Act
        DB::table('payment_allocations')->where('id', $id)->update(['status' => 'reversed']);

        // Assert
        expect(DB::table('payment_allocations')->where('id', $id)->value('status'))->toBe('reversed');
    });

    it('cannot become active again once reversed', function () {
        // Arrange
        $id = TestRecords::paymentAllocation();
        DB::table('payment_allocations')->where('id', $id)->update(['status' => 'reversed']);

        // Act & Assert
        expect(fn () => DB::table('payment_allocations')->where('id', $id)->update(['status' => 'active']))
            ->toThrow(QueryException::class, 'Status transition reversed -> active is not allowed');
    });

    it('cannot be written as reversed', function () {
        // Arrange
        $row = ['status' => 'reversed'];

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation(overrides: $row))
            ->toThrow(QueryException::class, 'Status "reversed" is not an initial status for payment_allocations');
    });

    it('never change what they settle once written', function (string $column, Closure $value) {
        // Arrange
        $id = TestRecords::paymentAllocation();

        // Act & Assert
        expect(fn () => DB::table('payment_allocations')->where('id', $id)->update([$column => $value()]))
            ->toThrow(QueryException::class, "payment_allocations {$id} is active: {$column} can no longer change");
    })->with([
        'amount' => ['amount', fn () => 500],
        'allocated at' => ['allocated_at', fn () => '2026-01-01 10:00:00+06'],
        'allocated by' => ['allocated_by', fn () => TestRecords::user()],
    ]);

    it('freeze every column but the status', function () {
        // Arrange: migration 000049 has run
        $lifecycle = ['id', 'status', 'is_active', 'is_deleted', 'deleted_at', 'created_at', 'updated_at', 'created_by', 'updated_by', 'created_session_id', 'updated_session_id'];

        // Act
        $frozen = DB::scalar("select pg_get_triggerdef(oid) from pg_trigger where tgname = 'trg_payment_allocations_freeze_written'");
        $columns = collect(DB::select("select column_name from information_schema.columns where table_name = 'payment_allocations'"))
            ->pluck('column_name')->diff($lifecycle);

        // Assert
        expect($columns->reject(fn (string $column) => str_contains((string) $frozen, "'{$column}'"))->values()->all())->toBe([]);
    });
});

describe('parent statuses (D47)', function () {
    it('allocate only to an issued, partially paid or overdue invoice', function (array $statuses, string $status) {
        // Arrange
        $invoiceId = TestRecords::invoice();
        TestRecords::gasChargeLine($invoiceId);
        TestRecords::moveInvoice($invoiceId, $statuses);

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation($invoiceId))
            ->toThrow(QueryException::class, "invoices {$invoiceId} is {$status}: payment_allocations in status active needs it to be issued or partially_paid or overdue");
    })->with([
        'draft' => [[], 'draft'],
        'approved' => [['approved'], 'approved'],
        'void' => [['void'], 'void'],
        'paid' => [['approved', 'issued', 'paid'], 'paid'],
        'written off' => [['approved', 'issued', 'written_off'], 'written_off'],
    ]);

    it('allocate to a partially paid or overdue invoice', function (string $status) {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        TestRecords::moveInvoice($invoiceId, [$status]);

        // Act
        $id = TestRecords::paymentAllocation($invoiceId);

        // Assert
        expect(DB::table('payment_allocations')->where('id', $id)->value('status'))->toBe('active');
    })->with(['partially_paid', 'overdue']);

    it('allocate only from a succeeded payment', function (array $statuses, string $status) {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        $paymentId = TestRecords::receivedPayment($invoiceId, ['status' => 'initiated']);
        foreach ($statuses as $next) {
            DB::table('payments')->where('id', $paymentId)->update(['status' => $next]);
        }

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation($invoiceId, $paymentId))
            ->toThrow(QueryException::class, "payments {$paymentId} is {$status}: payment_allocations in status active needs it to be succeeded");
    })->with([
        'initiated' => [[], 'initiated'],
        'pending' => [['pending'], 'pending'],
        'failed' => [['failed'], 'failed'],
        'reversed' => [['succeeded', 'reversed'], 'reversed'],
        'refunded' => [['succeeded', 'refunded'], 'refunded'],
    ]);

    it('still reverse an allocation of an invoice that is paid by now', function () {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        $id = TestRecords::paymentAllocation($invoiceId);
        TestRecords::moveInvoice($invoiceId, ['paid']);

        // Act
        DB::table('payment_allocations')->where('id', $id)->update(['status' => 'reversed']);

        // Assert
        expect(DB::table('payment_allocations')->where('id', $id)->value('status'))->toBe('reversed');
    });

    it('raise a refund only from a succeeded payment', function () {
        // Arrange
        $paymentId = TestRecords::payment();

        // Act & Assert
        expect(fn () => TestRecords::refund($paymentId))
            ->toThrow(QueryException::class, "payments {$paymentId} is initiated: refunds in status requested needs it to be succeeded");
    });

    it('stop a refund on its way once the payment is reversed', function () {
        // Arrange
        $paymentId = TestRecords::payment(overrides: ['status' => 'succeeded', 'received_at' => now()]);
        $id = TestRecords::refund($paymentId);
        DB::table('payments')->where('id', $paymentId)->update(['status' => 'reversed']);

        // Act & Assert
        expect(fn () => moveRefund($id, ['approved']))
            ->toThrow(QueryException::class, "payments {$paymentId} is reversed: refunds in status approved needs it to be succeeded");
    });

    it('still reject a refund whose payment is reversed', function () {
        // Arrange
        $paymentId = TestRecords::payment(overrides: ['status' => 'succeeded', 'received_at' => now()]);
        $id = TestRecords::refund($paymentId);
        DB::table('payments')->where('id', $paymentId)->update(['status' => 'reversed']);

        // Act
        moveRefund($id, ['rejected']);

        // Assert
        expect(DB::table('refunds')->where('id', $id)->value('status'))->toBe('rejected');
    });
});
