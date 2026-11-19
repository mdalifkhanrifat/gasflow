<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Guest links stay what they were made for (owner decision D50, design
 * V7.23, group 13 question 4):
 *
 * - A link is made only for an issued invoice (requireParentStatus with the
 *   statuses of invoice_issued_statuses()).
 * - It expires after it was made and allows at most 100 uses, the global
 *   bound of portal_access_policies.max_uses; the app applies the policy of
 *   the organization or building itself.
 * - revoked ⇔ revoked_at. Uses only grow, and only while the link is active
 *   and not past its expiry.
 * - Invoice, token, purpose, use limit and expiry are frozen from the
 *   first write (freezeColumns with no editable status): a different link is
 *   a new link.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE guest_access_links
              ADD CONSTRAINT ck_guest_access_links_expiry CHECK (expires_at > created_at),
              ADD CONSTRAINT ck_guest_access_links_max_uses_bound CHECK (max_uses <= 100),
              ADD CONSTRAINT ck_guest_access_links_revoked CHECK ((status = 'revoked') = (revoked_at IS NOT NULL));

            CREATE OR REPLACE FUNCTION guard_guest_link_use() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              IF lifecycle_override_active() OR NEW.use_count = OLD.use_count THEN
                RETURN NEW;
              END IF;
              IF NEW.use_count < OLD.use_count THEN
                RAISE EXCEPTION 'Guest link % was used % times; its uses cannot go down to %', OLD.id, OLD.use_count, NEW.use_count;
              END IF;
              IF OLD.status <> 'active' OR now() > OLD.expires_at THEN
                RAISE EXCEPTION 'Guest link % is % (expires %): it cannot be used', OLD.id, OLD.status, OLD.expires_at;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_guest_access_links_use
            BEFORE UPDATE OF use_count ON guest_access_links
            FOR EACH ROW EXECUTE FUNCTION guard_guest_link_use();
            SQL);

        $issued = DB::selectOne('select invoice_issued_statuses() as statuses')->statuses;
        LifecycleSchema::requireParentStatus('guest_access_links', parent: 'invoices', foreignKey: 'invoice_id',
            parentStatuses: str_getcsv(trim($issued, '{}')), whenStatuses: ['active'], name: 'issued_invoice');
        LifecycleSchema::freezeColumns('guest_access_links',
            ['invoice_id', 'token_hash', 'purpose', 'max_uses', 'expires_at'],
            editableStatuses: [], name: 'written');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_guest_access_links_freeze_written ON guest_access_links;
            DROP TRIGGER IF EXISTS trg_guest_access_links_parent_issued_invoice ON guest_access_links;
            DROP TRIGGER IF EXISTS trg_guest_access_links_use ON guest_access_links;
            DROP FUNCTION IF EXISTS guard_guest_link_use();
            ALTER TABLE guest_access_links
              DROP CONSTRAINT ck_guest_access_links_revoked,
              DROP CONSTRAINT ck_guest_access_links_max_uses_bound,
              DROP CONSTRAINT ck_guest_access_links_expiry;
            SQL);
    }
};
