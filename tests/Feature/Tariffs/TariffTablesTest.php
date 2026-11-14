<?php

/**
 * Migration group 9, part 1: tariff versions and building charge rules
 * (design 02 §6 and §14, V7.7, V7.9).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * Moves a draft tariff version along its transitions to the given status.
 */
function moveTariffVersion(int $id, string $status): void
{
    foreach (['approved' => ['approved'], 'active' => ['approved', 'active'], 'retired' => ['retired']][$status] as $next) {
        DB::table('tariff_versions')->where('id', $id)->update(['status' => $next]);
    }
}

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, array $expected) {
        // Arrange: migration group 9 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe($expected);
    })->with([
        ['tariff_versions', ['tariffs', 'history', 'protected', 'forbidden', true, 'draft']],
        ['building_charge_rules', ['tariffs', 'configuration', 'allowed', 'forbidden', false, 'active']],
    ]);
});

describe('tariff versions', function () {
    beforeEach(function () {
        $this->buildingId = TestRecords::building();
        $this->assignmentId = TestRecords::vendorAssignment($this->buildingId, '2026-01-01');
    });

    it('start as a draft', function () {
        // Arrange
        $id = TestRecords::tariffVersion($this->assignmentId);

        // Act
        $status = DB::table('tariff_versions')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('draft');
    });

    it('refuse a vendor that is not the vendor of the assignment', function () {
        // Arrange
        $row = ['vendor_organization_id' => TestRecords::organization()];

        // Act & Assert
        expect(fn () => TestRecords::tariffVersion($this->assignmentId, overrides: $row))
            ->toThrow(QueryException::class, 'fk_tariff_vendor_assignment');
    });

    it('refuse a vendor assignment of another building', function () {
        // Arrange
        $row = ['building_id' => TestRecords::building()];

        // Act & Assert
        expect(fn () => TestRecords::tariffVersion($this->assignmentId, overrides: $row))
            ->toThrow(QueryException::class, 'fk_tariff_vendor_assignment');
    });

    it('refuse a negative rate', function () {
        // Arrange
        $row = ['rate' => -1];

        // Act & Assert
        expect(fn () => TestRecords::tariffVersion($this->assignmentId, overrides: $row))
            ->toThrow(QueryException::class, 'ck_tariff_versions_rate');
    });

    it('refuse an end before the start', function () {
        // Arrange
        $row = ['effective_to' => '2025-12-31'];

        // Act & Assert
        expect(fn () => TestRecords::tariffVersion($this->assignmentId, overrides: $row))
            ->toThrow(QueryException::class, 'ck_tariff_versions_effective_range');
    });

    it('refuse a version number the tariff already has', function () {
        // Arrange
        TestRecords::tariffVersion($this->assignmentId, overrides: ['version_no' => 1]);

        // Act & Assert
        expect(fn () => TestRecords::tariffVersion($this->assignmentId, '2026-06-01', ['version_no' => 1]))
            ->toThrow(QueryException::class, 'uq_tariff_version_no');
    });

    it('refuse a second approved version for the same days', function () {
        // Arrange
        moveTariffVersion(TestRecords::tariffVersion($this->assignmentId), 'approved');
        $nextId = TestRecords::tariffVersion($this->assignmentId, '2026-06-01');

        // Act & Assert
        expect(fn () => moveTariffVersion($nextId, 'approved'))
            ->toThrow(QueryException::class, 'ex_tariff_versions_no_overlap');
    });

    it('count the last day of an approved version as covered', function () {
        // Arrange
        moveTariffVersion(TestRecords::tariffVersion($this->assignmentId, overrides: ['effective_to' => '2026-05-31']), 'active');
        $nextId = TestRecords::tariffVersion($this->assignmentId, '2026-05-31');

        // Act & Assert
        expect(fn () => moveTariffVersion($nextId, 'approved'))
            ->toThrow(QueryException::class, 'ex_tariff_versions_no_overlap');
    });

    it('allow the next approved version from the day after', function () {
        // Arrange
        moveTariffVersion(TestRecords::tariffVersion($this->assignmentId, overrides: ['effective_to' => '2026-05-31']), 'active');
        $nextId = TestRecords::tariffVersion($this->assignmentId, '2026-06-01');

        // Act
        moveTariffVersion($nextId, 'approved');

        // Assert
        expect(DB::table('tariff_versions')->where('id', $nextId)->value('status'))->toBe('approved');
    });

    it('allow an overlapping draft beside the approved version', function () {
        // Arrange
        moveTariffVersion(TestRecords::tariffVersion($this->assignmentId), 'approved');

        // Act
        TestRecords::tariffVersion($this->assignmentId, '2026-06-01');

        // Assert
        expect(DB::table('tariff_versions')->where('building_id', $this->buildingId)->count())->toBe(2);
    });

    it('allow an approved version for another customer category on the same days', function () {
        // Arrange
        moveTariffVersion(TestRecords::tariffVersion($this->assignmentId, overrides: ['customer_category' => 'domestic']), 'approved');
        $commercialId = TestRecords::tariffVersion($this->assignmentId, overrides: ['customer_category' => 'commercial']);

        // Act
        moveTariffVersion($commercialId, 'approved');

        // Assert
        expect(DB::table('tariff_versions')->where('id', $commercialId)->value('status'))->toBe('approved');
    });

    it('cannot go live without approval', function () {
        // Arrange
        $id = TestRecords::tariffVersion($this->assignmentId);

        // Act & Assert
        expect(fn () => DB::table('tariff_versions')->where('id', $id)->update(['status' => 'active']))
            ->toThrow(QueryException::class, 'Status transition draft -> active is not allowed for tariff_versions');
    });

    it('stay retired once retired', function () {
        // Arrange
        $id = TestRecords::tariffVersion($this->assignmentId);
        moveTariffVersion($id, 'retired');

        // Act & Assert
        expect(fn () => DB::table('tariff_versions')->where('id', $id)->update(['status' => 'approved']))
            ->toThrow(QueryException::class, 'Status transition retired -> approved is not allowed');
    });

    it('can never be deleted', function () {
        // Arrange
        $id = TestRecords::tariffVersion($this->assignmentId);

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('tariff_versions')->where('id', $id)->delete()))
            ->toThrow(QueryException::class, 'Records in tariff_versions are protected');
    });
});

