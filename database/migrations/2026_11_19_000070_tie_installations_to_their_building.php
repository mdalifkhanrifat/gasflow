<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Installations stay inside their building (owner decision D50, design
 * V7.23, group 13 question 1):
 *
 * - An agreement's customer belongs to an organization with access to the
 *   building (organization_can_access_building(), as for invoices).
 * - An installment carries its building, tied to its agreement's by a
 *   composite key. Its invoice line is an installation_emi line of an
 *   invoice of the same building and, when the agreement names one, of the
 *   agreement's customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE installation_agreements
              ADD CONSTRAINT uq_installation_agreement_building UNIQUE (id, building_id);

            ALTER TABLE installation_installments ADD COLUMN building_id BIGINT;
            UPDATE installation_installments i SET building_id = a.building_id
              FROM installation_agreements a WHERE a.id = i.installation_agreement_id;
            ALTER TABLE installation_installments
              ALTER COLUMN building_id SET NOT NULL,
              ADD CONSTRAINT installation_installments_building_id_foreign FOREIGN KEY (building_id) REFERENCES buildings(id),
              ADD CONSTRAINT fk_installment_agreement_building FOREIGN KEY (installation_agreement_id, building_id)
                REFERENCES installation_agreements(id, building_id);

            CREATE OR REPLACE FUNCTION validate_installation_agreement_customer() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE customer_org BIGINT;
            BEGIN
              SELECT tenant_organization_id INTO customer_org FROM customers WHERE id = NEW.customer_id;
              -- No customer, or a missing one (reported by its foreign key).
              IF customer_org IS NOT NULL AND NOT organization_can_access_building(customer_org, NEW.building_id) THEN
                RAISE EXCEPTION 'The customer of installation agreement % belongs to an organization without access to building %',
                  NEW.agreement_no, NEW.building_id;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_installation_agreements_customer
            BEFORE INSERT OR UPDATE OF customer_id, building_id ON installation_agreements
            FOR EACH ROW EXECUTE FUNCTION validate_installation_agreement_customer();

            CREATE OR REPLACE FUNCTION validate_installment_invoice_line() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE line RECORD; agreement_customer BIGINT;
            BEGIN
              IF NEW.invoice_line_id IS NULL THEN
                RETURN NEW;
              END IF;
              SELECT l.line_type, i.building_id, i.customer_id INTO line
                FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id WHERE l.id = NEW.invoice_line_id;
              -- A missing line is reported by its foreign key.
              IF NOT FOUND THEN
                RETURN NEW;
              END IF;
              IF line.line_type <> 'installation_emi' THEN
                RAISE EXCEPTION 'Installment % is billed by an installation_emi line, not a % line', NEW.installment_no, line.line_type;
              END IF;
              IF line.building_id <> NEW.building_id THEN
                RAISE EXCEPTION 'Invoice line % belongs to building %, not to the installment''s building %',
                  NEW.invoice_line_id, line.building_id, NEW.building_id;
              END IF;
              SELECT customer_id INTO agreement_customer FROM installation_agreements WHERE id = NEW.installation_agreement_id;
              IF agreement_customer IS NOT NULL AND agreement_customer <> line.customer_id THEN
                RAISE EXCEPTION 'Invoice line % bills customer %, not the agreement''s customer %',
                  NEW.invoice_line_id, line.customer_id, agreement_customer;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_installation_installments_invoice_line
            BEFORE INSERT OR UPDATE OF invoice_line_id, building_id, installation_agreement_id ON installation_installments
            FOR EACH ROW EXECUTE FUNCTION validate_installment_invoice_line();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_installation_installments_invoice_line ON installation_installments;
            DROP TRIGGER IF EXISTS trg_installation_agreements_customer ON installation_agreements;
            DROP FUNCTION IF EXISTS validate_installment_invoice_line();
            DROP FUNCTION IF EXISTS validate_installation_agreement_customer();
            ALTER TABLE installation_installments
              DROP CONSTRAINT fk_installment_agreement_building,
              DROP CONSTRAINT installation_installments_building_id_foreign,
              DROP COLUMN building_id;
            ALTER TABLE installation_agreements DROP CONSTRAINT uq_installation_agreement_building;
            SQL);
    }
};
