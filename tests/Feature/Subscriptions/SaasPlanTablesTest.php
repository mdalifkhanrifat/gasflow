<?php

/**
 * Migration group 6, part 1: SaaS packages, versions and features (design 02
 * §3, §10 and §14, V7.7, V7.10).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, string $class, string $softDelete, string $defaultStatus) {
        // Arrange: migration group 6 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->default_status])
            ->toBe(['subscriptions', $class, $softDelete, 'forbidden', $defaultStatus]);
    })->with([
        ['saas_plans', 'configuration', 'allowed', 'active'],
        ['plan_versions', 'configuration', 'allowed', 'draft'],
        ['plan_features', 'history', 'protected', 'active'],
        ['plan_feature_templates', 'configuration', 'allowed', 'active'],
    ]);
});

describe('packages', function () {
    it('ship the four packages with Bangla names', function () {
        // Arrange: migration group 6 has run

        // Act
        $names = DB::table('saas_plans')->orderBy('code')
            ->selectRaw("code, translations->'bn'->>'name' as bn_name")->pluck('bn_name', 'code')->all();

        // Assert
        expect($names)->toBe([
            'BILLING_ONLY' => 'শুধু বিলিং',
            'SUPPLY_BILLING' => 'সরবরাহ + বিলিং',
            'SYSTEM_BILLING' => 'সিস্টেম + বিলিং',
            'SYSTEM_SUPPLY_BILLING' => 'সিস্টেম + সরবরাহ + বিলিং',
        ]);
    });

    it('enable the modules each package sells', function (string $plan, array $enabled) {
        // Arrange: migration group 6 has run

        // Act
        $features = DB::table('plan_feature_templates as t')
            ->join('saas_plans as p', 'p.id', '=', 't.saas_plan_id')
            ->where('p.code', $plan)->where('t.enabled', true)
            ->orderBy('t.feature_code')->pluck('t.feature_code')->all();

        // Assert
        expect($features)->toBe($enabled);
    })->with([
        ['SYSTEM_SUPPLY_BILLING', ['module.billing', 'module.gas_supply', 'module.system_setup']],
        ['SUPPLY_BILLING', ['module.billing', 'module.gas_supply']],
        ['SYSTEM_BILLING', ['module.billing', 'module.system_setup']],
        ['BILLING_ONLY', ['module.billing']],
    ]);

    it('refuse a template for a feature the catalogue does not know', function () {
        // Arrange
        $planId = DB::table('saas_plans')->where('code', 'BILLING_ONLY')->value('id');

        // Act & Assert
        expect(fn () => DB::table('plan_feature_templates')->insert(['saas_plan_id' => $planId, 'feature_code' => 'module.unknown']))
            ->toThrow(QueryException::class, 'plan_feature_templates_feature_code_foreign');
    });
});

describe('plan versions', function () {
    it('start as a draft', function () {
        // Arrange
        $id = TestRecords::planVersion();

        // Act
        $status = DB::table('plan_versions')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('draft');
    });

    it('refuse a value outside the rules', function (array $row, string $constraint) {
        // Arrange: a version with one wrong value

        // Act & Assert
        expect(fn () => TestRecords::planVersion(overrides: $row))->toThrow(QueryException::class, $constraint);
    })->with([
        'weekly billing' => [['billing_interval' => 'weekly'], 'ck_plan_versions_billing_interval'],
        'negative price' => [['price' => -1], 'ck_plan_versions_price'],
        'negative trial' => [['trial_days' => -1], 'ck_plan_versions_trial_days'],
        'end before start' => [['effective_to' => '2025-12-31'], 'ck_plan_versions_effective_range'],
    ]);

    it('refuse a second version of the plan for the same days and interval', function () {
        // Arrange
        $versionId = TestRecords::planVersion();
        $planId = DB::table('plan_versions')->where('id', $versionId)->value('saas_plan_id');

        // Act & Assert
        expect(fn () => TestRecords::planVersion($planId, '2026-06-01'))
            ->toThrow(QueryException::class, 'ex_plan_version_period_no_overlap');
    });

    it('count the last day of a version as covered', function () {
        // Arrange
        $versionId = TestRecords::planVersion(overrides: ['effective_to' => '2026-06-30']);
        $planId = DB::table('plan_versions')->where('id', $versionId)->value('saas_plan_id');

        // Act & Assert
        expect(fn () => TestRecords::planVersion($planId, '2026-06-30'))
            ->toThrow(QueryException::class, 'ex_plan_version_period_no_overlap');
    });

    it('allow the next version from the day after the previous one ends', function () {
        // Arrange
        $versionId = TestRecords::planVersion(overrides: ['effective_to' => '2026-06-30']);
        $planId = DB::table('plan_versions')->where('id', $versionId)->value('saas_plan_id');

        // Act
        TestRecords::planVersion($planId, '2026-07-01');

        // Assert
        expect(DB::table('plan_versions')->where('saas_plan_id', $planId)->count())->toBe(2);
    });

    it('allow a yearly version beside a monthly one', function () {
        // Arrange
        $versionId = TestRecords::planVersion();
        $planId = DB::table('plan_versions')->where('id', $versionId)->value('saas_plan_id');

        // Act
        TestRecords::planVersion($planId, '2026-01-01', ['billing_interval' => 'yearly']);

        // Assert
        expect(DB::table('plan_versions')->where('saas_plan_id', $planId)->count())->toBe(2);
    });
});

describe('plan features', function () {
    it('refuse a feature the catalogue does not know', function () {
        // Arrange
        $versionId = TestRecords::planVersion();

        // Act & Assert
        expect(fn () => DB::table('plan_features')->insert(['plan_version_id' => $versionId, 'feature_code' => 'module.unknown']))
            ->toThrow(QueryException::class, 'plan_features_feature_code_foreign');
    });

    it('list a feature once per version', function () {
        // Arrange
        $row = ['plan_version_id' => TestRecords::planVersion(), 'feature_code' => 'module.billing'];
        DB::table('plan_features')->insert($row);

        // Act & Assert
        expect(fn () => DB::table('plan_features')->insert($row))
            ->toThrow(QueryException::class, 'plan_features_plan_version_id_feature_code_unique');
    });

    it('can never be deleted, because sold versions keep what they included', function () {
        // Arrange
        $id = (int) DB::table('plan_features')->insertGetId(['plan_version_id' => TestRecords::planVersion(), 'feature_code' => 'module.billing']);

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('plan_features')->where('id', $id)->delete()))
            ->toThrow(QueryException::class);
        expect(DB::table('plan_features')->where('id', $id)->value('is_deleted'))->toBeFalse();
    });
});
