<?php

/**
 * Migration group 11, part 1: payments and payment attempts (design 02 §7,
 * §16, §17, V7.7, V7.9).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * Moves a payment along the given statuses, one enforced transition at a
 * time. A succeeded payment is first given back in full before it becomes
 * refunded, as the refund action does.
 *
 * @param  list<string>  $statuses
 */
function movePayment(int $id, array $statuses): void
{
    foreach ($statuses as $status) {
        if ($status === 'refunded' && DB::table('payments')->where('id', $id)->value('status') === 'succeeded') {
            TestRecords::completeRefund($id);
        }
        DB::table('payments')->where('id', $id)->update(['status' => $status, ...($status === 'succeeded' ? ['received_at' => now()] : [])]);
    }
}

/**
 * Moves an attempt to the status, writing the given columns in the same update.
 *
 * @param  array<string, mixed>  $columns
 */
function moveAttempt(int $id, string $status, array $columns = []): void
{
    DB::table('payment_attempts')->where('id', $id)->update(['status' => $status, ...$columns]);
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
        ['payments', ['payments', 'financial', 'protected', 'forbidden', true, 'initiated']],
        ['payment_attempts', ['payments', 'financial', 'protected', 'forbidden', true, 'initiated']],
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
        'payments' => ['payments', fn () => TestRecords::payment()],
        'payment attempts' => ['payment_attempts', fn () => TestRecords::paymentAttempt()],
    ]);
});

describe('payments', function () {
    it('start as initiated', function () {
        // Arrange
        $id = TestRecords::payment();

        // Act
        $status = DB::table('payments')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('initiated');
    });

    it('refuse an amount that is not positive', function (int $amount) {
        // Arrange
        $row = ['amount' => $amount];

        // Act & Assert
        expect(fn () => TestRecords::payment(overrides: $row))
            ->toThrow(QueryException::class, 'ck_payments_amount');
    })->with([0, -100]);

    it('refuse a number that is already used', function () {
        // Arrange
        TestRecords::payment(overrides: ['payment_no' => 'PAY-2026-0001']);

        // Act & Assert
        expect(fn () => TestRecords::payment(overrides: ['payment_no' => 'PAY-2026-0001']))
            ->toThrow(QueryException::class, 'payments_payment_no_unique');
    });

    it('refuse a method that is not in the payment method catalogue', function () {
        // Arrange
        $row = ['method' => 'cheque'];

        // Act & Assert
        expect(fn () => TestRecords::payment(overrides: $row))
            ->toThrow(QueryException::class, 'fk_payments_method_catalog');
    });

    it('accept every method of the catalogue', function () {
        // Arrange
        $methods = DB::table('payment_method_catalog')->pluck('code');

        // Act
        $ids = $methods->map(fn (string $method) => TestRecords::payment(overrides: ['method' => $method]));

        // Assert
        expect(DB::table('payments')->whereIn('id', $ids)->pluck('method')->sort()->values()->all())
            ->toBe($methods->sort()->values()->all());
    });

    it('have a key of id and building for rows that must stay in its building', function () {
        // Arrange: migration group 11 has run

        // Act
        $definition = DB::scalar("select pg_get_constraintdef(oid) from pg_constraint where conname = 'uq_payment_id_building'");

        // Assert
        expect($definition)->toBe('UNIQUE (id, building_id)');
    });

    describe('status', function () {
        it('may be recorded as succeeded at once, like cash received at the desk', function () {
            // Arrange
            $row = ['status' => 'succeeded', 'received_at' => now()];

            // Act
            $id = TestRecords::payment(overrides: $row);

            // Assert
            expect(DB::table('payments')->where('id', $id)->value('status'))->toBe('succeeded');
        });

        it('cannot be recorded as failed, reversed or refunded', function (string $status) {
            // Arrange
            $row = ['status' => $status];

            // Act & Assert
            expect(fn () => TestRecords::payment(overrides: $row))
                ->toThrow(QueryException::class, "Status \"{$status}\" is not an initial status for payments");
        })->with(['failed', 'reversed', 'refunded']);

        it('succeed after waiting for the provider', function () {
            // Arrange
            $id = TestRecords::payment();

            // Act
            movePayment($id, ['pending', 'succeeded']);

            // Assert
            expect(DB::table('payments')->where('id', $id)->value('status'))->toBe('succeeded');
        });

        it('may be reversed or refunded once succeeded', function (string $status) {
            // Arrange
            $id = TestRecords::payment();
            movePayment($id, ['succeeded']);

            // Act
            movePayment($id, [$status]);

            // Assert
            expect(DB::table('payments')->where('id', $id)->value('status'))->toBe($status);
        })->with(['reversed', 'refunded']);

        it('cannot succeed once failed', function () {
            // Arrange
            $id = TestRecords::payment();
            movePayment($id, ['failed']);

            // Act & Assert
            expect(fn () => movePayment($id, ['succeeded']))
                ->toThrow(QueryException::class, 'Status transition failed -> succeeded is not allowed');
        });

        it('cannot be refunded before it succeeded', function () {
            // Arrange
            $id = TestRecords::payment();

            // Act & Assert
            expect(fn () => movePayment($id, ['refunded']))
                ->toThrow(QueryException::class, 'Status transition initiated -> refunded is not allowed');
        });
    });
});

