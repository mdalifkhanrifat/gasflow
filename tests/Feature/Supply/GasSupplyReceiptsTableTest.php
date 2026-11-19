<?php

/**
 * Migration group 13, part 5: upstream gas supply receipts (design 02 V7.4,
 * V7.7, V7.9, V7.17).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * Moves a receipt along the given statuses, one enforced transition at a time.
 *
 * @param  list<string>  $statuses
 */
function moveReceipt(int $id, array $statuses): void
{
    foreach ($statuses as $status) {
        DB::table('gas_supply_receipts')->where('id', $id)->update(['status' => $status]);
    }
}

describe('lifecycle registration', function () {
    it('registers the table as a protected supply record with enforced transitions', function () {
        // Arrange: migration 2026_11_19_000066 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', 'gas_supply_receipts')->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe(['supply', 'financial', 'protected', 'forbidden', true, 'draft']);
    });

    it('can never delete or hide a receipt', function () {
        // Arrange
        $id = TestRecords::gasSupplyReceipt();

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('gas_supply_receipts')->where('id', $id)->delete()))
            ->toThrow(QueryException::class, 'Records in gas_supply_receipts are protected');
    });
});

describe('gas supply receipts', function () {
    it('start as a draft in cubic metres and taka', function () {
        // Arrange
        $id = TestRecords::gasSupplyReceipt();

        // Act
        $row = DB::table('gas_supply_receipts')->where('id', $id)->first();

        // Assert
        expect([$row->status, $row->measurement_unit, $row->currency])->toBe(['draft', 'm3', 'BDT']);
    });

    it('refuse a wrong amount', function (array $row, string $constraint) {
        // Arrange: a new vendor assignment

        // Act & Assert
        expect(fn () => TestRecords::gasSupplyReceipt(overrides: $row))->toThrow(QueryException::class, $constraint);
    })->with([
        'no quantity' => [['quantity' => 0], 'ck_gas_supply_receipts_quantity'],
        'negative unit cost' => [['unit_cost' => -1], 'ck_gas_supply_receipts_unit_cost'],
        'negative total' => [['total_cost' => -1], 'ck_gas_supply_receipts_total_cost'],
    ]);

    it('refuse a supply number that the building already has', function () {
        // Arrange
        $assignmentId = TestRecords::vendorAssignment(TestRecords::building(), '2026-01-01');
        TestRecords::gasSupplyReceipt($assignmentId, ['supply_no' => 'GS-1']);

        // Act & Assert
        expect(fn () => TestRecords::gasSupplyReceipt($assignmentId, ['supply_no' => 'GS-1']))
            ->toThrow(QueryException::class, 'gas_supply_receipts_building_id_supply_no_unique');
    });

    it('let another building use the same supply number', function () {
        // Arrange
        TestRecords::gasSupplyReceipt(overrides: ['supply_no' => 'GS-1']);

        // Act
        $id = TestRecords::gasSupplyReceipt(overrides: ['supply_no' => 'GS-1']);

        // Assert
        expect(DB::table('gas_supply_receipts')->where('id', $id)->value('supply_no'))->toBe('GS-1');
    });

    it('refuse a vendor assignment of another building or vendor', function (Closure $change) {
        // Arrange
        $row = $change();

        // Act & Assert
        expect(fn () => TestRecords::gasSupplyReceipt(overrides: $row))
            ->toThrow(QueryException::class, 'fk_gas_supply_receipt_vendor_assignment');
    })->with([
        'other building' => [fn () => ['building_id' => TestRecords::building()]],
        'other vendor' => [fn () => ['vendor_organization_id' => TestRecords::organization()]],
    ]);

    it('may name a supply change of its building', function () {
        // Arrange
        $assignmentId = TestRecords::vendorAssignment(TestRecords::building(), '2026-01-01');
        $changeId = TestRecords::supplyChange(TestRecords::approvedTariffVersion($assignmentId), '2026-10-05');

        // Act
        $id = TestRecords::gasSupplyReceipt($assignmentId, ['supply_change_id' => $changeId]);

        // Assert
        expect(DB::table('gas_supply_receipts')->where('id', $id)->value('supply_change_id'))->toBe($changeId);
    });

    it('refuse a supply change of another building', function () {
        // Arrange
        $otherAssignmentId = TestRecords::vendorAssignment(TestRecords::building(), '2026-01-01');
        $changeId = TestRecords::supplyChange(TestRecords::approvedTariffVersion($otherAssignmentId), '2026-10-05');

        // Act & Assert
        expect(fn () => TestRecords::gasSupplyReceipt(overrides: ['supply_change_id' => $changeId]))
            ->toThrow(QueryException::class, 'fk_gas_supply_receipt_change');
    });
});

