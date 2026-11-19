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
