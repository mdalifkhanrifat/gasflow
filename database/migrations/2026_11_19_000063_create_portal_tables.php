<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 13, part 2: the customer portal's guest links (design 02
 * §11, V7.7, V7.9, V7.12).
 *
 * - portal_access_policies: how guest invoice links work for one
 *   organization or one building (exactly one of the two, one policy each):
 *   lifetime, uses, what the link may do, phone OTP, resend cooldown.
 * - guest_access_links: a link to one invoice, stored only as the hash of its
 *   token (redacted from every log), with an expiry and a use limit.
 *
 * Policies are configuration that may be hidden; links are protected
 * workflow rows (active → used up / expired / revoked, not enforced by the
 * design). Row-level security comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_access_policies', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('organization_id')->nullable()->unique()->constrained();
            $table->foreignId('building_id')->nullable()->unique()->constrained();
            $table->boolean('enabled')->default(true);
            $table->integer('link_ttl_minutes')->default(10080);
            $table->smallInteger('max_uses')->default(5);
            $table->boolean('allow_invoice_view')->default(true);
            $table->boolean('allow_online_payment')->default(true);
            $table->boolean('require_phone_otp')->default(false);
            $table->integer('resend_cooldown_seconds')->default(60);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('guest_access_links', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('invoice_id')->constrained();
            $table->char('token_hash', 64)->unique();
            $table->string('purpose', 24)->default('invoice_payment');
            $table->timestampTz('expires_at');
            $table->smallInteger('max_uses')->default(1);
            $table->smallInteger('use_count')->default(0);
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE portal_access_policies
              -- A policy is for one organization or one building, never both.
              ADD CONSTRAINT ck_portal_access_policies_scope
                CHECK ((organization_id IS NOT NULL)::int + (building_id IS NOT NULL)::int = 1),
              ADD CONSTRAINT ck_portal_access_policies_link_ttl_minutes CHECK (link_ttl_minutes BETWEEN 5 AND 43200),
              ADD CONSTRAINT ck_portal_access_policies_max_uses CHECK (max_uses BETWEEN 1 AND 100),
              ADD CONSTRAINT ck_portal_access_policies_resend_cooldown_seconds
                CHECK (resend_cooldown_seconds BETWEEN 0 AND 86400);

            ALTER TABLE guest_access_links
              ADD CONSTRAINT ck_guest_access_links_purpose CHECK (purpose IN ('invoice_view','invoice_payment')),
              ADD CONSTRAINT ck_guest_access_links_max_uses CHECK (max_uses > 0),
              ADD CONSTRAINT ck_guest_access_links_use_count CHECK (use_count >= 0 AND use_count <= max_uses);
            -- Live links of an invoice, for resend and revoke.
            CREATE INDEX idx_guest_links_invoice_active
              ON guest_access_links(invoice_id, expires_at) WHERE revoked_at IS NULL;
            SQL);

        // Lifecycle policies exactly as design V7.7; statuses as V7.9.
        LifecycleSchema::attach('portal_access_policies', module: 'customer_portal', class: RecordClass::Configuration);
        LifecycleSchema::attach('guest_access_links', module: 'customer_portal', class: RecordClass::Workflow,
            softDelete: SoftDeletePolicy::Protected);

        DB::unprepared(<<<'SQL'
            UPDATE lifecycle_entities SET redacted_columns = '{token_hash}' WHERE entity_name = 'guest_access_links';

            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('guest_access_links', 'active',   'Active',   'success', true,  true,  false, 10, '{"bn":{"label":"সক্রিয়"}}'),
                   ('guest_access_links', 'used_up',  'Used up',  'muted',   false, false, true,  20, '{"bn":{"label":"ব্যবহার শেষ"}}'),
                   ('guest_access_links', 'expired',  'Expired',  'muted',   false, false, true,  30, '{"bn":{"label":"মেয়াদোত্তীর্ণ"}}'),
                   ('guest_access_links', 'revoked',  'Revoked',  'danger',  false, false, true,  40, '{"bn":{"label":"প্রত্যাহার করা"}}');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_definitions WHERE entity_name = 'guest_access_links';
            DELETE FROM lifecycle_entities WHERE entity_name IN ('portal_access_policies', 'guest_access_links');
            SQL);

        Schema::dropIfExists('guest_access_links');
        Schema::dropIfExists('portal_access_policies');
    }
};
