<?php

/**
 * Migration group 15: row-level security (design 02 §15 / 02b, owner decision
 * D52, design V7.25). The suite logs in as gasflow_test (BYPASSRLS) to arrange
 * rows; every test of a policy switches to gasflow_app with useAppRole().
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\RowLevelSecurity;
use Tests\Support\TestRecords;

describe('the policies', function () {
    it('force row-level security on the table, for its owner too', function (string $table) {
        // Act
        $security = DB::selectOne(
            'select relrowsecurity as enabled, relforcerowsecurity as forced from pg_class where oid = ?::regclass',
            [$table],
        );

        // Assert
        expect($security->enabled)->toBeTrue()
            ->and($security->forced)->toBeTrue();
    })->with(array_keys(RowLevelSecurity::policies()));

    it('give the table exactly its policy, for every command', function (string $table, string $policy) {
        // Act
        $policies = DB::table('pg_policies')
            ->where('schemaname', 'public')
            ->where('tablename', $table)
            ->get(['policyname', 'cmd', 'permissive']);

        // Assert
        expect($policies->pluck('policyname')->all())->toBe([$policy])
            ->and($policies->first()->cmd)->toBe('ALL')
            ->and($policies->first()->permissive)->toBe('PERMISSIVE');
    })->with(fn () => collect(RowLevelSecurity::policies())->map(fn ($policy, $table) => [$table, $policy])->values()->all());
});

describe('without an organization context', function () {
    it('shows the runtime role no building', function () {
        // Arrange
        TestRecords::building();
        useAppRole();

        // Act
        $visible = DB::table('buildings')->count();

        // Assert
        expect($visible)->toBe(0);
    });
});

describe('organization memberships (D52)', function () {
    beforeEach(function () {
        $this->home = TestRecords::organization();
        $this->other = TestRecords::organization();
        $this->userId = TestRecords::user();
        $this->membershipId = TestRecords::membership($this->home, $this->userId);
    });

    it('are visible to their organization', function () {
        // Arrange
        setDatabaseContext(['organization_id' => $this->home]);
        useAppRole();

        // Act
        $visible = DB::table('organization_memberships')->where('id', $this->membershipId)->exists();

        // Assert
        expect($visible)->toBeTrue();
    });

    it('are visible to their own user in another organization', function () {
        // Arrange
        setDatabaseContext(['organization_id' => $this->other, 'user_id' => $this->userId]);
        useAppRole();

        // Act
        $visible = DB::table('organization_memberships')->where('id', $this->membershipId)->exists();

        // Assert
        expect($visible)->toBeTrue();
    });

    it('are hidden from another organization', function () {
        // Arrange
        setDatabaseContext(['organization_id' => $this->other, 'user_id' => TestRecords::user()]);
        useAppRole();

        // Act
        $visible = DB::table('organization_memberships')->where('id', $this->membershipId)->exists();

        // Assert
        expect($visible)->toBeFalse();
    });

    it('are written only by their organization, not by their user elsewhere', function () {
        // Arrange
        setDatabaseContext(['organization_id' => $this->other, 'user_id' => $this->userId]);
        useAppRole();

        // Act & Assert
        expect(fn () => TestRecords::membership($this->home, $this->userId))
            ->toThrow(QueryException::class, 'new row violates row-level security policy for table "organization_memberships"');
    });

    it('get roles only from their organization, not from their user elsewhere', function () {
        // Arrange
        $roleId = TestRecords::role('organization');
        setDatabaseContext(['organization_id' => $this->other, 'user_id' => $this->userId]);
        useAppRole();

        // Act & Assert
        expect(fn () => DB::table('membership_roles')->insert(['membership_id' => $this->membershipId, 'role_id' => $roleId]))
            ->toThrow(QueryException::class, 'new row violates row-level security policy for table "membership_roles"');
    });
});

describe('building membership roles (D52)', function () {
    beforeEach(function () {
        $this->buildingMembershipId = TestRecords::buildingMembership();
        $this->membership = DB::table('building_memberships')
            ->join('organization_memberships', 'organization_memberships.id', '=', 'building_memberships.organization_membership_id')
            ->where('building_memberships.id', $this->buildingMembershipId)
            ->first(['organization_memberships.organization_id', 'organization_memberships.user_id']);
        $this->roleId = TestRecords::role('building');
    });

    it('are written by the organization of the membership', function () {
        // Arrange
        setDatabaseContext(['organization_id' => $this->membership->organization_id]);
        useAppRole();

        // Act
        DB::table('building_membership_roles')->insert(['building_membership_id' => $this->buildingMembershipId, 'role_id' => $this->roleId]);

        // Assert
        expect(DB::table('building_membership_roles')->where('building_membership_id', $this->buildingMembershipId)->count())->toBe(1);
    });

    it('are refused to the member\'s own user working for an organization that only sees the building', function () {
        // Arrange
        $buildingId = DB::table('building_memberships')->where('id', $this->buildingMembershipId)->value('building_id');
        $grantee = TestRecords::organization();
        TestRecords::accessGrant($buildingId, $grantee);
        setDatabaseContext(['organization_id' => $grantee, 'user_id' => $this->membership->user_id]);
        useAppRole();

        // Act & Assert
        expect(fn () => DB::table('building_membership_roles')->insert(['building_membership_id' => $this->buildingMembershipId, 'role_id' => $this->roleId]))
            ->toThrow(QueryException::class, 'new row violates row-level security policy for table "building_membership_roles"');
    });
});

describe('user devices (D52)', function () {
    beforeEach(function () {
        $this->userId = TestRecords::user();
        $this->deviceId = TestRecords::userDevice($this->userId);
    });

    it('are visible to their own user', function () {
        // Arrange
        setDatabaseContext(['user_id' => $this->userId]);
        useAppRole();

        // Act
        $visible = DB::table('user_devices')->where('id', $this->deviceId)->exists();

        // Assert
        expect($visible)->toBeTrue();
    });

    it('are hidden from another user of the same organization', function () {
        // Arrange
        $organizationId = TestRecords::organization();
        TestRecords::membership($organizationId, $this->userId);
        setDatabaseContext(['organization_id' => $organizationId, 'user_id' => TestRecords::user()]);
        useAppRole();

        // Act
        $visible = DB::table('user_devices')->where('id', $this->deviceId)->exists();

        // Assert
        expect($visible)->toBeFalse();
    });

    it('cannot be registered for another user', function () {
        // Arrange
        setDatabaseContext(['user_id' => TestRecords::user()]);
        useAppRole();

        // Act & Assert
        expect(fn () => TestRecords::userDevice($this->userId))
            ->toThrow(QueryException::class, 'new row violates row-level security policy for table "user_devices"');
    });
});