describe('receipt statuses', function () {
    it('reach verified through recorded', function () {
        // Arrange
        $id = TestRecords::gasSupplyReceipt();

        // Act
        moveReceipt($id, ['recorded', 'verified']);

        // Assert
        expect(DB::table('gas_supply_receipts')->where('id', $id)->value('status'))->toBe('verified');
    });

    it('refuse a move the design does not allow', function (array $before, string $to) {
        // Arrange
        $id = TestRecords::gasSupplyReceipt();
        moveReceipt($id, $before);

        // Act & Assert
        expect(fn () => moveReceipt($id, [$to]))->toThrow(QueryException::class, 'is not allowed');
    })->with([
        'verify a draft' => [[], 'verified'],
        'cancel a verified receipt' => [['recorded', 'verified'], 'cancelled'],
        'back to draft' => [['recorded'], 'draft'],
    ]);

    it('may cancel a draft or a recorded receipt', function (array $before) {
        // Arrange
        $id = TestRecords::gasSupplyReceipt();
        moveReceipt($id, $before);

        // Act
        moveReceipt($id, ['cancelled']);

        // Assert
        expect(DB::table('gas_supply_receipts')->where('id', $id)->value('status'))->toBe('cancelled');
    })->with([
        'draft' => [[]],
        'recorded' => [['recorded']],
    ]);
});

describe('what a receipt bought', function () {
    it('costs its quantity times its unit cost', function () {
        // Arrange
        $row = ['quantity' => 1000, 'unit_cost' => 40, 'total_cost' => 39999];

        // Act & Assert
        expect(fn () => TestRecords::gasSupplyReceipt(overrides: $row))->toThrow(QueryException::class, 'ck_gas_supply_receipts_value');
    });

    it('rounds the total to the paisa', function () {
        // Arrange
        $row = ['quantity' => 10.555, 'unit_cost' => 3.3333, 'total_cost' => 35.18];

        // Act
        $id = TestRecords::gasSupplyReceipt(overrides: $row);

        // Assert
        expect(DB::table('gas_supply_receipts')->where('id', $id)->value('total_cost'))->toBe('35.18');
    });

    it('is dated inside its vendor assignment', function (string $date) {
        // Arrange
        $assignmentId = TestRecords::vendorAssignment(TestRecords::building(), '2026-01-01', ['valid_to' => '2026-06-30']);

        // Act & Assert
        expect(fn () => TestRecords::gasSupplyReceipt($assignmentId, ['supply_date' => $date]))
            ->toThrow(QueryException::class, 'is outside its vendor assignment (2026-01-01 to 2026-06-30)');
    })->with(['before' => '2025-12-31', 'after' => '2026-07-01']);

    it('may be dated on the last day of its vendor assignment', function () {
        // Arrange
        $assignmentId = TestRecords::vendorAssignment(TestRecords::building(), '2026-01-01', ['valid_to' => '2026-06-30']);

        // Act
        $id = TestRecords::gasSupplyReceipt($assignmentId, ['supply_date' => '2026-06-30']);

        // Assert
        expect(DB::table('gas_supply_receipts')->where('id', $id)->value('supply_date'))->toBe('2026-06-30');
    });

    it('names a supply change of its own vendor assignment', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $earlierId = TestRecords::vendorAssignment($buildingId, '2026-01-01', ['valid_to' => '2026-05-31']);
        $laterId = TestRecords::vendorAssignment($buildingId, '2026-06-01');
        $changeId = TestRecords::supplyChange(TestRecords::approvedTariffVersion($laterId), '2026-06-01');

        // Act & Assert
        expect(fn () => TestRecords::gasSupplyReceipt($earlierId, ['supply_date' => '2026-05-20', 'supply_change_id' => $changeId]))
            ->toThrow(QueryException::class, 'fk_gas_supply_receipt_change');
    });

    it('may change while a draft', function () {
        // Arrange
        $id = TestRecords::gasSupplyReceipt();

        // Act
        DB::table('gas_supply_receipts')->where('id', $id)->update(['quantity' => 500, 'total_cost' => 20000]);

        // Assert
        expect(DB::table('gas_supply_receipts')->where('id', $id)->value('quantity'))->toBe('500.000');
    });

    it('is frozen once recorded', function (string $column, mixed $value) {
        // Arrange
        $id = TestRecords::gasSupplyReceipt();
        moveReceipt($id, ['recorded']);

        // Act & Assert
        expect(fn () => DB::table('gas_supply_receipts')->where('id', $id)->update([$column => $value]))
            ->toThrow(QueryException::class, "{$column} can no longer change");
    })->with([
        ['quantity', 1],
        ['unit_cost', 1],
        ['total_cost', 1],
        ['supply_date', '2026-10-06'],
        ['upstream_supplier_name', 'Other'],
    ]);
});
