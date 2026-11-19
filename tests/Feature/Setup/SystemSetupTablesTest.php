<?php

/**
 * Migration group 13, part 4: system setup areas, pipeline zones and building
 * setup tasks (design 02 V7.4, V7.7, V7.9).
 */

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, string $class, string $defaultStatus) {
        // Arrange: migration 2026_11_19_000065 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe(['installations', $class, 'allowed', 'forbidden', false, $defaultStatus]);
    })->with([
        ['setup_area_catalog', 'configuration', 'active'],
        ['pipeline_zones', 'master', 'active'],
        ['building_setup_tasks', 'workflow', 'pending'],
    ]);
});

describe('setup area catalogue', function () {
    it('holds the areas of the design in order, each with a Bangla name', function () {
        // Arrange: the migration seeded the catalogue

        // Act
        $rows = DB::table('setup_area_catalog')->orderBy('sort_order')->get();

        // Assert
        expect($rows->pluck('code')->all())
            ->toBe(['meter_topology', 'pipeline', 'riser_valves', 'safety_inspection', 'billing_configuration', 'payment_gateway'])
            ->and($rows->filter(fn ($row) => (json_decode($row->translations)->bn->name ?? '') === '')->all())->toBe([]);
    });

    it('refuse a code that is not lower snake case', function () {
        // Arrange
        $row = ['code' => 'Fire-Safety', 'name' => 'Fire safety'];

        // Act & Assert
        expect(fn () => DB::table('setup_area_catalog')->insert($row))->toThrow(QueryException::class, 'ck_setup_area_catalog_code');
    });

    it('refuse translations that are not an object', function () {
        // Arrange
        $row = ['code' => 'fire_safety', 'name' => 'Fire safety', 'translations' => '[]'];

        // Act & Assert
        expect(fn () => DB::table('setup_area_catalog')->insert($row))->toThrow(QueryException::class, 'ck_setup_area_catalog_translations');
    });
});

describe('pipeline zones', function () {
    it('refuse a code that the building already has', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::pipelineZone($buildingId, ['code' => 'Z1']);

        // Act & Assert
        expect(fn () => TestRecords::pipelineZone($buildingId, ['code' => 'Z1']))
            ->toThrow(QueryException::class, 'pipeline_zones_building_id_code_unique');
    });

    it('let another building use the same code', function () {
        // Arrange
        TestRecords::pipelineZone(overrides: ['code' => 'Z1']);

        // Act
        $id = TestRecords::pipelineZone(overrides: ['code' => 'Z1']);

        // Assert
        expect(DB::table('pipeline_zones')->where('id', $id)->value('code'))->toBe('Z1');
    });

    it('may hold a meter of its own building', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $zoneId = TestRecords::pipelineZone($buildingId);

        // Act
        $meterId = TestRecords::meter($buildingId, ['pipeline_zone_id' => $zoneId]);

        // Assert
        expect(DB::table('meters')->where('id', $meterId)->value('pipeline_zone_id'))->toBe($zoneId);
    });

    it('never hold a meter of another building', function () {
        // Arrange
        $zoneId = TestRecords::pipelineZone();

        // Act & Assert
        expect(fn () => TestRecords::meter(overrides: ['pipeline_zone_id' => $zoneId]))
            ->toThrow(QueryException::class, 'fk_meters_pipeline_zone');
    });
});

