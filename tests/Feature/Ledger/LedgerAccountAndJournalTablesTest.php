<?php

/**
 * Migration group 12, part 1: chart of accounts and double-entry journals
 * (design 02 §8, §15, §18, V7.7, V7.9, V7.12; design tests 20–22, 24, 44).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TestRecords;

/**
 * Runs the checks that otherwise wait for COMMIT, then defers them again.
 */
function ledgerCheckAtCommit(): void
{
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
}

function postJournal(int $id): void
{
    DB::table('financial_journals')->where('id', $id)->update(['status' => 'posted', 'posted_at' => now()]);
}

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, array $expected) {
        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe($expected);
    })->with([
        ['financial_accounts', ['ledger', 'configuration', 'protected', 'forbidden', false, 'active']],
        ['financial_journals', ['ledger', 'financial', 'protected', 'forbidden', true, 'draft']],
        ['financial_journal_lines', ['ledger', 'financial', 'protected', 'forbidden', false, 'active']],
    ]);

    it('can never delete a row', function (string $table, Closure $create) {
        // Arrange
        $id = $create();

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))
            ->toThrow(QueryException::class, "Records in {$table} are protected");
    })->with([
        'accounts' => ['financial_accounts', fn () => TestRecords::financialAccount()],
        'journals' => ['financial_journals', fn () => TestRecords::journal()],
        'lines' => ['financial_journal_lines', fn () => TestRecords::journalLine(
            $journalId = TestRecords::journal(),
            TestRecords::financialAccount((int) DB::table('financial_journals')->where('id', $journalId)->value('organization_id')),
            100,
        )],
    ]);
});

describe('accounts', function () {
    it('refuse an unknown account type', function () {
        // Act & Assert
        expect(fn () => TestRecords::financialAccount(overrides: ['account_type' => 'cash']))
            ->toThrow(QueryException::class, 'ck_financial_accounts_account_type');
    });

    it('refuse a code used twice in the same organization and building', function () {
        // Arrange
        $organizationId = TestRecords::organization();
        TestRecords::financialAccount($organizationId, ['code' => '1100']);

        // Act & Assert
        expect(fn () => TestRecords::financialAccount($organizationId, ['code' => '1100']))
            ->toThrow(QueryException::class, 'uq_financial_account_code_scope');
    });

    it('let each organization use the same code', function () {
        // Arrange
        TestRecords::financialAccount(overrides: ['code' => '1100']);

        // Act
        $id = TestRecords::financialAccount(overrides: ['code' => '1100']);

        // Assert
        expect(DB::table('financial_accounts')->where('id', $id)->value('code'))->toBe('1100');
    });
});

describe('journal lines', function () {
    beforeEach(function () {
        $this->organizationId = TestRecords::organization();
        $this->journalId = TestRecords::journal($this->organizationId);
        $this->accountId = TestRecords::financialAccount($this->organizationId);
    });

    it('refuse a line with both or neither side', function (array $sides) {
        // Act & Assert
        expect(fn () => TestRecords::journalLine($this->journalId, $this->accountId, 0, $sides))
            ->toThrow(QueryException::class, 'ck_journal_lines_one_side');
    })->with([
        'both' => [['debit' => 10, 'credit' => 10]],
        'neither' => [['debit' => 0, 'credit' => 0]],
    ]);

    it('refuse a negative side', function () {
        // Act & Assert
        expect(fn () => TestRecords::journalLine($this->journalId, $this->accountId, 0, ['debit' => -5, 'credit' => 0]))
            ->toThrow(QueryException::class, 'ck_journal_lines_debit');
    });

    it('refuse an account of another organization', function () {
        // Act & Assert
        expect(fn () => TestRecords::journalLine($this->journalId, TestRecords::financialAccount(), 100))
            ->toThrow(QueryException::class, 'Journal/account organization context mismatch');
    });

    it('refuse an account in another currency', function () {
        // Arrange
        $usd = TestRecords::financialAccount($this->organizationId, ['currency' => 'USD']);

        // Act & Assert
        expect(fn () => TestRecords::journalLine($this->journalId, $usd, 100))
            ->toThrow(QueryException::class, 'Journal currency BDT differs from account currency USD');
    });

    it('refuse an invoice without its building, or of another building', function (Closure $row, string $error) {
        // Act & Assert
        expect(fn () => TestRecords::journalLine($this->journalId, $this->accountId, 100, $row()))
            ->toThrow(QueryException::class, $error);
    })->with([
        'no building' => [fn () => ['invoice_id' => TestRecords::invoice()], 'ck_journal_line_invoice_building'],
        'another building' => [fn () => ['invoice_id' => TestRecords::invoice(), 'building_id' => TestRecords::building()], 'fk_journal_line_invoice_building'],
    ]);
});

