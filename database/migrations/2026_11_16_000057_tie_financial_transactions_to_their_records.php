<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Financial transactions agree with the records they name, in the books of
 * whoever collects the money (owner decision D49, design V7.21, group 12
 * questions 2, 3 and 4; D47).
 *
 * - A transaction with a payment is in its collector's books
 *   (payee_organization_id); one with an invoice in the invoice's collector's
 *   (invoice_payee_organization_id()).
 * - A journal line's or transaction's customer is its invoice's and its
 *   payment's (composite keys with building and customer).
 * - What each type needs: invoice_issued an invoice; payment_received,
 *   payment_allocated and refund_issued a payment; payment_allocated and
 *   credit_note_applied an invoice; reversal (and only reversal) the
 *   reversed transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
              ADD CONSTRAINT uq_payment_building_customer UNIQUE (id, building_id, customer_id);

            ALTER TABLE financial_journal_lines
              ADD CONSTRAINT fk_journal_line_invoice_customer FOREIGN KEY (invoice_id, building_id, customer_id)
                REFERENCES invoices(id, building_id, customer_id);

            ALTER TABLE financial_transactions
              ADD CONSTRAINT fk_fin_tx_invoice_customer FOREIGN KEY (invoice_id, building_id, customer_id)
                REFERENCES invoices(id, building_id, customer_id),
              ADD CONSTRAINT fk_fin_tx_payment_customer FOREIGN KEY (payment_id, building_id, customer_id)
                REFERENCES payments(id, building_id, customer_id),
              ADD CONSTRAINT ck_fin_tx_invoice_issued
                CHECK (transaction_type <> 'invoice_issued' OR invoice_id IS NOT NULL),
              ADD CONSTRAINT ck_fin_tx_payment_types
                CHECK (transaction_type NOT IN ('payment_received','payment_allocated','refund_issued') OR payment_id IS NOT NULL),
              ADD CONSTRAINT ck_fin_tx_invoice_types
                CHECK (transaction_type NOT IN ('payment_allocated','credit_note_applied') OR invoice_id IS NOT NULL),
              ADD CONSTRAINT ck_fin_tx_reversal
                CHECK ((transaction_type = 'reversal') = (reversal_of_transaction_id IS NOT NULL));

            CREATE OR REPLACE FUNCTION validate_financial_transaction_books() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE collector BIGINT;
            BEGIN
              IF NEW.payment_id IS NOT NULL THEN
                SELECT payee_organization_id INTO collector FROM payments WHERE id = NEW.payment_id;
                IF collector IS DISTINCT FROM NEW.organization_id THEN
                  RAISE EXCEPTION 'Payment % was collected by organization %, so it is posted in its books, not in organization %',
                    NEW.payment_id, collector, NEW.organization_id;
                END IF;
              ELSIF NEW.invoice_id IS NOT NULL THEN
                collector := invoice_payee_organization_id(NEW.invoice_id);
                IF collector IS DISTINCT FROM NEW.organization_id THEN
                  RAISE EXCEPTION 'Invoice % is collected by organization %, so it is posted in its books, not in organization %',
                    NEW.invoice_id, collector, NEW.organization_id;
                END IF;
              END IF;
              RETURN NULL;
            END; $$;
            -- AFTER, so the record's own keys and checks speak first.
            CREATE OR REPLACE TRIGGER trg_validate_financial_transaction_books
            AFTER INSERT ON financial_transactions
            FOR EACH ROW EXECUTE FUNCTION validate_financial_transaction_books();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_validate_financial_transaction_books ON financial_transactions;
            DROP FUNCTION IF EXISTS validate_financial_transaction_books();
            ALTER TABLE financial_transactions
              DROP CONSTRAINT ck_fin_tx_reversal,
              DROP CONSTRAINT ck_fin_tx_invoice_types,
              DROP CONSTRAINT ck_fin_tx_payment_types,
              DROP CONSTRAINT ck_fin_tx_invoice_issued,
              DROP CONSTRAINT fk_fin_tx_payment_customer,
              DROP CONSTRAINT fk_fin_tx_invoice_customer;
            ALTER TABLE financial_journal_lines DROP CONSTRAINT fk_journal_line_invoice_customer;
            ALTER TABLE payments DROP CONSTRAINT uq_payment_building_customer;
            SQL);
    }
};
