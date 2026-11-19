<?php

/**
 * Migration group 13, part 1: installation agreements and their installment
 * (EMI) schedule (design 02 §12, V7.7, V7.9).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

describe('lifecycle registration', function () {
    it('registers each table as a protected financial record', function (string $table, string $defaultStatus) {
        // Arrange: migration 2026_11_19_000062 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe(['installations', 'financial', 'protected', 'forbidden', false, $defaultStatus]);
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
        $row = ['paid_amount' => 1000, 'status' => 'paid'];

        // Act
        $id = TestRecords::installment(overrides: $row);

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
