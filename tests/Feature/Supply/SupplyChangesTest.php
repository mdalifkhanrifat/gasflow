<?php

/**
 * The gas supply change register (design V7.17, D45): supply_changes and
 * supply_change_readings.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * An active rate of the vendor assignment from the given day.
 */
function activeRate(int $assignmentId, string $from, float $rate, ?string $to = null): int
{
    $id = TestRecords::tariffVersion($assignmentId, $from, ['rate' => $rate, 'effective_to' => $to]);
    DB::table('tariff_versions')->where('id', $id)->update(['status' => 'approved']);
    DB::table('tariff_versions')->where('id', $id)->update(['status' => 'active']);

    return $id;
}

beforeEach(function () {
    $this->buildingId = TestRecords::building();
    $this->assignmentId = TestRecords::vendorAssignment($this->buildingId, '2026-08-01');
    $this->tariffId = activeRate($this->assignmentId, '2026-08-01', 50);
    $this->mainMeterId = TestRecords::meter($this->buildingId, ['meter_type' => 'main']);
});

describe('lifecycle registration', function () {
    it('registers both tables as protected supply history', function (string $table, bool $enforced, string $defaultStatus) {
        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe(['supply', 'history', 'protected', $enforced, $defaultStatus]);
    })->with([
        ['supply_changes', true, 'recorded'],
        ['supply_change_readings', false, 'active'],
    ]);
});

describe('supply changes', function () {
    it('record the moment, the vendor and the price of a change', function () {
        // Act
        $id = TestRecords::supplyChange($this->tariffId, '2026-08-14');

        // Assert
        expect(DB::table('supply_changes')->where('id', $id)->first(['status', 'tariff_version_id']))
            ->toEqual((object) ['status' => 'recorded', 'tariff_version_id' => $this->tariffId]);
    });

    it('refuse a price of another vendor', function () {
        // Arrange
        $otherAssignmentId = TestRecords::vendorAssignment(TestRecords::building(), '2026-08-01');
        $row = ['building_vendor_assignment_id' => $otherAssignmentId];

        // Act & Assert
        expect(fn () => TestRecords::supplyChange($this->tariffId, '2026-08-14', $row))
            ->toThrow(QueryException::class, 'fk_supply_change_tariff');
    });

    it('take effect on the building day of the change or the next one', function () {
        // Arrange: 23:30 in Dhaka on 14 August
        $row = ['changed_at' => '2026-08-14 23:30:00+06'];

        // Act
        $id = TestRecords::supplyChange($this->tariffId, '2026-08-15', $row);

        // Assert
        expect(DB::table('supply_changes')->where('id', $id)->value('effective_on'))->toBe('2026-08-15');
    });

    it('use the building time zone for the day of the change', function () {
        // Arrange: 20:00 UTC on 14 August is 02:00 on 15 August in Dhaka
        $row = ['changed_at' => '2026-08-14 20:00:00+00'];

        // Act & Assert
        expect(fn () => TestRecords::supplyChange($this->tariffId, '2026-08-14', $row))
            ->toThrow(QueryException::class, 'takes effect that day or the next, not on 2026-08-14');
    });

    it('refuse a price that is not approved on that day', function () {
        // Arrange
        $draftId = TestRecords::tariffVersion($this->assignmentId, '2026-08-20', ['tariff_code' => 'BULK']);

        // Act & Assert
        expect(fn () => TestRecords::supplyChange($draftId, '2026-08-20'))
            ->toThrow(QueryException::class, "Tariff version {$draftId} is not an approved rate on 2026-08-20");
    });

    it('refuse a vendor whose assignment has ended by that day', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $assignmentId = TestRecords::vendorAssignment($buildingId, '2026-08-01', ['valid_to' => '2026-08-15', 'status' => 'ended']);
        $tariffId = activeRate($assignmentId, '2026-08-01', 50);

        // Act & Assert
        expect(fn () => TestRecords::supplyChange($tariffId, '2026-08-20'))
            ->toThrow(QueryException::class, "Vendor assignment {$assignmentId} does not supply the building on 2026-08-20");
    });

    it('refuse a vendor whose assignment was cancelled', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $assignmentId = TestRecords::vendorAssignment($buildingId, '2026-08-01');
        $tariffId = activeRate($assignmentId, '2026-08-01', 50);
        DB::table('building_vendor_assignments')->where('id', $assignmentId)->update(['status' => 'cancelled']);

        // Act & Assert
        expect(fn () => TestRecords::supplyChange($tariffId, '2026-08-14'))
            ->toThrow(QueryException::class, "Vendor assignment {$assignmentId} does not supply the building on 2026-08-14");
    });

    it('refuse a second change of the building on the same day', function () {
        // Arrange
        TestRecords::supplyChange($this->tariffId, '2026-08-14');

        // Act & Assert
        expect(fn () => TestRecords::supplyChange($this->tariffId, '2026-08-14', ['changed_at' => '2026-08-14 18:00:00+06']))
            ->toThrow(QueryException::class, 'uq_supply_change_building_day');
    });

    it('free the day again once cancelled', function () {
        // Arrange
        $id = TestRecords::supplyChange($this->tariffId, '2026-08-14');
        DB::table('supply_changes')->where('id', $id)->update(['status' => 'cancelled']);

        // Act
        TestRecords::supplyChange($this->tariffId, '2026-08-14', ['changed_at' => '2026-08-14 18:00:00+06']);

        // Assert
        expect(DB::table('supply_changes')->where('building_id', $this->buildingId)->count())->toBe(2);
    });

    it('need a main meter reading before they are verified', function () {
        // Arrange
        $id = TestRecords::supplyChange($this->tariffId, '2026-08-14');
        TestRecords::supplyChangeReading($id, TestRecords::meter($this->buildingId), 12);

        // Act & Assert
        expect(fn () => DB::table('supply_changes')->where('id', $id)->update(['status' => 'verified']))
            ->toThrow(QueryException::class, "Supply change {$id} needs a main meter reading before it is verified");
    });

    it('can be verified with a main meter reading', function () {
        // Arrange
        $id = TestRecords::supplyChange($this->tariffId, '2026-08-14');
        TestRecords::supplyChangeReading($id, $this->mainMeterId, 1520.5);

        // Act
        DB::table('supply_changes')->where('id', $id)->update(['status' => 'verified', 'verified_at' => now()]);

        // Assert
        expect(DB::table('supply_changes')->where('id', $id)->value('status'))->toBe('verified');
    });

    it('cannot change once verified', function () {
        // Arrange
        $id = TestRecords::supplyChange($this->tariffId, '2026-08-14');
        TestRecords::supplyChangeReading($id, $this->mainMeterId, 1520.5);
        DB::table('supply_changes')->where('id', $id)->update(['status' => 'verified']);

        // Act & Assert
        expect(fn () => DB::table('supply_changes')->where('id', $id)->update(['effective_on' => '2026-08-15']))
            ->toThrow(QueryException::class, "supply_changes {$id} is verified: effective_on can no longer change");
    });

    it('can never be deleted', function () {
        // Arrange
        $id = TestRecords::supplyChange($this->tariffId, '2026-08-14');

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('supply_changes')->where('id', $id)->delete()))
            ->toThrow(QueryException::class, 'Records in supply_changes are protected');
    });
});

