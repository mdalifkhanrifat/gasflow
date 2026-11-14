<?php

/**
 * Migration group 9, part 2: meter reconciliations (design 02 §6, V7.7, V7.15).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

beforeEach(function () {
    $this->buildingId = TestRecords::building();
    $this->periodId = TestRecords::readingPeriod($this->buildingId);
    $this->mainMeterId = TestRecords::meter($this->buildingId, ['meter_type' => 'main']);
});

/**
 * A reconciliation row of the test building: 100 on the main meter, 96 on the
 * sub-meters.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function reconciliationRow(object $test, array $overrides = []): array
{
    return [
        'building_id' => $test->buildingId,
        'reading_period_id' => $test->periodId,
        'main_meter_id' => $test->mainMeterId,
        'main_consumption' => 100,
        'submeter_total' => 96,
        'variance' => 4,
        ...$overrides,
    ];
}

describe('meter reconciliations', function () {
    it('are registered as protected reconciliation history', function () {
        // Arrange: migration group 9 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', 'meter_reconciliations')->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->default_status])
            ->toBe(['reconciliation', 'history', 'protected', 'forbidden', 'pending_review']);
    });

    it('start pending review', function () {
        // Arrange
        $id = DB::table('meter_reconciliations')->insertGetId(reconciliationRow($this));

        // Act
        $status = DB::table('meter_reconciliations')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('pending_review');
    });

    it('refuse a second reconciliation of the same period', function () {
        // Arrange
        DB::table('meter_reconciliations')->insert(reconciliationRow($this));

        // Act & Assert
        expect(fn () => DB::table('meter_reconciliations')->insert(reconciliationRow($this)))
            ->toThrow(QueryException::class, 'meter_reconciliations_building_id_reading_period_id_unique');
    });

    it('refuse a reading period of another building', function () {
        // Arrange
        $row = reconciliationRow($this, ['reading_period_id' => TestRecords::readingPeriod()]);

        // Act & Assert
        expect(fn () => DB::table('meter_reconciliations')->insert($row))
            ->toThrow(QueryException::class, 'fk_meter_reconciliation_period_building');
    });

    it('refuse a main meter of another building', function () {
        // Arrange
        $row = reconciliationRow($this, ['main_meter_id' => TestRecords::meter(overrides: ['meter_type' => 'main'])]);

        // Act & Assert
        expect(fn () => DB::table('meter_reconciliations')->insert($row))
            ->toThrow(QueryException::class, 'fk_meter_reconciliation_main_meter');
    });

    it('can never be deleted', function () {
        // Arrange
        $id = DB::table('meter_reconciliations')->insertGetId(reconciliationRow($this));

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('meter_reconciliations')->where('id', $id)->delete()))
            ->toThrow(QueryException::class, 'Records in meter_reconciliations are protected');
    });
});

describe('reconciliation checks', function () {
    it('refuse a variance that is not main minus sub-meters', function () {
        // Arrange
        $row = reconciliationRow($this, ['variance' => 50]);

        // Act & Assert
        expect(fn () => DB::table('meter_reconciliations')->insert($row))
            ->toThrow(QueryException::class, 'ck_meter_reconciliations_variance');
    });

    it('accept a negative variance when the sub-meters read more', function () {
        // Arrange
        $row = reconciliationRow($this, ['submeter_total' => 103, 'variance' => -3]);

        // Act
        $id = DB::table('meter_reconciliations')->insertGetId($row);

        // Assert
        expect((float) DB::table('meter_reconciliations')->where('id', $id)->value('variance'))->toBe(-3.0);
    });

    it('refuse a sub-meter as the main meter', function () {
        // Arrange
        $row = reconciliationRow($this, ['main_meter_id' => TestRecords::meter($this->buildingId)]);

        // Act & Assert
        expect(fn () => DB::table('meter_reconciliations')->insert($row))
            ->toThrow(QueryException::class, 'fk_meter_reconciliation_main_meter');
    });

    it('refuse a sub-meter passed off with another meter type', function () {
        // Arrange
        $row = reconciliationRow($this, ['main_meter_id' => TestRecords::meter($this->buildingId), 'main_meter_type' => 'sub_meter']);

        // Act & Assert
        expect(fn () => DB::table('meter_reconciliations')->insert($row))
            ->toThrow(QueryException::class, 'ck_meter_reconciliations_main_meter_type');
    });

    it('keep a reconciled main meter from being retyped', function () {
        // Arrange
        DB::table('meter_reconciliations')->insert(reconciliationRow($this));

        // Act & Assert
        expect(fn () => DB::table('meters')->where('id', $this->mainMeterId)->update(['meter_type' => 'sub_meter']))
            ->toThrow(QueryException::class, 'fk_meter_reconciliation_main_meter');
    });
});

describe('reconciliation review', function () {
    beforeEach(function () {
        $this->id = DB::table('meter_reconciliations')->insertGetId(reconciliationRow($this));
    });

    it('records the decision with its note', function () {
        // Act
        DB::table('meter_reconciliations')->where('id', $this->id)
            ->update(['status' => 'accepted', 'resolution_note' => 'Within the 5% loss allowance', 'reviewed_at' => now()]);

        // Assert
        expect(DB::table('meter_reconciliations')->where('id', $this->id)->value('resolution_note'))->toBe('Within the 5% loss allowance');
    });

    it('refuse to start already decided', function () {
        // Arrange
        $row = reconciliationRow($this, ['reading_period_id' => TestRecords::readingPeriod($this->buildingId, 11), 'status' => 'accepted']);

        // Act & Assert
        expect(fn () => DB::table('meter_reconciliations')->insert($row))
            ->toThrow(QueryException::class, 'Status "accepted" is not an initial status for meter_reconciliations');
    });

    it('cannot be reopened once decided', function () {
        // Arrange
        DB::table('meter_reconciliations')->where('id', $this->id)->update(['status' => 'accepted']);

        // Act & Assert
        expect(fn () => DB::table('meter_reconciliations')->where('id', $this->id)->update(['status' => 'pending_review']))
            ->toThrow(QueryException::class, 'Status transition accepted -> pending_review is not allowed');
    });

    it('keep its numbers once decided', function () {
        // Arrange
        DB::table('meter_reconciliations')->where('id', $this->id)->update(['status' => 'rejected']);

        // Act & Assert
        expect(fn () => DB::table('meter_reconciliations')->where('id', $this->id)
            ->update(['main_consumption' => 96, 'variance' => 0]))
            ->toThrow(QueryException::class, "meter_reconciliations {$this->id} is rejected: main_consumption, variance can no longer change");
    });
});
