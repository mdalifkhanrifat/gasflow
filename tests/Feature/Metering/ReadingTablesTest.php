<?php

/**
 * Migration group 8, part 2: reading periods, meter readings, reading
 * revisions and reading approvals (design 02 §5, V7.7, V7.9).
 *
 * Ports the reading_periods cases of gasflow-design-v7/tests: 09–12 and 39
 * (lifecycle-tests.sql) and 51 (override-tests.sql). Their generic parts run
 * on fixture tables in tests/Feature/Database.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TestRecords;

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, string $class, bool $enforced, string $defaultStatus) {
        // Arrange: migration group 8 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe(['metering', $class, 'protected', 'forbidden', $enforced, $defaultStatus]);
    })->with([
        ['reading_periods', 'workflow', true, 'open'],
        ['meter_readings', 'history', true, 'submitted'],
        ['meter_reading_revisions', 'history', false, 'active'],
        ['reading_approvals', 'history', false, 'active'],
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
        'reading periods (design 12, 39)' => ['reading_periods', fn () => TestRecords::readingPeriod()],
        'meter readings' => ['meter_readings', fn () => TestRecords::meterReading(TestRecords::readingPeriod())],
        'reading revisions' => ['meter_reading_revisions', fn () => DB::table('meter_reading_revisions')->insertGetId([
            'meter_reading_id' => TestRecords::meterReading(TestRecords::readingPeriod()),
            'revision_no' => 1,
            'previous_reading' => 0,
            'current_reading' => 12,
            'consumption' => 12,
            'reason' => 'Photo shows 12, not 10',
            'submitted_by' => TestRecords::user(),
        ])],
        'reading approvals' => ['reading_approvals', fn () => DB::table('reading_approvals')->insertGetId([
            'meter_reading_id' => TestRecords::meterReading(TestRecords::readingPeriod()),
            'reviewer_user_id' => TestRecords::user(),
            'decision' => 'approved',
        ])],
    ]);
});

describe('reading periods', function () {
    it('refuse a month outside 1 to 12', function () {
        // Arrange
        $row = ['period_month' => 13];

        // Act & Assert
        expect(fn () => TestRecords::readingPeriod(overrides: $row))->toThrow(QueryException::class, 'ck_reading_periods_period_month');
    });

    it('refuse an end before the start', function () {
        // Arrange
        $row = ['ends_on' => '2026-09-30', 'period_month' => 9];

        // Act & Assert
        expect(fn () => TestRecords::readingPeriod(overrides: $row))->toThrow(QueryException::class, 'ck_reading_periods_range');
    });

    it('refuse overlapping days in the same building', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::readingPeriod($buildingId);

        // Act & Assert
        expect(fn () => TestRecords::readingPeriod($buildingId, overrides: ['starts_on' => '2026-10-31', 'ends_on' => '2026-11-15', 'period_month' => 11]))
            ->toThrow(QueryException::class, 'ex_reading_periods_no_overlap');
    });

    it('may be two in a month, one for each half', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::readingPeriod($buildingId, overrides: ['ends_on' => '2026-10-15']);

        // Act
        TestRecords::readingPeriod($buildingId, overrides: ['starts_on' => '2026-10-16']);

        // Assert
        expect(DB::table('reading_periods')->where('building_id', $buildingId)->count())->toBe(2);
    });

    it('let the days of a cancelled period be read again', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $cancelledId = TestRecords::readingPeriod($buildingId);
        DB::table('reading_periods')->where('id', $cancelledId)->update(['status' => 'cancelled']);

        // Act
        TestRecords::readingPeriod($buildingId);

        // Assert
        expect(DB::table('reading_periods')->where('building_id', $buildingId)->count())->toBe(2);
    });

    it('label the month they end in', function () {
        // Arrange
        $row = ['ends_on' => '2026-11-09'];

        // Act & Assert
        expect(fn () => TestRecords::readingPeriod(overrides: $row))->toThrow(QueryException::class, 'ck_reading_periods_period_of_ends_on');
    });

    it('may change their dates while open', function () {
        // Arrange
        $id = TestRecords::readingPeriod();

        // Act
        DB::table('reading_periods')->where('id', $id)->update(['ends_on' => '2026-10-15']);

        // Assert
        expect(DB::table('reading_periods')->where('id', $id)->value('ends_on'))->toBe('2026-10-15');
    });

    it('freeze their dates after open', function (string $column, mixed $value) {
        // Arrange
        $id = TestRecords::readingPeriod();
        DB::table('reading_periods')->where('id', $id)->update(['status' => 'collecting']);

        // Act & Assert
        expect(fn () => DB::table('reading_periods')->where('id', $id)->update([$column => $value]))
            ->toThrow(QueryException::class, "{$column} can no longer change");
    })->with([
        ['building_id', fn () => TestRecords::building()],
        ['starts_on', '2026-10-02'],
        ['ends_on', '2026-10-30'],
        ['period_month', 11],
    ]);

    it('start open', function () {
        // Arrange
        $id = TestRecords::readingPeriod();

        // Act
        $status = DB::table('reading_periods')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('open');
    });

    it('refuse to start in a later status (design 11)', function () {
        // Arrange
        $row = ['status' => 'approved'];

        // Act & Assert
        expect(fn () => TestRecords::readingPeriod(overrides: $row))
            ->toThrow(QueryException::class, 'Status "approved" is not an initial status for reading_periods');
    });

    it('move from open to collecting (design 10)', function () {
        // Arrange
        $id = TestRecords::readingPeriod();

        // Act
        DB::table('reading_periods')->where('id', $id)->update(['status' => 'collecting']);

        // Assert
        expect(DB::table('reading_periods')->where('id', $id)->value('status'))->toBe('collecting');
    });

    it('refuse to jump from open to locked (design 09)', function () {
        // Arrange
        $id = TestRecords::readingPeriod();

        // Act & Assert
        expect(fn () => DB::table('reading_periods')->where('id', $id)->update(['status' => 'locked']))
            ->toThrow(QueryException::class, 'Status transition open -> locked is not allowed for reading_periods');
    });

    it('reach locked through review and approval', function () {
        // Arrange
        $id = TestRecords::readingPeriod();

        // Act
        foreach (['collecting', 'review', 'approved', 'locked'] as $status) {
            DB::table('reading_periods')->where('id', $id)->update(['status' => $status]);
        }

        // Assert
        expect(DB::table('reading_periods')->where('id', $id)->value('status'))->toBe('locked');
    });

    it('can be sent back from review to collecting', function () {
        // Arrange
        $id = TestRecords::readingPeriod();
        DB::table('reading_periods')->where('id', $id)->update(['status' => 'collecting']);
        DB::table('reading_periods')->where('id', $id)->update(['status' => 'review']);

        // Act
        DB::table('reading_periods')->where('id', $id)->update(['status' => 'collecting']);

        // Assert
        expect(DB::table('reading_periods')->where('id', $id)->value('status'))->toBe('collecting');
    });

    it('cannot be cancelled once in review', function () {
        // Arrange
        $id = TestRecords::readingPeriod();
        DB::table('reading_periods')->where('id', $id)->update(['status' => 'collecting']);
        DB::table('reading_periods')->where('id', $id)->update(['status' => 'review']);

        // Act & Assert
        expect(fn () => DB::table('reading_periods')->where('id', $id)->update(['status' => 'cancelled']))
            ->toThrow(QueryException::class, 'Status transition review -> cancelled is not allowed');
    });

    it('stop counting as active once cancelled', function () {
        // Arrange
        $id = TestRecords::readingPeriod();

        // Act
        DB::table('reading_periods')->where('id', $id)->update(['status' => 'cancelled']);

        // Assert
        expect(DB::table('reading_periods')->where('id', $id)->value('is_active'))->toBeFalse();
    });
});

describe('reading periods under the maintenance override', function () {
    beforeEach(function () {
        $this->operator = DB::connection('pgsql_maintenance');
        $this->operator->beginTransaction();
    });

    afterEach(function () {
        $this->operator->rollBack();
    });

    it('may jump an enforced transition (design 51)', function () {
        // Arrange
        $organizationId = $this->operator->table('organizations')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()),
            'code' => 'ORG-OVB',
            'name' => 'Override test vendor',
            'organization_type' => 'gas_vendor',
        ]);
        setDatabaseContext(['organization_id' => $organizationId], $this->operator);
        $buildingId = $this->operator->table('buildings')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()),
            'tenant_organization_id' => $organizationId,
            'code' => 'OVB',
            'name' => 'Override test building',
        ]);
        $id = $this->operator->table('reading_periods')->insertGetId([
            'building_id' => $buildingId,
            'period_year' => 2026,
            'period_month' => 9,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
        ]);
        $this->operator->select(
            "select set_config('app.maintenance_override', 'on', true), set_config('app.lifecycle_reason', ?, true)",
            ['Owner request #51: close a period opened by mistake'],
        );

        // Act
        $this->operator->table('reading_periods')->where('id', $id)->update(['status' => 'locked']);

        // Assert
        expect($this->operator->table('reading_periods')->where('id', $id)->value('status'))->toBe('locked');
    });
});

describe('meter readings', function () {
    beforeEach(function () {
        $this->buildingId = TestRecords::building();
        $this->periodId = TestRecords::readingPeriod($this->buildingId);
        $this->meterId = TestRecords::meter($this->buildingId);
    });

    it('start submitted', function () {
        // Arrange
        $id = TestRecords::meterReading($this->periodId, $this->meterId);

        // Act
        $status = DB::table('meter_readings')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('submitted');
    });

    it('refuse a negative value', function (string $column, string $constraint) {
        // Arrange
        $row = [$column => -1];

        // Act & Assert
        expect(fn () => TestRecords::meterReading($this->periodId, $this->meterId, $row))->toThrow(QueryException::class, $constraint);
    })->with([
        ['previous_reading', 'ck_meter_readings_previous_reading'],
        ['current_reading', 'ck_meter_readings_current_reading'],
        ['consumption', 'ck_meter_readings_consumption'],
    ]);

    it('refuse a meter factor of zero', function () {
        // Arrange
        $row = ['meter_factor_snapshot' => 0];

        // Act & Assert
        expect(fn () => TestRecords::meterReading($this->periodId, $this->meterId, $row))
            ->toThrow(QueryException::class, 'ck_meter_readings_meter_factor_snapshot');
    });

    it('refuse a billed occupancy without its meter assignment', function () {
        // Arrange
        $flatId = TestRecords::flat($this->buildingId);
        $assignmentId = TestRecords::meterAssignment($this->meterId, $flatId, '2026-01-01');
        $occupancyAssignmentId = TestRecords::meterOccupancyAssignment($assignmentId, TestRecords::occupancy($flatId, '2026-01-01'), '2026-01-01');

        // Act & Assert
        expect(fn () => TestRecords::meterReading($this->periodId, $this->meterId, ['meter_occupancy_assignment_id' => $occupancyAssignmentId]))
            ->toThrow(QueryException::class, 'ck_meter_readings_assignment_chain');
    });

    it('refuse an assignment of another meter', function () {
        // Arrange
        $otherAssignmentId = TestRecords::meterAssignment(TestRecords::meter($this->buildingId), null, '2026-01-01');

        // Act & Assert
        expect(fn () => TestRecords::meterReading($this->periodId, $this->meterId, ['meter_assignment_id' => $otherAssignmentId]))
            ->toThrow(QueryException::class, 'fk_meter_reading_meter_assignment');
    });

    it('refuse a billed occupancy of another assignment', function () {
        // Arrange
        $flatId = TestRecords::flat($this->buildingId);
        $assignmentId = TestRecords::meterAssignment($this->meterId, $flatId, '2026-01-01');
        $otherFlatId = TestRecords::flat($this->buildingId);
        $otherAssignmentId = TestRecords::meterAssignment(TestRecords::meter($this->buildingId), $otherFlatId, '2026-01-01');
        $otherOccupancyAssignmentId = TestRecords::meterOccupancyAssignment($otherAssignmentId, TestRecords::occupancy($otherFlatId, '2026-01-01'), '2026-01-01');

        // Act & Assert
        expect(fn () => TestRecords::meterReading($this->periodId, $this->meterId, [
            'meter_assignment_id' => $assignmentId,
            'meter_occupancy_assignment_id' => $otherOccupancyAssignmentId,
        ]))->toThrow(QueryException::class, 'fk_meter_reading_occupancy_assignment');
    });

    it('refuse a meter of another building', function () {
        // Arrange
        $otherMeterId = TestRecords::meter();

        // Act & Assert
        expect(fn () => TestRecords::meterReading($this->periodId, $otherMeterId))
            ->toThrow(QueryException::class, 'fk_meter_reading_meter_building');
    });

    it('refuse a reading without a building, which would skip the building checks', function () {
        // Arrange
        $otherMeterId = TestRecords::meter();

        // Act & Assert
        expect(fn () => TestRecords::meterReading($this->periodId, $otherMeterId, ['building_id' => null]))
            ->toThrow(QueryException::class, 'building_id');
    });

    it('refuse a period of another building', function () {
        // Arrange
        $otherPeriodId = TestRecords::readingPeriod();

        // Act & Assert
        expect(fn () => TestRecords::meterReading($otherPeriodId, $this->meterId, ['building_id' => $this->buildingId]))
            ->toThrow(QueryException::class, 'fk_meter_reading_period_building');
    });

    it('refuse a second reading of a meter without billed occupancy in one period', function () {
        // Arrange
        TestRecords::meterReading($this->periodId, $this->meterId);

        // Act & Assert
        expect(fn () => TestRecords::meterReading($this->periodId, $this->meterId))
            ->toThrow(QueryException::class, 'uq_meter_reading_period_unassigned_meter');
    });

    describe('with billed occupancies', function () {
        beforeEach(function () {
            $flatId = TestRecords::flat($this->buildingId);
            $this->assignmentId = TestRecords::meterAssignment($this->meterId, $flatId, '2026-01-01');
            $this->outgoingId = TestRecords::meterOccupancyAssignment(
                $this->assignmentId, TestRecords::occupancy($flatId, '2026-01-01'), '2026-01-01', ['valid_to' => '2026-10-15'],
            );
            $this->incomingId = TestRecords::meterOccupancyAssignment(
                $this->assignmentId, TestRecords::occupancy($flatId, '2026-10-16'), '2026-10-16', ['opening_reading' => 10],
            );
        });

        it('refuse a second reading for the same occupancy in one period', function () {
            // Arrange
            $row = ['meter_assignment_id' => $this->assignmentId, 'meter_occupancy_assignment_id' => $this->outgoingId];
            TestRecords::meterReading($this->periodId, $this->meterId, $row);

            // Act & Assert
            expect(fn () => TestRecords::meterReading($this->periodId, $this->meterId, $row))
                ->toThrow(QueryException::class, 'uq_meter_reading_period_occupancy');
        });

        it('allow one reading per occupancy when the flat changes hands mid-month', function () {
            // Arrange
            TestRecords::meterReading($this->periodId, $this->meterId, [
                'meter_assignment_id' => $this->assignmentId,
                'meter_occupancy_assignment_id' => $this->outgoingId,
            ]);

            // Act
            TestRecords::meterReading($this->periodId, $this->meterId, [
                'meter_assignment_id' => $this->assignmentId,
                'meter_occupancy_assignment_id' => $this->incomingId,
                'previous_reading' => 10,
                'current_reading' => 18,
                'consumption' => 8,
            ]);

            // Assert
            expect(DB::table('meter_readings')->where('reading_period_id', $this->periodId)->count())->toBe(2);
        });
    });

    it('refuse an idempotency key that was already used', function () {
        // Arrange
        $key = (string) Str::uuid7();
        TestRecords::meterReading($this->periodId, $this->meterId, ['idempotency_key' => $key]);

        // Act & Assert
        expect(fn () => TestRecords::meterReading(TestRecords::readingPeriod($this->buildingId, 11), $this->meterId, ['idempotency_key' => $key]))
            ->toThrow(QueryException::class, 'meter_readings_idempotency_key_unique');
    });

    it('can be superseded once approved', function () {
        // Arrange
        $id = TestRecords::meterReading($this->periodId, $this->meterId);
        DB::table('meter_readings')->where('id', $id)->update(['status' => 'approved']);

        // Act
        DB::table('meter_readings')->where('id', $id)->update(['status' => 'superseded']);

        // Assert
        expect(DB::table('meter_readings')->where('id', $id)->value('status'))->toBe('superseded');
    });

    it('cannot go back to submitted once approved', function () {
        // Arrange
        $id = TestRecords::meterReading($this->periodId, $this->meterId);
        DB::table('meter_readings')->where('id', $id)->update(['status' => 'approved']);

        // Act & Assert
        expect(fn () => DB::table('meter_readings')->where('id', $id)->update(['status' => 'submitted']))
            ->toThrow(QueryException::class, 'Status transition approved -> submitted is not allowed for meter_readings');
    });

    it('can be resubmitted after a correction request', function () {
        // Arrange
        $id = TestRecords::meterReading($this->periodId, $this->meterId);
        DB::table('meter_readings')->where('id', $id)->update(['status' => 'correction_requested']);

        // Act
        DB::table('meter_readings')->where('id', $id)->update(['status' => 'submitted']);

        // Assert
        expect(DB::table('meter_readings')->where('id', $id)->value('status'))->toBe('submitted');
    });
});

describe('reading revisions', function () {
    it('refuse a revision number the reading already has', function () {
        // Arrange
        $row = [
            'meter_reading_id' => TestRecords::meterReading(TestRecords::readingPeriod()),
            'revision_no' => 1,
            'previous_reading' => 0,
            'current_reading' => 12,
            'consumption' => 12,
            'reason' => 'Photo shows 12, not 10',
            'submitted_by' => TestRecords::user(),
        ];
        DB::table('meter_reading_revisions')->insert($row);

        // Act & Assert
        expect(fn () => DB::table('meter_reading_revisions')->insert($row))
            ->toThrow(QueryException::class, 'meter_reading_revisions_meter_reading_id_revision_no_unique');
    });
});

describe('reading approvals', function () {
    it('refuse an unknown decision', function () {
        // Arrange
        $row = [
            'meter_reading_id' => TestRecords::meterReading(TestRecords::readingPeriod()),
            'reviewer_user_id' => TestRecords::user(),
            'decision' => 'maybe',
        ];

        // Act & Assert
        expect(fn () => DB::table('reading_approvals')->insert($row))->toThrow(QueryException::class, 'ck_reading_approvals_decision');
    });
});

/**
 * Moves an open reading period along its transitions to the given status.
 */
