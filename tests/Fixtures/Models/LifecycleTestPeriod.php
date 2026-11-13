<?php

namespace Tests\Fixtures\Models;

use App\Shared\Lifecycle\HasLifecycle;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\Enums\LifecycleTestPeriodStatus;

/**
 * Test-only workflow record: own statuses, enforced transitions, protected.
 */
#[Table('lifecycle_test_periods')]
#[Fillable(['name'])]
class LifecycleTestPeriod extends Model
{
    use HasLifecycle;

    protected function casts(): array
    {
        return ['status' => LifecycleTestPeriodStatus::class];
    }
}
