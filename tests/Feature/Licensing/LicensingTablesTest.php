<?php

/**
 * Migration group 13, part 6: licensing tables, issuer and verifier side
 * (design 02 V7.5, V7.7, V7.9, V7.12, V7.13; design 09).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TestRecords;

/**
 * A public signing key with the given columns.
 *
 * @param  array<string, mixed>  $overrides
 */
function signingKey(array $overrides = []): int
{
    return (int) DB::table('license_signing_keys')->insertGetId([
        'key_id' => 'k-'.Str::random(8),
        'public_key' => 'MCowBQYDK2VwAyEA'.Str::random(32),
        ...$overrides,
    ]);
}

/**
 * The verifier-side license of a new installation.
 *
 * @param  array<string, mixed>  $overrides
 */
function installationLicense(array $overrides = []): int
{
    return (int) DB::table('installation_licenses')->insertGetId([
        'installation_id' => (string) Str::uuid(),
        'license_no' => 'LIC-1',
        'signing_key_id' => 'k-2026',
        'signed_payload' => '{"license_no":"LIC-1"}',
        'signature' => 'c2lnbmF0dXJl',
        ...$overrides,
    ]);
}

/**
 * The values the activity trail wrote for a new row.
 *
 * @return array<string, mixed>
 */
function trailValues(string $table, int $id): array
{
    return json_decode((string) DB::table('data_change_logs')
        ->where(['entity_name' => $table, 'record_key' => (string) $id])
        ->value('new_values'), true);
}

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, string $class, bool $enforced, string $defaultStatus) {
        // Arrange: migration 2026_11_19_000068 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe(['licensing', $class, 'protected', 'forbidden', $enforced, $defaultStatus]);
    })->with([
        ['license_signing_keys', 'configuration', false, 'active'],
        ['licenses', 'financial', true, 'draft'],
        ['license_activations', 'history', false, 'active'],
        ['license_check_ins', 'log', false, 'recorded'],
        ['installation_licenses', 'configuration', false, 'pending_activation'],
    ]);

    it('never lets a row be deleted', function (string $table, Closure $create) {
        // Arrange
        $id = $create();

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))
            ->toThrow(QueryException::class, "Records in {$table} are protected");
    })->with([
        'signing key' => ['license_signing_keys', fn () => signingKey()],
        'license' => ['licenses', fn () => TestRecords::license()],
        'activation' => ['license_activations', fn () => TestRecords::licenseActivation()],
        'installation license' => ['installation_licenses', fn () => installationLicense()],
    ]);
});

describe('license signing keys', function () {
    it('refuse an algorithm other than ed25519', function () {
        // Arrange
        $row = ['algorithm' => 'rsa'];

        // Act & Assert
        expect(fn () => signingKey($row))->toThrow(QueryException::class, 'ck_license_signing_keys_algorithm');
    });

    it('refuse an end that is not after the start', function () {
        // Arrange
        $row = ['valid_from' => '2026-06-01 00:00:00+06', 'valid_to' => '2026-06-01 00:00:00+06'];

        // Act & Assert
        expect(fn () => signingKey($row))->toThrow(QueryException::class, 'ck_license_signing_keys_validity');
    });

    it('refuse a key id that is already used', function () {
        // Arrange
        signingKey(['key_id' => 'k-2026']);

        // Act & Assert
        expect(fn () => signingKey(['key_id' => 'k-2026']))->toThrow(QueryException::class, 'license_signing_keys_key_id_unique');
    });
});