describe('building setup tasks', function () {
    it('start pending with an empty configuration', function () {
        // Arrange
        $id = TestRecords::setupTask();

        // Act
        $row = DB::table('building_setup_tasks')->where('id', $id)->first();

        // Assert
        expect([$row->status, $row->configuration])->toBe(['pending', '{}']);
    });

    it('refuse an area outside the catalogue', function () {
        // Arrange
        $row = ['setup_area_code' => 'landscaping'];

        // Act & Assert
        expect(fn () => TestRecords::setupTask(overrides: $row))
            ->toThrow(QueryException::class, 'building_setup_tasks_setup_area_code_foreign');
    });

    it('may be in a zone of their own building', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $zoneId = TestRecords::pipelineZone($buildingId);

        // Act
        $id = TestRecords::setupTask($buildingId, ['pipeline_zone_id' => $zoneId]);

        // Assert
        expect(DB::table('building_setup_tasks')->where('id', $id)->value('pipeline_zone_id'))->toBe($zoneId);
    });

    it('never sit in a zone of another building', function () {
        // Arrange
        $row = ['pipeline_zone_id' => TestRecords::pipelineZone()];

        // Act & Assert
        expect(fn () => TestRecords::setupTask(overrides: $row))
            ->toThrow(QueryException::class, 'fk_building_setup_task_pipeline_zone');
    });

    it('record who completed them and when together', function (array $completion) {
        // Arrange
        $row = $completion;

        // Act & Assert
        expect(fn () => TestRecords::setupTask(overrides: $row))
            ->toThrow(QueryException::class, 'ck_building_setup_tasks_completion');
    })->with([
        'time without person' => [['status' => 'completed', 'completed_at' => '2026-10-01 10:00:00+06']],
        'person without time' => [fn () => ['completed_by' => User::factory()->create()->id]],
    ]);
});

describe('who works on a setup task', function () {
    it('is completed exactly when its completion is recorded', function (array $row) {
        // Arrange
        $values = $row;

        // Act & Assert
        expect(fn () => TestRecords::setupTask(overrides: $values))->toThrow(QueryException::class, 'ck_building_setup_tasks_completed');
    })->with([
        'completed without completion' => [['status' => 'completed']],
        'completion on a pending task' => [fn () => ['completed_at' => '2026-10-01 10:00:00+06', 'completed_by' => User::factory()->create()->id]],
    ]);

    it('is completed with its time and person', function () {
        // Arrange
        $id = TestRecords::setupTask();

        // Act
        DB::table('building_setup_tasks')->where('id', $id)
            ->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => User::factory()->create()->id]);

        // Assert
        expect(DB::table('building_setup_tasks')->where('id', $id)->value('status'))->toBe('completed');
    });

    it('may be assigned to a member of the building\'s organization', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $userId = User::factory()->create()->id;
        TestRecords::membership(DB::table('buildings')->where('id', $buildingId)->value('tenant_organization_id'), $userId);

        // Act
        $id = TestRecords::setupTask($buildingId, ['assigned_user_id' => $userId]);

        // Assert
        expect(DB::table('building_setup_tasks')->where('id', $id)->value('assigned_user_id'))->toBe($userId);
    });

    it('may be assigned to a member of an organization with access', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $partnerId = TestRecords::organization();
        TestRecords::accessGrant($buildingId, $partnerId);
        $userId = User::factory()->create()->id;
        TestRecords::membership($partnerId, $userId);

        // Act
        $id = TestRecords::setupTask($buildingId, ['assigned_user_id' => $userId]);

        // Assert
        expect(DB::table('building_setup_tasks')->where('id', $id)->value('assigned_user_id'))->toBe($userId);
    });

    it('is never assigned to someone outside the building', function (Closure $member) {
        // Arrange
        $buildingId = TestRecords::building();
        $userId = $member($buildingId);

        // Act & Assert
        expect(fn () => TestRecords::setupTask($buildingId, ['assigned_user_id' => $userId]))
            ->toThrow(QueryException::class, 'is not a member of an organization with access to building');
    })->with([
        'no membership' => [fn () => User::factory()->create()->id],
        'member of another organization' => [function () {
            $userId = User::factory()->create()->id;
            TestRecords::membership(TestRecords::organization(), $userId);

            return $userId;
        }],
        'suspended member' => [function (int $buildingId) {
            $userId = User::factory()->create()->id;
            $membershipId = TestRecords::membership(DB::table('buildings')->where('id', $buildingId)->value('tenant_organization_id'), $userId);
            DB::table('organization_memberships')->where('id', $membershipId)->update(['status' => 'suspended']);

            return $userId;
        }],
    ]);
});
