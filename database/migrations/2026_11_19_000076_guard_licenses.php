<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A license is used only as it was signed (owner decision D50, design
 * V7.23, group 13 question 10):
 *
 * - A license leaves draft only with its signature and issue time, and what
 *   was signed is frozen after draft (freezeColumns): new terms are a new
 *   license.
 * - Its signing key is active and valid at the issue time (now, while not
 *   yet issued).
 * - Activations only for an issued or active license (requireParentStatus).
 *   Live activations (active or clone suspected) never exceed
 *   max_activations; the license row is locked while they are counted. The
 *   domain is one of allowed_domains when the list is not empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE licenses
              ADD CONSTRAINT ck_licenses_issued_signed
                CHECK (status = 'draft' OR (signature IS NOT NULL AND issued_at IS NOT NULL));

            CREATE OR REPLACE FUNCTION validate_license_signing_key() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE k RECORD; signed_at TIMESTAMPTZ := COALESCE(NEW.issued_at, now());
            BEGIN
              IF NEW.signing_key_id IS NULL THEN
                RETURN NEW;
              END IF;
              SELECT key_id, status, valid_from, valid_to INTO k FROM license_signing_keys WHERE id = NEW.signing_key_id;
              -- A missing key is reported by its foreign key.
              IF FOUND AND (k.status <> 'active' OR signed_at < k.valid_from OR (k.valid_to IS NOT NULL AND signed_at >= k.valid_to)) THEN
                RAISE EXCEPTION 'Signing key % is % and valid % to %: it cannot sign license % at %',
                  k.key_id, k.status, k.valid_from, COALESCE(k.valid_to::text, 'open'), NEW.license_no, signed_at;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_licenses_signing_key
            BEFORE INSERT OR UPDATE OF signing_key_id, issued_at ON licenses
            FOR EACH ROW EXECUTE FUNCTION validate_license_signing_key();

            CREATE OR REPLACE FUNCTION validate_license_activation() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE license RECORD; live INTEGER;
            BEGIN
              IF lifecycle_override_active() THEN
                RETURN NEW;
              END IF;
              SELECT license_no, max_activations, allowed_domains INTO license FROM licenses WHERE id = NEW.license_id FOR UPDATE;
              -- A missing license is reported by its foreign key.
              IF NOT FOUND THEN
                RETURN NEW;
              END IF;
              IF cardinality(license.allowed_domains) > 0 AND NOT NEW.domain = ANY (license.allowed_domains) THEN
                RAISE EXCEPTION 'License % does not allow domain %', license.license_no, NEW.domain;
              END IF;
              IF NEW.status IN ('active','clone_suspected') THEN
                SELECT count(*) INTO live FROM license_activations a
                  WHERE a.license_id = NEW.license_id AND a.status IN ('active','clone_suspected')
                    AND NOT a.is_deleted AND a.id IS DISTINCT FROM NEW.id;
                IF live + 1 > license.max_activations THEN
                  RAISE EXCEPTION 'License % allows % activations and already has %', license.license_no, license.max_activations, live
                    USING HINT = 'Deactivate an installation first.';
                END IF;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_license_activations_limits
            BEFORE INSERT OR UPDATE OF license_id, domain, status ON license_activations
            FOR EACH ROW EXECUTE FUNCTION validate_license_activation();
            SQL);

        LifecycleSchema::freezeColumns('licenses',
            ['public_id', 'license_no', 'licensee_organization_id', 'licensee_name', 'edition', 'allowed_domains',
                'max_activations', 'max_organizations', 'max_buildings', 'max_flats', 'max_users', 'features', 'valid_from',
                'valid_until', 'offline_grace_days', 'signing_key_id', 'signed_payload', 'signature', 'issued_at', 'issued_by'],
            editableStatuses: ['draft'], name: 'signed');
        LifecycleSchema::requireParentStatus('license_activations', parent: 'licenses', foreignKey: 'license_id',
            parentStatuses: ['issued', 'active'], whenStatuses: ['active'], name: 'issued_license');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_license_activations_parent_issued_license ON license_activations;
            DROP TRIGGER IF EXISTS trg_licenses_freeze_signed ON licenses;
            DROP TRIGGER IF EXISTS trg_license_activations_limits ON license_activations;
            DROP FUNCTION IF EXISTS validate_license_activation();
            DROP TRIGGER IF EXISTS trg_licenses_signing_key ON licenses;
            DROP FUNCTION IF EXISTS validate_license_signing_key();
            ALTER TABLE licenses DROP CONSTRAINT ck_licenses_issued_signed;
            SQL);
    }
};
