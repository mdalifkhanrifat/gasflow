<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The permission catalogue every environment needs (owner decision D53,
 * design V7.26, group 16 question 1): the 30 codes the status transitions
 * already name, and a foreign key so a transition cannot name a permission
 * that does not exist. The other permissions come with the Phase 4 Policies.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            INSERT INTO permissions(code, description) VALUES
              ('billing_cycle.approve',  'Approve a billing cycle under review'),
              ('billing_cycle.cancel',   'Cancel a billing cycle before it is issued'),
              ('billing_cycle.lock',     'Lock an issued billing cycle'),
              ('credit_note.approve',    'Approve or reject a credit note'),
              ('credit_note.void',       'Void an approved credit note'),
              ('gas_supply.cancel',      'Cancel a recorded gas supply receipt'),
              ('gas_supply.verify',      'Verify a recorded gas supply receipt'),
              ('installation.approve',   'Approve an installation agreement'),
              ('installation.cancel',    'Cancel an installation agreement'),
              ('installation.default',   'Mark an installation agreement defaulted, or restore it'),
              ('installation.waive',     'Waive an installation installment'),
              ('invoice.approve',        'Approve a draft invoice'),
              ('invoice.issue',          'Issue an approved invoice or billing cycle'),
              ('invoice.void',           'Void an invoice before it is issued'),
              ('invoice.write_off',      'Write off an unpaid invoice'),
              ('ledger.post',            'Post a journal to the ledger'),
              ('license.issue',          'Issue a license'),
              ('license.revoke',         'Revoke a license'),
              ('license.suspend',        'Suspend a license, or lift the suspension'),
              ('meter_reading.approve',  'Approve, reject or return a submitted meter reading'),
              ('meter_reading.revise',   'Supersede an approved meter reading with a revision'),
              ('payment.refund',         'Refund a payment, and approve or reject a refund'),
              ('payment.reverse',        'Reverse a payment and its allocations'),
              ('reading_period.approve', 'Approve a reading period under review'),
              ('reading_period.cancel',  'Cancel a reading period before review'),
              ('reading_period.lock',    'Lock an approved reading period'),
              ('reconciliation.review',  'Accept, adjust or reject a meter reconciliation'),
              ('supply_change.verify',   'Verify or cancel a recorded supply change'),
              ('tariff.approve',         'Approve, activate or retire a tariff version'),
              ('tariff.retro',           'Apply or waive a retro tariff adjustment');

            ALTER TABLE status_transitions
              ADD CONSTRAINT fk_status_transitions_permission_code
                FOREIGN KEY (permission_code) REFERENCES permissions(code);
            SQL);
    }

    /**
     * The lifecycle guard turns a DELETE into a soft delete, which would keep
     * the codes taken; the owner switches it off to remove the seed for good.
     * The trail keeps the rows' history.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE status_transitions DROP CONSTRAINT IF EXISTS fk_status_transitions_permission_code;
            ALTER TABLE permissions DISABLE TRIGGER trg_00_lifecycle_guard;
            DELETE FROM permissions WHERE code IN (
              'billing_cycle.approve', 'billing_cycle.cancel', 'billing_cycle.lock', 'credit_note.approve',
              'credit_note.void', 'gas_supply.cancel', 'gas_supply.verify', 'installation.approve',
              'installation.cancel', 'installation.default', 'installation.waive', 'invoice.approve',
              'invoice.issue', 'invoice.void', 'invoice.write_off', 'ledger.post', 'license.issue',
              'license.revoke', 'license.suspend', 'meter_reading.approve', 'meter_reading.revise',
              'payment.refund', 'payment.reverse', 'reading_period.approve', 'reading_period.cancel',
              'reading_period.lock', 'reconciliation.review', 'supply_change.verify', 'tariff.approve',
              'tariff.retro');
            ALTER TABLE permissions ENABLE TRIGGER trg_00_lifecycle_guard;
            SQL);
    }
};
