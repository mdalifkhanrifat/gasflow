<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A billing dispute is raised by the invoice's own customer (owner decision
 * D46, design V7.19, group 10 question 7): the dispute's invoice key now
 * includes building and customer (composite FK, like D42).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE invoices
              ADD CONSTRAINT uq_invoice_building_customer UNIQUE (id, building_id, customer_id);
            ALTER TABLE billing_disputes
              DROP CONSTRAINT fk_billing_dispute_invoice,
              ADD CONSTRAINT fk_billing_dispute_invoice FOREIGN KEY (invoice_id, building_id, customer_id)
                REFERENCES invoices(id, building_id, customer_id);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE billing_disputes
              DROP CONSTRAINT fk_billing_dispute_invoice,
              ADD CONSTRAINT fk_billing_dispute_invoice FOREIGN KEY (invoice_id, building_id)
                REFERENCES invoices(id, building_id);
            ALTER TABLE invoices DROP CONSTRAINT uq_invoice_building_customer;
            SQL);
    }
};
