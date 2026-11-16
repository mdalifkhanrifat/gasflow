<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A reversal undoes exactly one posted entry of its own organization
 * (owner decision D49, design V7.21, group 12 question 5).
 *
 * - A reversal journal has the source journal_reversal and the original's
 *   id (so it never clashes with the original's unique source and status),
 *   and only a reversal journal has that source.
 * - It reverses a posted journal (generic parent-status rule) and, at
 *   COMMIT once posted, mirrors its visible lines: same accounts and
 *   amounts, sides swapped.
 * - A reversal transaction reverses a transaction of the same organization
 *   (composite key), at most once, through the journal that reverses the
 *   original's journal. The original stays posted and unchanged; its
 *   reversal is the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE financial_journals
              ADD CONSTRAINT ck_financial_journals_reversal_source
                CHECK ((reversal_of_journal_id IS NULL) = (source_type <> 'journal_reversal')
                       AND (reversal_of_journal_id IS NULL OR source_id = reversal_of_journal_id));

            CREATE OR REPLACE FUNCTION assert_reversal_mirrors_original() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.status = 'posted' AND NEW.reversal_of_journal_id IS NOT NULL AND NOT NEW.is_deleted AND EXISTS (
                   (SELECT account_id, debit, credit FROM financial_journal_lines WHERE journal_id = NEW.id AND NOT is_deleted
                    EXCEPT ALL
                    SELECT account_id, credit, debit FROM financial_journal_lines WHERE journal_id = NEW.reversal_of_journal_id AND NOT is_deleted)
                   UNION ALL
                   (SELECT account_id, credit, debit FROM financial_journal_lines WHERE journal_id = NEW.reversal_of_journal_id AND NOT is_deleted
                    EXCEPT ALL
                    SELECT account_id, debit, credit FROM financial_journal_lines WHERE journal_id = NEW.id AND NOT is_deleted)) THEN
                RAISE EXCEPTION 'Reversal journal % does not mirror journal %: same accounts and amounts with the sides swapped',
                  NEW.id, NEW.reversal_of_journal_id;
              END IF;
              RETURN NULL;
            END; $$;
            DROP TRIGGER IF EXISTS ct_reversal_mirrors_original ON financial_journals;
            CREATE CONSTRAINT TRIGGER ct_reversal_mirrors_original
            AFTER INSERT OR UPDATE ON financial_journals
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION assert_reversal_mirrors_original();

            ALTER TABLE financial_transactions
              ADD CONSTRAINT uq_financial_transaction_id_org UNIQUE (id, organization_id),
              ADD CONSTRAINT fk_fin_tx_reversal_same_org FOREIGN KEY (reversal_of_transaction_id, organization_id)
                REFERENCES financial_transactions(id, organization_id);
            CREATE UNIQUE INDEX uq_fin_tx_one_reversal
              ON financial_transactions(reversal_of_transaction_id) WHERE reversal_of_transaction_id IS NOT NULL;

            CREATE OR REPLACE FUNCTION validate_financial_transaction_reversal() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE original_journal BIGINT;
            BEGIN
              IF NEW.reversal_of_transaction_id IS NOT NULL THEN
                SELECT journal_id INTO original_journal FROM financial_transactions WHERE id = NEW.reversal_of_transaction_id;
                IF NOT EXISTS (SELECT 1 FROM financial_journals
                               WHERE id = NEW.journal_id AND reversal_of_journal_id = original_journal) THEN
                  RAISE EXCEPTION 'Reversal transaction must be posted with the journal that reverses journal %', original_journal;
                END IF;
              END IF;
              RETURN NEW;
            END; $$;
            CREATE OR REPLACE TRIGGER trg_validate_financial_transaction_reversal
            BEFORE INSERT ON financial_transactions
            FOR EACH ROW EXECUTE FUNCTION validate_financial_transaction_reversal();
            SQL);

        LifecycleSchema::requireParentStatus('financial_journals', parent: 'financial_journals',
            foreignKey: 'reversal_of_journal_id', parentStatuses: ['posted'], name: 'reversed_journal');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_financial_journals_parent_reversed_journal ON financial_journals;
            DROP TRIGGER IF EXISTS trg_validate_financial_transaction_reversal ON financial_transactions;
            DROP FUNCTION IF EXISTS validate_financial_transaction_reversal();
            DROP INDEX IF EXISTS uq_fin_tx_one_reversal;
            ALTER TABLE financial_transactions
              DROP CONSTRAINT fk_fin_tx_reversal_same_org,
              DROP CONSTRAINT uq_financial_transaction_id_org;
            DROP TRIGGER IF EXISTS ct_reversal_mirrors_original ON financial_journals;
            DROP FUNCTION IF EXISTS assert_reversal_mirrors_original();
            ALTER TABLE financial_journals DROP CONSTRAINT ck_financial_journals_reversal_source;
            SQL);
    }
};
