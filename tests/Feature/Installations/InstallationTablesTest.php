<?php

/**
 * Migration group 13, part 1: installation agreements and their installment
 * (EMI) schedule (design 02 §12, V7.7, V7.9).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * The parts of a new agreement of 2 000 BDT in two parts of 1 000, moved to
 * active.
 *
 * @return list<int>
 */
function activeAgreementParts(): array
{
    $agreementId = TestRecords::installationAgreement(overrides: ['principal_amount' => 2000, 'installment_count' => 2]);
    $parts = [TestRecords::installment($agreementId, 1), TestRecords::installment($agreementId, 2)];
    DB::table('installation_agreements')->where('id', $agreementId)->update(['status' => 'active']);

    return $parts;
}

describe('lifecycle registration', function () {
    it('registers each table as a protected financial record with enforced transitions', function (string $table, string $defaultStatus) {
        // Arrange: migration 2026_11_19_000062 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe(['installations', 'financial', 'protected', 'forbidden', true, $defaultStatus]);
    })->with([
        ['installation_agreements', 'draft'],
        ['installation_installments', 'scheduled'],
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
        'agreements' => ['installation_agreements', fn () => TestRecords::installationAgreement()],
        'installments' => ['installation_installments', fn () => TestRecords::installment()],
    ]);
});

describe('installation agreements', function () {
    it('start as a draft in taka', function () {
        // Arrange
        $id = TestRecords::installationAgreement();

        // Act
        $row = DB::table('installation_agreements')->where('id', $id)->first();

        // Assert
        expect([$row->status, $row->currency])->toBe(['draft', 'BDT']);
    });

    it('refuse a wrong value', function (array $row, string $constraint) {
        // Arrange: a new building

        // Act & Assert
        expect(fn () => TestRecords::installationAgreement(overrides: $row))
            ->toThrow(QueryException::class, $constraint);
    })->with([
        'no principal' => [['principal_amount' => 0], 'ck_installation_agreements_principal_amount'],
        'no installments' => [['installment_count' => 0], 'ck_installation_agreements_installment_count'],
        'unknown status' => [['status' => 'paused'], 'Status "paused" is not defined for installation_agreements'],
    ]);

    it('refuse an agreement number that is already used', function () {
        // Arrange
        TestRecords::installationAgreement(overrides: ['agreement_no' => 'IA-1']);

        // Act & Assert
        expect(fn () => TestRecords::installationAgreement(overrides: ['agreement_no' => 'IA-1']))
            ->toThrow(QueryException::class, 'installation_agreements_agreement_no_unique');
    });
});

describe('installation installments', function () {
    it('start scheduled with nothing paid', function () {
        // Arrange
        $id = TestRecords::installment();

        // Act
        $row = DB::table('installation_installments')->where('id', $id)->first();

        // Assert
        expect([$row->status, $row->paid_amount])->toBe(['scheduled', '0.00']);
    });

    it('refuse a wrong value', function (array $row, string $constraint) {
        // Arrange: a new agreement

        // Act & Assert
        expect(fn () => TestRecords::installment(overrides: $row))
            ->toThrow(QueryException::class, $constraint);
    })->with([
        'no amount' => [['amount' => 0], 'ck_installation_installments_amount'],
        'negative paid' => [['paid_amount' => -1], 'ck_installation_installments_paid_amount'],
        'paid above the amount' => [['paid_amount' => 1000.01], 'ck_installation_installments_paid_amount'],
        'unknown status' => [['status' => 'cancelled'], 'Status "cancelled" is not defined for installation_installments'],
    ]);

    it('accept a part paid in full', function () {
        // Arrange
        $id = activeAgreementParts()[0];
        DB::table('installation_installments')->where('id', $id)->update(['status' => 'invoiced']);

        // Act
        DB::table('installation_installments')->where('id', $id)->update(['status' => 'paid', 'paid_amount' => 1000]);

        // Assert
        expect(DB::table('installation_installments')->where('id', $id)->value('paid_amount'))->toBe('1000.00');
    });

    it('refuse a part number that the agreement already has', function () {
        // Arrange
        $agreementId = TestRecords::installationAgreement();
        TestRecords::installment($agreementId, 1);

        // Act & Assert
        expect(fn () => TestRecords::installment($agreementId, 1))
            ->toThrow(QueryException::class, 'installation_installments_installation_agreement_id_installment');
    });

    it('let another agreement use the same part number', function () {
        // Arrange
        TestRecords::installment(TestRecords::installationAgreement(), 1);

        // Act
        $id = TestRecords::installment(TestRecords::installationAgreement(), 1);

        // Assert
        expect(DB::table('installation_installments')->where('id', $id)->value('installment_no'))->toBe(1);
    });
});

