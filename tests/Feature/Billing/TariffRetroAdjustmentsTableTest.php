<?php

/**
 * Migration group 10, part 5: retroactive rate differences (design 02 V7.16,
 * D44; building tie as D42; ties and freeze as D46, V7.19).
 *
 * Ports gasflow-design-v7/tests/tariff-tests.sql 96 (difference =
 * recalculated − billed) and 97 (one adjustment per line and version).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * The retro_adjustment line that bills the difference of the line at the
 * tariff version, on a new supplementary invoice of the same flat.
 */
function retroSettlementLine(int $originalLineId, int $tariffVersionId): int
{
    $original = DB::table('invoice_lines')->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
        ->where('invoice_lines.id', $originalLineId)->first(['invoices.billing_cycle_id', 'invoices.flat_id']);
    $supplementaryId = TestRecords::invoice($original->billing_cycle_id, ['flat_id' => $original->flat_id, 'segment_no' => 2]);

    return DB::table('invoice_lines')->insertGetId([
        'invoice_id' => $supplementaryId,
        'line_type' => 'retro_adjustment',
        'description' => 'Retro rate 1–10 Oct',
        'line_total' => 300,
        'tariff_version_id' => $tariffVersionId,
        'adjusts_invoice_line_id' => $originalLineId,
    ]);
}

