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

describe('reversals (D49)', function () {
    beforeEach(function () {
        $this->organizationId = TestRecords::organization();
        $this->originalId = TestRecords::postedEntry($this->organizationId);
    });

    it('post a reversal that mirrors its original', function () {
        // Arrange
        $id = TestRecords::reversalJournal($this->originalId);

        // Act
        postJournal($id);
        TestRecords::financialTransaction($id, [
            'transaction_type' => 'reversal',
            'reversal_of_transaction_id' => DB::table('financial_transactions')->where('journal_id', $this->originalId)->value('id'),
        ]);
        ledgerCheckAtCommit();

        // Assert
        expect(DB::table('financial_journals')->where('id', $this->originalId)->value('status'))->toBe('posted');
    });

    it('refuse a reversal that does not mirror its original, at COMMIT', function () {
        // Arrange
        $id = TestRecords::reversalJournal($this->originalId, 37);
        postJournal($id);
        TestRecords::financialTransaction($id, [
            'transaction_type' => 'reversal',
            'reversal_of_transaction_id' => DB::table('financial_transactions')->where('journal_id', $this->originalId)->value('id'),
        ]);

        // Act & Assert
        expect(fn () => ledgerCheckAtCommit())
            ->toThrow(QueryException::class, "Reversal journal {$id} does not mirror journal {$this->originalId}");
    });

    it('reverse only a posted journal', function () {
        // Arrange
        $draftId = TestRecords::balancedJournal($this->organizationId);

        // Act & Assert
        expect(fn () => TestRecords::reversalJournal($draftId))
            ->toThrow(QueryException::class, "financial_journals {$draftId} is draft: financial_journals in status draft needs it to be posted");
    });

    it('refuse a reversal without the reversal source, or that source without a reversal', function (Closure $create) {
        // Act & Assert
        expect(fn () => $create($this->organizationId, $this->originalId))
            ->toThrow(QueryException::class, 'ck_financial_journals_reversal_source');
    })->with([
        'other source' => [fn (int $org, int $original) => TestRecords::journal($org, ['reversal_of_journal_id' => $original])],
        'no original' => [fn (int $org, int $original) => TestRecords::journal($org, ['source_type' => 'journal_reversal', 'source_id' => $original])],
        'another original id' => [fn (int $org, int $original) => TestRecords::reversalJournal($original, overrides: ['source_id' => 1])],
    ]);

    it('reverse a journal once', function () {
        // Arrange
        $first = TestRecords::reversalJournal($this->originalId);
        postJournal($first);

        // Act & Assert
        expect(fn () => TestRecords::reversalJournal($this->originalId))
            ->toThrow(QueryException::class, 'uq_journal_one_reversal');
    });

    it('refuse reversing a journal of another organization', function () {
        // Act & Assert
        expect(fn () => TestRecords::journal(overrides: [
            'source_type' => 'journal_reversal', 'source_id' => $this->originalId, 'reversal_of_journal_id' => $this->originalId,
        ]))->toThrow(QueryException::class, 'fk_journal_reversal_same_org');
    });

    it('post a reversal transaction only with the journal that reverses the original\'s', function () {
        // Arrange
        $journalId = TestRecords::balancedJournal($this->organizationId);
        postJournal($journalId);

        // Act & Assert
        expect(fn () => TestRecords::financialTransaction($journalId, [
            'transaction_type' => 'reversal',
            'reversal_of_transaction_id' => DB::table('financial_transactions')->where('journal_id', $this->originalId)->value('id'),
        ]))->toThrow(QueryException::class, "Reversal transaction must be posted with the journal that reverses journal {$this->originalId}");
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
        $this->operator->table('financial_journals')->where('id', $id)->update(['status' => 'posted', 'posted_at' => now()]);
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

describe('books stay in the organization\'s buildings and keep their facts (D49)', function () {
    it('refuse an account or journal of a building the organization cannot access', function (string $table, Closure $create) {
        // Act & Assert
        expect(fn () => $create(TestRecords::organization(), TestRecords::building()))
            ->toThrow(QueryException::class, "{$table} of organization");
    })->with([
        'account' => ['financial_accounts', fn (int $org, int $building) => TestRecords::financialAccount($org, ['building_id' => $building])],
        'journal' => ['financial_journals', fn (int $org, int $building) => TestRecords::journal($org, ['building_id' => $building])],
    ]);

    it('accept a building the organization owns or has a grant in', function (bool $owner) {
        // Arrange
        $organizationId = TestRecords::organization();
        $buildingId = $owner ? TestRecords::building($organizationId) : TestRecords::building();
        if (! $owner) {
            TestRecords::accessGrant($buildingId, $organizationId);
        }

        // Act
        $id = TestRecords::journal($organizationId, ['building_id' => $buildingId]);

        // Assert
        expect(DB::table('financial_journals')->where('id', $id)->value('building_id'))->toBe($buildingId);
    })->with(['owner' => [true], 'grantee' => [false]]);

    it('refuse a line of another building than its journal\'s', function () {
        // Arrange
        $organizationId = TestRecords::organization();
        $journalId = TestRecords::journal($organizationId, ['building_id' => TestRecords::building($organizationId)]);

        // Act & Assert
        expect(fn () => TestRecords::journalLine($journalId, TestRecords::financialAccount($organizationId), 100, ['building_id' => TestRecords::building($organizationId)]))
            ->toThrow(QueryException::class, "belongs to journal {$journalId} of building");
    });

    it('refuse a posted journal without its posting time', function () {
        // Arrange
        $id = TestRecords::balancedJournal();

        // Act & Assert
        expect(fn () => DB::table('financial_journals')->where('id', $id)->update(['status' => 'posted']))
            ->toThrow(QueryException::class, 'ck_financial_journals_posted_at');
    });

    it('keep an account\'s identity from the first write', function (string $column, Closure $value) {
        // Arrange
        $id = TestRecords::financialAccount();

        // Act & Assert
        expect(fn () => DB::table('financial_accounts')->where('id', $id)->update([$column => $value()]))
            ->toThrow(QueryException::class, "financial_accounts {$id} is active: {$column} can no longer change");
    })->with([
        'organization' => ['organization_id', fn () => TestRecords::organization()],
        'code' => ['code', fn () => '9999'],
        'type' => ['account_type', fn () => 'expense'],
        'currency' => ['currency', fn () => 'USD'],
    ]);

    it('still let an account be renamed', function () {
        // Arrange
        $id = TestRecords::financialAccount();

        // Act
        DB::table('financial_accounts')->where('id', $id)->update(['name' => 'Customer receivables']);

        // Assert
        expect(DB::table('financial_accounts')->where('id', $id)->value('name'))->toBe('Customer receivables');
    });
});
