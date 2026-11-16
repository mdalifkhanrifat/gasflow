<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 12, part 2: the financial transaction register and the
 * accounting read model (design 02 §8, §18, V7.7, V7.12).
 *
 * - financial_transactions: one searchable, append-only row per posted
 *   journal (statements, cashbooks, settlement reports). It has the
 *   journal's organization, source, currency, building and amount (= the
 *   journal's debit and credit totals); invoice and payment are of its
 *   building. Never changed or deleted; a mistake is a reversal.
 * - At COMMIT a visible posted journal has exactly one visible
 *   transaction, and a visible transaction never points at a hidden
 *   journal, so the maintenance override hides a wrong entry as a whole.
 * - ledger_posted_lines: the only source for trial balance and ledger
 *   reports; hidden rows are left out (security_invoker, for RLS later).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->char('public_id', 26)->unique();
            $table->string('transaction_no', 100)->unique();
            $table->unsignedBigInteger('organization_id');
            $table->foreignId('building_id')->nullable()->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('journal_id');
            $table->string('transaction_type', 40);
            $table->string('direction', 10);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('BDT');
            $table->string('payment_method', 24)->nullable();
            $table->string('external_reference', 180)->nullable();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('status', 20)->default('posted');
            $table->unsignedBigInteger('reversal_of_transaction_id')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['source_type', 'source_id', 'transaction_type', 'status']);
            $table->unique('journal_id', 'uq_financial_transaction_journal');
            $table->foreign('organization_id')->references('id')->on('organizations');
            $table->foreign('reversal_of_transaction_id')->references('id')->on('financial_transactions');
            $table->foreign(['journal_id', 'organization_id'], 'fk_fin_tx_journal_org')
                ->references(['id', 'organization_id'])->on('financial_journals');
            $table->foreign(['invoice_id', 'building_id'], 'fk_fin_tx_invoice_building')
                ->references(['id', 'building_id'])->on('invoices');
            $table->foreign(['payment_id', 'building_id'], 'fk_fin_tx_payment_building')
                ->references(['id', 'building_id'])->on('payments');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE financial_transactions
              ADD CONSTRAINT ck_fin_tx_transaction_type CHECK (transaction_type IN (
                'invoice_issued','payment_received','payment_allocated',
                'credit_note_applied','refund_issued','saas_subscription_paid',
                'installation_settled','reversal')),
              ADD CONSTRAINT ck_fin_tx_direction CHECK (direction IN ('debit','credit')),
              ADD CONSTRAINT ck_fin_tx_amount CHECK (amount > 0),
              ADD CONSTRAINT ck_fin_tx_invoice_building CHECK (invoice_id IS NULL OR building_id IS NOT NULL),
              ADD CONSTRAINT ck_fin_tx_payment_building CHECK (payment_id IS NULL OR building_id IS NOT NULL);

            CREATE INDEX idx_fin_tx_org_occurred_brin ON financial_transactions USING brin(occurred_at);
            CREATE INDEX idx_fin_tx_building_customer ON financial_transactions(building_id, customer_id, occurred_at DESC);
            CREATE INDEX idx_fin_tx_invoice ON financial_transactions(invoice_id) WHERE invoice_id IS NOT NULL;
            CREATE INDEX idx_fin_tx_payment ON financial_transactions(payment_id) WHERE payment_id IS NOT NULL;

            SQL);

        // Lifecycle policy exactly as design V7.7.
        LifecycleSchema::attach('financial_transactions', module: 'ledger', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'posted');

        // The lifecycle columns exist now, so the register's triggers can use them.
        DB::unprepared(<<<'SQL'
            -- Never changed or deleted (only the maintenance override).
            CREATE OR REPLACE FUNCTION prevent_financial_transaction_mutation() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              IF lifecycle_override_active() THEN
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
              END IF;
              RAISE EXCEPTION 'Financial transactions are immutable; create a compensating reversal';
            END; $$;
            CREATE OR REPLACE TRIGGER trg_financial_transaction_immutable
            BEFORE UPDATE OR DELETE ON financial_transactions
            FOR EACH ROW EXECUTE FUNCTION prevent_financial_transaction_mutation();

            -- Backed by a posted journal of the same organization, source, currency, building and amount.
            CREATE OR REPLACE FUNCTION validate_financial_transaction_posting() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE j_status VARCHAR(20); j_currency CHAR(3); j_source_type VARCHAR(80);
                    j_source_id BIGINT; j_building_id BIGINT; journal_debit NUMERIC(16,2); journal_credit NUMERIC(16,2);
            BEGIN
              IF NEW.is_deleted THEN RETURN NEW; END IF;
              SELECT status, currency, source_type, source_id, building_id
                INTO j_status, j_currency, j_source_type, j_source_id, j_building_id
                FROM financial_journals WHERE id = NEW.journal_id AND organization_id = NEW.organization_id;
              IF j_status IS DISTINCT FROM 'posted' THEN RAISE EXCEPTION 'Financial transaction requires a posted journal'; END IF;
              IF j_currency IS DISTINCT FROM NEW.currency THEN RAISE EXCEPTION 'Transaction/journal currency mismatch'; END IF;
              IF j_source_type IS DISTINCT FROM NEW.source_type OR j_source_id IS DISTINCT FROM NEW.source_id THEN
                RAISE EXCEPTION 'Transaction source does not match its journal source';
              END IF;
              IF j_building_id IS NOT NULL AND NEW.building_id IS DISTINCT FROM j_building_id THEN
                RAISE EXCEPTION 'Transaction building does not match journal building';
              END IF;
              SELECT COALESCE(SUM(debit),0), COALESCE(SUM(credit),0) INTO journal_debit, journal_credit
                FROM financial_journal_lines WHERE journal_id = NEW.journal_id AND NOT is_deleted;
              IF journal_debit IS DISTINCT FROM NEW.amount OR journal_credit IS DISTINCT FROM NEW.amount THEN
                RAISE EXCEPTION 'Transaction amount % must equal journal debit and credit totals (%, %)',
                  NEW.amount, journal_debit, journal_credit;
              END IF;
              RETURN NEW;
            END; $$;
            CREATE OR REPLACE TRIGGER trg_validate_financial_transaction_posting
            BEFORE INSERT ON financial_transactions
            FOR EACH ROW EXECUTE FUNCTION validate_financial_transaction_posting();

            -- At COMMIT: a visible posted journal has exactly one visible transaction, and a
            -- visible transaction never points at a hidden journal.
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
            DROP TRIGGER IF EXISTS ct_posted_journal_requires_transaction ON financial_journals;
            CREATE CONSTRAINT TRIGGER ct_posted_journal_requires_transaction
            AFTER INSERT OR UPDATE ON financial_journals
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION assert_posted_journal_has_transaction();
            DROP TRIGGER IF EXISTS ct_transaction_journal_exists_once ON financial_transactions;
            CREATE CONSTRAINT TRIGGER ct_transaction_journal_exists_once
            AFTER INSERT OR UPDATE OF is_deleted, journal_id ON financial_transactions
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION assert_posted_journal_has_transaction();
            SQL);

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('financial_transactions', 'posted',   'Posted',   'success', NULL, true,  true, 10, '{"bn":{"label":"পোস্টকৃত"}}'),
                   ('financial_transactions', 'reversed', 'Reversed', 'muted',   NULL, false, true, 20, '{"bn":{"label":"রিভার্সকৃত"}}');

            -- The only source for trial balance and ledger reports; hidden rows are left out.
            CREATE OR REPLACE VIEW ledger_posted_lines WITH (security_invoker = true) AS
            SELECT l.*, j.journal_no, j.journal_date, j.source_type, j.source_id
            FROM financial_journal_lines l
            JOIN financial_journals j ON j.id = l.journal_id
            WHERE j.status = 'posted' AND NOT j.is_deleted AND NOT l.is_deleted;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP VIEW IF EXISTS ledger_posted_lines;
            DELETE FROM status_definitions WHERE entity_name = 'financial_transactions';
            DELETE FROM lifecycle_entities WHERE entity_name = 'financial_transactions';
            DROP TRIGGER IF EXISTS ct_posted_journal_requires_transaction ON financial_journals;
            DROP TABLE IF EXISTS financial_transactions;
            DROP FUNCTION IF EXISTS assert_posted_journal_has_transaction();
            DROP FUNCTION IF EXISTS validate_financial_transaction_posting();
            DROP FUNCTION IF EXISTS prevent_financial_transaction_mutation();
            SQL);
    }
};
