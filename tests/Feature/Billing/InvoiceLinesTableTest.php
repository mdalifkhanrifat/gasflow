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
        $tariffId = TestRecords::approvedTariffVersion(DB::table('invoices')->where('id', $this->invoiceId)->value('building_vendor_assignment_id'), 'HOMES');

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
            TestRecords::moveInvoice($this->invoiceId, ['approved', 'issued']);
            $this->original = DB::table('invoices')->where('id', $this->invoiceId)->first();
            $this->retroTariffId = TestRecords::approvedTariffVersion($this->original->building_vendor_assignment_id, 'RETRO');
            $this->supplementaryId = TestRecords::invoice($this->original->billing_cycle_id, ['flat_id' => $this->original->flat_id, 'segment_no' => 2]);
            $this->row = [
                'invoice_id' => $this->supplementaryId,
                'line_type' => 'retro_adjustment',
                'description' => 'Retro rate 1–10 Oct',
                'line_total' => 300,
                'tariff_version_id' => $this->retroTariffId,
                'adjusts_invoice_line_id' => $this->originalLineId,
            ];
        });

        it('name the new rate and the line they correct', function (string $column) {
            // Arrange
            $row = [...$this->row, $column => null];

            // Act & Assert
            expect(fn () => DB::table('invoice_lines')->insert($row))
                ->toThrow(QueryException::class, 'ck_invoice_lines_retro_adjustment');
        })->with(['tariff_version_id', 'adjusts_invoice_line_id']);

        it('bill the difference on a supplementary invoice', function () {
            // Act
            $id = DB::table('invoice_lines')->insertGetId([...$this->row, 'segment_from' => '2026-10-05', 'segment_to' => '2026-10-10']);

            // Assert
            expect(DB::table('invoice_lines')->where('id', $id)->value('adjusts_invoice_line_id'))->toBe($this->originalLineId);
        });

        it('bill an earlier month\'s difference in a later cycle', function () {
            // Arrange
            $novemberCycleId = TestRecords::billingCycle(TestRecords::approvedReadingPeriod($this->original->building_id, 11));
            $laterInvoiceId = TestRecords::invoice($novemberCycleId, ['flat_id' => $this->original->flat_id]);

            // Act
            $id = DB::table('invoice_lines')->insertGetId(
                [...$this->row, 'invoice_id' => $laterInvoiceId, 'segment_from' => '2026-10-01', 'segment_to' => '2026-10-10'],
            );

            // Assert
            expect(DB::table('invoice_lines')->where('id', $id)->value('segment_to'))->toBe('2026-10-10');
        });

        it('correct only a gas line (D46)', function () {
            // Arrange
            $serviceLineId = DB::table('invoice_lines')->insertGetId(
                ['invoice_id' => TestRecords::invoice($this->original->billing_cycle_id, ['flat_id' => $this->original->flat_id, 'segment_no' => 3]), 'line_type' => 'service_charge', 'description' => 'Service', 'line_total' => 100],
            );

            // Act & Assert
            expect(fn () => DB::table('invoice_lines')->insert([...$this->row, 'adjusts_invoice_line_id' => $serviceLineId]))
                ->toThrow(QueryException::class, 'corrects a gas line, not a service_charge line');
        });

        it('correct only a line of the same flat (D46)', function () {
            // Arrange
            $otherFlatInvoiceId = TestRecords::invoice($this->original->billing_cycle_id, ['segment_no' => 2]);

            // Act & Assert
            expect(fn () => DB::table('invoice_lines')->insert([...$this->row, 'invoice_id' => $otherFlatInvoiceId]))
                ->toThrow(QueryException::class, 'belongs to another flat or vendor assignment');
        });

        it('correct only a line of an issued invoice (D46)', function () {
            // Arrange
            $draftLineId = TestRecords::gasChargeLine(TestRecords::invoice($this->original->billing_cycle_id, ['flat_id' => $this->original->flat_id, 'segment_no' => 3]));

            // Act & Assert
            expect(fn () => DB::table('invoice_lines')->insert([...$this->row, 'adjusts_invoice_line_id' => $draftLineId]))
                ->toThrow(QueryException::class, 'is draft: only issued lines get a retro adjustment');
        });

        it('stay inside the days of the corrected line (D46)', function () {
            // Act & Assert
            expect(fn () => DB::table('invoice_lines')->insert([...$this->row, 'segment_from' => '2026-10-05', 'segment_to' => '2026-10-12']))
                ->toThrow(QueryException::class, 'outside the corrected line');
        });

        it('are the only lines that name a corrected line (D46)', function () {
            // Act & Assert
            expect(fn () => TestRecords::gasChargeLine($this->supplementaryId, overrides: ['adjusts_invoice_line_id' => $this->originalLineId]))
                ->toThrow(QueryException::class, 'ck_invoice_lines_adjusts_only_retro');
        });
    });
});

