<?php

/**
 * Migration group 13, part 2: portal access policies and guest invoice links
 * (design 02 §11, V7.7, V7.9, V7.12).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * A portal policy with the given columns (scope included).
 *
 * @param  array<string, mixed>  $row
 */
function portalPolicy(array $row): int
{
    return (int) DB::table('portal_access_policies')->insertGetId($row);
}

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, array $expected) {
        // Arrange: migration 2026_11_19_000063 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe($expected);
    })->with([
        ['portal_access_policies', ['customer_portal', 'configuration', 'allowed', 'forbidden', false, 'active']],
        ['guest_access_links', ['customer_portal', 'workflow', 'protected', 'forbidden', false, 'active']],
    ]);

    it('lets a policy be hidden', function () {
        // Arrange
        $id = portalPolicy(['building_id' => TestRecords::building()]);

        // Act
        DB::table('portal_access_policies')->where('id', $id)->delete();

        // Assert
        expect(DB::table('portal_access_policies')->where('id', $id)->value('is_deleted'))->toBeTrue();
    });

    it('never lets a link be deleted or hidden', function () {
        // Arrange
        $id = TestRecords::guestLink();

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('guest_access_links')->where('id', $id)->delete()))
            ->toThrow(QueryException::class, 'Records in guest_access_links are protected');
    });
});

describe('portal access policies', function () {
    it('default to a week, five uses, view and pay, no OTP, a minute between resends', function () {
        // Arrange
        $id = portalPolicy(['organization_id' => TestRecords::organization()]);

        // Act
        $row = DB::table('portal_access_policies')->where('id', $id)->first();

        // Assert
        expect([$row->enabled, $row->link_ttl_minutes, $row->max_uses, $row->allow_invoice_view, $row->allow_online_payment, $row->require_phone_otp, $row->resend_cooldown_seconds])
            ->toBe([true, 10080, 5, true, true, false, 60]);
    });

    it('belong to an organization or a building, never both or neither', function (Closure $scope) {
        // Arrange
        $row = $scope();

        // Act & Assert
        expect(fn () => portalPolicy($row))->toThrow(QueryException::class, 'ck_portal_access_policies_scope');
    })->with([
        'both' => [fn () => ['organization_id' => TestRecords::organization(), 'building_id' => TestRecords::building()]],
        'neither' => [fn () => []],
    ]);

    it('refuse a second policy for the same scope', function (string $column, Closure $owner, string $constraint) {
        // Arrange
        $ownerId = $owner();
        portalPolicy([$column => $ownerId]);

        // Act & Assert
        expect(fn () => portalPolicy([$column => $ownerId]))->toThrow(QueryException::class, $constraint);
    })->with([
        'organization' => ['organization_id', fn () => TestRecords::organization(), 'portal_access_policies_organization_id_unique'],
        'building' => ['building_id', fn () => TestRecords::building(), 'portal_access_policies_building_id_unique'],
    ]);

    it('refuse a value outside its limits', function (array $values, string $constraint) {
        // Arrange
        $row = ['building_id' => TestRecords::building(), ...$values];

        // Act & Assert
        expect(fn () => portalPolicy($row))->toThrow(QueryException::class, $constraint);
    })->with([
        'link shorter than 5 minutes' => [['link_ttl_minutes' => 4], 'ck_portal_access_policies_link_ttl_minutes'],
        'link longer than 30 days' => [['link_ttl_minutes' => 43201], 'ck_portal_access_policies_link_ttl_minutes'],
        'no use' => [['max_uses' => 0], 'ck_portal_access_policies_max_uses'],
        'more than 100 uses' => [['max_uses' => 101], 'ck_portal_access_policies_max_uses'],
        'negative cooldown' => [['resend_cooldown_seconds' => -1], 'ck_portal_access_policies_resend_cooldown_seconds'],
        'cooldown over a day' => [['resend_cooldown_seconds' => 86401], 'ck_portal_access_policies_resend_cooldown_seconds'],
    ]);
});