describe('statuses', function () {
    it('come only from the status registry, so a new status needs no schema change', function (string $table) {
        // Arrange: migration 2026_11_19_000067 has run

        // Act
        $checks = DB::select("select conname from pg_constraint where conrelid = ?::regclass and contype = 'c' and pg_get_constraintdef(oid) ~ '\\(?status\\)?(::text)? = ANY'", [$table]);

        // Assert
        expect($checks)->toBe([]);
    })->with(['installation_agreements', 'installation_installments']);
});

/**
 * An installation_emi line of 1 000 BDT on the invoice.
 */
function emiLine(int $invoiceId): int
{
    return (int) DB::table('invoice_lines')->insertGetId([
        'invoice_id' => $invoiceId, 'line_type' => 'installation_emi', 'description' => 'Installment 1 of 12',
        'quantity' => 1, 'unit' => 'part', 'unit_rate' => 1000, 'line_total' => 1000,
    ]);
}

/**
 * An agreement for the customer of a new draft invoice, in that invoice's
 * building.
 *
 * @return array{agreement: int, invoice: int}
 */
function agreementOfInvoiceCustomer(): array
{
    $invoice = DB::table('invoices')->where('id', TestRecords::invoice())->first();

    return [
        'agreement' => TestRecords::installationAgreement($invoice->building_id, ['customer_id' => $invoice->customer_id]),
        'invoice' => $invoice->id,
    ];
}

describe('the building of an installation', function () {
    it('takes a customer of the building\'s organization', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $tenantId = DB::table('buildings')->where('id', $buildingId)->value('tenant_organization_id');

        // Act
        $id = TestRecords::installationAgreement($buildingId, ['customer_id' => TestRecords::customer($tenantId)]);

        // Assert
        expect(DB::table('installation_agreements')->where('id', $id)->value('customer_id'))->not->toBeNull();
    });

    it('takes a customer of an organization with access to the building', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $partnerId = TestRecords::organization();
        TestRecords::accessGrant($buildingId, $partnerId);

        // Act
        $id = TestRecords::installationAgreement($buildingId, ['customer_id' => TestRecords::customer($partnerId)]);

        // Assert
        expect(DB::table('installation_agreements')->where('id', $id)->value('customer_id'))->not->toBeNull();
    });

    it('refuses a customer of an organization without access', function () {
        // Arrange
        $row = ['customer_id' => TestRecords::customer(TestRecords::organization())];

        // Act & Assert
        expect(fn () => TestRecords::installationAgreement(overrides: $row))
            ->toThrow(QueryException::class, 'belongs to an organization without access to building');
    });

    it('keeps each installment in its agreement\'s building', function () {
        // Arrange
        $row = ['building_id' => TestRecords::building()];

        // Act & Assert
        expect(fn () => TestRecords::installment(overrides: $row))
            ->toThrow(QueryException::class, 'fk_installment_agreement_building');
    });

    it('bills an installment through an installation line of the agreement\'s customer', function () {
        // Arrange
        ['agreement' => $agreementId, 'invoice' => $invoiceId] = agreementOfInvoiceCustomer();
        $lineId = emiLine($invoiceId);

        // Act
        $id = TestRecords::installment($agreementId, 1, ['invoice_line_id' => $lineId]);

        // Assert
        expect(DB::table('installation_installments')->where('id', $id)->value('invoice_line_id'))->toBe($lineId);
    });

    it('refuses a gas line', function () {
        // Arrange
        ['agreement' => $agreementId, 'invoice' => $invoiceId] = agreementOfInvoiceCustomer();
        $lineId = TestRecords::gasChargeLine($invoiceId);

        // Act & Assert
        expect(fn () => TestRecords::installment($agreementId, 1, ['invoice_line_id' => $lineId]))
            ->toThrow(QueryException::class, 'is billed by an installation_emi line, not a gas_charge line');
    });

    it('refuses a line of another building', function () {
        // Arrange
        $lineId = emiLine(TestRecords::invoice());

        // Act & Assert
        expect(fn () => TestRecords::installment(overrides: ['invoice_line_id' => $lineId]))
            ->toThrow(QueryException::class, 'not to the installment\'s building');
    });

    it('refuses a line of another customer of the building', function () {
        // Arrange
        ['agreement' => $agreementId, 'invoice' => $invoiceId] = agreementOfInvoiceCustomer();
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        $tenantId = DB::table('buildings')->where('id', $invoice->building_id)->value('tenant_organization_id');
        $otherInvoiceId = TestRecords::invoice($invoice->billing_cycle_id, ['customer_id' => TestRecords::customer($tenantId)]);
        $lineId = emiLine($otherInvoiceId);

        // Act & Assert
        expect(fn () => TestRecords::installment($agreementId, 1, ['invoice_line_id' => $lineId]))
            ->toThrow(QueryException::class, 'not the agreement\'s customer');
    });
});

