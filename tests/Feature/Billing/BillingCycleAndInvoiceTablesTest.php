<?php

/**
 * Migration group 10, part 1: billing cycles and customer invoices (design 02
 * §6, V5 segments, V7.7, V7.9, V7.16; building tie as D42).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * Moves a row along the given statuses, one enforced transition at a time.
 *
 * @param  list<string>  $statuses
 */
function moveBillingRow(string $table, int $id, array $statuses): void
{
    foreach ($statuses as $status) {
        DB::table($table)->where('id', $id)->update(['status' => $status]);
    }
}

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, array $expected) {
        // Arrange: migration group 10 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe($expected);
    })->with([
        ['billing_cycles', ['billing', 'workflow', 'protected', 'forbidden', true, 'draft']],
        ['invoices', ['billing', 'financial', 'protected', 'forbidden', true, 'draft']],
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
        'billing cycles' => ['billing_cycles', fn () => TestRecords::billingCycle()],
        'invoices' => ['invoices', fn () => TestRecords::invoice()],
    ]);
});

describe('billing cycles', function () {
    it('start as a draft', function () {
        // Arrange
        $id = TestRecords::billingCycle();

        // Act
        $status = DB::table('billing_cycles')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('draft');
    });

    it('refuse a month outside 1 to 12', function () {
        // Arrange
        $row = ['period_month' => 13];

        // Act & Assert
        expect(fn () => TestRecords::billingCycle(overrides: $row))
            ->toThrow(QueryException::class, 'ck_billing_cycles_period_month');
    });

    it('refuse an unknown billing mode', function () {
        // Arrange
        $row = ['billing_mode' => 'flat_rate'];

        // Act & Assert
        expect(fn () => TestRecords::billingCycle(overrides: $row))
            ->toThrow(QueryException::class, 'ck_billing_cycles_billing_mode');
    });

    it('refuse an end before the start', function () {
        // Arrange
        $row = ['starts_on' => '2026-10-31', 'ends_on' => '2026-10-01'];

        // Act & Assert
        expect(fn () => TestRecords::billingCycle(overrides: $row))
            ->toThrow(QueryException::class, 'ck_billing_cycles_range');
    });

    it('refuse a second cycle for the same building and month', function () {
        // Arrange
        $periodId = TestRecords::readingPeriod();
        TestRecords::billingCycle($periodId);

        // Act & Assert
        expect(fn () => TestRecords::billingCycle($periodId))
            ->toThrow(QueryException::class, 'billing_cycles_building_id_period_year_period_month_unique');
    });

    it('refuse a reading period of another building', function () {
        // Arrange
        $row = ['reading_period_id' => TestRecords::readingPeriod()];

        // Act & Assert
        expect(fn () => TestRecords::billingCycle(overrides: $row))
            ->toThrow(QueryException::class, 'fk_billing_cycle_reading_period');
    });

    it('reach locked through calculation, review, approval and issue', function () {
        // Arrange
        $id = TestRecords::billingCycle();

        // Act
        moveBillingRow('billing_cycles', $id, ['calculating', 'review', 'approved', 'issued', 'locked']);

        // Assert
        expect(DB::table('billing_cycles')->where('id', $id)->value('status'))->toBe('locked');
    });

    it('refuse to skip the review', function () {
        // Arrange
        $id = TestRecords::billingCycle();
        moveBillingRow('billing_cycles', $id, ['calculating']);

        // Act & Assert
        expect(fn () => moveBillingRow('billing_cycles', $id, ['approved']))
            ->toThrow(QueryException::class, 'Status transition calculating -> approved is not allowed');
    });

    it('can be recalculated from review', function () {
        // Arrange
        $id = TestRecords::billingCycle();
        moveBillingRow('billing_cycles', $id, ['calculating', 'review']);

        // Act
        moveBillingRow('billing_cycles', $id, ['calculating']);

        // Assert
        expect(DB::table('billing_cycles')->where('id', $id)->value('status'))->toBe('calculating');
    });

    it('cannot be cancelled once approved', function () {
        // Arrange
        $id = TestRecords::billingCycle();
        moveBillingRow('billing_cycles', $id, ['calculating', 'review', 'approved']);

        // Act & Assert
        expect(fn () => moveBillingRow('billing_cycles', $id, ['cancelled']))
            ->toThrow(QueryException::class, 'Status transition approved -> cancelled is not allowed');
    });
});