describe('building charge rules', function () {
    it('refuse an unknown calculation type', function () {
        // Arrange
        $row = ['calculation_type' => 'per_flat'];

        // Act & Assert
        expect(fn () => TestRecords::chargeRule(overrides: $row))->toThrow(QueryException::class, 'ck_building_charge_rules_calculation_type');
    });

    it('refuse a negative amount', function () {
        // Arrange
        $row = ['amount' => -5];

        // Act & Assert
        expect(fn () => TestRecords::chargeRule(overrides: $row))->toThrow(QueryException::class, 'ck_building_charge_rules_amount');
    });

    it('refuse an end before the start', function () {
        // Arrange
        $row = ['effective_to' => '2025-12-31'];

        // Act & Assert
        expect(fn () => TestRecords::chargeRule(overrides: $row))->toThrow(QueryException::class, 'ck_building_charge_rules_effective_range');
    });

    it('refuse a second active rule with the same code on the same days', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::chargeRule($buildingId);

        // Act & Assert
        expect(fn () => TestRecords::chargeRule($buildingId, '2026-03-01'))
            ->toThrow(QueryException::class, 'ex_building_charge_rules_no_overlap');
    });

    it('allow a draft of the next rule beside the active one', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::chargeRule($buildingId);

        // Act
        TestRecords::chargeRule($buildingId, '2026-03-01', ['status' => 'draft']);

        // Assert
        expect(DB::table('building_charge_rules')->where('building_id', $buildingId)->count())->toBe(2);
    });

    it('stop counting as active once retired', function () {
        // Arrange
        $id = TestRecords::chargeRule();

        // Act
        DB::table('building_charge_rules')->where('id', $id)->update(['status' => 'retired']);

        // Assert
        expect(DB::table('building_charge_rules')->where('id', $id)->value('is_active'))->toBeFalse();
    });

    it('are hidden, not removed, when deleted', function () {
        // Arrange
        $id = TestRecords::chargeRule();

        // Act
        DB::table('building_charge_rules')->where('id', $id)->delete();

        // Assert
        expect(DB::table('building_charge_rules')->where('id', $id)->value('is_deleted'))->toBeTrue();
    });
});
