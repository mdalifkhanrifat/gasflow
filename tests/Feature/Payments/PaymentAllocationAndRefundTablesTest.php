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

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, array $expected) {
        // Arrange: migration group 11 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe($expected);
    })->with([
        ['payment_allocations', ['payments', 'financial', 'protected', 'forbidden', false, 'active']],
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
        $otherPaymentId = TestRecords::payment(overrides: ['status' => 'succeeded']);

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation($invoiceId, $otherPaymentId))
            ->toThrow(QueryException::class, 'fk_allocation_payment_building');
    });

    it('refuse an invoice of another building (design §17)', function () {
        // Arrange
        $paymentId = TestRecords::receivedPayment(TestRecords::issuedInvoice());
        $otherInvoiceId = TestRecords::issuedInvoice();
        $row = ['building_id' => DB::table('payments')->where('id', $paymentId)->value('building_id')];

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
