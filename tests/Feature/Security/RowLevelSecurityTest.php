<?php

/**
 * Migration group 15: row-level security (design 02 §15 / 02b, owner decision
 * D52, design V7.25). The suite logs in as gasflow_test (BYPASSRLS) to arrange
 * rows; every test of a policy switches to gasflow_app with useAppRole().
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RowLevelSecurity;
use Tests\Support\TenantWorld;
use Tests\Support\TestRecords;

/**
 * The keys (md5 of the row) of every row of the table the current role sees.
 *
 * @return list<string>
 */
function visibleRowKeys(string $table): array
{
    return array_column(DB::select("select md5(t::text) as k from {$table} t"), 'k');
}

/**
 * Every row of every table under row-level security, by table.
 *
 * @return array<string, list<string>>
 */
function rowKeysByTable(): array
{
    return collect(RowLevelSecurity::policies())->keys()
        ->mapWithKeys(fn (string $table) => [$table => visibleRowKeys($table)])->all();
}

/**
 * Two organizations, each with a row in every table under row-level security:
 * the rows written while building each one are its own.
 *
 * @return array{0: TenantWorld, 1: array<string, list<string>>, 2: TenantWorld, 3: array<string, list<string>>}
 */
function twoTenantWorlds(): array
{
    $first = TenantWorld::create();
    $beforeFirst = rowKeysByTable();
    $first->fill();
    $afterFirst = rowKeysByTable();
    $second = TenantWorld::create();
    $beforeSecond = rowKeysByTable();
    $second->fill();
    $afterSecond = rowKeysByTable();
    setDatabaseContext(['organization_id' => '', 'user_id' => '']);

    $firstRows = collect($afterFirst)->map(fn ($keys, $table) => array_values(array_diff($keys, $beforeFirst[$table])))->all();
    $secondRows = collect($afterSecond)->map(fn ($keys, $table) => array_values(array_diff($keys, $beforeSecond[$table])))->all();

    return [$first, $firstRows, $second, $secondRows];
}

/**
 * The runtime role inside the tenant's context (organization and user).
 */
function actAsTenant(?TenantWorld $tenant): void
{
    setDatabaseContext(['organization_id' => $tenant?->organizationId ?? '', 'user_id' => $tenant?->userId ?? '']);
    useAppRole();
}

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

describe('the tables', function () {
    it('are exactly the design\'s and the ones D52 added', function () {
        // Act
        $tables = DB::table('pg_class')
            ->whereRaw("relnamespace = 'public'::regnamespace and relrowsecurity")
            ->orderBy('relname')
            ->pluck('relname');

        // Assert
        expect($tables->all())->toBe(array_keys(RowLevelSecurity::policies()));
    });
});

describe('two tenants', function () {
    beforeEach(function () {
        [$this->first, $this->firstRows, $this->second, $this->secondRows] = twoTenantWorlds();
    });

    it('each have rows in every table under row-level security', function () {
        // Act
        $empty = collect(RowLevelSecurity::policies())->keys()
            ->filter(fn (string $table) => $this->firstRows[$table] === [] || $this->secondRows[$table] === [])
            ->values();

        // Assert
        expect($empty->all())->toBe([]);
    });

    it('see their own rows in every table', function () {
        // Arrange
        actAsTenant($this->first);

        // Act
        $missing = collect(RowLevelSecurity::policies())->keys()
            ->filter(fn (string $table) => array_intersect($this->firstRows[$table], visibleRowKeys($table)) === [])
            ->values();

        // Assert
        expect($missing->all())->toBe([]);
    });

    it('see none of the other tenant\'s rows in any table', function (int $viewer) {
        // Arrange
        [$tenant, $others] = $viewer === 1 ? [$this->first, $this->secondRows] : [$this->second, $this->firstRows];
        actAsTenant($tenant);

        // Act
        $leaks = collect(RowLevelSecurity::policies())->keys()
            ->filter(fn (string $table) => array_intersect($others[$table], visibleRowKeys($table)) !== [])
            ->values();

        // Assert
        expect($leaks->all())->toBe([]);
    })->with([1, 2]);

    it('see nothing of either without a context', function () {
        // Arrange
        actAsTenant(null);

        // Act
        $leaks = collect(RowLevelSecurity::policies())->keys()
            ->filter(fn (string $table) => array_intersect([...$this->firstRows[$table], ...$this->secondRows[$table]], visibleRowKeys($table)) !== [])
            ->values();

        // Assert
        expect($leaks->all())->toBe([]);
    });

    it('change or delete none of the other tenant\'s rows', function (string $command) {
        // Arrange
        actAsTenant($this->first);
        $tables = collect(RowLevelSecurity::policies())->keys()
            ->filter(fn (string $table) => DB::scalar("select has_table_privilege(?::regclass, '{$command}')", [$table]));

        // Act
        $touched = $tables->filter(function (string $table) use ($command) {
            $keys = '{'.implode(',', $this->secondRows[$table]).'}';
            $column = DB::scalar(<<<'SQL'
                select attname from pg_attribute
                where attrelid = ?::regclass and attnum > 0 and not attisdropped and attidentity = '' and attgenerated = ''
                order by attnum limit 1
                SQL, [$table]);
            $sql = $command === 'UPDATE'
                ? "update {$table} t set {$column} = {$column} where md5(t::text) = any(?::text[])"
                : "delete from {$table} t where md5(t::text) = any(?::text[])";

            return DB::affectingStatement($sql, [$keys]) > 0;
        })->values();

        // Assert
        expect($tables)->not->toBeEmpty()
            ->and($touched->all())->toBe([]);
    })->with(['UPDATE', 'DELETE']);
});