describe('invoices', function () {
    beforeEach(function () {
        $this->cycleId = TestRecords::billingCycle();
        $this->buildingId = DB::table('billing_cycles')->where('id', $this->cycleId)->value('building_id');
    });

    it('start as a draft', function () {
        // Arrange
        $id = TestRecords::invoice($this->cycleId);

        // Act
        $status = DB::table('invoices')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('draft');
    });

    it('may leave the invoice-level tariff empty, as the lines carry it', function () {
        // Arrange
        $id = TestRecords::invoice($this->cycleId);

        // Act
        $tariffId = DB::table('invoices')->where('id', $id)->value('tariff_version_id');

        // Assert
        expect($tariffId)->toBeNull();
    });

    it('refuse an unknown billing mode', function () {
        // Arrange
        $row = ['billing_mode' => 'flat_rate'];

        // Act & Assert
        expect(fn () => TestRecords::invoice($this->cycleId, $row))
            ->toThrow(QueryException::class, 'ck_invoices_billing_mode');
    });

    it('refuse a negative consumption', function () {
        // Arrange
        $row = ['consumption' => -1];

        // Act & Assert
        expect(fn () => TestRecords::invoice($this->cycleId, $row))
            ->toThrow(QueryException::class, 'ck_invoices_consumption');
    });

    it('refuse a segment number of zero', function () {
        // Arrange
        $row = ['segment_no' => 0];

        // Act & Assert
        expect(fn () => TestRecords::invoice($this->cycleId, $row))
            ->toThrow(QueryException::class, 'ck_invoices_segment_no');
    });

    it('refuse an invoice number that is already used', function () {
        // Arrange
        TestRecords::invoice($this->cycleId, ['invoice_no' => 'INV-2026-10-0001']);

        // Act & Assert
        expect(fn () => TestRecords::invoice($this->cycleId, ['invoice_no' => 'INV-2026-10-0001']))
            ->toThrow(QueryException::class, 'invoices_invoice_no_unique');
    });

    it('refuse a billing cycle of another building', function () {
        // Arrange
        $row = ['billing_cycle_id' => TestRecords::billingCycle()];

        // Act & Assert
        expect(fn () => TestRecords::invoice($this->cycleId, $row))
            ->toThrow(QueryException::class, 'fk_invoice_billing_cycle');
    });

    it('refuse a flat of another building', function () {
        // Arrange
        $row = ['flat_id' => TestRecords::flat()];

        // Act & Assert
        expect(fn () => TestRecords::invoice($this->cycleId, $row))
            ->toThrow(QueryException::class, 'fk_invoice_flat');
    });

    it('refuse a vendor that is not the vendor of the assignment', function () {
        // Arrange
        $row = ['gas_vendor_organization_id' => TestRecords::organization()];

        // Act & Assert
        expect(fn () => TestRecords::invoice($this->cycleId, $row))
            ->toThrow(QueryException::class, 'fk_invoice_vendor_assignment');
    });

    it('refuse a tariff version of another vendor assignment', function () {
        // Arrange
        $otherAssignmentId = TestRecords::vendorAssignment(TestRecords::building(), '2026-01-01');
        $row = ['tariff_version_id' => TestRecords::tariffVersion($otherAssignmentId)];

        // Act & Assert
        expect(fn () => TestRecords::invoice($this->cycleId, $row))
            ->toThrow(QueryException::class, 'fk_invoice_tariff_version');
    });

    it('refuse a billed occupancy without its meter assignment', function () {
        // Arrange
        $flatId = TestRecords::flat($this->buildingId);
        $assignmentId = TestRecords::meterAssignment(TestRecords::meter($this->buildingId), $flatId, '2026-01-01');
        $row = [
            'flat_id' => $flatId,
            'meter_occupancy_assignment_id' => TestRecords::meterOccupancyAssignment($assignmentId, TestRecords::occupancy($flatId, '2026-01-01'), '2026-01-01'),
        ];

        // Act & Assert
        expect(fn () => TestRecords::invoice($this->cycleId, $row))
            ->toThrow(QueryException::class, 'ck_invoices_assignment_chain');
    });

    it('refuse a meter assignment of another flat', function () {
        // Arrange
        $otherFlatId = TestRecords::flat($this->buildingId);
        $row = ['meter_assignment_id' => TestRecords::meterAssignment(TestRecords::meter($this->buildingId), $otherFlatId, '2026-01-01')];

        // Act & Assert
        expect(fn () => TestRecords::invoice($this->cycleId, $row))
            ->toThrow(QueryException::class, 'fk_invoice_meter_assignment');
    });

    describe('segments', function () {
        beforeEach(function () {
            $this->flatId = TestRecords::flat($this->buildingId);
        });

        it('refuse a second invoice of a flat for the same cycle and segment', function () {
            // Arrange
            TestRecords::invoice($this->cycleId, ['flat_id' => $this->flatId]);

            // Act & Assert
            expect(fn () => TestRecords::invoice($this->cycleId, ['flat_id' => $this->flatId]))
                ->toThrow(QueryException::class, 'uq_invoice_cycle_flat_segment_without_occupancy');
        });

        it('allow one invoice per vendor segment of a flat in a cycle', function () {
            // Arrange
            TestRecords::invoice($this->cycleId, ['flat_id' => $this->flatId]);

            // Act
            TestRecords::invoice($this->cycleId, ['flat_id' => $this->flatId, 'segment_no' => 2]);

            // Assert
            expect(DB::table('invoices')->where('flat_id', $this->flatId)->count())->toBe(2);
        });

        it('refuse a second invoice of a billed occupancy for the same cycle and segment', function () {
            // Arrange
            $assignmentId = TestRecords::meterAssignment(TestRecords::meter($this->buildingId), $this->flatId, '2026-01-01');
            $row = [
                'flat_id' => $this->flatId,
                'meter_assignment_id' => $assignmentId,
                'meter_occupancy_assignment_id' => TestRecords::meterOccupancyAssignment($assignmentId, TestRecords::occupancy($this->flatId, '2026-01-01'), '2026-01-01'),
            ];
            TestRecords::invoice($this->cycleId, $row);

            // Act & Assert
            expect(fn () => TestRecords::invoice($this->cycleId, $row))
                ->toThrow(QueryException::class, 'uq_invoice_cycle_occupancy_segment');
        });
    });

    describe('status', function () {
        it('reach paid through approval and issue', function () {
            // Arrange
            $id = TestRecords::invoice($this->cycleId);

            // Act
            moveBillingRow('invoices', $id, ['approved', 'issued', 'partially_paid', 'paid']);

            // Assert
            expect(DB::table('invoices')->where('id', $id)->value('status'))->toBe('paid');
        });

        it('refuse to be issued without approval', function () {
            // Arrange
            $id = TestRecords::invoice($this->cycleId);

            // Act & Assert
            expect(fn () => moveBillingRow('invoices', $id, ['issued']))
                ->toThrow(QueryException::class, 'Status transition draft -> issued is not allowed');
        });

        it('cannot return to draft once approved', function () {
            // Arrange
            $id = TestRecords::invoice($this->cycleId);
            moveBillingRow('invoices', $id, ['approved']);

            // Act & Assert
            expect(fn () => moveBillingRow('invoices', $id, ['draft']))
                ->toThrow(QueryException::class, 'Status transition approved -> draft is not allowed');
        });

        it('cannot be voided once issued', function () {
            // Arrange
            $id = TestRecords::invoice($this->cycleId);
            moveBillingRow('invoices', $id, ['approved', 'issued']);

            // Act & Assert
            expect(fn () => moveBillingRow('invoices', $id, ['void']))
                ->toThrow(QueryException::class, 'Status transition issued -> void is not allowed');
        });

        it('can be written off once overdue', function () {
            // Arrange
            $id = TestRecords::invoice($this->cycleId);
            moveBillingRow('invoices', $id, ['approved', 'issued', 'overdue']);

            // Act
            moveBillingRow('invoices', $id, ['written_off']);

            // Assert
            expect(DB::table('invoices')->where('id', $id)->value('status'))->toBe('written_off');
        });
    });
});