beforeEach(function () {
    $this->invoiceId = TestRecords::invoice();
    $this->assignmentId = DB::table('invoices')->where('id', $this->invoiceId)->value('building_vendor_assignment_id');
    $this->lineId = TestRecords::gasChargeLine($this->invoiceId);
    TestRecords::moveInvoice($this->invoiceId, ['approved', 'issued']);
    $this->retroTariffId = TestRecords::approvedTariffVersion($this->assignmentId, 'RETRO');
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
        $laterTariffId = TestRecords::approvedTariffVersion($this->assignmentId, 'RETRO2');

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

    it('refuse to be billed and credited at once', function () {
        // Arrange
        $row = [
            'adjustment_invoice_line_id' => retroSettlementLine($this->lineId, $this->retroTariffId),
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

    it('can be billed on its retro adjustment line', function () {
        // Arrange
        $row = ['adjustment_invoice_line_id' => retroSettlementLine($this->lineId, $this->retroTariffId)];

        // Act
        $id = TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, $row);

        // Assert
        expect(DB::table('tariff_retro_adjustments')->where('id', $id)->value('adjustment_invoice_line_id'))->not->toBeNull();
    });

    it('are waived with no settlement', function () {
        // Arrange
        $id = TestRecords::retroAdjustment($this->lineId, $this->retroTariffId);

        // Act
        DB::table('tariff_retro_adjustments')->where('id', $id)->update(['status' => 'waived']);

        // Assert
        expect(DB::table('tariff_retro_adjustments')->where('id', $id)->value('status'))->toBe('waived');
    });

    it('are applied with the settlement set in the same update', function () {
        // Arrange
        $id = TestRecords::retroAdjustment($this->lineId, $this->retroTariffId);

        // Act
        DB::table('tariff_retro_adjustments')->where('id', $id)->update(['status' => 'applied', 'credit_note_id' => TestRecords::creditNote($this->invoiceId)]);

        // Assert
        expect(DB::table('tariff_retro_adjustments')->where('id', $id)->value('status'))->toBe('applied');
    });

    it('cannot be reopened once applied', function () {
        // Arrange
        $id = TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, ['credit_note_id' => TestRecords::creditNote($this->invoiceId)]);
        DB::table('tariff_retro_adjustments')->where('id', $id)->update(['status' => 'applied']);

        // Act & Assert
        expect(fn () => DB::table('tariff_retro_adjustments')->where('id', $id)->update(['status' => 'pending']))
            ->toThrow(QueryException::class, 'Status transition applied -> pending is not allowed');
    });
});

describe('retro adjustment ties (D46)', function () {
    it('refuse to be applied without a settlement', function () {
        // Arrange
        $id = TestRecords::retroAdjustment($this->lineId, $this->retroTariffId);

        // Act & Assert
        expect(fn () => DB::table('tariff_retro_adjustments')->where('id', $id)->update(['status' => 'applied']))
            ->toThrow(QueryException::class, 'ck_tariff_retro_adjustments_applied_settlement');
    });

    it('refuse a line of another building', function () {
        // Arrange
        $row = ['building_id' => TestRecords::building()];

        // Act & Assert
        expect(fn () => TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, $row))
            ->toThrow(QueryException::class, "Line {$this->lineId} belongs to another building");
    });

    it('refuse a tariff version of another building', function () {
        // Arrange
        $otherBuildingTariffId = TestRecords::approvedTariffVersion(TestRecords::vendorAssignment(TestRecords::building(), '2026-01-01'));

        // Act & Assert
        expect(fn () => DB::table('tariff_retro_adjustments')->insert([
            'building_id' => DB::table('invoices')->where('id', $this->invoiceId)->value('building_id'),
            'tariff_version_id' => $otherBuildingTariffId,
            'original_invoice_line_id' => $this->lineId,
            'segment_from' => '2026-10-01', 'segment_to' => '2026-10-10', 'quantity' => 100,
            'billed_amount' => 3000, 'recalculated_amount' => 3300, 'difference' => 300,
        ]))->toThrow(QueryException::class, "Tariff version {$otherBuildingTariffId} is not of the vendor assignment");
    });

    it('refuse a line of a draft invoice', function () {
        // Arrange
        $draftLineId = TestRecords::gasChargeLine(TestRecords::invoice(
            DB::table('invoices')->where('id', $this->invoiceId)->value('billing_cycle_id'),
        ));

        // Act & Assert
        expect(fn () => TestRecords::retroAdjustment($draftLineId, $this->retroTariffId))
            ->toThrow(QueryException::class, 'is draft: only issued lines are recalculated');
    });

    it('refuse a line that is not a gas line', function () {
        // Arrange
        $serviceLineId = DB::table('invoice_lines')->insertGetId([
            'invoice_id' => TestRecords::invoice(DB::table('invoices')->where('id', $this->invoiceId)->value('billing_cycle_id')),
            'line_type' => 'service_charge', 'description' => 'Service', 'line_total' => 100,
            'segment_from' => '2026-10-01', 'segment_to' => '2026-10-10',
        ]);

        // Act & Assert
        expect(fn () => TestRecords::retroAdjustment($serviceLineId, $this->retroTariffId))
            ->toThrow(QueryException::class, 'recalculates a gas line, not a service_charge line');
    });

    it('refuse days outside the recalculated line', function () {
        // Arrange
        $row = ['segment_to' => '2026-10-11'];

        // Act & Assert
        expect(fn () => TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, $row))
            ->toThrow(QueryException::class, "outside line {$this->lineId}");
    });

    it('refuse a settlement line that corrects another line', function () {
        // Arrange
        $otherLineId = TestRecords::gasChargeLine(TestRecords::invoice(
            DB::table('invoices')->where('id', $this->invoiceId)->value('billing_cycle_id'),
        ));
        $row = ['adjustment_invoice_line_id' => $otherLineId];

        // Act & Assert
        expect(fn () => TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, $row))
            ->toThrow(QueryException::class, "is not the retro adjustment of line {$this->lineId}");
    });

    it('refuse the retro line of another issued line of the same flat', function () {
        // Arrange
        $original = DB::table('invoices')->where('id', $this->invoiceId)->first();
        $secondInvoiceId = TestRecords::invoice($original->billing_cycle_id, ['flat_id' => $original->flat_id, 'segment_no' => 3]);
        $secondLineId = TestRecords::gasChargeLine($secondInvoiceId);
        TestRecords::moveInvoice($secondInvoiceId, ['approved', 'issued']);
        $row = ['adjustment_invoice_line_id' => retroSettlementLine($secondLineId, $this->retroTariffId)];

        // Act & Assert
        expect(fn () => TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, $row))
            ->toThrow(QueryException::class, "is not the retro adjustment of line {$this->lineId}");
    });

    it('refuse a wrong settlement given when it is applied', function () {
        // Arrange
        $id = TestRecords::retroAdjustment($this->lineId, $this->retroTariffId);

        // Act & Assert
        expect(fn () => DB::table('tariff_retro_adjustments')->where('id', $id)->update(['status' => 'applied', 'credit_note_id' => TestRecords::creditNote()]))
            ->toThrow(QueryException::class, "is not on the invoice of line {$this->lineId}");
    });

    it('refuse a settlement line at another tariff version', function () {
        // Arrange
        $row = ['adjustment_invoice_line_id' => retroSettlementLine($this->lineId, TestRecords::approvedTariffVersion($this->assignmentId, 'RETRO2'))];

        // Act & Assert
        expect(fn () => TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, $row))
            ->toThrow(QueryException::class, 'is not the retro adjustment of line');
    });

    it('refuse a credit note of another invoice', function () {
        // Arrange
        $row = ['credit_note_id' => TestRecords::creditNote()];

        // Act & Assert
        expect(fn () => TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, $row))
            ->toThrow(QueryException::class, "is not on the invoice of line {$this->lineId}");
    });

    it('can change while pending', function () {
        // Arrange
        $id = TestRecords::retroAdjustment($this->lineId, $this->retroTariffId);

        // Act
        DB::table('tariff_retro_adjustments')->where('id', $id)->update(['recalculated_amount' => 3600, 'difference' => 600]);

        // Assert
        expect(DB::table('tariff_retro_adjustments')->where('id', $id)->value('difference'))->toBe('600.00');
    });

    it('keep their numbers once decided', function (string $status) {
        // Arrange
        $id = TestRecords::retroAdjustment($this->lineId, $this->retroTariffId, ['credit_note_id' => TestRecords::creditNote($this->invoiceId)]);
        DB::table('tariff_retro_adjustments')->where('id', $id)->update(['status' => $status]);

        // Act & Assert
        expect(fn () => DB::table('tariff_retro_adjustments')->where('id', $id)->update(['billed_amount' => 2000, 'difference' => 1300]))
            ->toThrow(QueryException::class, 'can no longer change');
    })->with(['applied', 'waived']);

    it('freeze every number and link once decided', function () {
        // Arrange
        $mayChange = ['id', 'status', 'is_active', 'is_deleted', 'deleted_at', 'created_at', 'updated_at',
            'created_by', 'updated_by', 'created_session_id', 'updated_session_id'];
        $columns = DB::table('information_schema.columns')->where('table_name', 'tariff_retro_adjustments')->pluck('column_name')->all();

        // Act
        $definition = DB::selectOne(
            "select pg_get_triggerdef(oid) as definition from pg_trigger where tgname = 'trg_tariff_retro_adjustments_freeze_decided'",
        )->definition;
        preg_match_all("/'([a-z_]+)'/", $definition, $arguments);

        // Assert
        expect(array_slice($arguments[1], 1))->toEqualCanonicalizing(array_values(array_diff($columns, $mayChange)));
    });
});