describe('writing as a tenant', function () {
    beforeEach(function () {
        $this->own = TestRecords::organization();
        $this->other = TestRecords::organization();
        $this->ownBuilding = TestRecords::building($this->own);
        $this->otherBuilding = TestRecords::building($this->other);
        $this->otherMeter = TestRecords::meter($this->otherBuilding);
        setDatabaseContext(['organization_id' => $this->own]);
        useAppRole();
    });

    it('writes rows of its own organization and building', function () {
        // Act
        $customerId = TestRecords::customer($this->own);
        $flatId = TestRecords::flat($this->ownBuilding);

        // Assert
        expect(DB::table('customers')->where('id', $customerId)->exists())->toBeTrue()
            ->and(DB::table('flats')->where('id', $flatId)->exists())->toBeTrue();
    });

    // Plain inserts: with RETURNING the visibility check (USING) would refuse
    // the row first, and the write check (WITH CHECK) would go untested.
    it('cannot write a row of another organization, building or parent', function (string $table, Closure $write) {
        // Act & Assert
        expect(fn () => $write($this))
            ->toThrow(QueryException::class, "new row violates row-level security policy for table \"{$table}\"");
    })->with([
        'organization scope' => ['customers', fn ($test) => DB::table('customers')->insert([
            'public_id' => strtolower((string) Str::ulid()), 'tenant_organization_id' => $test->other,
            'customer_code' => 'C-OTHER', 'full_name' => 'Rahima Begum',
        ])],
        'building scope' => ['flats', fn ($test) => DB::table('flats')->insert(['building_id' => $test->otherBuilding, 'code' => 'F-OTHER'])],
        'parent scope' => ['meter_events', fn ($test) => DB::table('meter_events')->insert(
            ['meter_id' => $test->otherMeter, 'event_type' => 'installed', 'event_at' => now(), 'reading_at_event' => 0],
        )],
    ]);

    it('sees a building of another organization only through an active access grant', function () {
        // Arrange: the grant is arranged as the test role, then back to the app role
        DB::statement('RESET ROLE');
        TestRecords::accessGrant($this->otherBuilding, $this->own);
        useAppRole();

        // Act
        $visible = DB::table('buildings')->whereIn('id', [$this->ownBuilding, $this->otherBuilding])->pluck('id')->sort()->values();

        // Assert
        expect($visible->all())->toBe([$this->ownBuilding, $this->otherBuilding]);
    });
});

describe('integrity checks under row-level security', function () {
    // The probe of 2026-11-20: a check that reads another table must see the
    // other tenant's row, though the caller cannot (a foreign key ignores RLS).
    beforeEach(function () {
        $this->own = TestRecords::organization();
        $this->ownBuilding = TestRecords::building($this->own);
        $this->other = TestRecords::organization();
        $this->otherCustomer = TestRecords::customer($this->other);
        $this->otherUser = TestRecords::user();
        TestRecords::membership($this->other, $this->otherUser);
        setDatabaseContext(['organization_id' => $this->own]);
        useAppRole();
    });

    it('refuse a notification of its building to another tenant\'s customer', function () {
        // Act & Assert
        expect(fn () => DB::table('notifications')->insert([
            'building_id' => $this->ownBuilding, 'customer_id' => $this->otherCustomer,
            'channel' => 'sms', 'event_code' => 'invoice.issued', 'message' => 'Your bill is ready',
        ]))->toThrow(QueryException::class, "Customer {$this->otherCustomer} is not a customer of this notification's organization or building");
    });

    it('refuse an occupancy of its flat by another tenant\'s customer', function () {
        // Arrange
        $flatId = TestRecords::flat($this->ownBuilding);

        // Act & Assert
        expect(fn () => TestRecords::occupancy($flatId, '2026-01-01', ['customer_id' => $this->otherCustomer]))
            ->toThrow(QueryException::class, 'The customer belongs to an organization without access to this building');
    });

    it('refuse a setup task of its building for another tenant\'s user', function () {
        // Act & Assert
        expect(fn () => TestRecords::setupTask($this->ownBuilding, ['assigned_user_id' => $this->otherUser]))
            ->toThrow(QueryException::class, 'is not a member of an organization with access to building');
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
        expect(fn () => DB::table('organization_memberships')->insert(['organization_id' => $this->home, 'user_id' => $this->userId, 'membership_role' => 'staff']))
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
        expect(fn () => DB::table('user_devices')->insert([
            'user_id' => $this->userId, 'device_uuid' => (string) Str::uuid(), 'client_type' => 'pwa', 'platform' => 'android',
        ]))
            ->toThrow(QueryException::class, 'new row violates row-level security policy for table "user_devices"');
    });
});
