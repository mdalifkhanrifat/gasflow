<?php

/**
 * Migration group 10, part 3: invoice lines (design 02 §6, V7.16, V7.17).
 *
 * Ports the invoice line cases of gasflow-design-v7/tests/tariff-tests.sql:
 * 93 (a gas line needs its tariff and days), 94 (the tariff belongs to the
 * invoice's vendor assignment) and 95 (lines of an approved invoice are
 * frozen).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

beforeEach(function () {
    $this->invoiceId = TestRecords::invoice();
});

describe('lifecycle registration', function () {
    it('registers the table with the policies of the design', function () {
        // Arrange: migration group 10 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', 'invoice_lines')->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe(['billing', 'financial', 'protected', 'forbidden', false, 'active']);
    });

    it('can never delete or hide a line', function () {
        // Arrange
        $id = TestRecords::gasChargeLine($this->invoiceId);

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('invoice_lines')->where('id', $id)->delete()))
            ->toThrow(QueryException::class, 'Records in invoice_lines are protected')
            ->and(fn () => DB::transaction(fn () => DB::table('invoice_lines')->where('id', $id)->update(['is_deleted' => true])))
            ->toThrow(QueryException::class, 'Records in invoice_lines are protected');
    });
});

describe('gas lines', function () {
    it('record the rate, the days and how the quantity was split', function () {
        // Arrange
        $tariffId = TestRecords::tariffVersion(DB::table('invoices')->where('id', $this->invoiceId)->value('building_vendor_assignment_id'));

        // Act
        $id = TestRecords::gasChargeLine($this->invoiceId, $tariffId, ['split_method' => 'own_meter']);

        // Assert
        expect(DB::table('invoice_lines')->where('id', $id)->first(['tariff_version_id', 'segment_from', 'segment_to', 'split_method']))
            ->toEqual((object) ['tariff_version_id' => $tariffId, 'segment_from' => '2026-10-01', 'segment_to' => '2026-10-10', 'split_method' => 'own_meter']);
    });

    it('need their tariff version and days (design 93)', function (string $column) {
        // Arrange
        $row = [$column => null];

        // Act & Assert
        expect(fn () => TestRecords::gasChargeLine($this->invoiceId, overrides: $row))
            ->toThrow(QueryException::class, 'ck_invoice_lines_gas_charge_tariff');
    })->with(['tariff_version_id', 'segment_from', 'segment_to']);

    it('need to say how the quantity was split', function () {
        // Arrange
        $row = ['split_method' => null];

        // Act & Assert
        expect(fn () => TestRecords::gasChargeLine($this->invoiceId, overrides: $row))
            ->toThrow(QueryException::class, 'ck_invoice_lines_gas_charge_split');
    });

    it('refuse an unknown split method', function () {
        // Arrange
        $row = ['split_method' => 'guess'];

        // Act & Assert
        expect(fn () => TestRecords::gasChargeLine($this->invoiceId, overrides: $row))
            ->toThrow(QueryException::class, 'ck_invoice_lines_split_method');
    });

    it('refuse a last day before the first', function () {
        // Arrange
        $row = ['segment_from' => '2026-10-10', 'segment_to' => '2026-10-01'];

        // Act & Assert
        expect(fn () => TestRecords::gasChargeLine($this->invoiceId, overrides: $row))
            ->toThrow(QueryException::class, 'ck_invoice_lines_segment');
    });

    it('refuse a tariff version of another vendor assignment (design 94)', function () {
        // Arrange
        $otherTariffId = TestRecords::tariffVersion();

        // Act & Assert
        expect(fn () => TestRecords::gasChargeLine($this->invoiceId, $otherTariffId))
            ->toThrow(QueryException::class, 'does not belong to the vendor assignment of invoice');
    });

    it('refuse a later change to a tariff version of another vendor assignment', function () {
        // Arrange
        $id = TestRecords::gasChargeLine($this->invoiceId);

        // Act & Assert
        expect(fn () => DB::table('invoice_lines')->where('id', $id)->update(['tariff_version_id' => TestRecords::tariffVersion()]))
            ->toThrow(QueryException::class, 'does not belong to the vendor assignment of invoice');
    });

    it('refuse a move to an invoice of another vendor assignment', function () {
        // Arrange
        $id = TestRecords::gasChargeLine($this->invoiceId);

        // Act & Assert
        expect(fn () => DB::table('invoice_lines')->where('id', $id)->update(['invoice_id' => TestRecords::invoice()]))
            ->toThrow(QueryException::class, 'does not belong to the vendor assignment of invoice');
    });
});

describe('other lines', function () {
    it('refuse an unknown line type', function () {
        // Arrange
        $row = ['line_type' => 'tip'];

        // Act & Assert
        expect(fn () => TestRecords::gasChargeLine($this->invoiceId, overrides: $row))
            ->toThrow(QueryException::class, 'ck_invoice_lines_line_type');
    });

    it('need no tariff for a service charge', function () {
        // Arrange
        $row = ['invoice_id' => $this->invoiceId, 'line_type' => 'service_charge', 'description' => 'Service charge', 'line_total' => 100];

        // Act
        $id = DB::table('invoice_lines')->insertGetId($row);

        // Assert
        expect(DB::table('invoice_lines')->where('id', $id)->value('tariff_version_id'))->toBeNull();
    });

    describe('retro adjustments', function () {
        beforeEach(function () {
            $this->originalLineId = TestRecords::gasChargeLine($this->invoiceId);
            $this->assignmentId = DB::table('invoices')->where('id', $this->invoiceId)->value('building_vendor_assignment_id');
            $this->supplementaryId = TestRecords::invoice(
                DB::table('invoices')->where('id', $this->invoiceId)->value('billing_cycle_id'),
                ['segment_no' => 2, 'subtotal' => 300, 'total' => 300],
            );
        });

        it('name the new rate and the line they correct', function (string $column) {
            // Arrange
            $row = [
                'invoice_id' => $this->supplementaryId,
                'line_type' => 'retro_adjustment',
                'description' => 'Retro rate 1–10 Oct',
                'line_total' => 300,
                'tariff_version_id' => TestRecords::tariffVersion($this->assignmentId),
                'adjusts_invoice_line_id' => $this->originalLineId,
                $column => null,
            ];

            // Act & Assert
            expect(fn () => DB::table('invoice_lines')->insert($row))
                ->toThrow(QueryException::class, 'ck_invoice_lines_retro_adjustment');
        })->with(['tariff_version_id', 'adjusts_invoice_line_id']);

        it('bill the difference on a supplementary invoice', function () {
            // Arrange
            $row = [
                'invoice_id' => $this->supplementaryId,
                'line_type' => 'retro_adjustment',
                'description' => 'Retro rate 1–10 Oct',
                'line_total' => 300,
                'tariff_version_id' => TestRecords::tariffVersion($this->assignmentId),
                'adjusts_invoice_line_id' => $this->originalLineId,
            ];

            // Act
            $id = DB::table('invoice_lines')->insertGetId($row);

            // Assert
            expect(DB::table('invoice_lines')->where('id', $id)->value('adjusts_invoice_line_id'))->toBe($this->originalLineId);
        });
    });
});

describe('lines of a closed invoice', function () {
    it('can still change while the invoice is a draft', function () {
        // Arrange
        $id = TestRecords::gasChargeLine($this->invoiceId);

        // Act
        DB::table('invoice_lines')->where('id', $id)->update(['line_total' => 2900]);

        // Assert
        expect(DB::table('invoice_lines')->where('id', $id)->value('line_total'))->toBe('2900.00');
    });

    it('can no longer change once the invoice is approved (design 95)', function () {
        // Arrange
        $id = TestRecords::gasChargeLine($this->invoiceId);
        DB::table('invoices')->where('id', $this->invoiceId)->update(['status' => 'approved']);

        // Act & Assert
        expect(fn () => DB::table('invoice_lines')->where('id', $id)->update(['line_total' => 2900]))
            ->toThrow(QueryException::class, 'its invoice_lines can no longer change');
    });

    it('cannot be added once the invoice has left draft', function (array $statuses) {
        // Arrange
        foreach ($statuses as $status) {
            DB::table('invoices')->where('id', $this->invoiceId)->update(['status' => $status]);
        }

        // Act & Assert
        expect(fn () => TestRecords::gasChargeLine($this->invoiceId))
            ->toThrow(QueryException::class, 'its invoice_lines can no longer change');
    })->with([
        'approved' => [['approved']],
        'issued' => [['approved', 'issued']],
        'partially paid' => [['approved', 'issued', 'partially_paid']],
        'paid' => [['approved', 'issued', 'paid']],
        'overdue' => [['approved', 'issued', 'overdue']],
        'void' => [['void']],
        'written off' => [['approved', 'issued', 'written_off']],
    ]);
});
