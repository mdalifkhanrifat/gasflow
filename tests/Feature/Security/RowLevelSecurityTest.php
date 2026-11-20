<?php

/**
 * Migration group 15: row-level security (design 02 §15 / 02b, owner decision
 * D52, design V7.25). The suite logs in as gasflow_test (BYPASSRLS) to arrange
 * rows; every test of a policy switches to gasflow_app with useAppRole().
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\RowLevelSecurity;
use Tests\Support\TestRecords;

describe('the design policies', function () {
    it('force row-level security on the table, for its owner too', function (string $table) {
        // Act
        $security = DB::selectOne(
            'select relrowsecurity as enabled, relforcerowsecurity as forced from pg_class where oid = ?::regclass',
            [$table],
        );

        // Assert
        expect($security->enabled)->toBeTrue()
            ->and($security->forced)->toBeTrue();
    })->with(array_keys(RowLevelSecurity::DESIGN_POLICIES));

    it('give the table exactly its design policy, for every command', function (string $table, string $policy) {
        // Act
        $policies = DB::table('pg_policies')
            ->where('schemaname', 'public')
            ->where('tablename', $table)
            ->get(['policyname', 'cmd', 'permissive']);

        // Assert
        expect($policies->pluck('policyname')->all())->toBe([$policy])
            ->and($policies->first()->cmd)->toBe('ALL')
            ->and($policies->first()->permissive)->toBe('PERMISSIVE');
    })->with(fn () => collect(RowLevelSecurity::DESIGN_POLICIES)->map(fn ($policy, $table) => [$table, $policy])->values()->all());
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