function moveReadingPeriod(int $id, string $status): void
{
    $path = [
        'collecting' => ['collecting'],
        'review' => ['collecting', 'review'],
        'approved' => ['collecting', 'review', 'approved'],
        'locked' => ['collecting', 'review', 'approved', 'locked'],
        'cancelled' => ['cancelled'],
    ][$status];

    foreach ($path as $next) {
        DB::table('reading_periods')->where('id', $id)->update(['status' => $next]);
    }
}

describe('readings of a closed period', function () {
    beforeEach(function () {
        $this->periodId = TestRecords::readingPeriod();
    });

    it('can still be decided while the period is in review', function () {
        // Arrange
        $id = TestRecords::meterReading($this->periodId);
        moveReadingPeriod($this->periodId, 'review');

        // Act
        DB::table('meter_readings')->where('id', $id)->update(['status' => 'approved']);

        // Assert
        expect(DB::table('meter_readings')->where('id', $id)->value('status'))->toBe('approved');
    });

    it('cannot be added once the period is closed', function (string $status) {
        // Arrange
        moveReadingPeriod($this->periodId, $status);

        // Act & Assert
        expect(fn () => TestRecords::meterReading($this->periodId))
            ->toThrow(QueryException::class, "reading_periods {$this->periodId} is {$status}: its meter_readings can no longer change");
    })->with(['approved', 'locked', 'cancelled']);

    it('cannot be superseded once the period is locked', function () {
        // Arrange
        $id = TestRecords::meterReading($this->periodId);
        moveReadingPeriod($this->periodId, 'review');
        DB::table('meter_readings')->where('id', $id)->update(['status' => 'approved']);
        DB::table('reading_periods')->where('id', $this->periodId)->update(['status' => 'approved']);
        DB::table('reading_periods')->where('id', $this->periodId)->update(['status' => 'locked']);

        // Act & Assert
        expect(fn () => DB::table('meter_readings')->where('id', $id)->update(['status' => 'superseded']))
            ->toThrow(QueryException::class, 'can no longer change');
    });

    it('cannot have their numbers changed once the period is approved', function () {
        // Arrange
        $id = TestRecords::meterReading($this->periodId);
        moveReadingPeriod($this->periodId, 'approved');

        // Act & Assert
        expect(fn () => DB::table('meter_readings')->where('id', $id)->update(['current_reading' => 11, 'consumption' => 11]))
            ->toThrow(QueryException::class, 'can no longer change');
    });
});
