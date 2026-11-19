<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 13, part 6: licensing (design 02 V7.5, V7.7, V7.9, V7.12,
 * V7.13 clone detection; design 09).
 *
 * Issuer side (the platform's license server):
 * - license_signing_keys: public keys only. A private signing key is never
 *   stored in the database or the repository.
 * - licenses: what a licensee may run (edition, domains, limits, features,
 *   dates, offline grace) and its signature. Signature, signed payload and
 *   signing key are recorded together. draft → issued → active ⇄ suspended →
 *   expired / revoked is enforced.
 * - license_activations: one per license and installation, with the
 *   fingerprint, hashed tokens and the rolling nonce that exposes a clone.
 * - license_check_ins: every check-in and its result (a log, not copied into
 *   the change trail).
 *
 * Verifier side (every installation):
 * - installation_licenses: the signed license this installation runs under,
 *   one row per installation.
 *
 * Tokens and signatures are redacted from every log. All five are protected.
 * Row-level security comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_signing_keys', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->string('key_id', 40)->unique();
            $table->string('algorithm', 16)->default('ed25519');
            $table->text('public_key');
            $table->timestampTz('valid_from')->useCurrent();
            $table->timestampTz('valid_to')->nullable();
        });

        Schema::create('licenses', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->char('public_id', 26)->unique();
            $table->string('license_no', 40)->unique();
            $table->foreignId('licensee_organization_id')->nullable()->constrained('organizations');
            $table->string('licensee_name', 180);
            $table->string('edition', 24);
            $table->smallInteger('max_activations')->default(1);
            $table->integer('max_organizations')->nullable();
            $table->integer('max_buildings')->nullable();
            $table->integer('max_flats')->nullable();
            $table->integer('max_users')->nullable();
            $table->jsonb('features')->default('{}');
            $table->date('valid_from');
            $table->date('valid_until');
            $table->smallInteger('offline_grace_days')->default(14);
            $table->foreignId('signing_key_id')->nullable()->constrained('license_signing_keys');
            $table->jsonb('signed_payload')->nullable();
            $table->text('signature')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();
        });

        Schema::create('license_activations', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('license_id')->constrained();
            $table->uuid('installation_id');
            $table->string('domain', 190);
            $table->char('fingerprint_hash', 64);
            $table->char('activation_token_hash', 64)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->timestampTz('activated_at')->useCurrent();
            $table->timestampTz('last_check_in_at')->nullable();
            $table->char('rolling_nonce_hash', 64)->nullable();
            $table->timestampTz('clone_suspected_at')->nullable();
            $table->unique(['license_id', 'installation_id']);
        });

        Schema::create('license_check_ins', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('license_activation_id')->constrained();
            $table->timestampTz('checked_at')->useCurrent();
            $table->string('result', 24);
            $table->string('app_version', 40)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->jsonb('usage_snapshot')->default('{}');
        });

        Schema::create('installation_licenses', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->uuid('installation_id')->unique();
            $table->string('license_no', 40);
            $table->string('signing_key_id', 40);
            $table->jsonb('signed_payload');
            $table->text('signature');
            $table->text('activation_token')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('last_online_check_at')->nullable();
            $table->timestampTz('grace_until')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE license_signing_keys
              ADD CONSTRAINT ck_license_signing_keys_algorithm CHECK (algorithm IN ('ed25519')),
              ADD CONSTRAINT ck_license_signing_keys_validity CHECK (valid_to IS NULL OR valid_to > valid_from);

            ALTER TABLE licenses
              ADD COLUMN allowed_domains TEXT[] NOT NULL DEFAULT '{}',
              ADD CONSTRAINT ck_licenses_edition CHECK (edition IN ('hosted_saas','self_hosted','internal','trial')),
              ADD CONSTRAINT ck_licenses_max_activations CHECK (max_activations > 0),
              ADD CONSTRAINT ck_licenses_limits CHECK (
                (max_organizations IS NULL OR max_organizations > 0) AND (max_buildings IS NULL OR max_buildings > 0)
                AND (max_flats IS NULL OR max_flats > 0) AND (max_users IS NULL OR max_users > 0)),
              ADD CONSTRAINT ck_licenses_offline_grace_days CHECK (offline_grace_days BETWEEN 0 AND 90),
              ADD CONSTRAINT ck_licenses_validity CHECK (valid_until >= valid_from),
              -- A signature always comes with what was signed and the key that signed it.
              ADD CONSTRAINT ck_licenses_signed_payload CHECK ((signature IS NULL) = (signed_payload IS NULL)),
              ADD CONSTRAINT ck_licenses_signing_key CHECK ((signature IS NULL) = (signing_key_id IS NULL));

            ALTER TABLE license_check_ins
              ADD CONSTRAINT ck_license_check_ins_result CHECK (result IN
                ('valid','expired','revoked','domain_mismatch','fingerprint_mismatch','limit_exceeded','tampered','clone_detected'));
            CREATE INDEX idx_license_check_ins_checked_brin ON license_check_ins USING brin(checked_at);
            SQL);

        // Lifecycle policies exactly as design V7.7; transitions as V7.9.
        LifecycleSchema::attach('license_signing_keys', module: 'licensing', class: RecordClass::Configuration,
            softDelete: SoftDeletePolicy::Protected);
        LifecycleSchema::attach('licenses', module: 'licensing', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true, defaultStatus: 'draft');
        LifecycleSchema::attach('license_activations', module: 'licensing', class: RecordClass::History,
            softDelete: SoftDeletePolicy::Protected);
        LifecycleSchema::attach('license_check_ins', module: 'licensing', class: RecordClass::Log,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'recorded');
        LifecycleSchema::attach('installation_licenses', module: 'licensing', class: RecordClass::Configuration,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'pending_activation');

        DB::unprepared(<<<'SQL'
            UPDATE lifecycle_entities SET log_data_changes = FALSE WHERE entity_name = 'license_check_ins';
            UPDATE lifecycle_entities SET redacted_columns = '{activation_token_hash,rolling_nonce_hash}'
              WHERE entity_name = 'license_activations';
            UPDATE lifecycle_entities SET redacted_columns = '{activation_token,signature}'
              WHERE entity_name = 'installation_licenses';

            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('license_signing_keys', 'active',  'Active',  'success', true,  true,  false, 10, '{"bn":{"label":"সক্রিয়"}}'),
                   ('license_signing_keys', 'rotated', 'Rotated', 'muted',   false, false, false, 20, '{"bn":{"label":"বদলানো হয়েছে"}}'),
                   ('license_signing_keys', 'revoked', 'Revoked', 'danger',  false, false, true,  30, '{"bn":{"label":"প্রত্যাহার করা"}}'),
                   ('licenses', 'draft',     'Draft',     'neutral', NULL,  true,  false, 10, '{"bn":{"label":"খসড়া"}}'),
                   ('licenses', 'issued',    'Issued',    'info',    NULL,  false, false, 20, '{"bn":{"label":"ইস্যুকৃত"}}'),
                   ('licenses', 'active',    'Active',    'success', true,  false, false, 30, '{"bn":{"label":"সক্রিয়"}}'),
                   ('licenses', 'suspended', 'Suspended', 'warning', false, false, false, 40, '{"bn":{"label":"স্থগিত"}}'),
                   ('licenses', 'expired',   'Expired',   'muted',   false, false, true,  50, '{"bn":{"label":"মেয়াদোত্তীর্ণ"}}'),
                   ('licenses', 'revoked',   'Revoked',   'danger',  false, false, true,  60, '{"bn":{"label":"প্রত্যাহার করা"}}'),
                   ('license_activations', 'active',          'Active',          'success', true,  true,  false, 10, '{"bn":{"label":"সক্রিয়"}}'),
                   ('license_activations', 'deactivated',     'Deactivated',     'muted',   false, false, true,  20, '{"bn":{"label":"নিষ্ক্রিয় করা"}}'),
                   ('license_activations', 'clone_suspected', 'Clone suspected', 'danger',  false, false, false, 25, '{"bn":{"label":"নকল কপির সন্দেহ"}}'),
                   ('license_activations', 'blocked',         'Blocked',         'danger',  false, false, true,  30, '{"bn":{"label":"অবরুদ্ধ"}}'),
                   ('license_check_ins', 'recorded', 'Recorded', 'neutral', NULL, true, true, 10, '{"bn":{"label":"রেকর্ডকৃত"}}'),
                   ('installation_licenses', 'pending_activation', 'Pending activation', 'warning', NULL,  true,  false, 10, '{"bn":{"label":"সক্রিয়করণের অপেক্ষায়"}}'),
                   ('installation_licenses', 'active',             'Active',             'success', true,  true,  false, 20, '{"bn":{"label":"সক্রিয়"}}'),
                   ('installation_licenses', 'grace',              'Offline grace',      'warning', true,  false, false, 30, '{"bn":{"label":"অফলাইন ছাড়ের সময়"}}'),
                   ('installation_licenses', 'expired',            'Expired',            'danger',  false, false, false, 40, '{"bn":{"label":"মেয়াদোত্তীর্ণ"}}'),
                   ('installation_licenses', 'invalid',            'Invalid',            'danger',  false, false, false, 50, '{"bn":{"label":"অবৈধ"}}');

            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('licenses', 'draft',     'issued',    'license.issue',   false),
                   ('licenses', 'issued',    'active',    NULL,              false),
                   ('licenses', 'issued',    'revoked',   'license.revoke',  true),
                   ('licenses', 'active',    'suspended', 'license.suspend', true),
                   ('licenses', 'suspended', 'active',    'license.suspend', true),
                   ('licenses', 'active',    'expired',   NULL,              false),
                   ('licenses', 'active',    'revoked',   'license.revoke',  true),
                   ('licenses', 'suspended', 'revoked',   'license.revoke',  true);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_transitions WHERE entity_name = 'licenses';
            DELETE FROM status_definitions WHERE entity_name IN
              ('license_signing_keys', 'licenses', 'license_activations', 'license_check_ins', 'installation_licenses');
            DELETE FROM lifecycle_entities WHERE entity_name IN
              ('license_signing_keys', 'licenses', 'license_activations', 'license_check_ins', 'installation_licenses');
            SQL);

        Schema::dropIfExists('installation_licenses');
        Schema::dropIfExists('license_check_ins');
        Schema::dropIfExists('license_activations');
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('license_signing_keys');
    }
};