describe('payment attempts', function () {
    it('start as initiated', function () {
        // Arrange
        $id = TestRecords::paymentAttempt();

        // Act
        $status = DB::table('payment_attempts')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('initiated');
    });

    it('refuse an amount that is not positive', function () {
        // Arrange
        $row = ['amount' => 0];

        // Act & Assert
        expect(fn () => TestRecords::paymentAttempt(overrides: $row))
            ->toThrow(QueryException::class, 'ck_payment_attempts_amount');
    });

    it('refuse a provider that is not registered', function () {
        // Arrange
        $row = ['provider' => 'paypal'];

        // Act & Assert
        expect(fn () => TestRecords::paymentAttempt(overrides: $row))
            ->toThrow(QueryException::class, 'fk_payment_attempt_provider');
    });

    it('refuse an idempotency key that is already used', function () {
        // Arrange
        $key = '0195f2a4-7c1e-7b3a-9d2f-4e6a8b0c1d2e';
        TestRecords::paymentAttempt(overrides: ['idempotency_key' => $key]);

        // Act & Assert
        expect(fn () => TestRecords::paymentAttempt(overrides: ['idempotency_key' => $key]))
            ->toThrow(QueryException::class, 'payment_attempts_idempotency_key_unique');
    });

    it('refuse a provider transaction id that the provider already reported', function () {
        // Arrange
        TestRecords::paymentAttempt(overrides: ['provider_transaction_id' => 'TRX9A8B7C6D']);

        // Act & Assert
        expect(fn () => TestRecords::paymentAttempt(overrides: ['provider_transaction_id' => 'TRX9A8B7C6D']))
            ->toThrow(QueryException::class, 'payment_attempts_provider_provider_transaction_id_unique');
    });

    it('accept the same transaction id from another provider', function () {
        // Arrange
        TestRecords::paymentAttempt(overrides: ['provider_transaction_id' => 'TRX9A8B7C6D']);

        // Act
        $id = TestRecords::paymentAttempt(overrides: ['provider' => 'nagad', 'provider_transaction_id' => 'TRX9A8B7C6D']);

        // Assert
        expect(DB::table('payment_attempts')->where('id', $id)->value('provider'))->toBe('nagad');
    });

    it('accept several attempts that have no transaction id yet', function () {
        // Arrange
        $paymentId = TestRecords::payment();
        TestRecords::paymentAttempt($paymentId);

        // Act
        TestRecords::paymentAttempt($paymentId);

        // Assert
        expect(DB::table('payment_attempts')->where('payment_id', $paymentId)->count())->toBe(2);
    });

    it('start with empty provider metadata', function () {
        // Arrange
        $id = TestRecords::paymentAttempt();

        // Act
        $metadata = DB::table('payment_attempts')->where('id', $id)->value('provider_metadata');

        // Assert
        expect($metadata)->toBe('{}');
    });
});