/**
 * Moves a row along the given statuses, one enforced transition at a time.
 *
 * @param  list<string>  $statuses
 */
function moveInstallationRow(string $table, int $id, array $statuses): void
{
    foreach ($statuses as $status) {
        DB::table($table)->where('id', $id)->update(['status' => $status]);
    }
}

/**
 * The agreement of an installment.
 */
function agreementOf(int $installmentId): int
{
    return (int) DB::table('installation_installments')->where('id', $installmentId)->value('installation_agreement_id');
}

describe('the schedule of an agreement', function () {
    it('lets a draft become active once its parts match the principal', function () {
        // Arrange & Act
        $agreementId = agreementOf(activeAgreementParts()[0]);

        // Assert
        expect(DB::table('installation_agreements')->where('id', $agreementId)->value('status'))->toBe('active');
    });

    it('keeps a draft whose parts do not match', function (Closure $parts, string $message) {
        // Arrange
        $agreementId = TestRecords::installationAgreement(overrides: ['principal_amount' => 2000, 'installment_count' => 2]);
        $parts($agreementId);

        // Act & Assert
        expect(fn () => moveInstallationRow('installation_agreements', $agreementId, ['active']))
            ->toThrow(QueryException::class, $message);
    })->with([
        'too few parts' => [fn (int $id) => TestRecords::installment($id, 1), 'has 1 parts of 1000.00, not 2 parts of 2000.00'],
        'a wrong sum' => [function (int $id) {
            TestRecords::installment($id, 1);
            TestRecords::installment($id, 2, ['amount' => 900]);
        }, 'has 2 parts of 1900.00, not 2 parts of 2000.00'],
    ]);

    it('cannot start active without its parts', function () {
        // Arrange
        $row = ['status' => 'active'];

        // Act & Assert
        expect(fn () => TestRecords::installationAgreement(overrides: $row))->toThrow(QueryException::class, 'has 0 parts of 0.00');
    });

    it('lets a draft without parts be cancelled', function () {
        // Arrange
        $agreementId = TestRecords::installationAgreement();

        // Act
        moveInstallationRow('installation_agreements', $agreementId, ['cancelled']);

        // Assert
        expect(DB::table('installation_agreements')->where('id', $agreementId)->value('status'))->toBe('cancelled');
    });

    it('refuses a part due before the agreement starts', function () {
        // Arrange
        $row = ['due_date' => '2025-12-31'];

        // Act & Assert
        expect(fn () => TestRecords::installment(overrides: $row))
            ->toThrow(QueryException::class, 'is due on 2025-12-31, before its agreement starts on 2026-01-01');
    });

    it('freezes the agreement\'s terms once active', function () {
        // Arrange
        $agreementId = agreementOf(activeAgreementParts()[0]);

        // Act & Assert
        expect(fn () => DB::table('installation_agreements')->where('id', $agreementId)->update(['principal_amount' => 1]))
            ->toThrow(QueryException::class, 'principal_amount can no longer change');
    });

    it('takes no new part once active', function () {
        // Arrange
        $agreementId = agreementOf(activeAgreementParts()[0]);

        // Act & Assert
        expect(fn () => TestRecords::installment($agreementId, 3))->toThrow(QueryException::class, 'can no longer change');
    });

    it('freezes the schedule of its parts once active', function (string $column, mixed $value) {
        // Arrange
        $id = activeAgreementParts()[0];

        // Act & Assert
        expect(fn () => DB::table('installation_installments')->where('id', $id)->update([$column => $value]))
            ->toThrow(QueryException::class, "{$column} of installation_installments {$id} can no longer change");
    })->with([
        ['amount', 500],
        ['due_date', '2026-06-01'],
        ['installment_no', 9],
    ]);

    it('is completed only when every part is paid or waived', function () {
        // Arrange
        [$paidId, $openId] = activeAgreementParts();
        moveInstallationRow('installation_installments', $paidId, ['invoiced']);
        DB::table('installation_installments')->where('id', $paidId)->update(['status' => 'paid', 'paid_amount' => 1000]);

        // Act & Assert
        expect(fn () => moveInstallationRow('installation_agreements', agreementOf($openId), ['completed']))
            ->toThrow(QueryException::class, 'installation_installments');
    });

    it('completes once the last part is waived', function () {
        // Arrange
        [$paidId, $waivedId] = activeAgreementParts();
        moveInstallationRow('installation_installments', $paidId, ['invoiced']);
        DB::table('installation_installments')->where('id', $paidId)->update(['status' => 'paid', 'paid_amount' => 1000]);
        moveInstallationRow('installation_installments', $waivedId, ['waived']);

        // Act
        moveInstallationRow('installation_agreements', agreementOf($waivedId), ['completed']);

        // Assert
        expect(DB::table('installation_agreements')->where('id', agreementOf($waivedId))->value('status'))->toBe('completed');
    });

    it('may come back from default', function () {
        // Arrange
        $agreementId = agreementOf(activeAgreementParts()[0]);

        // Act
        moveInstallationRow('installation_agreements', $agreementId, ['defaulted', 'active']);

        // Assert
        expect(DB::table('installation_agreements')->where('id', $agreementId)->value('status'))->toBe('active');
    });
});