describe('supply change readings', function () {
    beforeEach(function () {
        $this->changeId = TestRecords::supplyChange($this->tariffId, '2026-08-14');
    });

    it('refuse a meter of another building', function () {
        // Act & Assert
        expect(fn () => TestRecords::supplyChangeReading($this->changeId, TestRecords::meter(), 10))
            ->toThrow(QueryException::class, 'fk_supply_change_reading_meter');
    });

    it('refuse a negative reading', function () {
        // Act & Assert
        expect(fn () => TestRecords::supplyChangeReading($this->changeId, $this->mainMeterId, -1))
            ->toThrow(QueryException::class, 'ck_supply_change_readings_reading');
    });

    it('refuse a second reading of the same meter', function () {
        // Arrange
        TestRecords::supplyChangeReading($this->changeId, $this->mainMeterId, 10);

        // Act & Assert
        expect(fn () => TestRecords::supplyChangeReading($this->changeId, $this->mainMeterId, 11))
            ->toThrow(QueryException::class, 'supply_change_readings_supply_change_id_meter_id_unique');
    });

    it('cannot be added once the change is verified', function () {
        // Arrange
        TestRecords::supplyChangeReading($this->changeId, $this->mainMeterId, 10);
        DB::table('supply_changes')->where('id', $this->changeId)->update(['status' => 'verified']);

        // Act & Assert
        expect(fn () => TestRecords::supplyChangeReading($this->changeId, TestRecords::meter($this->buildingId), 5))
            ->toThrow(QueryException::class, "supply_changes {$this->changeId} is verified: its supply_change_readings can no longer change");
    });
});
