<?php

namespace Tests\Fixtures\Models;

use App\Models\Concerns\HasLifecycle;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Test-only master record: generic statuses, soft delete allowed.
 */
#[Table('lifecycle_test_items')]
#[Fillable(['name', 'secret'])]
class LifecycleTestItem extends Model
{
    use HasLifecycle, SoftDeletes;
}
