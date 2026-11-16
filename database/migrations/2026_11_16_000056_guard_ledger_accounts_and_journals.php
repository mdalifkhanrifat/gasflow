<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ledger books stay in the organization's buildings and keep their facts
 * (owner decision D49, design V7.21, group 12 questions 1, 6, 7 and 8).
 *
 * - validate_ledger_building(): an account's or journal's building is one
 *   the organization may access (organization_can_access_building()); a
 *   line is of its journal's building when the journal has one.
 * - A posted journal has its posting time.
 * - An account keeps its organization, building, code, type and currency
 *   from the first write (a different account is a new one).
 * - At COMMIT a hidden journal has no visible transaction (V7.12 checked
 *   only the transaction side).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_ledger_building() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE journal_building BIGINT;
            BEGIN
              IF TG_TABLE_NAME = 'financial_journal_lines' THEN
                SELECT building_id INTO journal_building FROM financial_journals WHERE id = NEW.journal_id;
                IF journal_building IS NOT NULL AND NEW.building_id IS DISTINCT FROM journal_building THEN
                  RAISE EXCEPTION 'Journal line of building % belongs to journal % of building %',
                    NEW.building_id, NEW.journal_id, journal_building;
                END IF;
              ELSIF NEW.building_id IS NOT NULL AND NOT organization_can_access_building(NEW.organization_id, NEW.building_id) THEN
                RAISE EXCEPTION '% of organization % cannot use building %, which it has no access to',
                  TG_TABLE_NAME, NEW.organization_id, NEW.building_id;
              END IF;
              RETURN NEW;
            END; $$;
            CREATE OR REPLACE TRIGGER trg_financial_accounts_building
            BEFORE INSERT OR UPDATE OF organization_id, building_id ON financial_accounts
            FOR EACH ROW EXECUTE FUNCTION validate_ledger_building();
            CREATE OR REPLACE TRIGGER trg_financial_journals_building
            BEFORE INSERT OR UPDATE OF organization_id, building_id ON financial_journals
            FOR EACH ROW EXECUTE FUNCTION validate_ledger_building();
            CREATE OR REPLACE TRIGGER trg_financial_journal_lines_building
            BEFORE INSERT OR UPDATE OF journal_id, building_id ON financial_journal_lines
            FOR EACH ROW EXECUTE FUNCTION validate_ledger_building();

            ALTER TABLE financial_journals
              ADD CONSTRAINT ck_financial_journals_posted_at CHECK (status <> 'posted' OR posted_at IS NOT NULL);

            CREATE OR REPLACE FUNCTION assert_posted_journal_has_transaction() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE jid BIGINT; jstatus VARCHAR(20); jdeleted BOOLEAN; tx_count BIGINT;
            BEGIN
              IF TG_TABLE_NAME = 'financial_journals' THEN jid := NEW.id; ELSE jid := NEW.journal_id; END IF;
              SELECT status, is_deleted INTO jstatus, jdeleted FROM financial_journals WHERE id = jid;
              IF TG_TABLE_NAME = 'financial_transactions' AND NOT NEW.is_deleted AND jdeleted THEN
                RAISE EXCEPTION 'Financial transaction % points to hidden journal %; hide both together', NEW.id, jid;
              END IF;
              IF jdeleted AND EXISTS (SELECT 1 FROM financial_transactions WHERE journal_id = jid AND NOT is_deleted) THEN
                RAISE EXCEPTION 'Hidden journal % still has a visible financial transaction; hide both together', jid;
              END IF;
              IF jstatus = 'posted' AND NOT jdeleted THEN
                SELECT COUNT(*) INTO tx_count FROM financial_transactions WHERE journal_id = jid AND NOT is_deleted;
                IF tx_count <> 1 THEN
                  RAISE EXCEPTION 'Posted journal % must have exactly one visible financial transaction; found %', jid, tx_count;
                END IF;
              END IF;
              RETURN NULL;
            END; $$;
            SQL);

        LifecycleSchema::freezeColumns('financial_accounts',
            ['organization_id', 'building_id', 'code', 'account_type', 'currency'], editableStatuses: [], name: 'written');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_financial_accounts_freeze_written ON financial_accounts;
            ALTER TABLE financial_journals DROP CONSTRAINT ck_financial_journals_posted_at;
            DROP TRIGGER IF EXISTS trg_financial_journal_lines_building ON financial_journal_lines;
            DROP TRIGGER IF EXISTS trg_financial_journals_building ON financial_journals;
            DROP TRIGGER IF EXISTS trg_financial_accounts_building ON financial_accounts;
            DROP FUNCTION IF EXISTS validate_ledger_building();

            CREATE OR REPLACE FUNCTION assert_posted_journal_has_transaction() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE jid BIGINT; jstatus VARCHAR(20); jdeleted BOOLEAN; tx_count BIGINT;
            BEGIN
              IF TG_TABLE_NAME = 'financial_journals' THEN jid := NEW.id; ELSE jid := NEW.journal_id; END IF;
              SELECT status, is_deleted INTO jstatus, jdeleted FROM financial_journals WHERE id = jid;
              IF TG_TABLE_NAME = 'financial_transactions' AND NOT NEW.is_deleted AND jdeleted THEN
                RAISE EXCEPTION 'Financial transaction % points to hidden journal %; hide both together', NEW.id, jid;
              END IF;
              IF jstatus = 'posted' AND NOT jdeleted THEN
                SELECT COUNT(*) INTO tx_count FROM financial_transactions WHERE journal_id = jid AND NOT is_deleted;
                IF tx_count <> 1 THEN
                  RAISE EXCEPTION 'Posted journal % must have exactly one visible financial transaction; found %', jid, tx_count;
                END IF;
              END IF;
              RETURN NULL;
            END; $$;
            SQL);
    }
};