describe('licenses', function () {
    it('start as a draft for one activation, two weeks of offline grace and no domain', function () {
        // Arrange
        $id = TestRecords::license();

        // Act
        $row = DB::table('licenses')->where('id', $id)->first();

        // Assert
        expect([$row->status, $row->max_activations, $row->offline_grace_days, $row->allowed_domains])->toBe(['draft', 1, 14, '{}']);
    });

    it('refuse a wrong value', function (array $row, string $constraint) {
        // Arrange: no license yet

        // Act & Assert
        expect(fn () => TestRecords::license($row))->toThrow(QueryException::class, $constraint);
    })->with([
        'unknown edition' => [['edition' => 'pirate'], 'ck_licenses_edition'],
        'no activation' => [['max_activations' => 0], 'ck_licenses_max_activations'],
        'no organization' => [['max_organizations' => 0], 'ck_licenses_limits'],
        'no building' => [['max_buildings' => 0], 'ck_licenses_limits'],
        'no flat' => [['max_flats' => 0], 'ck_licenses_limits'],
        'no user' => [['max_users' => 0], 'ck_licenses_limits'],
        'grace over 90 days' => [['offline_grace_days' => 91], 'ck_licenses_offline_grace_days'],
        'end before start' => [['valid_until' => '2025-12-31'], 'ck_licenses_validity'],
    ]);

    it('keep a signature with what was signed and the key that signed it', function (Closure $row, string $constraint) {
        // Arrange
        $values = $row();

        // Act & Assert
        expect(fn () => TestRecords::license($values))->toThrow(QueryException::class, $constraint);
    })->with([
        'signature without payload' => [fn () => ['signature' => 'c2ln', 'signing_key_id' => signingKey()], 'ck_licenses_signed_payload'],
        'signature without key' => [fn () => ['signature' => 'c2ln', 'signed_payload' => '{}'], 'ck_licenses_signing_key'],
    ]);

    it('accept a complete signature', function () {
        // Arrange
        $row = ['signature' => 'c2ln', 'signed_payload' => '{"license_no":"LIC-1"}', 'signing_key_id' => signingKey()];

        // Act
        $id = TestRecords::license($row);

        // Assert
        expect(DB::table('licenses')->where('id', $id)->value('signature'))->toBe('c2ln');
    });

    it('go from draft through issue to active, suspended and back', function () {
        // Arrange
        $id = TestRecords::license();

        // Act
        foreach (['issued', 'active', 'suspended', 'active'] as $status) {
            DB::table('licenses')->where('id', $id)->update(['status' => $status]);
        }

        // Assert
        expect(DB::table('licenses')->where('id', $id)->value('status'))->toBe('active');
    });

    it('refuse a move the design does not allow', function (array $before, string $to) {
        // Arrange
        $id = TestRecords::license();
        foreach ($before as $status) {
            DB::table('licenses')->where('id', $id)->update(['status' => $status]);
        }

        // Act & Assert
        expect(fn () => DB::table('licenses')->where('id', $id)->update(['status' => $to]))
            ->toThrow(QueryException::class, 'is not allowed');
    })->with([
        'activate a draft' => [[], 'active'],
        'revive an expired license' => [['issued', 'active', 'expired'], 'active'],
        'revive a revoked license' => [['issued', 'revoked'], 'issued'],
    ]);
});

describe('license activations', function () {
    it('refuse a second activation of the same installation', function () {
        // Arrange
        $licenseId = TestRecords::license();
        $installationId = (string) Str::uuid();
        TestRecords::licenseActivation($licenseId, ['installation_id' => $installationId]);

        // Act & Assert
        expect(fn () => TestRecords::licenseActivation($licenseId, ['installation_id' => $installationId]))
            ->toThrow(QueryException::class, 'license_activations_license_id_installation_id_unique');
    });

    it('never write their token or nonce hashes into the activity trail', function () {
        // Arrange
        $row = ['activation_token_hash' => hash('sha256', 'token'), 'rolling_nonce_hash' => hash('sha256', 'nonce')];

        // Act
        $id = TestRecords::licenseActivation(overrides: $row);

        // Assert
        $values = trailValues('license_activations', $id);
        expect([$values['activation_token_hash'], $values['rolling_nonce_hash']])->toBe(['[redacted]', '[redacted]']);
    });

    it('may be flagged as a suspected clone', function () {
        // Arrange
        $id = TestRecords::licenseActivation();

        // Act
        DB::table('license_activations')->where('id', $id)->update(['status' => 'clone_suspected', 'clone_suspected_at' => now()]);

        // Assert
        expect(DB::table('license_activations')->where('id', $id)->value('status'))->toBe('clone_suspected');
    });
});

describe('license check-ins', function () {
    it('record a detected clone', function () {
        // Arrange
        $activationId = TestRecords::licenseActivation();

        // Act
        $id = DB::table('license_check_ins')->insertGetId(['license_activation_id' => $activationId, 'result' => 'clone_detected']);

        // Assert
        expect(DB::table('license_check_ins')->where('id', $id)->value('status'))->toBe('recorded');
    });

    it('refuse an unknown result', function () {
        // Arrange
        $row = ['license_activation_id' => TestRecords::licenseActivation(), 'result' => 'maybe'];

        // Act & Assert
        expect(fn () => DB::table('license_check_ins')->insert($row))->toThrow(QueryException::class, 'ck_license_check_ins_result');
    });

    it('stay out of the change trail, being a log themselves', function () {
        // Arrange
        $activationId = TestRecords::licenseActivation();

        // Act
        $id = DB::table('license_check_ins')->insertGetId(['license_activation_id' => $activationId, 'result' => 'valid']);

        // Assert
        expect(DB::table('data_change_logs')->where(['entity_name' => 'license_check_ins', 'record_key' => (string) $id])->exists())->toBeFalse();
    });
});

describe('installation licenses', function () {
    it('start pending activation', function () {
        // Arrange
        $id = installationLicense();

        // Act
        $status = DB::table('installation_licenses')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('pending_activation');
    });

    it('hold one license per installation', function () {
        // Arrange
        $installationId = (string) Str::uuid();
        installationLicense(['installation_id' => $installationId]);

        // Act & Assert
        expect(fn () => installationLicense(['installation_id' => $installationId]))
            ->toThrow(QueryException::class, 'installation_licenses_installation_id_unique');
    });

    it('never write the signature or activation token into the activity trail', function () {
        // Arrange
        $row = ['activation_token' => 'tok-secret'];

        // Act
        $id = installationLicense($row);

        // Assert
        $values = trailValues('installation_licenses', $id);
        expect([$values['signature'], $values['activation_token']])->toBe(['[redacted]', '[redacted]']);
    });
});
