<?php

use App\Enums\Lifecycle\RecordClass;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 13, part 3: payment and SMS gateway configurations of an
 * organization or a building (design 02 §13, V7.7, V7.9, V7.12).
 *
 * - organization_payment_gateways: a provider of the catalogue in sandbox or
 *   production, with encrypted credentials and webhook secret (redacted from
 *   every log). One per scope, provider and environment; one default per
 *   scope and environment.
 * - organization_sms_gateways: the same for SMS, without environments.
 * - payment_attempts.gateway_configuration_id: the configuration an attempt
 *   went through.
 *
 * A configuration is for one organization or one building, never both.
 * inactive → active → disabled, not enforced by the design. Configurations
 * may be hidden. Row-level security comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_payment_gateways', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('organization_id')->nullable()->constrained();
            $table->foreignId('building_id')->nullable()->constrained();
            $table->string('provider', 60);
            $table->string('environment', 16)->default('sandbox');
            $table->string('merchant_identifier', 180)->nullable();
            $table->text('credentials_encrypted');
            $table->text('webhook_secret_encrypted')->nullable();
            $table->text('callback_base_url')->nullable();
            $table->string('status', 20)->default('inactive');
            $table->boolean('is_default')->default(false);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->foreign('provider', 'fk_org_payment_gateway_provider')->references('code')->on('integration_providers');
        });

        Schema::create('organization_sms_gateways', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('organization_id')->nullable()->constrained();
            $table->foreignId('building_id')->nullable()->constrained();
            $table->string('provider', 60);
            $table->string('sender_id', 100)->nullable();
            $table->text('credentials_encrypted');
            $table->string('status', 20)->default('inactive');
            $table->boolean('is_default')->default(false);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->foreign('provider', 'fk_org_sms_gateway_provider')->references('code')->on('integration_providers');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE organization_payment_gateways
              ADD CONSTRAINT ck_organization_payment_gateways_scope
                CHECK ((organization_id IS NOT NULL)::int + (building_id IS NOT NULL)::int = 1),
              ADD CONSTRAINT ck_organization_payment_gateways_environment CHECK (environment IN ('sandbox','production'));
            CREATE UNIQUE INDEX uq_org_payment_gateway_provider
              ON organization_payment_gateways(organization_id, provider, environment) WHERE organization_id IS NOT NULL;
            CREATE UNIQUE INDEX uq_building_payment_gateway_provider
              ON organization_payment_gateways(building_id, provider, environment) WHERE building_id IS NOT NULL;
            CREATE UNIQUE INDEX uq_org_payment_gateway_default
              ON organization_payment_gateways(organization_id, environment) WHERE organization_id IS NOT NULL AND is_default;
            CREATE UNIQUE INDEX uq_building_payment_gateway_default
              ON organization_payment_gateways(building_id, environment) WHERE building_id IS NOT NULL AND is_default;

            ALTER TABLE organization_sms_gateways
              ADD CONSTRAINT ck_organization_sms_gateways_scope
                CHECK ((organization_id IS NOT NULL)::int + (building_id IS NOT NULL)::int = 1);
            CREATE UNIQUE INDEX uq_org_sms_gateway_provider
              ON organization_sms_gateways(organization_id, provider) WHERE organization_id IS NOT NULL;
            CREATE UNIQUE INDEX uq_building_sms_gateway_provider
              ON organization_sms_gateways(building_id, provider) WHERE building_id IS NOT NULL;
            CREATE UNIQUE INDEX uq_org_sms_gateway_default
              ON organization_sms_gateways(organization_id) WHERE organization_id IS NOT NULL AND is_default;
            CREATE UNIQUE INDEX uq_building_sms_gateway_default
              ON organization_sms_gateways(building_id) WHERE building_id IS NOT NULL AND is_default;

            ALTER TABLE payment_attempts
              ADD COLUMN gateway_configuration_id BIGINT,
              ADD CONSTRAINT fk_payment_attempt_gateway_configuration FOREIGN KEY (gateway_configuration_id)
                REFERENCES organization_payment_gateways(id);
            SQL);

        // Lifecycle policies exactly as design V7.7; statuses as V7.9.
        LifecycleSchema::attach('organization_payment_gateways', module: 'integrations', class: RecordClass::Configuration,
            defaultStatus: 'inactive');
        LifecycleSchema::attach('organization_sms_gateways', module: 'integrations', class: RecordClass::Configuration,
            defaultStatus: 'inactive');

        DB::unprepared(<<<'SQL'
            UPDATE lifecycle_entities SET redacted_columns = '{credentials_encrypted,webhook_secret_encrypted}'
              WHERE entity_name = 'organization_payment_gateways';
            UPDATE lifecycle_entities SET redacted_columns = '{credentials_encrypted}'
              WHERE entity_name = 'organization_sms_gateways';

            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('organization_payment_gateways', 'active',   'Active',   'success', true,  true,  false, 10, '{"bn":{"label":"সক্রিয়"}}'),
                   ('organization_payment_gateways', 'inactive', 'Inactive', 'muted',   false, true,  false, 20, '{"bn":{"label":"নিষ্ক্রিয়"}}'),
                   ('organization_payment_gateways', 'disabled', 'Disabled', 'danger',  false, false, false, 30, '{"bn":{"label":"বন্ধ"}}'),
                   ('organization_sms_gateways', 'active',   'Active',   'success', true,  true,  false, 10, '{"bn":{"label":"সক্রিয়"}}'),
                   ('organization_sms_gateways', 'inactive', 'Inactive', 'muted',   false, true,  false, 20, '{"bn":{"label":"নিষ্ক্রিয়"}}'),
                   ('organization_sms_gateways', 'disabled', 'Disabled', 'danger',  false, false, false, 30, '{"bn":{"label":"বন্ধ"}}');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_attempts
              DROP CONSTRAINT fk_payment_attempt_gateway_configuration,
              DROP COLUMN gateway_configuration_id;
            DELETE FROM status_definitions WHERE entity_name IN ('organization_payment_gateways', 'organization_sms_gateways');
            DELETE FROM lifecycle_entities WHERE entity_name IN ('organization_payment_gateways', 'organization_sms_gateways');
            SQL);

        Schema::dropIfExists('organization_sms_gateways');
        Schema::dropIfExists('organization_payment_gateways');
    }
};
