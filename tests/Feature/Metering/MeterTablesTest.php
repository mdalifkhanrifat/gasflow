<?php

/**
 * Migration group 8, part 1: meters, meter assignments, meter occupancy
 * assignments and meter events (design 02 §5 and §14, V7.7).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, string $class, string $softDelete) {
        // Arrange: migration group 8 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy])
            ->toBe(['metering', $class, $softDelete, 'forbidden']);
    })->with([
        ['meters', 'master', 'allowed'],
        ['meter_assignments', 'history', 'protected'],
        ['meter_occupancy_assignments', 'history', 'protected'],
        ['meter_events', 'history', 'protected'],
    ]);
});

describe('meters', function () {
    it('refuse a number the building already uses', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::meter($buildingId, ['meter_number' => 'M-1']);

        // Act & Assert
        expect(fn () => TestRecords::meter($buildingId, ['meter_number' => 'M-1']))
            ->toThrow(QueryException::class, 'meters_building_id_meter_number_unique');
    });

    it('refuse a type that is not in the catalogue', function () {
        // Arrange
        $row = ['meter_type' => 'smart_prepaid'];

        // Act & Assert
        expect(fn () => TestRecords::meter(overrides: $row))->toThrow(QueryException::class, 'fk_meters_meter_type_catalog');
    });

    it('refuse a factor of zero', function () {
        // Arrange
        $row = ['meter_factor' => 0];

        // Act & Assert
        expect(fn () => TestRecords::meter(overrides: $row))->toThrow(QueryException::class, 'ck_meters_meter_factor');
    });

    it('refuse a negative initial reading', function () {
        // Arrange
        $row = ['initial_reading' => -0.5];

        // Act & Assert
        expect(fn () => TestRecords::meter(overrides: $row))->toThrow(QueryException::class, 'ck_meters_initial_reading');
    });

    it('keep their active flag while faulty', function () {
        // Arrange
        $id = TestRecords::meter();

        // Act
        DB::table('meters')->where('id', $id)->update(['status' => 'faulty']);

        // Assert
        expect(DB::table('meters')->where('id', $id)->value('is_active'))->toBeTrue();
    });

    it('stop counting as active once replaced', function () {
        // Arrange
        $id = TestRecords::meter();

        // Act
        DB::table('meters')->where('id', $id)->update(['status' => 'replaced']);

        // Assert
        expect(DB::table('meters')->where('id', $id)->value('is_active'))->toBeFalse();
    });

    it('are hidden, not removed, when deleted', function () {
        // Arrange
        $id = TestRecords::meter();

        // Act
        DB::table('meters')->where('id', $id)->delete();

        // Assert
        expect(DB::table('meters')->where('id', $id)->value('is_deleted'))->toBeTrue();
    });
});

describe('meter assignments', function () {
    beforeEach(function () {
        $this->buildingId = TestRecords::building();
    });

    it('may place a meter without a flat', function () {
        // Arrange
        $meterId = TestRecords::meter($this->buildingId, ['meter_type' => 'main']);

        // Act
        $id = TestRecords::meterAssignment($meterId, null, '2026-01-01');

        // Assert
        expect(DB::table('meter_assignments')->where('id', $id)->value('flat_id'))->toBeNull();
    });

    it('refuse an end before the start', function () {
        // Arrange
        $meterId = TestRecords::meter($this->buildingId);

        // Act & Assert
        expect(fn () => TestRecords::meterAssignment($meterId, TestRecords::flat($this->buildingId), '2026-02-01', ['valid_to' => '2026-01-31']))
            ->toThrow(QueryException::class, 'ck_meter_assignments_valid_range');
    });

    it('refuse a second place for the same meter on the same days', function () {
        // Arrange
        $meterId = TestRecords::meter($this->buildingId);
        TestRecords::meterAssignment($meterId, TestRecords::flat($this->buildingId), '2026-01-01');

        // Act & Assert
        expect(fn () => TestRecords::meterAssignment($meterId, TestRecords::flat($this->buildingId), '2026-03-01'))
            ->toThrow(QueryException::class, 'ex_meter_physical_assignment_no_overlap');
    });

    it('count the last day of a placement as covered', function () {
        // Arrange
        $meterId = TestRecords::meter($this->buildingId);
        TestRecords::meterAssignment($meterId, TestRecords::flat($this->buildingId), '2026-01-01', ['valid_to' => '2026-06-30']);

        // Act & Assert
        expect(fn () => TestRecords::meterAssignment($meterId, TestRecords::flat($this->buildingId), '2026-06-30'))
            ->toThrow(QueryException::class, 'ex_meter_physical_assignment_no_overlap');
    });

    it('allow the meter to move from the day after', function () {
        // Arrange
        $meterId = TestRecords::meter($this->buildingId);
        TestRecords::meterAssignment($meterId, TestRecords::flat($this->buildingId), '2026-01-01', ['valid_to' => '2026-06-30']);

        // Act
        TestRecords::meterAssignment($meterId, TestRecords::flat($this->buildingId), '2026-07-01');

        // Assert
        expect(DB::table('meter_assignments')->where('meter_id', $meterId)->count())->toBe(2);
    });

    it('refuse a flat of another building', function () {
        // Arrange
        $meterId = TestRecords::meter($this->buildingId);

        // Act & Assert
        expect(fn () => TestRecords::meterAssignment($meterId, TestRecords::flat(), '2026-01-01'))
            ->toThrow(QueryException::class, 'fk_meter_assignment_flat_building');
    });

    it('refuse a placement without a building, which would skip the building checks', function () {
        // Arrange
        $meterId = TestRecords::meter($this->buildingId);

        // Act & Assert
        expect(fn () => TestRecords::meterAssignment($meterId, TestRecords::flat(), '2026-01-01', ['building_id' => null]))
            ->toThrow(QueryException::class, 'building_id');
    });

    it('refuse a building other than the meter\'s', function () {
        // Arrange
        $meterId = TestRecords::meter($this->buildingId);

        // Act & Assert
        expect(fn () => TestRecords::meterAssignment($meterId, null, '2026-01-01', ['building_id' => TestRecords::building()]))
            ->toThrow(QueryException::class, 'fk_meter_assignment_meter_building');
    });

    it('can never be deleted', function () {
        // Arrange
        $id = TestRecords::meterAssignment(TestRecords::meter($this->buildingId), TestRecords::flat($this->buildingId), '2026-01-01');

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('meter_assignments')->where('id', $id)->delete()))
            ->toThrow(QueryException::class);
        expect(DB::table('meter_assignments')->where('id', $id)->value('is_deleted'))->toBeFalse();
    });
});

describe('meter occupancy assignments', function () {
    beforeEach(function () {
        $this->buildingId = TestRecords::building();
        $this->flatId = TestRecords::flat($this->buildingId);
        $this->assignmentId = TestRecords::meterAssignment(TestRecords::meter($this->buildingId), $this->flatId, '2026-01-01');
    });

    it('refuse an occupancy of a flat the meter is not in', function () {
        // Arrange
        $otherOccupancyId = TestRecords::occupancy(TestRecords::flat($this->buildingId), '2026-01-01');

        // Act & Assert
        expect(fn () => TestRecords::meterOccupancyAssignment($this->assignmentId, $otherOccupancyId, '2026-01-01'))
            ->toThrow(QueryException::class, 'fk_meter_occupancy_meter_assignment');
    });

    it('refuse a flat that does not match the occupancy', function () {
        // Arrange
        $otherOccupancyId = TestRecords::occupancy(TestRecords::flat($this->buildingId), '2026-01-01');

        // Act & Assert
        expect(fn () => TestRecords::meterOccupancyAssignment($this->assignmentId, $otherOccupancyId, '2026-01-01', ['flat_id' => $this->flatId]))
            ->toThrow(QueryException::class, 'fk_meter_occupancy_flat_occupancy');
    });

    it('refuse a negative opening reading', function () {
        // Arrange
        $occupancyId = TestRecords::occupancy($this->flatId, '2026-01-01');

        // Act & Assert
        expect(fn () => TestRecords::meterOccupancyAssignment($this->assignmentId, $occupancyId, '2026-01-01', ['opening_reading' => -1]))
            ->toThrow(QueryException::class, 'ck_meter_occupancy_assignments_opening_reading');
    });

    it('refuse an end before the start', function () {
        // Arrange
        $occupancyId = TestRecords::occupancy($this->flatId, '2026-01-01');

        // Act & Assert
        expect(fn () => TestRecords::meterOccupancyAssignment($this->assignmentId, $occupancyId, '2026-02-01', ['valid_to' => '2026-01-31']))
            ->toThrow(QueryException::class, 'ck_meter_occupancy_assignments_valid_range');
    });

    it('refuse a second billing-responsible occupancy for the same days', function () {
        // Arrange
        TestRecords::meterOccupancyAssignment($this->assignmentId, TestRecords::occupancy($this->flatId, '2026-01-01'), '2026-01-01');
        $nextOccupancyId = TestRecords::occupancy($this->flatId, '2026-03-01');

        // Act & Assert
        expect(fn () => TestRecords::meterOccupancyAssignment($this->assignmentId, $nextOccupancyId, '2026-03-01'))
            ->toThrow(QueryException::class, 'ex_meter_occupancy_responsibility_no_overlap');
    });

    it('count the handover day as covered by the outgoing occupancy', function () {
        // Arrange
        $firstOccupancyId = TestRecords::occupancy($this->flatId, '2026-01-01');
        TestRecords::meterOccupancyAssignment($this->assignmentId, $firstOccupancyId, '2026-01-01', ['valid_to' => '2026-06-30']);
        $nextOccupancyId = TestRecords::occupancy($this->flatId, '2026-06-30');

        // Act & Assert
        expect(fn () => TestRecords::meterOccupancyAssignment($this->assignmentId, $nextOccupancyId, '2026-06-30'))
            ->toThrow(QueryException::class, 'ex_meter_occupancy_responsibility_no_overlap');
    });

    it('allow the next occupancy to pay from the day after the handover', function () {
        // Arrange
        $firstOccupancyId = TestRecords::occupancy($this->flatId, '2026-01-01');
        TestRecords::meterOccupancyAssignment($this->assignmentId, $firstOccupancyId, '2026-01-01', ['valid_to' => '2026-06-30', 'closing_reading' => 412.5]);
        $nextOccupancyId = TestRecords::occupancy($this->flatId, '2026-07-01');

        // Act
        TestRecords::meterOccupancyAssignment($this->assignmentId, $nextOccupancyId, '2026-07-01', ['opening_reading' => 412.5]);

        // Assert
        expect(DB::table('meter_occupancy_assignments')->where('meter_assignment_id', $this->assignmentId)->count())->toBe(2);
    });

    it('allow occupancies that are not billed beside the billed one', function () {
        // Arrange
        TestRecords::meterOccupancyAssignment($this->assignmentId, TestRecords::occupancy($this->flatId, '2026-01-01'), '2026-01-01');
        $ownerOccupancyId = TestRecords::occupancy($this->flatId, '2026-01-01', ['occupancy_type' => 'owner']);

        // Act
        TestRecords::meterOccupancyAssignment($this->assignmentId, $ownerOccupancyId, '2026-01-01', ['is_billing_responsible' => false]);

        // Assert
        expect(DB::table('meter_occupancy_assignments')->where('meter_assignment_id', $this->assignmentId)->count())->toBe(2);
    });

    it('can never be deleted', function () {
        // Arrange
        $id = TestRecords::meterOccupancyAssignment($this->assignmentId, TestRecords::occupancy($this->flatId, '2026-01-01'), '2026-01-01');

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('meter_occupancy_assignments')->where('id', $id)->delete()))
            ->toThrow(QueryException::class);
        expect(DB::table('meter_occupancy_assignments')->where('id', $id)->value('is_deleted'))->toBeFalse();
    });
});

describe('meter events', function () {
    it('refuse an unknown event type', function () {
        // Arrange
        $row = ['meter_id' => TestRecords::meter(), 'event_type' => 'stolen', 'event_at' => now()];

        // Act & Assert
        expect(fn () => DB::table('meter_events')->insert($row))->toThrow(QueryException::class, 'ck_meter_events_event_type');
    });

    it('can never be deleted', function () {
        // Arrange
        $id = DB::table('meter_events')->insertGetId([
            'meter_id' => TestRecords::meter(),
            'event_type' => 'installed',
            'event_at' => now(),
            'reading_at_event' => 0,
        ]);

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('meter_events')->where('id', $id)->delete()))
            ->toThrow(QueryException::class);
        expect(DB::table('meter_events')->where('id', $id)->value('is_deleted'))->toBeFalse();
    });
});
