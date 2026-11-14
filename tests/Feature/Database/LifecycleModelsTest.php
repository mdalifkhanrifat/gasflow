<?php

/**
 * Models agree with the lifecycle registry (design 07 §5, 08): SoftDeletes on
 * exactly the models whose table allows soft delete.
 */

use App\Models\Concerns\HasLifecycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tests\Fixtures\Models\LifecycleTestItem;
use Tests\Fixtures\Models\LifecycleTestPeriod;
use Tests\Support\LifecycleModels;

/** Soft-deletes a protected (financial) table. */
final class LifecycleModelsTestLedger extends Model
{
    use HasLifecycle, SoftDeletes;

    protected $table = 'lifecycle_test_ledgers';
}

/** Forgets SoftDeletes on a table that allows soft delete. */
final class LifecycleModelsTestItemWithoutSoftDeletes extends Model
{
    use HasLifecycle;

    protected $table = 'lifecycle_test_items';
}

/** Claims the lifecycle standard for a table outside it. */
final class LifecycleModelsTestUnregistered extends Model
{
    use HasLifecycle;

    protected $table = 'public_id_test_records';
}

describe('application models', function () {
    it('finds every model under app/Models', function () {
        // Arrange: app/Models holds User

        // Act
        $models = LifecycleModels::inApp();

        // Assert
        expect($models)->toContain(User::class);
    });

    it('use SoftDeletes exactly where the table allows soft delete', function () {
        // Arrange
        $models = LifecycleModels::inApp();

        // Act
        $problems = LifecycleModels::softDeleteMismatches($models);

        // Assert
        expect($problems)->toBe([]);
    });
});

describe('soft delete agreement check', function () {
    it('accepts models that follow their table policy', function () {
        // Arrange
        $models = [LifecycleTestItem::class, LifecycleTestPeriod::class];

        // Act
        $problems = LifecycleModels::softDeleteMismatches($models);

        // Assert
        expect($problems)->toBe([]);
    });

    it('reports SoftDeletes on a protected table', function () {
        // Arrange
        $models = [LifecycleModelsTestLedger::class];

        // Act
        $problems = LifecycleModels::softDeleteMismatches($models);

        // Assert
        expect($problems)->toBe(['LifecycleModelsTestLedger: uses SoftDeletes, but lifecycle_test_ledgers is protected']);
    });

    it('reports a missing SoftDeletes on a table that allows soft delete', function () {
        // Arrange
        $models = [LifecycleModelsTestItemWithoutSoftDeletes::class];

        // Act
        $problems = LifecycleModels::softDeleteMismatches($models);

        // Assert
        expect($problems)->toBe(['LifecycleModelsTestItemWithoutSoftDeletes: needs SoftDeletes, because lifecycle_test_items allows soft delete']);
    });

    it('reports a lifecycle model whose table is not in the standard', function () {
        // Arrange
        $models = [LifecycleModelsTestUnregistered::class];

        // Act
        $problems = LifecycleModels::softDeleteMismatches($models);

        // Assert
        expect($problems)->toBe(['LifecycleModelsTestUnregistered: table public_id_test_records is not in the lifecycle standard']);
    });

    it('skips models outside the lifecycle standard', function () {
        // Arrange
        $models = [User::class];

        // Act
        $problems = LifecycleModels::softDeleteMismatches($models);

        // Assert
        expect($problems)->toBe([]);
    });
});
