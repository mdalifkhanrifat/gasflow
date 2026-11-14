<?php

/**
 * Migration group 3, part 2: organizations, memberships and scope-aware roles
 * (design 02 §1 and §16, V7.7; design SQL tests 25 and 26).
 */

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 */
function insertOrganization(array $overrides = []): int
{
    return (int) DB::table('organizations')->insertGetId([
        'public_id' => strtolower((string) Str::ulid()),
        'code' => 'ORG-'.Str::upper(Str::random(6)),
        'name' => 'Northstar Gas Services',
        'organization_type' => 'gas_vendor',
        ...$overrides,
    ]);
}

function insertRole(string $scope): int
{
    return (int) DB::table('roles')->insertGetId(['code' => "{$scope}.role.".Str::random(6), 'name' => ucfirst($scope).' role', 'scope' => $scope]);
}

function insertMembership(int $organizationId, int $userId): int
{
    return (int) DB::table('organization_memberships')->insertGetId([
        'organization_id' => $organizationId,
        'user_id' => $userId,
        'membership_role' => 'staff',
    ]);
}

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, string $module, string $class, string $softDelete, string $hardDelete) {
        // Arrange: migration group 3 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy])
            ->toBe([$module, $class, $softDelete, $hardDelete]);
    })->with([
        ['roles', 'identity', 'configuration', 'allowed', 'forbidden'],
        ['permissions', 'identity', 'configuration', 'allowed', 'forbidden'],
        ['role_permissions', 'identity', 'pivot', 'allowed', 'allowed'],
        ['platform_user_roles', 'identity', 'pivot', 'allowed', 'allowed'],
        ['organizations', 'organizations', 'master', 'allowed', 'forbidden'],
        ['organization_memberships', 'tenancy', 'workflow', 'protected', 'forbidden'],
        ['membership_roles', 'tenancy', 'pivot', 'allowed', 'allowed'],
    ]);
});

describe('organizations', function () {
    it('start pending, in Bangla, with optional location tracking', function () {
        // Arrange
        $id = insertOrganization();

        // Act
        $organization = DB::table('organizations')->find($id);

        // Assert
        expect([$organization->status, $organization->default_locale, $organization->location_tracking_policy])
            ->toBe(['pending', 'bn', 'optional']);
    });

    it('refuse a language GasFlow does not speak', function () {
        // Arrange
        $id = insertOrganization();

        // Act & Assert
        expect(fn () => DB::table('organizations')->where('id', $id)->update(['default_locale' => 'fr']))
            ->toThrow(QueryException::class, 'organizations_default_locale_foreign');
    });

    it('refuse an unknown organization type', function () {
        // Arrange
        $row = ['organization_type' => 'bank'];

        // Act & Assert
        expect(fn () => insertOrganization($row))->toThrow(QueryException::class, 'ck_organizations_type');
    });

    it('refuse an unknown location tracking policy', function () {
        // Arrange
        $row = ['location_tracking_policy' => 'always'];

        // Act & Assert
        expect(fn () => insertOrganization($row))->toThrow(QueryException::class, 'ck_organizations_location_tracking_policy');
    });

    it('are hidden, not removed, when deleted', function () {
        // Arrange
        $id = insertOrganization();

        // Act
        DB::table('organizations')->where('id', $id)->delete();

        // Assert
        expect(DB::table('organizations')->where('id', $id)->value('is_deleted'))->toBeTrue();
    });
});

describe('memberships', function () {
    it('allow one membership per user and organization', function () {
        // Arrange
        $organizationId = insertOrganization();
        $userId = User::factory()->create()->id;
        insertMembership($organizationId, $userId);

        // Act & Assert
        expect(fn () => insertMembership($organizationId, $userId))
            ->toThrow(QueryException::class, 'organization_memberships_organization_id_user_id_unique');
    });

    it('can never be deleted, only revoked', function () {
        // Arrange
        $membershipId = insertMembership(insertOrganization(), User::factory()->create()->id);

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('organization_memberships')->where('id', $membershipId)->delete()))
            ->toThrow(QueryException::class);
        expect(DB::table('organization_memberships')->where('id', $membershipId)->value('is_deleted'))->toBeFalse();
    });

    it('take organization roles', function () {
        // Arrange
        $membershipId = insertMembership(insertOrganization(), User::factory()->create()->id);
        $roleId = insertRole('organization');

        // Act
        DB::table('membership_roles')->insert(['membership_id' => $membershipId, 'role_id' => $roleId]);

        // Assert
        expect(DB::table('membership_roles')->where('membership_id', $membershipId)->pluck('role_id')->all())->toBe([$roleId]);
    });

    it('refuse a platform or building role', function (string $scope) {
        // Arrange
        $membershipId = insertMembership(insertOrganization(), User::factory()->create()->id);
        $roleId = insertRole($scope);

        // Act & Assert
        expect(fn () => DB::table('membership_roles')->insert(['membership_id' => $membershipId, 'role_id' => $roleId]))
            ->toThrow(QueryException::class, 'membership_roles only accepts organization-scoped roles');
    })->with(['platform', 'building']);
});

describe('platform roles', function () {
    it('are given to users', function () {
        // Arrange
        $userId = User::factory()->create()->id;
        $roleId = insertRole('platform');

        // Act
        DB::table('platform_user_roles')->insert(['user_id' => $userId, 'role_id' => $roleId]);

        // Assert
        expect(DB::table('platform_user_roles')->where('user_id', $userId)->count())->toBe(1);
    });

    it('refuse an organization or building role', function (string $scope) {
        // Arrange
        $userId = User::factory()->create()->id;
        $roleId = insertRole($scope);

        // Act & Assert
        expect(fn () => DB::table('platform_user_roles')->insert(['user_id' => $userId, 'role_id' => $roleId]))
            ->toThrow(QueryException::class, 'platform_user_roles only accepts platform-scoped roles');
    })->with(['organization', 'building']);
});

describe('roles', function () {
    it('refuse an unknown scope', function () {
        // Arrange
        $row = ['code' => 'odd.role', 'name' => 'Odd', 'scope' => 'global'];

        // Act & Assert
        expect(fn () => DB::table('roles')->insert($row))->toThrow(QueryException::class, 'ck_roles_scope');
    });

    it('lose a permission for good when it is taken away', function () {
        // Arrange
        $roleId = insertRole('organization');
        $permissionId = (int) DB::table('permissions')->insertGetId(['code' => 'invoice.issue']);
        DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);

        // Act
        DB::table('role_permissions')->where(['role_id' => $roleId, 'permission_id' => $permissionId])->delete();

        // Assert
        expect(DB::table('role_permissions')->where('role_id', $roleId)->exists())->toBeFalse();
    });
});
