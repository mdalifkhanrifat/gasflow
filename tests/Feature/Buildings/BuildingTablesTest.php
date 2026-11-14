<?php

/**
 * Migration group 4, part 1: buildings, ownership history and gas vendor
 * assignments (design 02 §2 and §14, V7.7).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, string $class, string $softDelete) {
        // Arrange: migration group 4 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy])
            ->toBe(['buildings', $class, $softDelete, 'forbidden']);
    })->with([
        ['buildings', 'master', 'allowed'],
        ['building_ownerships', 'history', 'protected'],
        ['building_vendor_assignments', 'history', 'protected'],
    ]);
});

describe('buildings', function () {
    it('start active in Bangladesh with internal cost allocation', function () {
        // Arrange
        $id = TestRecords::building();

        // Act
        $building = DB::table('buildings')->find($id);

        // Assert
        expect([$building->status, $building->country_code, $building->timezone, $building->currency, $building->billing_mode])
            ->toBe(['active', 'BD', 'Asia/Dhaka', 'BDT', 'internal_cost_allocation']);
    });

    it('refuse a code the organization already uses', function () {
        // Arrange
        $organizationId = TestRecords::organization();
        TestRecords::building($organizationId, ['code' => 'LV-01']);

        // Act & Assert
        expect(fn () => TestRecords::building($organizationId, ['code' => 'LV-01']))
            ->toThrow(QueryException::class, 'buildings_tenant_organization_id_code_unique');
    });

    it('allow the same code in another organization', function () {
        // Arrange
        TestRecords::building(overrides: ['code' => 'LV-01']);

        // Act
        TestRecords::building(overrides: ['code' => 'LV-01']);

        // Assert
        expect(DB::table('buildings')->where('code', 'LV-01')->count())->toBe(2);
    });

    it('refuse an unknown billing mode', function () {
        // Arrange
        $row = ['billing_mode' => 'flat_rate'];

        // Act & Assert
        expect(fn () => TestRecords::building(overrides: $row))->toThrow(QueryException::class, 'ck_buildings_billing_mode');
    });

    it('refuse a status the catalogue does not know', function () {
        // Arrange
        $row = ['status' => 'closed'];

        // Act & Assert
        expect(fn () => TestRecords::building(overrides: $row))->toThrow(QueryException::class, 'Status "closed" is not defined for buildings');
    });

    it('are hidden, not removed, when deleted', function () {
        // Arrange
        $id = TestRecords::building();

        // Act
        DB::table('buildings')->where('id', $id)->delete();

        // Assert
        expect(DB::table('buildings')->where('id', $id)->value('is_deleted'))->toBeTrue();
    });
});

describe('ownerships', function () {
    it('refuse a share above one hundred percent', function () {
        // Arrange
        $row = ['building_id' => TestRecords::building(), 'owner_name' => 'Karim', 'ownership_share' => 100.5, 'valid_from' => '2026-01-01'];

        // Act & Assert
        expect(fn () => DB::table('building_ownerships')->insert($row))->toThrow(QueryException::class, 'ck_building_ownerships_share');
    });

    it('refuse an end before the start', function () {
        // Arrange
        $row = ['building_id' => TestRecords::building(), 'owner_name' => 'Karim', 'valid_from' => '2026-02-01', 'valid_to' => '2026-01-31'];

        // Act & Assert
        expect(fn () => DB::table('building_ownerships')->insert($row))->toThrow(QueryException::class, 'ck_building_ownerships_valid_range');
    });

    it('can never be deleted', function () {
        // Arrange
        $id = (int) DB::table('building_ownerships')->insertGetId(
            ['building_id' => TestRecords::building(), 'owner_name' => 'Karim', 'valid_from' => '2026-01-01'],
        );

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('building_ownerships')->where('id', $id)->delete()))
            ->toThrow(QueryException::class);
        expect(DB::table('building_ownerships')->where('id', $id)->value('is_deleted'))->toBeFalse();
    });
});

describe('vendor assignments', function () {
    it('refuse a second active gas vendor for the same days', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::vendorAssignment($buildingId, '2026-01-01');

        // Act & Assert
        expect(fn () => TestRecords::vendorAssignment($buildingId, '2026-06-01'))
            ->toThrow(QueryException::class, 'ex_building_single_gas_vendor_no_overlap');
    });

    it('count the last day of an assignment as covered', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::vendorAssignment($buildingId, '2026-01-01', ['valid_to' => '2026-06-30']);

        // Act & Assert
        expect(fn () => TestRecords::vendorAssignment($buildingId, '2026-06-30'))
            ->toThrow(QueryException::class, 'ex_building_single_gas_vendor_no_overlap');
    });

    it('refuse a new vendor on days an ended vendor still covers', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::vendorAssignment($buildingId, '2026-01-01', ['valid_to' => '2026-06-30', 'status' => 'ended']);

        // Act & Assert
        expect(fn () => TestRecords::vendorAssignment($buildingId, '2026-06-01'))
            ->toThrow(QueryException::class, 'ex_building_single_gas_vendor_no_overlap');
    });

    it('allow the next vendor from the day after the previous one ends', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::vendorAssignment($buildingId, '2026-01-01', ['valid_to' => '2026-06-30']);

        // Act
        TestRecords::vendorAssignment($buildingId, '2026-07-01');

        // Assert
        expect(DB::table('building_vendor_assignments')->where('building_id', $buildingId)->count())->toBe(2);
    });

    it('ignore an assignment that is no longer active', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::vendorAssignment($buildingId, '2026-01-01', ['status' => 'cancelled']);

        // Act
        TestRecords::vendorAssignment($buildingId, '2026-01-01');

        // Assert
        expect(DB::table('building_vendor_assignments')->where('building_id', $buildingId)->count())->toBe(2);
    });

    it('allow other assignment types alongside the gas vendor', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::vendorAssignment($buildingId, '2026-01-01');

        // Act
        TestRecords::vendorAssignment($buildingId, '2026-01-01', ['assignment_type' => 'maintenance']);

        // Assert
        expect(DB::table('building_vendor_assignments')->where('building_id', $buildingId)->count())->toBe(2);
    });

    it('refuse an end before the start', function () {
        // Arrange
        $buildingId = TestRecords::building();

        // Act & Assert
        expect(fn () => TestRecords::vendorAssignment($buildingId, '2026-02-01', ['valid_to' => '2026-01-31']))
            ->toThrow(QueryException::class, 'ck_building_vendor_assignments_valid_range');
    });
});