describe('guest access links', function () {
    it('start active for paying, with one use', function () {
        // Arrange
        $id = TestRecords::guestLink();

        // Act
        $row = DB::table('guest_access_links')->where('id', $id)->first();

        // Assert
        expect([$row->status, $row->purpose, $row->max_uses, $row->use_count])->toBe(['active', 'invoice_payment', 1, 0]);
    });

    it('refuse a wrong value', function (array $row, string $constraint) {
        // Arrange: a new invoice

        // Act & Assert
        expect(fn () => TestRecords::guestLink(overrides: $row))->toThrow(QueryException::class, $constraint);
    })->with([
        'unknown purpose' => [['purpose' => 'invoice_edit'], 'ck_guest_access_links_purpose'],
        'no use' => [['max_uses' => 0], 'ck_guest_access_links_max_uses'],
        'negative uses' => [['use_count' => -1], 'ck_guest_access_links_use_count'],
        'more uses than allowed' => [['use_count' => 2], 'ck_guest_access_links_use_count'],
    ]);

    it('refuse a token that is already used', function () {
        // Arrange
        $hash = hash('sha256', 'token');
        TestRecords::guestLink(overrides: ['token_hash' => $hash]);

        // Act & Assert
        expect(fn () => TestRecords::guestLink(overrides: ['token_hash' => $hash]))
            ->toThrow(QueryException::class, 'guest_access_links_token_hash_unique');
    });

    it('never write the token hash into the activity trail', function () {
        // Arrange: a new invoice

        // Act
        $id = TestRecords::guestLink();

        // Assert
        $values = json_decode((string) DB::table('data_change_logs')
            ->where(['entity_name' => 'guest_access_links', 'record_key' => (string) $id])
            ->value('new_values'), true);
        expect($values['token_hash'])->toBe('[redacted]');
    });
});

describe('what a guest link may do', function () {
    it('is made only for an issued invoice', function () {
        // Arrange
        $invoiceId = TestRecords::invoice();

        // Act & Assert
        expect(fn () => TestRecords::guestLink($invoiceId))->toThrow(QueryException::class, 'invoices');
    });

    it('refuses a wrong value', function (array $row, string $constraint) {
        // Arrange: a new issued invoice

        // Act & Assert
        expect(fn () => TestRecords::guestLink(overrides: $row))->toThrow(QueryException::class, $constraint);
    })->with([
        'already expired' => [['expires_at' => '2020-01-01 00:00:00+06'], 'ck_guest_access_links_expiry'],
        'more than 100 uses' => [['max_uses' => 101], 'ck_guest_access_links_max_uses_bound'],
        'revoked time on an active link' => [['revoked_at' => '2026-10-01 00:00:00+06'], 'ck_guest_access_links_revoked'],
    ]);

    it('is revoked together with its revoke time', function () {
        // Arrange
        $id = TestRecords::guestLink();

        // Act & Assert
        expect(fn () => DB::table('guest_access_links')->where('id', $id)->update(['status' => 'revoked']))
            ->toThrow(QueryException::class, 'ck_guest_access_links_revoked');
    });

    it('counts a use while active', function () {
        // Arrange
        $id = TestRecords::guestLink(overrides: ['max_uses' => 3]);

        // Act
        DB::table('guest_access_links')->where('id', $id)->update(['use_count' => 1, 'last_used_at' => now()]);

        // Assert
        expect(DB::table('guest_access_links')->where('id', $id)->value('use_count'))->toBe(1);
    });

    it('cannot be used once revoked', function () {
        // Arrange
        $id = TestRecords::guestLink(overrides: ['max_uses' => 3]);
        DB::table('guest_access_links')->where('id', $id)->update(['status' => 'revoked', 'revoked_at' => now()]);

        // Act & Assert
        expect(fn () => DB::table('guest_access_links')->where('id', $id)->update(['use_count' => 1]))
            ->toThrow(QueryException::class, 'is revoked');
    });

    it('cannot be used after its expiry', function () {
        // Arrange
        $id = TestRecords::guestLink(overrides: ['max_uses' => 3, 'created_at' => now()->subDays(2), 'expires_at' => now()->subDay()]);

        // Act & Assert
        expect(fn () => DB::table('guest_access_links')->where('id', $id)->update(['use_count' => 1]))
            ->toThrow(QueryException::class, 'it cannot be used');
    });

    it('never takes back a use', function () {
        // Arrange
        $id = TestRecords::guestLink(overrides: ['max_uses' => 3]);
        DB::table('guest_access_links')->where('id', $id)->update(['use_count' => 2]);

        // Act & Assert
        expect(fn () => DB::table('guest_access_links')->where('id', $id)->update(['use_count' => 1]))
            ->toThrow(QueryException::class, 'cannot go down');
    });

    it('keeps what it was made for', function (string $column, Closure $value) {
        // Arrange
        $id = TestRecords::guestLink();

        // Act & Assert
        expect(fn () => DB::table('guest_access_links')->where('id', $id)->update([$column => $value()]))
            ->toThrow(QueryException::class, "{$column} can no longer change");
    })->with([
        'invoice' => ['invoice_id', fn () => TestRecords::issuedInvoice()],
        'token' => ['token_hash', fn () => hash('sha256', 'other')],
        'purpose' => ['purpose', fn () => 'invoice_view'],
        'uses' => ['max_uses', fn () => 5],
        'expiry' => ['expires_at', fn () => now()->addYear()],
    ]);
});
