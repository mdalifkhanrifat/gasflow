<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\IntegrityChecks;

/*
 * Integrity checks run as gasflow_integrity (decision D52): a trigger that
 * reads another table must see every row it compares, whatever row-level
 * security hides from the caller.
 */

describe('integrity checks', function () {
    it('runs every trigger function that reads another table as the integrity role', function () {
        // Arrange
        $checks = IntegrityChecks::readingTriggerFunctions();

        // Act
        $wrong = DB::table('pg_proc')
            ->whereRaw("pronamespace = 'public'::regnamespace")
            ->whereIn('proname', $checks)
            ->where(fn ($query) => $query
                ->whereRaw("proowner <> 'gasflow_integrity'::regrole")
                ->orWhere('prosecdef', false)
                ->orWhereRaw("proconfig is distinct from array['search_path=public, pg_temp']"))
            ->pluck('proname');

        // Assert
        expect($wrong->all())->toBe([]);
    });

    it('finds checks that read directly, through a dynamic table or through a helper', function () {
        // Act
        $checks = IntegrityChecks::readingTriggerFunctions();

        // Assert
        expect($checks)->toContain('validate_notification_scope', 'lifecycle_guard_closed_parent', 'validate_setup_task_assignee')
            ->not->toContain('lifecycle_guard', 'lifecycle_log_change', 'lifecycle_guard_frozen_columns');
    });

    it('gives the integrity role nothing but read-only checks', function () {
        // Arrange
        $checks = IntegrityChecks::readingTriggerFunctions();

        // Act
        $others = DB::table('pg_proc')
            ->whereRaw("proowner = 'gasflow_integrity'::regrole")
            ->whereNotIn('proname', $checks)
            ->pluck('proname');

        // Assert
        expect($others->all())->toBe([]);
    });

    it('lets the integrity role lock the rows its checks lock', function () {
        // Act
        $unlockable = DB::table(DB::raw(<<<'SQL'
            (SELECT DISTINCT split_part(encode(t.tgargs, 'escape'), '\000', 1) AS locked
               FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid
              WHERE p.proname IN ('lifecycle_guard_closed_parent', 'lifecycle_guard_parent_status',
                                  'lifecycle_guard_parent_frozen_columns', 'lifecycle_guard_children_status')
             UNION SELECT unnest(array['invoices', 'payments', 'licenses'])) locks
            SQL))
            ->whereRaw("not has_column_privilege('gasflow_integrity', locked::regclass, 'id', 'UPDATE')")
            ->pluck('locked');

        // Assert
        expect($unlockable->all())->toBe([]);
    });
});

describe('adopting a check', function () {
    beforeEach(function () {
        $this->owner = DB::connection('pgsql_migrations');
        $this->owner->beginTransaction();
    });

    afterEach(function () {
        $this->owner->rollBack();
    });

    it('hands a trigger function to the integrity role', function () {
        // Arrange
        $this->owner->unprepared(
            'CREATE FUNCTION integrity_test_check() RETURNS TRIGGER LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END; $$',
        );

        // Act
        $this->owner->select("select adopt_integrity_check('integrity_test_check()')");

        // Assert
        $function = $this->owner->selectOne(
            "select proowner::regrole::text as owner, prosecdef, proconfig from pg_proc where proname = 'integrity_test_check'",
        );
        expect($function->owner)->toBe('gasflow_integrity')
            ->and($function->prosecdef)->toBeTrue()
            ->and($function->proconfig)->toBe('{"search_path=public, pg_temp"}');
    });

    it('refuses a function that is not a trigger', function () {
        // Act & Assert
        expect(fn () => $this->owner->select("select adopt_integrity_check('app_current_user_id()')"))
            ->toThrow(QueryException::class, 'app_current_user_id() is not a trigger function');
    });

    it('lets the integrity role lock the table a parent or children rule locks', function (string $locked, Closure $declare) {
        // Arrange
        $this->owner->unprepared("REVOKE UPDATE (id) ON {$locked} FROM gasflow_integrity");

        // Act
        $declare();

        // Assert
        $lockable = $this->owner->selectOne(
            "select has_column_privilege('gasflow_integrity', ?::regclass, 'id', 'UPDATE') as lockable", [$locked],
        )->lockable;
        expect($lockable)->toBeTrue();
    })->with([
        'freezeWhenParentClosed' => ['lifecycle_test_periods', fn () => LifecycleSchema::freezeWhenParentClosed(
            'lifecycle_test_period_lines', parent: 'lifecycle_test_periods', foreignKey: 'period_id',
            closedStatuses: ['locked'], connection: 'pgsql_migrations')],
        'refuseNewChildrenWhenParentClosed' => ['lifecycle_test_batches', fn () => LifecycleSchema::refuseNewChildrenWhenParentClosed(
            'lifecycle_test_batch_items', parent: 'lifecycle_test_batches', foreignKey: 'batch_id',
            closedStatuses: ['closed'], connection: 'pgsql_migrations')],
        'requireParentStatus' => ['lifecycle_test_batches', fn () => LifecycleSchema::requireParentStatus(
            'lifecycle_test_batch_items', parent: 'lifecycle_test_batches', foreignKey: 'batch_id',
            parentStatuses: ['open'], connection: 'pgsql_migrations')],
        'requireChildrenStatus' => ['lifecycle_test_batch_items', fn () => LifecycleSchema::requireChildrenStatus(
            'lifecycle_test_batches', child: 'lifecycle_test_batch_items', foreignKey: 'batch_id',
            whenStatuses: ['cancelled'], childStatuses: ['void'], connection: 'pgsql_migrations')],
        'freezeColumnsWithParent' => ['lifecycle_test_batches', fn () => LifecycleSchema::freezeColumnsWithParent(
            'lifecycle_test_batch_items', parent: 'lifecycle_test_batches', foreignKey: 'batch_id',
            columns: ['amount'], closedStatuses: ['cancelled'], connection: 'pgsql_migrations')],
    ]);
});

describe('integrity helpers', function () {
    it('cannot be called by the runtime role', function (string $call) {
        // Act & Assert
        expect(fn () => DB::select($call))->toThrow(QueryException::class, 'permission denied for function');
    })->with([
        "select adopt_integrity_check('lifecycle_guard()')",
        "select lifecycle_allow_integrity_lock('invoices')",
    ]);
});
