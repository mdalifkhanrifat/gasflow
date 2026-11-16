<?php

/**
 * Migration group 12, part 2: the financial transaction register and the
 * accounting read model (design 02 §8, §18, V7.12; design tests 23, 46–48,
 * 50, 65).
 */

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TestRecords;

function transactionsCheckAtCommit(?Connection $connection = null): void
{
    $connection ??= DB::connection();
    $connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
    $connection->statement('SET CONSTRAINTS ALL DEFERRED');
}

describe('lifecycle registration', function () {
    it('registers the table with the policy of the design', function () {
        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', 'financial_transactions')->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe(['ledger', 'financial', 'protected', false, 'posted']);
    });
});

describe('a financial transaction', function () {
    beforeEach(function () {
        $this->journalId = TestRecords::balancedJournal();
        DB::table('financial_journals')->where('id', $this->journalId)->update(['status' => 'posted', 'posted_at' => now()]);
    });

    it('is written for a posted journal', function () {
        // Act
        $id = TestRecords::financialTransaction($this->journalId);
        transactionsCheckAtCommit();

        // Assert
        expect(DB::table('financial_transactions')->where('id', $id)->value('amount'))->toBe('100.00');
    });

    it('refuse a draft journal', function () {
        // Act & Assert
        expect(fn () => TestRecords::financialTransaction(TestRecords::balancedJournal()))
            ->toThrow(QueryException::class, 'Financial transaction requires a posted journal');
    });

    it('refuse what its journal does not say', function (Closure $row, string $error) {
        // Act & Assert
        expect(fn () => TestRecords::financialTransaction($this->journalId, $row()))->toThrow(QueryException::class, $error);
    })->with([
        'amount' => [fn () => ['amount' => 90], 'Transaction amount 90.00 must equal journal debit and credit totals (100.00, 100.00)'],
        'currency' => [fn () => ['currency' => 'USD'], 'Transaction/journal currency mismatch'],
        'source' => [fn () => ['source_id' => 1], 'Transaction source does not match its journal source'],
        'organization' => [fn () => ['organization_id' => TestRecords::organization()], 'Financial transaction requires a posted journal'],
    ]);

    it('refuse another building than its journal\'s', function () {
        // Arrange
        $organizationId = TestRecords::organization();
        $line = ['building_id' => TestRecords::building($organizationId)];
        $journalId = TestRecords::journal($organizationId, $line);
        TestRecords::journalLine($journalId, TestRecords::financialAccount($organizationId), 100, $line);
        TestRecords::journalLine($journalId, TestRecords::financialAccount($organizationId), -100, $line);
        DB::table('financial_journals')->where('id', $journalId)->update(['status' => 'posted', 'posted_at' => now()]);

        // Act & Assert
        expect(fn () => TestRecords::financialTransaction($journalId, ['building_id' => TestRecords::building()]))
            ->toThrow(QueryException::class, 'Transaction building does not match journal building');
    });

    it('refuse an invoice or payment of another building, or without a building', function (Closure $row, string $error) {
        // Act & Assert
        expect(fn () => TestRecords::financialTransaction($this->journalId, $row()))->toThrow(QueryException::class, $error);
    })->with([
        'invoice without building' => [fn () => ['invoice_id' => TestRecords::invoice()], 'ck_fin_tx_invoice_building'],
        'payment without building' => [fn () => ['payment_id' => TestRecords::payment()], 'ck_fin_tx_payment_building'],
        'invoice of another building' => [fn () => ['invoice_id' => TestRecords::invoice(), 'building_id' => TestRecords::building()], 'fk_fin_tx_invoice_building'],
        'payment of another building' => [fn () => ['payment_id' => TestRecords::payment(), 'building_id' => TestRecords::building()], 'fk_fin_tx_payment_building'],
    ]);

    it('refuse an unknown type or direction', function (array $row, string $error) {
        // Act & Assert
        expect(fn () => TestRecords::financialTransaction($this->journalId, $row))->toThrow(QueryException::class, $error);
    })->with([
        'type' => [['transaction_type' => 'gift'], 'ck_fin_tx_transaction_type'],
        'direction' => [['direction' => 'both'], 'ck_fin_tx_direction'],
    ]);

    it('is the only one of its journal', function () {
        // Arrange
        TestRecords::financialTransaction($this->journalId);

        // Act & Assert
        expect(fn () => TestRecords::financialTransaction($this->journalId, ['transaction_type' => 'payment_received']))
            ->toThrow(QueryException::class, 'uq_financial_transaction_journal');
    });

    it('never changes once written (design 23)', function (array $change) {
        // Arrange
        $id = TestRecords::financialTransaction($this->journalId);

        // Act & Assert
        expect(fn () => DB::table('financial_transactions')->where('id', $id)->update($change))
            ->toThrow(QueryException::class, 'Financial transactions are immutable');
    })->with([
        'flag' => [['is_active' => false]],
        'amount' => [['amount' => 50]],
        'status' => [['status' => 'reversed']],
    ]);

    it('is required for every posted journal at COMMIT', function () {
        // Act & Assert
        expect(fn () => transactionsCheckAtCommit())
            ->toThrow(QueryException::class, "Posted journal {$this->journalId} must have exactly one visible financial transaction; found 0");
    });
});