describe('installment statuses', function () {
    it('bill a part only on an approved agreement', function () {
        // Arrange
        $id = TestRecords::installment();

        // Act & Assert
        expect(fn () => moveInstallationRow('installation_installments', $id, ['invoiced']))
            ->toThrow(QueryException::class, 'installation_agreements');
    });

    it('refuse a move the design does not allow', function (array $before, string $to) {
        // Arrange
        $id = activeAgreementParts()[0];
        moveInstallationRow('installation_installments', $id, $before);

        // Act & Assert
        expect(fn () => moveInstallationRow('installation_installments', $id, [$to]))->toThrow(QueryException::class, 'is not allowed');
    })->with([
        'paid without an invoice' => [[], 'paid'],
        'back to scheduled' => [['invoiced'], 'scheduled'],
    ]);

    it('say what was paid', function (string $status, float $paid) {
        // Arrange
        $id = activeAgreementParts()[0];
        moveInstallationRow('installation_installments', $id, ['invoiced']);

        // Act & Assert
        expect(fn () => DB::table('installation_installments')->where('id', $id)->update(['status' => $status, 'paid_amount' => $paid]))
            ->toThrow(QueryException::class, 'ck_installation_installments_status_payment');
    })->with([
        'invoiced with money' => ['invoiced', 10],
        'partially paid with nothing' => ['partially_paid', 0],
        'partially paid in full' => ['partially_paid', 1000],
        'paid with less' => ['paid', 999],
        'overdue in full' => ['overdue', 1000],
    ]);

    it('record a partial payment', function () {
        // Arrange
        $id = activeAgreementParts()[0];
        moveInstallationRow('installation_installments', $id, ['invoiced']);

        // Act
        DB::table('installation_installments')->where('id', $id)->update(['status' => 'partially_paid', 'paid_amount' => 400]);

        // Assert
        expect(DB::table('installation_installments')->where('id', $id)->value('status'))->toBe('partially_paid');
    });
});
