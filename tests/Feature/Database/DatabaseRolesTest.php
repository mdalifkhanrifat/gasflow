<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

describe('test database', function () {
    it('runs on the dedicated postgres testing database', function () {
        // Act
        $database = DB::selectOne('select current_database() as name')->name;

        // Assert
        expect(DB::getDriverName())->toBe('pgsql')
            ->and($database)->toBe('gasflow_testing');
    });
});

describe('refresh guard', function () {
    it('refuses to refresh a database that is not a testing database', function () {
        // Arrange
        config(['database.connections.pgsql_migrations.database' => 'gasflow']);

        // Act & Assert
        expect(fn () => $this->beforeRefreshingDatabase())
            ->toThrow(RuntimeException::class, 'Refusing to refresh [gasflow]');
    });
});

describe('runtime role', function () {
    it('connects as the restricted gasflow_app role', function () {
        // Act
        $role = DB::selectOne('select current_user as name')->name;

        // Assert
        expect($role)->toBe('gasflow_app');
    });

    it('has neither superuser nor bypass-rls rights', function () {
        // Act
        $role = DB::selectOne(
            'select rolsuper, rolbypassrls from pg_roles where rolname = current_user',
        );

        // Assert
        expect($role->rolsuper)->toBeFalse()
            ->and($role->rolbypassrls)->toBeFalse();
    });

    it('can read and write application tables', function () {
        // Arrange
        $email = 'role-check@example.test';

        // Act
        DB::table('users')->insert([
            'public_id' => strtolower((string) Str::ulid()),
            'name' => 'Role Check',
            'email' => $email,
            'password' => 'not-a-real-hash',
        ]);

        // Assert
        expect(DB::table('users')->where('email', $email)->exists())->toBeTrue();
    });

    it('cannot create tables', function () {
        // Act & Assert
        expect(fn () => DB::statement('create table should_not_exist (id int)'))
            ->toThrow(QueryException::class, 'permission denied for schema public');
    });

    it('cannot truncate tables', function () {
        // Act & Assert
        expect(fn () => DB::statement('truncate table users'))
            ->toThrow(QueryException::class, 'permission denied for table users');
    });
});

describe('integrity role', function () {
    it('cannot log in and bypasses row-level security', function () {
        // Act
        $role = DB::selectOne(
            "select rolcanlogin, rolsuper, rolbypassrls from pg_roles where rolname = 'gasflow_integrity'",
        );

        // Assert
        expect($role->rolcanlogin)->toBeFalse()
            ->and($role->rolsuper)->toBeFalse()
            ->and($role->rolbypassrls)->toBeTrue();
    });

    it('reads every table', function () {
        // Act
        $unreadable = DB::table('pg_class')
            ->whereRaw("relnamespace = 'public'::regnamespace and relkind in ('r', 'p')")
            ->whereRaw("not has_table_privilege('gasflow_integrity', oid, 'SELECT')")
            ->pluck('relname');

        // Assert
        expect($unreadable->all())->toBe([]);
    });

    it('cannot insert, update, delete or truncate any table', function () {
        // Act
        $writable = DB::table('pg_class')
            ->whereRaw("relnamespace = 'public'::regnamespace and relkind in ('r', 'p')")
            ->whereRaw("has_table_privilege('gasflow_integrity', oid, 'INSERT, UPDATE, DELETE, TRUNCATE')")
            ->pluck('relname');

        // Assert
        expect($writable->all())->toBe([]);
    });

    it('is not available to the runtime roles', function (string $role) {
        // Act
        $member = DB::selectOne("select pg_has_role(?, 'gasflow_integrity', 'MEMBER') as member", [$role])->member;

        // Assert
        expect($member)->toBeFalse();
    })->with(['gasflow_app', 'gasflow_maintenance', 'gasflow_test']);
});

describe('test role', function () {
    it('logs in and bypasses row-level security', function () {
        // Act
        $role = DB::selectOne(
            "select rolcanlogin, rolsuper, rolbypassrls from pg_roles where rolname = 'gasflow_test'",
        );

        // Assert
        expect($role->rolcanlogin)->toBeTrue()
            ->and($role->rolsuper)->toBeFalse()
            ->and($role->rolbypassrls)->toBeTrue();
    });

    it('has the privileges of the app role and no other role', function () {
        // Act
        $roles = DB::table('pg_auth_members')
            ->whereRaw("member = 'gasflow_test'::regrole")
            ->selectRaw('roleid::regrole::text as name')
            ->pluck('name');

        // Assert
        expect($roles->all())->toBe(['gasflow_app']);
    });

    it('has no grants of its own', function () {
        // Act
        $grants = DB::selectOne(<<<'SQL'
            select count(*) as total
            from pg_class c, aclexplode(c.relacl) a
            where c.relnamespace = 'public'::regnamespace and a.grantee = 'gasflow_test'::regrole
            SQL)->total;

        // Assert
        expect($grants)->toBe(0);
    });
});

describe('migration role', function () {
    it('owns every table the migrations create', function () {
        // Act
        $owners = DB::table('pg_tables')
            ->where('schemaname', 'public')
            ->distinct()
            ->pluck('tableowner');

        // Assert
        expect($owners->all())->toBe(['gasflow_owner']);
    });
});