describe('the maintenance override (design 46–48, 50)', function () {
    beforeEach(function () {
        $this->operator = DB::connection('pgsql_maintenance');
        $this->operator->beginTransaction();
        $op = $this->operator;
        $organizationId = $op->table('organizations')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()), 'code' => 'L-OVR', 'name' => 'Ledger', 'organization_type' => 'management',
        ]);
        $accounts = collect(['1100' => 'asset', '4100' => 'revenue'])->map(fn ($type, $code) => $op->table('financial_accounts')->insertGetId([
            'organization_id' => $organizationId, 'code' => (string) $code, 'name' => $type, 'account_type' => $type,
        ]));
        $this->journalId = $op->table('financial_journals')->insertGetId([
            'organization_id' => $organizationId, 'journal_no' => 'J-OVR', 'journal_date' => '2026-01-30', 'source_type' => 'invoice', 'source_id' => 1,
        ]);
        foreach ([[$accounts['1100'], 100, 0], [$accounts['4100'], 0, 100]] as [$account, $debit, $credit]) {
            $op->table('financial_journal_lines')->insert([
                'journal_id' => $this->journalId, 'account_id' => $account, 'organization_id' => $organizationId, 'debit' => $debit, 'credit' => $credit,
            ]);
        }
        $op->table('financial_journals')->where('id', $this->journalId)->update(['status' => 'posted', 'posted_at' => now()]);
        $this->transactionId = $op->table('financial_transactions')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()), 'transaction_no' => 'FT-OVR', 'organization_id' => $organizationId,
            'journal_id' => $this->journalId, 'transaction_type' => 'invoice_issued', 'direction' => 'debit', 'amount' => 100,
            'source_type' => 'invoice', 'source_id' => 1,
        ]);
        transactionsCheckAtCommit($op);
        $op->select("select set_config('app.maintenance_override', 'on', true), set_config('app.lifecycle_reason', ?, true)", ['Audit request #17: wrong entry']);
    });

    afterEach(function () {
        $this->operator->rollBack();
    });

    it('refuse hiding a transaction while its journal stays in the books (design 46)', function () {
        // Arrange
        $this->operator->table('financial_transactions')->where('id', $this->transactionId)->delete();

        // Act & Assert
        expect(fn () => transactionsCheckAtCommit($this->operator))
            ->toThrow(QueryException::class, "Posted journal {$this->journalId} must have exactly one visible financial transaction; found 0");
    });

    it('refuse hiding a journal while its transaction stays visible (D49)', function () {
        // Arrange
        $this->operator->table('financial_journal_lines')->where('journal_id', $this->journalId)->update(['is_deleted' => true]);
        $this->operator->table('financial_journals')->where('id', $this->journalId)->update(['is_deleted' => true]);

        // Act & Assert
        expect(fn () => transactionsCheckAtCommit($this->operator))
            ->toThrow(QueryException::class, "Hidden journal {$this->journalId} still has a visible financial transaction");
    });

    it('hide the whole entry and leave it out of the books (design 47, 48)', function () {
        // Act
        $this->operator->table('financial_journal_lines')->where('journal_id', $this->journalId)->update(['is_deleted' => true]);
        $this->operator->table('financial_journals')->where('id', $this->journalId)->update(['is_deleted' => true]);
        $this->operator->table('financial_transactions')->where('id', $this->transactionId)->update(['is_deleted' => true]);
        transactionsCheckAtCommit($this->operator);

        // Assert
        expect($this->operator->table('ledger_posted_lines')->where('journal_id', $this->journalId)->count())->toBe(0)
            ->and($this->operator->table('financial_transactions')->where('id', $this->transactionId)->exists())->toBeTrue();
    });

    it('leave out the lines of a hidden journal, even lines that are not hidden', function () {
        // Act
        $this->operator->table('financial_journals')->where('id', $this->journalId)->update(['is_deleted' => true]);
        $this->operator->table('financial_transactions')->where('id', $this->transactionId)->update(['is_deleted' => true]);
        transactionsCheckAtCommit($this->operator);

        // Assert
        expect($this->operator->table('ledger_posted_lines')->where('journal_id', $this->journalId)->count())->toBe(0);
    });

    it('return a restored entry to the books (design 50)', function () {
        // Arrange
        foreach ([true, false] as $hidden) {
            $this->operator->table('financial_journal_lines')->where('journal_id', $this->journalId)->update(['is_deleted' => $hidden]);
            $this->operator->table('financial_journals')->where('id', $this->journalId)->update(['is_deleted' => $hidden]);
            $this->operator->table('financial_transactions')->where('id', $this->transactionId)->update(['is_deleted' => $hidden]);
        }

        // Act
        transactionsCheckAtCommit($this->operator);

        // Assert
        expect($this->operator->table('ledger_posted_lines')->where('journal_id', $this->journalId)->count())->toBe(2);
    });
});

describe('ledger_posted_lines', function () {
    it('respects row-level security of the caller (design 65)', function () {
        // Act
        $options = DB::scalar("select array_to_string(reloptions, ',') from pg_class where relname = 'ledger_posted_lines'");

        // Assert
        expect($options)->toContain('security_invoker=true');
    });

    it('shows only posted journals', function () {
        // Arrange
        $draft = TestRecords::balancedJournal();
        $posted = TestRecords::postedEntry();

        // Act
        $journals = DB::table('ledger_posted_lines')->whereIn('journal_id', [$draft, $posted])->distinct()->pluck('journal_id')->all();

        // Assert
        expect($journals)->toBe([$posted]);
    });
});
