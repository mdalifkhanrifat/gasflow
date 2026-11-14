<?php

/**
 * Migration group 4, part 2: building access grants, building memberships and
 * building-scoped roles (design 02 §2, §15 and §16, V7.7).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, string $class, string $softDelete, string $hardDelete) {
        // Arrange: migration group 4 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy])
            ->toBe(['tenancy', $class, $softDelete, $hardDelete]);
    })->with([
        ['building_access_grants', 'workflow', 'protected', 'forbidden'],
        ['building_memberships', 'workflow', 'protected', 'forbidden'],
        ['building_membership_roles', 'pivot', 'allowed', 'allowed'],
    ]);
});

describe('access grants', function () {
    it('refuse an unknown access role', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $vendorId = TestRecords::organization();

        // Act & Assert
        expect(fn () => TestRecords::accessGrant($buildingId, $vendorId, ['access_role' => 'owner']))
            ->toThrow(QueryException::class, 'ck_building_access_grants_access_role');
    });

    it('refuse an end before the start', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $vendorId = TestRecords::organization();

        // Act & Assert
        expect(fn () => TestRecords::accessGrant($buildingId, $vendorId, ['valid_from' => '2026-02-01', 'valid_to' => '2026-01-31']))
            ->toThrow(QueryException::class, 'ck_building_access_grants_valid_range');
    });

    it('refuse an owner that is not the building tenant', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $vendorId = TestRecords::organization();

        // Act & Assert
        expect(fn () => TestRecords::accessGrant($buildingId, $vendorId, ['building_owner_organization_id' => $vendorId]))
            ->toThrow(QueryException::class, 'fk_access_grant_building_owner');
    });

    it('refuse the same grant twice', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $vendorId = TestRecords::organization();
        TestRecords::accessGrant($buildingId, $vendorId);

        // Act & Assert
        expect(fn () => TestRecords::accessGrant($buildingId, $vendorId))
            ->toThrow(QueryException::class, 'building_access_grants_building_id_grantee_organization_id_acce');
    });

    it('can never be deleted, only revoked', function () {
        // Arrange
        $grantId = TestRecords::accessGrant(TestRecords::building(), TestRecords::organization());

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('building_access_grants')->where('id', $grantId)->delete()))
            ->toThrow(QueryException::class);
        expect(DB::table('building_access_grants')->where('id', $grantId)->value('is_deleted'))->toBeFalse();
    });
});

describe('building memberships', function () {
    it('accept a member of the building tenant', function () {
        // Arrange
        $tenantId = TestRecords::organization();
        $buildingId = TestRecords::building($tenantId);

        // Act
        DB::table('building_memberships')->insert(['building_id' => $buildingId, 'organization_membership_id' => TestRecords::member($tenantId)]);

        // Assert
        expect(DB::table('building_memberships')->where('building_id', $buildingId)->count())->toBe(1);
    });

    it('accept a member of an organization with an active grant', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $vendorId = TestRecords::organization();
        TestRecords::accessGrant($buildingId, $vendorId);

        // Act
        DB::table('building_memberships')->insert(['building_id' => $buildingId, 'organization_membership_id' => TestRecords::member($vendorId)]);

        // Assert
        expect(DB::table('building_memberships')->where('building_id', $buildingId)->count())->toBe(1);
    });

    it('refuse a member of an organization without a grant', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $membershipId = TestRecords::member(TestRecords::organization());

        // Act & Assert
        expect(fn () => DB::table('building_memberships')->insert(['building_id' => $buildingId, 'organization_membership_id' => $membershipId]))
            ->toThrow(QueryException::class, 'Organization has no active grant for this building');
    });

    it('refuse a member whose grant is not in force today', function (array $grant) {
        // Arrange
        $buildingId = TestRecords::building();
        $vendorId = TestRecords::organization();
        TestRecords::accessGrant($buildingId, $vendorId, $grant);
        $membershipId = TestRecords::member($vendorId);

        // Act & Assert
        expect(fn () => DB::table('building_memberships')->insert(['building_id' => $buildingId, 'organization_membership_id' => $membershipId]))
            ->toThrow(QueryException::class, 'Organization has no active grant for this building');
    })->with([
        'revoked' => fn () => ['status' => 'revoked'],
        'ended yesterday' => fn () => ['valid_from' => today()->subMonth()->toDateString(), 'valid_to' => today()->subDay()->toDateString()],
        'starting tomorrow' => fn () => ['valid_from' => today()->addDay()->toDateString()],
    ]);

    it('refuse an organization membership that is not active', function () {
        // Arrange
        $tenantId = TestRecords::organization();
        $buildingId = TestRecords::building($tenantId);
        $membershipId = TestRecords::member($tenantId);
        DB::table('organization_memberships')->where('id', $membershipId)->update(['status' => 'suspended']);

        // Act & Assert
        expect(fn () => DB::table('building_memberships')->insert(['building_id' => $buildingId, 'organization_membership_id' => $membershipId]))
            ->toThrow(QueryException::class, 'An active organization membership is required');
    });

    it('refuse the same member twice in one building', function () {
        // Arrange
        $tenantId = TestRecords::organization();
        $row = ['building_id' => TestRecords::building($tenantId), 'organization_membership_id' => TestRecords::member($tenantId)];
        DB::table('building_memberships')->insert($row);

        // Act & Assert
        expect(fn () => DB::table('building_memberships')->insert($row))
            ->toThrow(QueryException::class, 'building_memberships_building_id_organization_membership_id_uni');
    });

    it('refuse an end that is not after the start', function () {
        // Arrange
        $tenantId = TestRecords::organization();
        $row = [
            'building_id' => TestRecords::building($tenantId),
            'organization_membership_id' => TestRecords::member($tenantId),
            'valid_from' => '2026-01-01 09:00:00+06',
            'valid_to' => '2026-01-01 09:00:00+06',
        ];

        // Act & Assert
        expect(fn () => DB::table('building_memberships')->insert($row))
            ->toThrow(QueryException::class, 'ck_building_memberships_valid_range');
    });
});

describe('building roles', function () {
    it('are given to a building membership', function () {
        // Arrange
        $buildingMembershipId = TestRecords::buildingMembership();
        $roleId = TestRecords::role('building');

        // Act
        DB::table('building_membership_roles')->insert(['building_membership_id' => $buildingMembershipId, 'role_id' => $roleId]);

        // Assert
        expect(DB::table('building_membership_roles')->where('building_membership_id', $buildingMembershipId)->pluck('role_id')->all())
            ->toBe([$roleId]);
    });

    it('refuse a platform or organization role', function (string $scope) {
        // Arrange
        $buildingMembershipId = TestRecords::buildingMembership();
        $roleId = TestRecords::role($scope);

        // Act & Assert
        expect(fn () => DB::table('building_membership_roles')->insert(['building_membership_id' => $buildingMembershipId, 'role_id' => $roleId]))
            ->toThrow(QueryException::class, 'building_membership_roles only accepts building-scoped roles');
    })->with(['platform', 'organization']);

    it('lose a role for good when it is taken away', function () {
        // Arrange
        $buildingMembershipId = TestRecords::buildingMembership();
        DB::table('building_membership_roles')->insert(['building_membership_id' => $buildingMembershipId, 'role_id' => TestRecords::role('building')]);

        // Act
        DB::table('building_membership_roles')->where('building_membership_id', $buildingMembershipId)->delete();

        // Assert
        expect(DB::table('building_membership_roles')->where('building_membership_id', $buildingMembershipId)->exists())->toBeFalse();
    });
});
