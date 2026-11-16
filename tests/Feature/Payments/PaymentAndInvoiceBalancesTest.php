<?php

/**
 * Money never exceeds its source, and statuses say what the balance says
 * (owner decision D47, design V7.20, group 11 questions 4 and 7):
 * payment_balance(), invoice_balance() and their guards.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * @return array<string, string>
 */
function paymentBalance(int $paymentId): array
{
    return (array) DB::selectOne('select * from payment_balance(?)', [$paymentId]);
}

/**
 * @return array<string, string>
 */
function invoiceBalance(int $invoiceId): array
{
    return (array) DB::selectOne('select * from invoice_balance(?)', [$invoiceId]);
}

/**
 * Runs the checks that otherwise wait for COMMIT, then defers them again.
 */
function checkAtCommit(): void
{
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
}

function reverseAllocation(int $allocationId): void
{
    DB::table('payment_allocations')->where('id', $allocationId)->update(['status' => 'reversed']);
}

describe('payment_balance()', function () {
    it('counts active allocations and refunds that are not rejected', function () {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        $paymentId = TestRecords::receivedPayment($invoiceId, ['amount' => 5000]);
        TestRecords::paymentAllocation($invoiceId, $paymentId, ['amount' => 1000]);
        reverseAllocation(TestRecords::paymentAllocation(TestRecords::issuedInvoice(
            (int) DB::table('invoices')->where('id', $invoiceId)->value('billing_cycle_id'),
            ['customer_id' => DB::table('invoices')->where('id', $invoiceId)->value('customer_id')],
        ), $paymentId, ['amount' => 700]));
        TestRecords::completeRefund($paymentId, 300);
        TestRecords::refund($paymentId, ['amount' => 200]);
        DB::table('refunds')->where('id', TestRecords::refund($paymentId, ['amount' => 900]))->update(['status' => 'rejected']);

        // Act
        $balance = paymentBalance($paymentId);

        // Assert
        expect($balance)->toBe([
            'amount' => '5000.00', 'allocated' => '1000.00', 'refunded' => '500.00',
            'refunded_completed' => '300.00', 'unallocated' => '3500.00',
        ]);
    });

    it('keeps an overpayment on the payment as the customer\'s credit', function () {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        $paymentId = TestRecords::receivedPayment($invoiceId, ['amount' => 5000]);

        // Act
        TestRecords::paymentAllocation($invoiceId, $paymentId, ['amount' => 3000]);

        // Assert
        expect(paymentBalance($paymentId)['unallocated'])->toBe('2000.00');
    });
});

describe('a payment gives no more than it has', function () {
    beforeEach(function () {
        $this->invoiceId = TestRecords::issuedInvoice();
        $this->paymentId = TestRecords::receivedPayment($this->invoiceId);
    });

    it('refuse allocations above the payment', function () {
        // Arrange
        $second = TestRecords::issuedInvoice(
            (int) DB::table('invoices')->where('id', $this->invoiceId)->value('billing_cycle_id'),
            ['customer_id' => DB::table('invoices')->where('id', $this->invoiceId)->value('customer_id')],
        );
        TestRecords::paymentAllocation($this->invoiceId, $this->paymentId, ['amount' => 2000]);

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation($second, $this->paymentId, ['amount' => 1500]))
            ->toThrow(QueryException::class, "Payment {$this->paymentId} has only 3000.00 to give: 3500.00 allocated and 0.00 refunded would exceed it");
    });

    it('refuse a refund of money that settles an invoice', function () {
        // Arrange
        TestRecords::paymentAllocation($this->invoiceId, $this->paymentId, ['amount' => 3000]);

        // Act & Assert
        expect(fn () => TestRecords::refund($this->paymentId, ['amount' => 1]))
            ->toThrow(QueryException::class, '3000.00 allocated and 1.00 refunded would exceed it');
    });

    it('refuse refunds above the payment, also when only requested', function () {
        // Arrange
        TestRecords::refund($this->paymentId, ['amount' => 2000]);

        // Act & Assert
        expect(fn () => TestRecords::refund($this->paymentId, ['amount' => 2000]))
            ->toThrow(QueryException::class, '0.00 allocated and 4000.00 refunded would exceed it');
    });

    it('free the money of a rejected refund', function () {
        // Arrange
        $rejected = TestRecords::refund($this->paymentId, ['amount' => 3000]);
        DB::table('refunds')->where('id', $rejected)->update(['status' => 'rejected']);

        // Act
        TestRecords::refund($this->paymentId, ['amount' => 3000]);

        // Assert
        expect(paymentBalance($this->paymentId)['unallocated'])->toBe('0.00');
    });

    it('free the money of a reversed allocation', function () {
        // Arrange
        reverseAllocation(TestRecords::paymentAllocation($this->invoiceId, $this->paymentId, ['amount' => 3000]));

        // Act
        TestRecords::refund($this->paymentId, ['amount' => 3000]);

        // Assert
        expect(paymentBalance($this->paymentId)['refunded'])->toBe('3000.00');
    });
});

