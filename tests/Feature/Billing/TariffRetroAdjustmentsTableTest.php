<?php

/**
 * Migration group 10, part 5: retroactive rate differences (design 02 V7.16,
 * D44; building tie as D42).
 *
 * Ports gasflow-design-v7/tests/tariff-tests.sql 96 (difference =
 * recalculated − billed) and 97 (one adjustment per line and version).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

beforeEach(function () {
    $this->invoiceId = TestRecords::invoice();
    $this->assignmentId = DB::table('invoices')->where('id', $this->invoiceId)->value('building_vendor_assignment_id');
    $this->lineId = TestRecords::gasChargeLine($this->invoiceId);
    $this->retroTariffId = TestRecords::tariffVersion($this->assignmentId, overrides: ['rate' => 33]);
});

describe('lifecycle registration', function () {
    it('registers the table with the policies of the design', function () {
        // Arrange: migration group 10 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', 'tariff_retro_adjustments')->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe(['billing', 'financial', 'protected', 'forbidden', true, 'pending']);
    });

    it('can never delete or hide an adjustment', function () {
        // Arrange
        $id = TestRecords::retroAdjustment($this->lineId, $this->retroTariffId);

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('tariff_retro_adjustments')->where('id', $id)->delete()))
            ->toThrow(QueryException::class, 'Records in tariff_retro_adjustments are protected')
            ->and(fn () => DB::transaction(fn () => DB::table('tariff_retro_adjustments')->where('id', $id)->update(['is_deleted' => true])))
            ->toThrow(QueryException::class, 'Records in tariff_retro_adjustments are protected');
    });
});

describe('retro adjustments', function () {
    it('start pending', function () {
        // Arrange
        $id = TestRecords::retroAdjustment($this->lineId, $this->retroTariffId);

        // Act
        $status = DB::table('tariff_retro_adjustments')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('pending');
    });

    it('refuse a difference that is not recalculated minus billed (design 96)', function () {
        // Arrange
        $row = ['difference' => 200];

        // Act & Assert
        expect(fn () => TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, $row))
            ->toThrow(QueryException::class, 'ck_tariff_retro_adjustments_difference');
    });

    it('accept a lower rate as a negative difference', function () {
        // Arrange
        $row = ['recalculated_amount' => 2800, 'difference' => -200];

        // Act
        $id = TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, $row);

        // Assert
        expect(DB::table('tariff_retro_adjustments')->where('id', $id)->value('difference'))->toBe('-200.00');
    });

    it('refuse a second adjustment of the line for the same version (design 97)', function () {
        // Arrange
        TestRecords::retroAdjustment($this->lineId, $this->retroTariffId);

        // Act & Assert
        expect(fn () => TestRecords::retroAdjustment($this->lineId, $this->retroTariffId))
            ->toThrow(QueryException::class, 'uq_tariff_retro_adjustment_line_version');
    });

    it('allow another adjustment of the line for a later version', function () {
        // Arrange
        TestRecords::retroAdjustment($this->lineId, $this->retroTariffId);
        $laterTariffId = TestRecords::tariffVersion($this->assignmentId, overrides: ['rate' => 35]);

        // Act
        TestRecords::retroAdjustment($this->lineId, $laterTariffId, ['recalculated_amount' => 3500, 'difference' => 500]);

        // Assert
        expect(DB::table('tariff_retro_adjustments')->where('original_invoice_line_id', $this->lineId)->count())->toBe(2);
    });

    it('refuse a last day before the first', function () {
        // Arrange
        $row = ['segment_from' => '2026-10-10', 'segment_to' => '2026-10-01'];

        // Act & Assert
        expect(fn () => TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, $row))
            ->toThrow(QueryException::class, 'ck_tariff_retro_adjustments_range');
    });

    it('refuse a negative quantity', function () {
        // Arrange
        $row = ['quantity' => -1];

        // Act & Assert
        expect(fn () => TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, $row))
            ->toThrow(QueryException::class, 'ck_tariff_retro_adjustments_quantity');
    });

    it('refuse a tariff version of another building', function () {
        // Arrange
        $row = ['building_id' => TestRecords::building()];

        // Act & Assert
        expect(fn () => TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, $row))
            ->toThrow(QueryException::class, 'fk_tariff_retro_adjustment_tariff');
    });

    it('refuse to be billed and credited at once', function () {
        // Arrange
        $row = [
            'adjustment_invoice_line_id' => TestRecords::gasChargeLine(TestRecords::invoice()),
            'credit_note_id' => TestRecords::creditNote($this->invoiceId),
        ];

        // Act & Assert
        expect(fn () => TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, $row))
            ->toThrow(QueryException::class, 'ck_tariff_retro_adjustments_settlement');
    });

    it('can be credited instead of billed', function () {
        // Arrange
        $row = ['recalculated_amount' => 2800, 'difference' => -200, 'credit_note_id' => TestRecords::creditNote($this->invoiceId)];

        // Act
        $id = TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, $row);

        // Assert
        expect(DB::table('tariff_retro_adjustments')->where('id', $id)->value('credit_note_id'))->not->toBeNull();
    });

    it('end applied or waived', function (string $status) {
        // Arrange
        $id = TestRecords::retroAdjustment($this->lineId, $this->retroTariffId);

        // Act
        DB::table('tariff_retro_adjustments')->where('id', $id)->update(['status' => $status]);

        // Assert
        expect(DB::table('tariff_retro_adjustments')->where('id', $id)->value('status'))->toBe($status);
    })->with(['applied', 'waived']);

    it('cannot be reopened once applied', function () {
        // Arrange
        $id = TestRecords::retroAdjustment($this->lineId, $this->retroTariffId);
        DB::table('tariff_retro_adjustments')->where('id', $id)->update(['status' => 'applied']);

        // Act & Assert
        expect(fn () => DB::table('tariff_retro_adjustments')->where('id', $id)->update(['status' => 'pending']))
            ->toThrow(QueryException::class, 'Status transition applied -> pending is not allowed');
    });
});
