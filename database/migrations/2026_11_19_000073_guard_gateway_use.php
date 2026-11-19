<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gateways are used only by whoever collects the money (owner decisions
 * D47 and D50, design V7.23, group 13 questions 5 to 7):
 *
 * - A payment gateway uses a payment provider and an SMS gateway an SMS
 *   provider: a fixed provider_type column and a composite key
 *   (provider, provider_type) to integration_providers.
 * - A default gateway is active.
 * - An attempt goes through a gateway of its payment's collector: an
 *   organization gateway of payments.payee_organization_id, or a building
 *   gateway, which belongs to the building's owner, for that building's
 *   internal cost allocation payments. Its provider is the gateway's, and a
 *   new attempt (or a new gateway on an attempt) needs an active gateway.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE integration_providers
              ADD CONSTRAINT uq_integration_provider_type UNIQUE (code, provider_type);

            ALTER TABLE organization_payment_gateways
              ADD COLUMN provider_type VARCHAR(16) NOT NULL DEFAULT 'payment',
              ADD CONSTRAINT ck_organization_payment_gateways_provider_type CHECK (provider_type = 'payment'),
              DROP CONSTRAINT fk_org_payment_gateway_provider,
              ADD CONSTRAINT fk_org_payment_gateway_provider FOREIGN KEY (provider, provider_type)
                REFERENCES integration_providers(code, provider_type),
              ADD CONSTRAINT ck_organization_payment_gateways_default_active CHECK (NOT is_default OR status = 'active');

            ALTER TABLE organization_sms_gateways
              ADD COLUMN provider_type VARCHAR(16) NOT NULL DEFAULT 'sms',
              ADD CONSTRAINT ck_organization_sms_gateways_provider_type CHECK (provider_type = 'sms'),
              DROP CONSTRAINT fk_org_sms_gateway_provider,
              ADD CONSTRAINT fk_org_sms_gateway_provider FOREIGN KEY (provider, provider_type)
                REFERENCES integration_providers(code, provider_type),
              ADD CONSTRAINT ck_organization_sms_gateways_default_active CHECK (NOT is_default OR status = 'active');

            CREATE OR REPLACE FUNCTION validate_payment_attempt_gateway() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE gateway RECORD; payment RECORD;
            BEGIN
              IF NEW.gateway_configuration_id IS NULL THEN
                RETURN NEW;
              END IF;
              SELECT organization_id, building_id, provider, status INTO gateway
                FROM organization_payment_gateways WHERE id = NEW.gateway_configuration_id;
              SELECT building_id, billing_mode, payee_organization_id INTO payment FROM payments WHERE id = NEW.payment_id;
              -- A missing gateway or payment is reported by its foreign key.
              IF gateway IS NULL OR payment IS NULL THEN
                RETURN NEW;
              END IF;
              IF gateway.organization_id IS NOT NULL AND gateway.organization_id <> payment.payee_organization_id THEN
                RAISE EXCEPTION 'Gateway % belongs to organization %, not to organization % that collects payment %',
                  NEW.gateway_configuration_id, gateway.organization_id, payment.payee_organization_id, NEW.payment_id;
              END IF;
              IF gateway.building_id IS NOT NULL
                 AND (gateway.building_id <> payment.building_id OR payment.billing_mode <> 'internal_cost_allocation') THEN
                RAISE EXCEPTION 'Building gateway % serves only the internal cost allocation of building %, not a % payment of building %',
                  NEW.gateway_configuration_id, gateway.building_id, payment.billing_mode, payment.building_id;
              END IF;
              IF NEW.provider <> gateway.provider THEN
                RAISE EXCEPTION 'Attempt through gateway % uses %, not the gateway''s provider %',
                  NEW.gateway_configuration_id, NEW.provider, gateway.provider;
              END IF;
              IF (TG_OP = 'INSERT' OR NEW.gateway_configuration_id IS DISTINCT FROM OLD.gateway_configuration_id)
                 AND gateway.status <> 'active' THEN
                RAISE EXCEPTION 'Gateway % is %: only an active gateway takes new attempts', NEW.gateway_configuration_id, gateway.status;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_payment_attempts_gateway
            BEFORE INSERT OR UPDATE OF gateway_configuration_id, provider, payment_id ON payment_attempts
            FOR EACH ROW EXECUTE FUNCTION validate_payment_attempt_gateway();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_payment_attempts_gateway ON payment_attempts;
            DROP FUNCTION IF EXISTS validate_payment_attempt_gateway();
            ALTER TABLE organization_sms_gateways
              DROP CONSTRAINT ck_organization_sms_gateways_default_active,
              DROP CONSTRAINT fk_org_sms_gateway_provider,
              DROP COLUMN provider_type,
              ADD CONSTRAINT fk_org_sms_gateway_provider FOREIGN KEY (provider) REFERENCES integration_providers(code);
            ALTER TABLE organization_payment_gateways
              DROP CONSTRAINT ck_organization_payment_gateways_default_active,
              DROP CONSTRAINT fk_org_payment_gateway_provider,
              DROP COLUMN provider_type,
              ADD CONSTRAINT fk_org_payment_gateway_provider FOREIGN KEY (provider) REFERENCES integration_providers(code);
            ALTER TABLE integration_providers DROP CONSTRAINT uq_integration_provider_type;
            SQL);
    }
};