describe('who collects a payment (D47)', function () {
    beforeEach(function () {
        $this->buildingId = TestRecords::building();
        $this->ownerId = (int) DB::table('buildings')->where('id', $this->buildingId)->value('tenant_organization_id');
        $this->vendorId = (int) DB::table('building_vendor_assignments')
            ->where('id', TestRecords::vendorAssignment($this->buildingId, '2026-01-01'))->value('vendor_organization_id');
    });

    it('refuse an unknown billing mode', function () {
        // Arrange
        $row = ['billing_mode' => 'resale'];

        // Act & Assert
        expect(fn () => TestRecords::payment($this->buildingId, $row))
            ->toThrow(QueryException::class, 'ck_payments_billing_mode');
    });

    describe('for the owner\'s internal cost allocation', function () {
        it('are collected by the building\'s owner organization', function () {
            // Arrange
            $row = ['billing_mode' => 'internal_cost_allocation', 'payee_organization_id' => $this->ownerId];

            // Act
            $id = TestRecords::payment($this->buildingId, $row);

            // Assert
            expect(DB::table('payments')->where('id', $id)->value('payee_organization_id'))->toBe($this->ownerId);
        });

        it('refuse the gas vendor as collector', function () {
            // Arrange
            $row = ['billing_mode' => 'internal_cost_allocation', 'payee_organization_id' => $this->vendorId];

            // Act & Assert
            expect(fn () => TestRecords::payment($this->buildingId, $row))
                ->toThrow(QueryException::class, "only its owner organization {$this->ownerId} may collect it");
        });
    });

    describe('for authorized gas billing', function () {
        it('are collected by a gas vendor of the building', function () {
            // Arrange
            $row = ['billing_mode' => 'authorized_meter_billing', 'payee_organization_id' => $this->vendorId];

            // Act
            $id = TestRecords::payment($this->buildingId, $row);

            // Assert
            expect(DB::table('payments')->where('id', $id)->value('payee_organization_id'))->toBe($this->vendorId);
        });

        it('may still be collected by a vendor whose assignment has ended', function () {
            // Arrange
            DB::table('building_vendor_assignments')->where('vendor_organization_id', $this->vendorId)
                ->update(['valid_to' => '2026-06-30', 'status' => 'ended']);
            $row = ['billing_mode' => 'authorized_meter_billing', 'payee_organization_id' => $this->vendorId];

            // Act
            $id = TestRecords::payment($this->buildingId, $row);

            // Assert
            expect(DB::table('payments')->where('id', $id)->value('payee_organization_id'))->toBe($this->vendorId);
        });

        it('refuse an organization assigned to the building for something other than gas', function () {
            // Arrange
            $contractorId = TestRecords::organization();
            TestRecords::vendorAssignment($this->buildingId, '2026-01-01', ['vendor_organization_id' => $contractorId, 'assignment_type' => 'meter_maintenance']);
            $row = ['billing_mode' => 'authorized_meter_billing', 'payee_organization_id' => $contractorId];

            // Act & Assert
            expect(fn () => TestRecords::payment($this->buildingId, $row))
                ->toThrow(QueryException::class, "not organization {$contractorId}");
        });

        it('refuse an organization that is not a gas vendor of the building', function (string $who) {
            // Arrange
            $payee = $who === 'owner' ? $this->ownerId : TestRecords::organization();
            $row = ['billing_mode' => 'authorized_meter_billing', 'payee_organization_id' => $payee];

            // Act & Assert
            expect(fn () => TestRecords::payment($this->buildingId, $row))
                ->toThrow(QueryException::class, "is collected by a gas vendor of building {$this->buildingId}, not organization {$payee}");
        })->with(['owner', 'another organization']);
    });

    it('cannot later move to a collector that does not fit', function () {
        // Arrange
        $id = TestRecords::payment($this->buildingId);

        // Act & Assert
        expect(fn () => DB::table('payments')->where('id', $id)->update(['payee_organization_id' => $this->vendorId]))
            ->toThrow(QueryException::class, 'only its owner organization');
    });
});

describe('the customer of a payment (D47)', function () {
    it('refuse a customer of an organization without access to the building', function () {
        // Arrange
        $row = ['customer_id' => TestRecords::customer()];

        // Act & Assert
        expect(fn () => TestRecords::payment(overrides: $row))
            ->toThrow(QueryException::class, 'belongs to an organization without access to building');
    });

    it('accept a customer of an organization with a grant in the building', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $granteeId = TestRecords::organization();
        TestRecords::accessGrant($buildingId, $granteeId);
        $customerId = TestRecords::customer($granteeId);

        // Act
        $id = TestRecords::payment($buildingId, ['customer_id' => $customerId]);

        // Assert
        expect(DB::table('payments')->where('id', $id)->value('customer_id'))->toBe($customerId);
    });
});

