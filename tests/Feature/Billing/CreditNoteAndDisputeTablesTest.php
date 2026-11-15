<?php

/**
 * Migration group 10, part 4: credit notes and billing disputes (design 02
 * §7 and §9, V7.7, V7.9; building tie as D42).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, array $expected) {
        // Arrange: migration group 10 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe($expected);
    })->with([
        ['credit_notes', ['billing', 'financial', 'protected', 'forbidden', true, 'pending_approval']],
        ['billing_disputes', ['billing', 'workflow', 'protected', 'forbidden', false, 'open']],
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
        'credit notes' => ['credit_notes', fn () => TestRecords::creditNote()],
        'billing disputes' => ['billing_disputes', fn () => TestRecords::billingDispute()],
    ]);
});

describe('credit notes', function () {
    it('wait for approval when raised', function () {
        // Arrange
        $id = TestRecords::creditNote();

        // Act
        $status = DB::table('credit_notes')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('pending_approval');
    });

    it('refuse an amount of zero', function () {
        // Arrange
        $row = ['amount' => 0];

        // Act & Assert
        expect(fn () => TestRecords::creditNote(overrides: $row))
            ->toThrow(QueryException::class, 'ck_credit_notes_amount');
    });

    it('refuse a number that is already used', function () {
        // Arrange
        TestRecords::creditNote(overrides: ['credit_note_no' => 'CN-2026-0001']);

        // Act & Assert
        expect(fn () => TestRecords::creditNote(overrides: ['credit_note_no' => 'CN-2026-0001']))
            ->toThrow(QueryException::class, 'credit_notes_credit_note_no_unique');
    });

    it('are applied after approval', function () {
        // Arrange
        $id = TestRecords::creditNote();

        // Act
        DB::table('credit_notes')->where('id', $id)->update(['status' => 'approved']);
        DB::table('credit_notes')->where('id', $id)->update(['status' => 'applied']);

        // Assert
        expect(DB::table('credit_notes')->where('id', $id)->value('status'))->toBe('applied');
    });

    it('cannot be applied without approval', function () {
        // Arrange
        $id = TestRecords::creditNote();

        // Act & Assert
        expect(fn () => DB::table('credit_notes')->where('id', $id)->update(['status' => 'applied']))
            ->toThrow(QueryException::class, 'Status transition pending_approval -> applied is not allowed');
    });

    it('cannot be voided once applied', function () {
        // Arrange
        $id = TestRecords::creditNote();
        DB::table('credit_notes')->where('id', $id)->update(['status' => 'approved']);
        DB::table('credit_notes')->where('id', $id)->update(['status' => 'applied']);

        // Act & Assert
        expect(fn () => DB::table('credit_notes')->where('id', $id)->update(['status' => 'void']))
            ->toThrow(QueryException::class, 'Status transition applied -> void is not allowed');
    });
});

describe('billing disputes', function () {
    it('start open', function () {
        // Arrange
        $id = TestRecords::billingDispute();

        // Act
        $status = DB::table('billing_disputes')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('open');
    });

    it('refuse an invoice of another building', function () {
        // Arrange
        $row = ['building_id' => TestRecords::building()];

        // Act & Assert
        expect(fn () => TestRecords::billingDispute(overrides: $row))
            ->toThrow(QueryException::class, 'fk_billing_dispute_invoice');
    });

    it('refuse a number that is already used', function () {
        // Arrange
        TestRecords::billingDispute(overrides: ['dispute_no' => 'DSP-2026-0001']);

        // Act & Assert
        expect(fn () => TestRecords::billingDispute(overrides: ['dispute_no' => 'DSP-2026-0001']))
            ->toThrow(QueryException::class, 'billing_disputes_dispute_no_unique');
    });

    it('may go from open straight to a final status, as their path is not enforced', function (string $status) {
        // Arrange
        $id = TestRecords::billingDispute();

        // Act
        DB::table('billing_disputes')->where('id', $id)->update(['status' => $status]);

        // Assert
        expect(DB::table('billing_disputes')->where('id', $id)->value('status'))->toBe($status);
    })->with(['resolved', 'rejected', 'closed']);
});
