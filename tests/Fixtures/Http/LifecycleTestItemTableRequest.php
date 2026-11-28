<?php

namespace Tests\Fixtures\Http;

use App\Http\Requests\TableRequest;

/**
 * Test-only list endpoint request over lifecycle_test_items.
 */
class LifecycleTestItemTableRequest extends TableRequest
{
    protected array $sortable = ['name' => 'name', 'created' => 'created_at'];

    protected ?string $defaultSort = 'name';

    protected array $filterable = ['status' => 'status'];

    protected array $searchable = ['name'];
}