describe('payment statuses and their children (D47)', function () {
    it('take new attempts while initiated or pending', function (array $statuses) {
        // Arrange
        $paymentId = TestRecords::payment();
        movePayment($paymentId, $statuses);

        // Act
        TestRecords::paymentAttempt($paymentId);

        // Assert
        expect(DB::table('payment_attempts')->where('payment_id', $paymentId)->count())->toBe(1);
    })->with(['initiated' => [[]], 'pending' => [['pending']]]);

    it('take no new attempt once decided', function (array $statuses, string $status) {
        // Arrange
        $paymentId = TestRecords::payment();
        movePayment($paymentId, $statuses);

        // Act & Assert
        expect(fn () => TestRecords::paymentAttempt($paymentId))
            ->toThrow(QueryException::class, "payments {$paymentId} is {$status}");
    })->with([
        'succeeded' => [['succeeded'], 'succeeded'],
        'failed' => [['failed'], 'failed'],
        'reversed' => [['succeeded', 'reversed'], 'reversed'],
        'refunded' => [['succeeded', 'refunded'], 'refunded'],
    ]);

    it('cannot be reversed or refunded while it still settles an invoice', function (string $status) {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        $paymentId = TestRecords::receivedPayment($invoiceId);
        TestRecords::paymentAllocation($invoiceId, $paymentId);

        // Act & Assert
        expect(fn () => movePayment($paymentId, [$status]))
            ->toThrow(QueryException::class, "payments {$paymentId} cannot become {$status}: 1 of its payment_allocations are active (must be reversed)");
    })->with(['reversed', 'refunded']);

    it('can be reversed once its allocations are reversed', function () {
        // Arrange
        $invoiceId = TestRecords::issuedInvoice();
        $paymentId = TestRecords::receivedPayment($invoiceId);
        $allocationId = TestRecords::paymentAllocation($invoiceId, $paymentId);
        DB::table('payment_allocations')->where('id', $allocationId)->update(['status' => 'reversed']);

        // Act
        movePayment($paymentId, ['reversed']);

        // Assert
        expect(DB::table('payments')->where('id', $paymentId)->value('status'))->toBe('reversed');
    });
});

describe('payment attempts follow their payment (D47)', function () {
    it('refuse an amount or currency other than the payment\'s', function (array $row) {
        // Act & Assert
        expect(fn () => TestRecords::paymentAttempt(overrides: $row))
            ->toThrow(QueryException::class, 'fk_payment_attempt_payment_amount');
    })->with([
        'amount' => [['amount' => 2999]],
        'currency' => [['currency' => 'USD']],
    ]);

    it('succeed after waiting, with what the provider confirmed', function () {
        // Arrange
        $id = TestRecords::paymentAttempt();
        moveAttempt($id, 'pending');

        // Act
        moveAttempt($id, 'succeeded', ['provider_transaction_id' => 'TRX9A8B7C6D', 'verified_at' => now()]);

        // Assert
        expect(DB::table('payment_attempts')->where('id', $id)->value('status'))->toBe('succeeded');
    });

    it('cannot succeed without the provider transaction and verification', function (array $columns) {
        // Arrange
        $id = TestRecords::paymentAttempt();

        // Act & Assert
        expect(fn () => moveAttempt($id, 'succeeded', $columns))
            ->toThrow(QueryException::class, 'ck_payment_attempts_succeeded');
    })->with([
        'no transaction' => [['verified_at' => '2026-11-16 10:00:00+06']],
        'no verification' => [['provider_transaction_id' => 'TRX1']],
    ]);

    it('cannot change once decided', function (string $status, string $next) {
        // Arrange
        $id = TestRecords::paymentAttempt();
        moveAttempt($id, $status, ['provider_transaction_id' => 'TRX1', 'verified_at' => now()]);

        // Act & Assert
        expect(fn () => moveAttempt($id, $next))
            ->toThrow(QueryException::class, "Status transition {$status} -> {$next} is not allowed");
    })->with([
        'succeeded back to initiated' => ['succeeded', 'initiated'],
        'succeeded to failed' => ['succeeded', 'failed'],
        'failed to succeeded' => ['failed', 'succeeded'],
        'expired to pending' => ['expired', 'pending'],
    ]);

    it('keep what the provider confirmed once decided', function (string $column, string $value) {
        // Arrange
        $id = TestRecords::paymentAttempt();
        moveAttempt($id, 'succeeded', ['provider_transaction_id' => 'TRX1', 'verified_at' => now()]);

        // Act & Assert
        expect(fn () => DB::table('payment_attempts')->where('id', $id)->update([$column => $value]))
            ->toThrow(QueryException::class, "payment_attempts {$id} is succeeded: {$column} can no longer change");
    })->with([
        'transaction' => ['provider_transaction_id', 'TRX2'],
        'verification' => ['verified_at', '2026-01-01 10:00:00+06'],
        'idempotency key' => ['idempotency_key', '0195f2a4-7c1e-7b3a-9d2f-4e6a8b0c1d2e'],
        'request reference' => ['request_reference', 'REQ-2'],
        'provider' => ['provider', 'nagad'],
    ]);

    it('still take more provider metadata once decided', function () {
        // Arrange
        $id = TestRecords::paymentAttempt();
        moveAttempt($id, 'succeeded', ['provider_transaction_id' => 'TRX1', 'verified_at' => now()]);

        // Act
        DB::table('payment_attempts')->where('id', $id)->update(['provider_metadata' => '{"settled":true}']);

        // Assert
        expect(DB::table('payment_attempts')->where('id', $id)->value('provider_metadata'))->toBe('{"settled": true}');
    });
});