describe('invoice amounts', function () {
    beforeEach(function () {
        $this->cycleId = TestRecords::billingCycle();
    });

    it('refuse a total that is not subtotal plus tax minus discount and credit', function () {
        // Arrange
        $row = ['subtotal' => 3000, 'tax_total' => 150, 'discount_total' => 100, 'credit_total' => 50, 'total' => 3100];

        // Act & Assert
        expect(fn () => TestRecords::invoice($this->cycleId, $row))
            ->toThrow(QueryException::class, 'ck_invoices_total_sum');
    });

    it('accept a total that is subtotal plus tax minus discount and credit', function () {
        // Arrange
        $row = ['subtotal' => 3000, 'tax_total' => 150, 'discount_total' => 100, 'credit_total' => 50, 'total' => 3000];

        // Act
        $id = TestRecords::invoice($this->cycleId, $row);

        // Assert
        expect(DB::table('invoices')->where('id', $id)->value('total'))->toBe('3000.00');
    });

    it('refuse a negative discount or credit, which would add to the total', function (string $column, string $constraint) {
        // Arrange
        $row = [$column => -100, 'total' => 3100];

        // Act & Assert
        expect(fn () => TestRecords::invoice($this->cycleId, $row))
            ->toThrow(QueryException::class, $constraint);
    })->with([
        ['discount_total', 'ck_invoices_discount_total'],
        ['credit_total', 'ck_invoices_credit_total'],
    ]);

    it('let a draft change what it bills', function () {
        // Arrange
        $id = TestRecords::invoice($this->cycleId);

        // Act
        DB::table('invoices')->where('id', $id)->update(['consumption' => 120, 'subtotal' => 3600, 'total' => 3600]);

        // Assert
        expect(DB::table('invoices')->where('id', $id)->value('total'))->toBe('3600.00');
    });

    it('let a draft get its final amounts in the update that approves it', function () {
        // Arrange
        $id = TestRecords::invoice($this->cycleId);

        // Act
        DB::table('invoices')->where('id', $id)->update(['status' => 'approved', 'subtotal' => 3600, 'total' => 3600]);

        // Assert
        expect(DB::table('invoices')->where('id', $id)->first())
            ->status->toBe('approved')
            ->total->toBe('3600.00');
    });

    it('freeze what an approved invoice bills', function (Closure $change) {
        // Arrange
        $id = TestRecords::invoice($this->cycleId);
        moveBillingRow('invoices', $id, ['approved']);

        // Act & Assert
        expect(fn () => DB::table('invoices')->where('id', $id)->update($change()))
            ->toThrow(QueryException::class, 'can no longer change');
    })->with([
        'amounts' => fn () => ['subtotal' => 2000, 'total' => 2000],
        'discount' => fn () => ['discount_total' => 100, 'total' => 2900],
        'credit' => fn () => ['credit_total' => 100, 'total' => 2900],
        'tax' => fn () => ['tax_total' => 150, 'total' => 3150],
        'number' => fn () => ['invoice_no' => 'INV-CHANGED'],
        'currency' => fn () => ['currency' => 'USD'],
        'consumption' => fn () => ['consumption' => 120],
        'readings' => fn () => ['previous_reading' => 10, 'current_reading' => 110],
        'customer' => fn () => ['customer_id' => TestRecords::customer()],
        'customer name' => fn () => ['customer_name_snapshot' => 'Someone else'],
        'vendor name' => fn () => ['gas_vendor_name_snapshot' => 'Other Gas Ltd'],
        'tariff snapshot' => fn () => ['tariff_rate_snapshot' => 35],
        'segment' => fn () => ['segment_no' => 2],
    ]);

    it('freeze every column of an approved invoice except its status, due date and issue time', function () {
        // Arrange
        $mayChange = ['id', 'status', 'due_at', 'issued_at', 'is_active', 'is_deleted', 'deleted_at', 'created_at', 'updated_at',
            'created_by', 'updated_by', 'created_session_id', 'updated_session_id'];
        $columns = DB::table('information_schema.columns')->where('table_name', 'invoices')->pluck('column_name')->all();

        // Act
        $definition = DB::selectOne(
            "select pg_get_triggerdef(oid) as definition from pg_trigger where tgname = 'trg_invoices_freeze_billed'",
        )->definition;
        preg_match_all("/'([a-z_]+)'/", $definition, $arguments);

        // Assert
        expect(array_slice($arguments[1], 1))->toEqualCanonicalizing(array_values(array_diff($columns, $mayChange)));
    });

    it('set the issue time once, when the approved invoice is issued', function () {
        // Arrange
        $id = TestRecords::invoice($this->cycleId);
        moveBillingRow('invoices', $id, ['approved']);

        // Act
        DB::table('invoices')->where('id', $id)->update(['status' => 'issued', 'issued_at' => '2026-11-01 10:00:00+06']);

        // Assert
        expect(DB::table('invoices')->where('id', $id)->value('status'))->toBe('issued');
    });

    it('keep the issue time of an issued invoice', function () {
        // Arrange
        $id = TestRecords::invoice($this->cycleId);
        moveBillingRow('invoices', $id, ['approved']);
        DB::table('invoices')->where('id', $id)->update(['status' => 'issued', 'issued_at' => '2026-11-01 10:00:00+06']);

        // Act & Assert
        expect(fn () => DB::table('invoices')->where('id', $id)->update(['issued_at' => '2026-11-05 10:00:00+06']))
            ->toThrow(QueryException::class, 'issued_at can no longer change');
    });

    it('still let an issued invoice be paid or given more time', function () {
        // Arrange
        $id = TestRecords::invoice($this->cycleId);
        moveBillingRow('invoices', $id, ['approved', 'issued']);

        // Act
        DB::table('invoices')->where('id', $id)->update(['status' => 'paid', 'due_at' => '2026-12-31 23:59:59+06']);

        // Assert
        expect(DB::table('invoices')->where('id', $id)->value('status'))->toBe('paid');
    });
});
