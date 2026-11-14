<?php

/**
 * Migration group 7, part 1: flats, customers and flat occupancies (design 02
 * §4 and §14, V7.7, V7.10).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, string $module, string $class, string $softDelete) {
        // Arrange: migration group 7 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy])
            ->toBe([$module, $class, $softDelete, 'forbidden']);
    })->with([
        ['flats', 'buildings', 'master', 'allowed'],
        ['customers', 'customers', 'master', 'allowed'],
        ['flat_occupancies', 'customers', 'history', 'protected'],
    ]);
});

describe('flats', function () {
    it('refuse a code the building already uses', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::flat($buildingId, ['code' => 'A-1']);

        // Act & Assert
        expect(fn () => TestRecords::flat($buildingId, ['code' => 'A-1']))
            ->toThrow(QueryException::class, 'flats_building_id_code_unique');
    });

    it('refuse a negative area', function () {
        // Arrange
        $row = ['area_sqft' => -1];

        // Act & Assert
        expect(fn () => TestRecords::flat(overrides: $row))->toThrow(QueryException::class, 'ck_flats_area_sqft');
    });

    it('keep their active flag while under maintenance', function () {
        // Arrange
        $id = TestRecords::flat();

        // Act
        DB::table('flats')->where('id', $id)->update(['status' => 'under_maintenance']);

        // Assert
        expect(DB::table('flats')->where('id', $id)->value('is_active'))->toBeTrue();
    });

    it('are hidden, not removed, when deleted', function () {
        // Arrange
        $id = TestRecords::flat();

        // Act
        DB::table('flats')->where('id', $id)->delete();

        // Assert
        expect(DB::table('flats')->where('id', $id)->value('is_deleted'))->toBeTrue();
    });
});

describe('customers', function () {
    it('get Bangla as their language', function () {
        // Arrange
        $id = TestRecords::customer();

        // Act
        $locale = DB::table('customers')->where('id', $id)->value('preferred_locale');

        // Assert
        expect($locale)->toBe('bn');
    });

    it('refuse a language GasFlow does not speak', function () {
        // Arrange
        $row = ['preferred_locale' => 'fr'];

        // Act & Assert
        expect(fn () => TestRecords::customer(overrides: $row))->toThrow(QueryException::class, 'customers_preferred_locale_foreign');
    });

    it('refuse a code the organization already uses', function () {
        // Arrange
        $organizationId = TestRecords::organization();
        TestRecords::customer($organizationId, ['customer_code' => 'C-100']);

        // Act & Assert
        expect(fn () => TestRecords::customer($organizationId, ['customer_code' => 'C-100']))
            ->toThrow(QueryException::class, 'uq_customer_code_tenant');
    });

    it('allow the same code in another organization', function () {
        // Arrange
        TestRecords::customer(overrides: ['customer_code' => 'C-100']);

        // Act
        TestRecords::customer(overrides: ['customer_code' => 'C-100']);

        // Assert
        expect(DB::table('customers')->where('customer_code', 'C-100')->count())->toBe(2);
    });

    it('stop counting as active once blocked', function () {
        // Arrange
        $id = TestRecords::customer();

        // Act
        DB::table('customers')->where('id', $id)->update(['status' => 'blocked']);

        // Assert
        expect(DB::table('customers')->where('id', $id)->value('is_active'))->toBeFalse();
    });
});

describe('flat occupancies', function () {
    it('refuse an unknown occupancy type', function () {
        // Arrange
        $flatId = TestRecords::flat();

        // Act & Assert
        expect(fn () => TestRecords::occupancy($flatId, '2026-01-01', ['occupancy_type' => 'guest']))
            ->toThrow(QueryException::class, 'ck_flat_occupancies_occupancy_type');
    });

    it('refuse an end before the start', function () {
        // Arrange
        $flatId = TestRecords::flat();

        // Act & Assert
        expect(fn () => TestRecords::occupancy($flatId, '2026-02-01', ['valid_to' => '2026-01-31']))
            ->toThrow(QueryException::class, 'ck_flat_occupancies_valid_range');
    });

    it('refuse a second primary contact for the same days', function () {
        // Arrange
        $flatId = TestRecords::flat();
        TestRecords::occupancy($flatId, '2026-01-01', ['is_primary_contact' => true]);

        // Act & Assert
        expect(fn () => TestRecords::occupancy($flatId, '2026-03-01', ['is_primary_contact' => true]))
            ->toThrow(QueryException::class, 'ex_flat_primary_occupancy_no_overlap');
    });

    it('count the last day of a primary contact as covered', function () {
        // Arrange
        $flatId = TestRecords::flat();
        TestRecords::occupancy($flatId, '2026-01-01', ['is_primary_contact' => true, 'valid_to' => '2026-06-30']);

        // Act & Assert
        expect(fn () => TestRecords::occupancy($flatId, '2026-06-30', ['is_primary_contact' => true]))
            ->toThrow(QueryException::class, 'ex_flat_primary_occupancy_no_overlap');
    });

    it('allow the next primary contact from the day after a handover', function () {
        // Arrange
        $flatId = TestRecords::flat();
        TestRecords::occupancy($flatId, '2026-01-01', ['is_primary_contact' => true, 'valid_to' => '2026-06-30']);

        // Act
        TestRecords::occupancy($flatId, '2026-07-01', ['is_primary_contact' => true]);

        // Assert
        expect(DB::table('flat_occupancies')->where('flat_id', $flatId)->count())->toBe(2);
    });

    it('allow other occupants beside the primary contact', function () {
        // Arrange
        $flatId = TestRecords::flat();
        TestRecords::occupancy($flatId, '2026-01-01', ['is_primary_contact' => true, 'occupancy_type' => 'owner']);

        // Act
        TestRecords::occupancy($flatId, '2026-01-01');

        // Assert
        expect(DB::table('flat_occupancies')->where('flat_id', $flatId)->count())->toBe(2);
    });

    it('can never be deleted', function () {
        // Arrange
        $id = TestRecords::occupancy(TestRecords::flat(), '2026-01-01');

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('flat_occupancies')->where('id', $id)->delete()))
            ->toThrow(QueryException::class);
        expect(DB::table('flat_occupancies')->where('id', $id)->value('is_deleted'))->toBeFalse();
    });
});