describe('gas line days and rate (D46)', function () {
    beforeEach(function () {
        $this->assignmentId = DB::table('invoices')->where('id', $this->invoiceId)->value('building_vendor_assignment_id');
    });

    it('refuse a draft rate', function () {
        // Arrange
        $draftId = TestRecords::tariffVersion($this->assignmentId, overrides: ['tariff_code' => 'DRAFT']);

        // Act & Assert
        expect(fn () => TestRecords::gasChargeLine($this->invoiceId, $draftId))
            ->toThrow(QueryException::class, "Tariff version {$draftId} is draft");
    });

    it('accept a rate that was retired after it priced these days', function () {
        // Arrange
        $tariffId = TestRecords::approvedTariffVersion($this->assignmentId, 'OLD');
        DB::table('tariff_versions')->where('id', $tariffId)->update(['status' => 'retired']);

        // Act
        $id = TestRecords::gasChargeLine($this->invoiceId, $tariffId);

        // Assert
        expect(DB::table('invoice_lines')->where('id', $id)->value('tariff_version_id'))->toBe($tariffId);
    });

    it('refuse days outside the rate', function (array $rate) {
        // Arrange
        $tariffId = TestRecords::tariffVersion($this->assignmentId, $rate[0], ['tariff_code' => 'SHORT', 'effective_to' => $rate[1]]);
        DB::table('tariff_versions')->where('id', $tariffId)->update(['status' => 'approved']);

        // Act & Assert
        expect(fn () => TestRecords::gasChargeLine($this->invoiceId, $tariffId))
            ->toThrow(QueryException::class, "outside tariff version {$tariffId}");
    })->with([
        'rate starts later' => [['2026-10-05', null]],
        'rate ends earlier' => [['2026-01-01', '2026-10-05']],
    ]);

    it('refuse days outside the billing cycle', function (array $days) {
        // Act & Assert
        expect(fn () => TestRecords::gasChargeLine($this->invoiceId, overrides: ['segment_from' => $days[0], 'segment_to' => $days[1]]))
            ->toThrow(QueryException::class, 'outside the billing cycle');
    })->with([
        'before' => [['2026-09-30', '2026-10-10']],
        'after' => [['2026-10-20', '2026-11-01']],
    ]);
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
        TestRecords::moveInvoice($this->invoiceId, ['approved']);

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

describe('invoice amounts from their lines (D46)', function () {
    beforeEach(function () {
        TestRecords::gasChargeLine($this->invoiceId);
        TestRecords::gasChargeLine($this->invoiceId, overrides: ['segment_from' => '2026-10-11', 'segment_to' => '2026-10-31', 'line_total' => 4500]);
        foreach ([['service_charge', 100], ['tax', 380], ['discount', -200], ['credit', -50], ['previous_balance', 999]] as [$type, $total]) {
            DB::table('invoice_lines')->insert(['invoice_id' => $this->invoiceId, 'line_type' => $type, 'description' => $type, 'line_total' => $total]);
        }
    });

    it('are added up by type, leaving the previous balance out', function () {
        // Act
        $totals = DB::selectOne('select * from invoice_line_totals(?)', [$this->invoiceId]);

        // Assert
        expect((array) $totals)->toBe([
            'subtotal' => '7600.00', 'tax_total' => '380.00', 'discount_total' => '200.00', 'credit_total' => '50.00', 'total' => '7730.00',
        ]);
    });

    it('count only the invoice\'s own lines', function () {
        // Arrange
        TestRecords::gasChargeLine(TestRecords::invoice());

        // Act
        $totals = DB::selectOne('select * from invoice_line_totals(?)', [$this->invoiceId]);

        // Assert
        expect($totals->subtotal)->toBe('7600.00');
    });

    it('let the invoice be approved when its amounts match', function () {
        // Act
        TestRecords::moveInvoice($this->invoiceId, ['approved']);

        // Assert
        expect(DB::table('invoices')->where('id', $this->invoiceId)->first(['status', 'total']))
            ->toEqual((object) ['status' => 'approved', 'total' => '7730.00']);
    });

    it('refuse to approve an invoice whose amounts differ from its lines', function (string $column, int $amount) {
        // Arrange
        $totals = (array) DB::selectOne('select * from invoice_line_totals(?)', [$this->invoiceId]);
        $totals[$column] += $amount;
        $totals['total'] = $totals['subtotal'] + $totals['tax_total'] - $totals['discount_total'] - $totals['credit_total'];
        DB::table('invoices')->where('id', $this->invoiceId)->update($totals);

        // Act & Assert
        expect(fn () => DB::table('invoices')->where('id', $this->invoiceId)->update(['status' => 'approved']))
            ->toThrow(QueryException::class, 'does not match its lines');
    })->with([
        'subtotal' => ['subtotal', 1],
        'tax' => ['tax_total', 1],
        'discount' => ['discount_total', 1],
        'credit' => ['credit_total', 1],
    ]);

    it('may void a draft whose amounts differ from its lines', function () {
        // Act
        DB::table('invoices')->where('id', $this->invoiceId)->update(['status' => 'void']);

        // Assert
        expect(DB::table('invoices')->where('id', $this->invoiceId)->value('status'))->toBe('void');
    });

    it('refuse a discount or credit line that adds to the bill', function (string $type) {
        // Act & Assert
        expect(fn () => DB::table('invoice_lines')->insert(['invoice_id' => $this->invoiceId, 'line_type' => $type, 'description' => $type, 'line_total' => 10]))
            ->toThrow(QueryException::class, 'ck_invoice_lines_deduction_sign');
    })->with(['discount', 'credit']);
});