describe('invoice_balance()', function () {
    it('counts approved and applied credit notes and active allocations', function () {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        DB::table('credit_notes')->where('id', TestRecords::creditNote($invoiceId, ['amount' => 300]))->update(['status' => 'approved']);
        TestRecords::creditNote($invoiceId, ['amount' => 400]);
        TestRecords::paymentAllocation($invoiceId, overrides: ['amount' => 1000]);
        reverseAllocation(TestRecords::paymentAllocation($invoiceId, overrides: ['amount' => 600]));

        // Act
        $balance = invoiceBalance($invoiceId);

        // Assert
        expect($balance)->toBe(['total' => '3000.00', 'credited' => '300.00', 'paid' => '1000.00', 'due' => '1700.00']);
    });
});

describe('an invoice is settled no more than its total', function () {
    beforeEach(function () {
        $this->invoiceId = TestRecords::issuedInvoice();
    });

    it('refuse allocations above the invoice', function () {
        // Arrange
        TestRecords::paymentAllocation($this->invoiceId, overrides: ['amount' => 2000]);

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation($this->invoiceId, overrides: ['amount' => 1500]))
            ->toThrow(QueryException::class, "Invoice {$this->invoiceId} of 3000.00 would be settled beyond its total: 0.00 credited and 3500.00 paid");
    });

    it('refuse an allocation of what a credit note already covers', function () {
        // Arrange
        DB::table('credit_notes')->where('id', TestRecords::creditNote($this->invoiceId, ['amount' => 2500]))->update(['status' => 'approved']);

        // Act & Assert
        expect(fn () => TestRecords::paymentAllocation($this->invoiceId))
            ->toThrow(QueryException::class, '2500.00 credited and 1000.00 paid');
    });

    it('refuse a credit note approval for what is already paid', function () {
        // Arrange
        TestRecords::paymentAllocation($this->invoiceId, overrides: ['amount' => 2500]);
        $creditNoteId = TestRecords::creditNote($this->invoiceId, ['amount' => 1000]);

        // Act & Assert
        expect(fn () => DB::table('credit_notes')->where('id', $creditNoteId)->update(['status' => 'approved']))
            ->toThrow(QueryException::class, '1000.00 credited and 2500.00 paid');
    });

    it('accept payments and credit notes that together make the total', function () {
        // Arrange
        DB::table('credit_notes')->where('id', TestRecords::creditNote($this->invoiceId, ['amount' => 1000]))->update(['status' => 'approved']);

        // Act
        TestRecords::payInvoice($this->invoiceId);

        // Assert
        expect(DB::table('invoices')->where('id', $this->invoiceId)->value('status'))->toBe('paid')
            ->and(invoiceBalance($this->invoiceId)['due'])->toBe('0.00');
    });
});

