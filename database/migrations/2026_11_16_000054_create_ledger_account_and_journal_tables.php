<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 12, part 1: chart of accounts and double-entry journals
 * (design 02 §8, §15, §18, V7.7, V7.9, V7.12).
 *
 * - financial_accounts: each organization's own accounts (optionally per
 *   building), code unique per organization and building.
 * - financial_journals: draft → posted (enforced). A posted journal is
 *   immutable; a correction is a reversal journal of the same organization
 *   (at most one per journal).
 * - financial_journal_lines: one side each (debit or credit), the journal's
 *   organization and currency, an account of that organization, an invoice
 *   of the line's building. Lines of a posted journal never change.
 * - At COMMIT a posted, visible journal has at least two visible lines and
 *   balances (deferred constraint triggers). Hidden rows are left out, so
 *   the maintenance override can hide a wrong journal with its lines.
 *
 * Row-level security comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('building_id')->nullable()->constrained();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('account_type', 24);
            $table->char('currency', 3)->default('BDT');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['id', 'organization_id'], 'uq_financial_account_id_org');
        });

        Schema::create('financial_journals', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('building_id')->nullable()->constrained();
            $table->string('journal_no', 100)->unique();
            $table->date('journal_date');
            $table->char('currency', 3)->default('BDT');
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('status', 20)->default('draft');
            $table->text('description')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->unsignedBigInteger('reversal_of_journal_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['source_type', 'source_id', 'status']);
            $table->unique(['id', 'organization_id'], 'uq_financial_journal_id_org');
            $table->foreign(['reversal_of_journal_id', 'organization_id'], 'fk_journal_reversal_same_org')
                ->references(['id', 'organization_id'])->on('financial_journals');
        });

        Schema::create('financial_journal_lines', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->unsignedBigInteger('journal_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('organization_id');
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->foreignId('building_id')->nullable()->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->text('description')->nullable();
            $table->index('journal_id', 'idx_journal_lines_journal');
            $table->index('account_id', 'idx_journal_lines_account');
            $table->foreign('organization_id')->references('id')->on('organizations');
            $table->foreign(['journal_id', 'organization_id'], 'fk_journal_line_journal_org')
                ->references(['id', 'organization_id'])->on('financial_journals');
            $table->foreign(['account_id', 'organization_id'], 'fk_journal_line_account_org')
                ->references(['id', 'organization_id'])->on('financial_accounts');
            $table->foreign(['invoice_id', 'building_id'], 'fk_journal_line_invoice_building')
                ->references(['id', 'building_id'])->on('invoices');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE financial_accounts
              ADD CONSTRAINT ck_financial_accounts_account_type
                CHECK (account_type IN ('asset','liability','equity','revenue','expense'));
            CREATE UNIQUE INDEX uq_financial_account_code_scope
              ON financial_accounts(organization_id, COALESCE(building_id, 0), code);

            CREATE UNIQUE INDEX uq_journal_one_reversal
              ON financial_journals(reversal_of_journal_id) WHERE reversal_of_journal_id IS NOT NULL;

            ALTER TABLE financial_journal_lines
              ADD CONSTRAINT ck_journal_lines_debit CHECK (debit >= 0),
              ADD CONSTRAINT ck_journal_lines_credit CHECK (credit >= 0),
              ADD CONSTRAINT ck_journal_lines_one_side CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)),
              ADD CONSTRAINT ck_journal_line_invoice_building CHECK (invoice_id IS NULL OR building_id IS NOT NULL);

            -- A line uses its journal's organization and currency and an account of that organization.
            CREATE OR REPLACE FUNCTION validate_journal_line_context() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE j_currency CHAR(3); a_currency CHAR(3); j_status VARCHAR(20);
            BEGIN
              SELECT currency, status INTO j_currency, j_status FROM financial_journals
                WHERE id = NEW.journal_id AND organization_id = NEW.organization_id;
              SELECT currency INTO a_currency FROM financial_accounts
                WHERE id = NEW.account_id AND organization_id = NEW.organization_id;
              IF j_currency IS NULL OR a_currency IS NULL THEN
                RAISE EXCEPTION 'Journal/account organization context mismatch';
              END IF;
              IF j_currency <> a_currency THEN
                RAISE EXCEPTION 'Journal currency % differs from account currency %', j_currency, a_currency;
              END IF;
              IF j_status = 'posted' AND NOT lifecycle_override_active() THEN
                RAISE EXCEPTION 'Cannot add or change lines on an already-posted journal';
              END IF;
              RETURN NEW;
            END; $$;
            CREATE OR REPLACE TRIGGER trg_validate_journal_line_context
            BEFORE INSERT OR UPDATE ON financial_journal_lines
            FOR EACH ROW EXECUTE FUNCTION validate_journal_line_context();

            -- Posted journals and their lines never change (only the maintenance override).
            CREATE OR REPLACE FUNCTION prevent_posted_journal_mutation() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE jstatus VARCHAR(20);
            BEGIN
              IF lifecycle_override_active() THEN
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
              END IF;
              IF TG_TABLE_NAME = 'financial_journals' THEN
                IF OLD.status IN ('posted','reversed') THEN
                  RAISE EXCEPTION 'Posted/reversed journals are immutable; create a reversal journal';
                END IF;
              ELSE
                IF TG_OP <> 'INSERT' THEN
                  SELECT status INTO jstatus FROM financial_journals WHERE id = OLD.journal_id;
                  IF jstatus IN ('posted','reversed') THEN
                    RAISE EXCEPTION 'Lines of posted/reversed journal % are immutable', OLD.journal_id;
                  END IF;
                END IF;
                IF TG_OP <> 'DELETE' THEN
                  SELECT status INTO jstatus FROM financial_journals WHERE id = NEW.journal_id;
                  IF jstatus IN ('posted','reversed') THEN
                    RAISE EXCEPTION 'Lines of posted/reversed journal % are immutable', NEW.journal_id;
                  END IF;
                END IF;
              END IF;
              IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
              RETURN NEW;
            END; $$;
            CREATE OR REPLACE TRIGGER trg_immutable_posted_journal
            BEFORE UPDATE OR DELETE ON financial_journals
            FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_mutation();
            CREATE OR REPLACE TRIGGER trg_immutable_posted_journal_lines
            BEFORE UPDATE OR DELETE ON financial_journal_lines
            FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_mutation();

            -- At COMMIT a visible posted journal has two or more visible lines and balances.
            CREATE OR REPLACE FUNCTION assert_posted_journal_balanced() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE ids BIGINT[]; jid BIGINT; jstatus VARCHAR(20); jdeleted BOOLEAN;
                    debit_total NUMERIC(16,2); credit_total NUMERIC(16,2); line_count BIGINT;
            BEGIN
              IF TG_TABLE_NAME = 'financial_journals' THEN
                IF TG_OP = 'INSERT' THEN ids := ARRAY[NEW.id];
                ELSE ids := ARRAY[OLD.id, NEW.id]; END IF;
              ELSE
                IF TG_OP = 'INSERT' THEN ids := ARRAY[NEW.journal_id];
                ELSIF TG_OP = 'DELETE' THEN ids := ARRAY[OLD.journal_id];
                ELSE ids := ARRAY[OLD.journal_id, NEW.journal_id]; END IF;
              END IF;
              FOREACH jid IN ARRAY ids LOOP
                IF jid IS NULL THEN CONTINUE; END IF;
                SELECT status, is_deleted INTO jstatus, jdeleted FROM financial_journals WHERE id = jid;
                IF jstatus = 'posted' AND NOT jdeleted THEN
                  SELECT COALESCE(SUM(debit),0), COALESCE(SUM(credit),0), COUNT(*)
                    INTO debit_total, credit_total, line_count
                    FROM financial_journal_lines WHERE journal_id = jid AND NOT is_deleted;
                  IF line_count < 2 OR debit_total <> credit_total OR debit_total <= 0 THEN
                    RAISE EXCEPTION 'Posted journal % is unbalanced (debit %, credit %, visible lines %)',
                      jid, debit_total, credit_total, line_count;
                  END IF;
                END IF;
              END LOOP;
              RETURN NULL;
            END; $$;
            DROP TRIGGER IF EXISTS ct_journal_balance_on_header ON financial_journals;
            CREATE CONSTRAINT TRIGGER ct_journal_balance_on_header
            AFTER INSERT OR UPDATE ON financial_journals
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION assert_posted_journal_balanced();
            DROP TRIGGER IF EXISTS ct_journal_balance_on_lines ON financial_journal_lines;
            CREATE CONSTRAINT TRIGGER ct_journal_balance_on_lines
            AFTER INSERT OR UPDATE OR DELETE ON financial_journal_lines
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION assert_posted_journal_balanced();
            SQL);

        // Lifecycle policies exactly as design V7.7; journal transition as V7.9.
        LifecycleSchema::attach('financial_accounts', module: 'ledger', class: RecordClass::Configuration,
            softDelete: SoftDeletePolicy::Protected);
        LifecycleSchema::attach('financial_journals', module: 'ledger', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true, defaultStatus: 'draft');
        LifecycleSchema::attach('financial_journal_lines', module: 'ledger', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected);

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('financial_journals', 'draft',  'Draft',  'neutral', NULL, true,  false, 10, '{"bn":{"label":"খসড়া"}}'),
                   ('financial_journals', 'posted', 'Posted', 'success', NULL, false, true,  20, '{"bn":{"label":"পোস্টকৃত"}}');
            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('financial_journals', 'draft', 'posted', 'ledger.post', false);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_transitions WHERE entity_name = 'financial_journals';
            DELETE FROM status_definitions WHERE entity_name = 'financial_journals';
            DELETE FROM lifecycle_entities WHERE entity_name IN ('financial_accounts', 'financial_journals', 'financial_journal_lines');
            DROP TABLE IF EXISTS financial_journal_lines;
            DROP TABLE IF EXISTS financial_journals;
            DROP TABLE IF EXISTS financial_accounts;
            DROP FUNCTION IF EXISTS assert_posted_journal_balanced();
            DROP FUNCTION IF EXISTS prevent_posted_journal_mutation();
            DROP FUNCTION IF EXISTS validate_journal_line_context();
            SQL);
    }
};