describe('a received payment keeps its facts (D47)', function () {
    it('cannot be received without a receive time', function () {
        // Arrange
        $id = TestRecords::payment();

        // Act & Assert
        expect(fn () => DB::table('payments')->where('id', $id)->update(['status' => 'succeeded']))
            ->toThrow(QueryException::class, 'ck_payments_received');
    });

    it('may still be corrected while initiated or pending', function (array $statuses) {
        // Arrange
        $id = TestRecords::payment();
        movePayment($id, $statuses);

        // Act
        DB::table('payments')->where('id', $id)->update(['amount' => 2500, 'method' => 'bkash']);

        // Assert
        expect(DB::table('payments')->where('id', $id)->value('amount'))->toBe('2500.00');
    })->with(['initiated' => [[]], 'pending' => [['pending']]]);

    it('keep what was received once succeeded', function (string $column, Closure $value) {
        // Arrange
        $id = TestRecords::payment(overrides: ['status' => 'succeeded']);

        // Act & Assert
        expect(fn () => DB::table('payments')->where('id', $id)->update([$column => $value($id)]))
            ->toThrow(QueryException::class, "payments {$id} is succeeded: {$column} can no longer change");
    })->with([
        'amount' => ['amount', fn () => 2500],
        'currency' => ['currency', fn () => 'USD'],
        'method' => ['method', fn () => 'bkash'],
        'number' => ['payment_no', fn () => 'PAY-CHANGED'],
        'public id' => ['public_id', fn () => '01jcf3k8m2n4p6q8r0s2t4v6w8'],
        'customer' => ['customer_id', fn (int $id) => TestRecords::customer(
            (int) DB::table('payments')->where('id', $id)->value('payee_organization_id'),
        )],
        'receive time' => ['received_at', fn () => '2020-01-01 10:00:00+06'],
        'receiver' => ['received_by', fn () => TestRecords::user()],
        'building' => ['building_id', fn () => TestRecords::building()],
        'billing mode' => ['billing_mode', fn () => 'authorized_meter_billing'],
        'collector' => ['payee_organization_id', fn () => TestRecords::organization()],
    ]);

    it('still take notes once succeeded', function () {
        // Arrange
        $id = TestRecords::payment(overrides: ['status' => 'succeeded']);

        // Act
        DB::table('payments')->where('id', $id)->update(['notes' => 'Paid by the flat owner for the tenant']);

        // Assert
        expect(DB::table('payments')->where('id', $id)->value('notes'))->toBe('Paid by the flat owner for the tenant');
    });

    it('freeze every column but its notes and status', function () {
        // Arrange: migration 000053 has run
        $free = ['id', 'notes', 'status', 'is_active', 'is_deleted', 'deleted_at', 'created_at', 'updated_at', 'created_by', 'updated_by', 'created_session_id', 'updated_session_id'];

        // Act
        $frozen = (string) DB::scalar("select pg_get_triggerdef(oid) from pg_trigger where tgname = 'trg_payments_freeze_received'");
        $columns = collect(DB::select("select column_name from information_schema.columns where table_name = 'payments'"))
            ->pluck('column_name')->diff($free);

        // Assert
        expect($columns->reject(fn (string $column) => str_contains($frozen, "'{$column}'"))->values()->all())->toBe([]);
    });
});