describe('an invoice\'s status says what its balance says', function () {
    beforeEach(function () {
        $this->invoiceId = TestRecords::issuedInvoice();
    });

    it('cannot be paid while something is due', function () {
        // Arrange
        TestRecords::paymentAllocation($this->invoiceId);

        // Act & Assert
        expect(fn () => DB::table('invoices')->where('id', $this->invoiceId)->update(['status' => 'paid']))
            ->toThrow(QueryException::class, "Invoice {$this->invoiceId} cannot be paid: 1000.00 paid and 2000.00 due of 3000.00");
    });

    it('cannot be partially paid with nothing paid', function () {
        // Act & Assert
        expect(fn () => DB::table('invoices')->where('id', $this->invoiceId)->update(['status' => 'partially_paid']))
            ->toThrow(QueryException::class, 'cannot be partially_paid: 0.00 paid and 3000.00 due');
    });

    it('cannot be overdue with nothing due', function () {
        // Arrange
        DB::table('credit_notes')->where('id', TestRecords::creditNote($this->invoiceId, ['amount' => 3000]))->update(['status' => 'approved']);

        // Act & Assert
        expect(fn () => DB::table('invoices')->where('id', $this->invoiceId)->update(['status' => 'overdue']))
            ->toThrow(QueryException::class, 'cannot be overdue: 0.00 paid and 0.00 due');
    });

    it('is partially paid again once one of its payments is reversed', function () {
        // Arrange
        TestRecords::payInvoice($this->invoiceId, 1000);
        reverseAllocation(TestRecords::payInvoice($this->invoiceId));

        // Act
        DB::table('invoices')->where('id', $this->invoiceId)->update(['status' => 'partially_paid']);
        checkAtCommit();

        // Assert
        expect(DB::table('invoices')->where('id', $this->invoiceId)->value('status'))->toBe('partially_paid');
    });

    it('is issued again once its only payment is reversed', function () {
        // Arrange
        reverseAllocation(TestRecords::payInvoice($this->invoiceId));

        // Act
        DB::table('invoices')->where('id', $this->invoiceId)->update(['status' => 'issued']);
        checkAtCommit();

        // Assert
        expect(DB::table('invoices')->where('id', $this->invoiceId)->value('status'))->toBe('issued');
    });

    it('may go back each way a reversal needs', function (array $payments, int $reversed, string $from, string $to) {
        // Arrange
        $allocations = array_map(fn ($amount) => TestRecords::payInvoice($this->invoiceId, $amount), $payments);
        foreach (array_slice($allocations, -$reversed) as $allocationId) {
            reverseAllocation($allocationId);
        }

        // Act
        DB::table('invoices')->where('id', $this->invoiceId)->update(['status' => $to]);
        checkAtCommit();

        // Assert
        expect([$from, DB::table('invoices')->where('id', $this->invoiceId)->value('status')])->toBe([$from, $to]);
    })->with([
        'paid to partially paid' => [[1000, null], 1, 'paid', 'partially_paid'],
        'paid to overdue' => [[null], 1, 'paid', 'overdue'],
        'paid to issued' => [[null], 1, 'paid', 'issued'],
        'partially paid to issued' => [[1000], 1, 'partially_paid', 'issued'],
    ]);

    it('show paid as a status that is no longer final', function () {
        // Act
        $terminal = DB::table('status_definitions')->where('entity_name', 'invoices')->where('code', 'paid')->value('is_terminal');

        // Assert
        expect($terminal)->toBeFalse();
    });

    it('cannot go back to issued while something is still paid', function () {
        // Arrange
        TestRecords::payInvoice($this->invoiceId, 1000);

        // Act & Assert
        expect(fn () => DB::table('invoices')->where('id', $this->invoiceId)->update(['status' => 'issued']))
            ->toThrow(QueryException::class, 'cannot be issued: 1000.00 paid and 2000.00 due');
    });

    describe('at COMMIT', function () {
        it('refuse a reversed allocation that leaves its invoice paid', function () {
            // Arrange: the payment itself was fine at its COMMIT
            $allocationId = TestRecords::payInvoice($this->invoiceId);
            checkAtCommit();
            reverseAllocation($allocationId);

            // Act & Assert
            expect(fn () => checkAtCommit())
                ->toThrow(QueryException::class, "Invoice {$this->invoiceId} cannot be paid: 0.00 paid and 3000.00 due of 3000.00");
        });

        it('refuse an allocation that leaves its invoice issued', function () {
            // Arrange
            TestRecords::paymentAllocation($this->invoiceId);

            // Act & Assert
            expect(fn () => checkAtCommit())
                ->toThrow(QueryException::class, 'cannot be issued: 1000.00 paid and 2000.00 due');
        });

        it('refuse a voided credit note that leaves its invoice paid', function () {
            // Arrange
            $creditNoteId = TestRecords::creditNote($this->invoiceId, ['amount' => 1000]);
            DB::table('credit_notes')->where('id', $creditNoteId)->update(['status' => 'approved']);
            TestRecords::payInvoice($this->invoiceId);
            checkAtCommit();
            DB::table('credit_notes')->where('id', $creditNoteId)->update(['status' => 'void']);

            // Act & Assert
            expect(fn () => checkAtCommit())
                ->toThrow(QueryException::class, 'cannot be paid: 2000.00 paid and 1000.00 due');
        });

        it('accept an allocation when the invoice moves in the same transaction', function () {
            // Arrange
            TestRecords::payInvoice($this->invoiceId, 1000);

            // Act
            checkAtCommit();

            // Assert
            expect(DB::table('invoices')->where('id', $this->invoiceId)->value('status'))->toBe('partially_paid');
        });
    });
});

describe('a refunded payment', function () {
    beforeEach(function () {
        $this->paymentId = TestRecords::payment(overrides: ['status' => 'succeeded', 'received_at' => now()]);
    });

    it('cannot be refunded while only part was given back', function () {
        // Arrange
        TestRecords::completeRefund($this->paymentId, 1000);

        // Act & Assert
        expect(fn () => DB::table('payments')->where('id', $this->paymentId)->update(['status' => 'refunded']))
            ->toThrow(QueryException::class, "Payment {$this->paymentId} cannot be refunded: 1000.00 of 3000.00 was given back");
    });

    it('cannot be refunded before the refund is completed', function () {
        // Arrange
        TestRecords::refund($this->paymentId, ['amount' => 3000]);

        // Act & Assert
        expect(fn () => DB::table('payments')->where('id', $this->paymentId)->update(['status' => 'refunded']))
            ->toThrow(QueryException::class, 'cannot be refunded: 0.00 of 3000.00 was given back');
    });

    it('is refunded once all of it was given back', function () {
        // Arrange
        TestRecords::completeRefund($this->paymentId, 1000);
        TestRecords::completeRefund($this->paymentId, 2000);

        // Act
        DB::table('payments')->where('id', $this->paymentId)->update(['status' => 'refunded']);

        // Assert
        expect(DB::table('payments')->where('id', $this->paymentId)->value('status'))->toBe('refunded');
    });
});