describe('posting', function () {
    it('post a balanced journal (design 20)', function () {
        // Arrange
        $id = TestRecords::balancedJournal();

        // Act
        postJournal($id);
        TestRecords::financialTransaction($id);
        ledgerCheckAtCommit();

        // Assert
        expect(DB::table('financial_journals')->where('id', $id)->value('status'))->toBe('posted');
    });

    it('refuse an unbalanced journal at COMMIT (design 24)', function () {
        // Arrange
        $id = TestRecords::balancedJournal();
        TestRecords::journalLine($id, TestRecords::financialAccount((int) DB::table('financial_journals')->where('id', $id)->value('organization_id')), 10);
        postJournal($id);

        // Act & Assert
        expect(fn () => ledgerCheckAtCommit())
            ->toThrow(QueryException::class, "Posted journal {$id} is unbalanced (debit 110.00, credit 100.00, visible lines 3)");
    });

    it('refuse a journal with a single line at COMMIT', function () {
        // Arrange
        $organizationId = TestRecords::organization();
        $id = TestRecords::journal($organizationId);
        TestRecords::journalLine($id, TestRecords::financialAccount($organizationId), 100);
        postJournal($id);

        // Act & Assert
        expect(fn () => ledgerCheckAtCommit())
            ->toThrow(QueryException::class, 'visible lines 1');
    });

    it('keep a posted journal and its lines as they are (design 21)', function (Closure $change, string $error) {
        // Arrange
        $id = TestRecords::balancedJournal();
        postJournal($id);

        // Act & Assert
        expect(fn () => $change($id))->toThrow(QueryException::class, $error);
    })->with([
        'journal date' => [fn (int $id) => DB::table('financial_journals')->where('id', $id)->update(['journal_date' => '2026-01-01']), 'Posted/reversed journals are immutable'],
        'line amount' => [fn (int $id) => DB::table('financial_journal_lines')->where('journal_id', $id)->update(['debit' => DB::raw('debit * 2'), 'credit' => DB::raw('credit * 2')]), 'immutable'],
        'new line' => [fn (int $id) => TestRecords::journalLine($id, (int) DB::table('financial_journal_lines')->where('journal_id', $id)->value('account_id'), 5), 'Cannot add or change lines on an already-posted journal'],
    ]);

    it('cannot be hidden by the app (design 22)', function () {
        // Arrange
        $id = TestRecords::balancedJournal();
        postJournal($id);

        // Act & Assert
        expect(fn () => DB::table('financial_journals')->where('id', $id)->update(['is_deleted' => true]))
            ->toThrow(QueryException::class, 'financial_journals');
    });

    it('cannot go back to draft', function () {
        // Arrange
        $id = TestRecords::balancedJournal();
        postJournal($id);

        // Act & Assert
        expect(fn () => DB::table('financial_journals')->where('id', $id)->update(['status' => 'draft']))
            ->toThrow(QueryException::class, 'Status transition posted -> draft is not allowed');
    });
});

describe('reversals', function () {
    it('reverse a journal of the same organization once', function () {
        // Arrange
        $original = TestRecords::balancedJournal($organizationId = TestRecords::organization());
        TestRecords::journal($organizationId, ['reversal_of_journal_id' => $original]);

        // Act & Assert
        expect(fn () => TestRecords::journal($organizationId, ['reversal_of_journal_id' => $original]))
            ->toThrow(QueryException::class, 'uq_journal_one_reversal');
    });

    it('refuse reversing a journal of another organization', function () {
        // Act & Assert
        expect(fn () => TestRecords::journal(overrides: ['reversal_of_journal_id' => TestRecords::balancedJournal()]))
            ->toThrow(QueryException::class, 'fk_journal_reversal_same_org');
    });
});

describe('maintenance override', function () {
    beforeEach(function () {
        $this->operator = DB::connection('pgsql_maintenance');
        $this->operator->beginTransaction();
    });

    afterEach(function () {
        $this->operator->rollBack();
    });

    it('may still correct a posted journal (design 44)', function () {
        // Arrange
        $organizationId = $this->operator->table('organizations')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()), 'code' => 'L-OVR', 'name' => 'Ledger', 'organization_type' => 'management',
        ]);
        $id = $this->operator->table('financial_journals')->insertGetId([
            'organization_id' => $organizationId, 'journal_no' => 'J-OVR', 'journal_date' => '2026-01-30', 'source_type' => 'invoice', 'source_id' => 1,
            'status' => 'draft',
        ]);
        $this->operator->table('financial_journals')->where('id', $id)->update(['status' => 'posted']);
        $this->operator->select(
            "select set_config('app.maintenance_override', 'on', true), set_config('app.lifecycle_reason', ?, true)",
            ['Audit request #17: wrong journal date'],
        );

        // Act
        $this->operator->table('financial_journals')->where('id', $id)->update(['journal_date' => '2026-01-31']);

        // Assert
        expect($this->operator->table('financial_journals')->where('id', $id)->value('journal_date'))->toBe('2026-01-31');
    });
});
