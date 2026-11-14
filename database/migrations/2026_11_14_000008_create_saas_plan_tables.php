<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 6, part 1: the SaaS packages (design 02 §3, §10 and §14,
 * V7.7, V7.10).
 *
 * - saas_plans: the four packages, with Bangla names.
 * - plan_versions: a priced version of a plan per billing interval; versions
 *   of one plan and interval never overlap in time.
 * - plan_features: what a sold version includes (history: never hidden).
 * - plan_feature_templates: the default feature set of each package.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_plans', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestampTz('created_at')->useCurrent();
            $table->jsonb('translations')->default('{}');
        });

        Schema::create('plan_versions', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('saas_plan_id')->constrained();
            $table->integer('version_no');
            $table->string('billing_interval', 16);
            $table->decimal('price', 14, 2);
            $table->char('currency', 3)->default('BDT');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->smallInteger('trial_days')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['saas_plan_id', 'version_no', 'billing_interval']);
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('plan_version_id')->constrained()->cascadeOnDelete();
            $table->string('feature_code', 80);
            $table->foreign('feature_code')->references('feature_code')->on('feature_catalog');
            $table->boolean('enabled')->default(true);
            $table->bigInteger('limit_value')->nullable();
            $table->jsonb('configuration')->default('{}');
            $table->unique(['plan_version_id', 'feature_code']);
        });

        Schema::create('plan_feature_templates', function (Blueprint $table) {
            $table->foreignId('saas_plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature_code', 80);
            $table->foreign('feature_code')->references('feature_code')->on('feature_catalog');
            $table->boolean('enabled')->default(true);
            $table->jsonb('configuration')->default('{}');
            $table->primary(['saas_plan_id', 'feature_code']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE saas_plans
              ADD CONSTRAINT ck_saas_plans_translations CHECK (jsonb_typeof(translations) = 'object');

            ALTER TABLE plan_versions
              ADD CONSTRAINT ck_plan_versions_billing_interval CHECK (billing_interval IN ('monthly','yearly')),
              ADD CONSTRAINT ck_plan_versions_price CHECK (price >= 0),
              ADD CONSTRAINT ck_plan_versions_trial_days CHECK (trial_days >= 0),
              ADD CONSTRAINT ck_plan_versions_effective_range CHECK (effective_to IS NULL OR effective_to >= effective_from),
              -- effective_to is inclusive, so the range ends the day after it (design §14).
              ADD CONSTRAINT ex_plan_version_period_no_overlap
                EXCLUDE USING gist (
                  saas_plan_id WITH =,
                  billing_interval WITH =,
                  daterange(effective_from, CASE WHEN effective_to IS NULL THEN NULL ELSE effective_to + 1 END, '[)') WITH &&
                );
            SQL);

        // Lifecycle policies exactly as design V7.7.
        LifecycleSchema::attach('saas_plans', module: 'subscriptions', class: RecordClass::Configuration);
        LifecycleSchema::attach('plan_versions', module: 'subscriptions', class: RecordClass::Configuration, defaultStatus: 'draft');
        LifecycleSchema::attach('plan_features', module: 'subscriptions', class: RecordClass::History, softDelete: SoftDeletePolicy::Protected);
        LifecycleSchema::attach('plan_feature_templates', module: 'subscriptions', class: RecordClass::Configuration);

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('saas_plans', 'draft',   'Draft',   'neutral', NULL,  true,  false, 10, '{"bn":{"label":"খসড়া"}}'),
                   ('saas_plans', 'active',  'Active',  'success', true,  true,  false, 20, '{"bn":{"label":"সক্রিয়"}}'),
                   ('saas_plans', 'retired', 'Retired', 'muted',   false, false, true,  30, '{"bn":{"label":"অবসরপ্রাপ্ত"}}'),
                   ('plan_versions', 'draft',   'Draft',   'neutral', NULL,  true,  false, 10, '{"bn":{"label":"খসড়া"}}'),
                   ('plan_versions', 'active',  'Active',  'success', true,  true,  false, 20, '{"bn":{"label":"সক্রিয়"}}'),
                   ('plan_versions', 'retired', 'Retired', 'muted',   false, false, true,  30, '{"bn":{"label":"অবসরপ্রাপ্ত"}}');

            INSERT INTO saas_plans(code, name, description, translations) VALUES
             ('SYSTEM_SUPPLY_BILLING', 'System + Supply + Billing', 'System setup, gas supply operations and customer billing',
              '{"bn":{"name":"সিস্টেম + সরবরাহ + বিলিং","description":"সিস্টেম সেটআপ, গ্যাস সরবরাহ কার্যক্রম ও গ্রাহক বিলিং"}}'),
             ('SUPPLY_BILLING', 'Supply + Billing', 'Gas supply operations and customer billing',
              '{"bn":{"name":"সরবরাহ + বিলিং","description":"গ্যাস সরবরাহ কার্যক্রম ও গ্রাহক বিলিং"}}'),
             ('SYSTEM_BILLING', 'System + Billing', 'System setup and customer billing; no gas supply operations',
              '{"bn":{"name":"সিস্টেম + বিলিং","description":"সিস্টেম সেটআপ ও গ্রাহক বিলিং; গ্যাস সরবরাহ কার্যক্রম নেই"}}'),
             ('BILLING_ONLY', 'Billing Only', 'Customer billing module only',
              '{"bn":{"name":"শুধু বিলিং","description":"শুধু গ্রাহক বিলিং মডিউল"}}');

            -- Default feature set of each package (design §10).
            INSERT INTO plan_feature_templates(saas_plan_id, feature_code, enabled)
            SELECT p.id, v.feature_code,
                   CASE p.code
                     WHEN 'SYSTEM_SUPPLY_BILLING' THEN TRUE
                     WHEN 'SUPPLY_BILLING' THEN v.feature_code <> 'module.system_setup'
                     WHEN 'SYSTEM_BILLING' THEN v.feature_code <> 'module.gas_supply'
                     WHEN 'BILLING_ONLY' THEN v.feature_code = 'module.billing'
                   END
            FROM saas_plans p
            CROSS JOIN (VALUES ('module.system_setup'), ('module.gas_supply'), ('module.billing')) AS v(feature_code);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_definitions WHERE entity_name IN ('saas_plans', 'plan_versions');
            DELETE FROM lifecycle_entities WHERE entity_name IN ('saas_plans', 'plan_versions', 'plan_features', 'plan_feature_templates');
            SQL);

        Schema::dropIfExists('plan_feature_templates');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plan_versions');
        Schema::dropIfExists('saas_plans');
    }
};
