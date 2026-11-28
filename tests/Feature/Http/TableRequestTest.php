<?php

/**
 * TableRequest: the shared search / filter / sort / page query of list endpoints.
 */

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Http\LifecycleTestItemTableRequest;
use Tests\Fixtures\Models\LifecycleTestItem;

beforeEach(function () {
    Route::middleware('web')->get('/_table-test/items', fn (LifecycleTestItemTableRequest $request) => JsonResource::collection(
        $request->paginate(LifecycleTestItem::query())->through(fn (LifecycleTestItem $item): array => [
            'id' => $item->id,
            'name' => $item->name,
            'status' => $item->status,
        ]),
    ));
});

/**
 * @param  list<string>  $names
 * @return list<LifecycleTestItem>
 */
function tableItems(array $names): array
{
    return array_map(fn (string $name): LifecycleTestItem => LifecycleTestItem::create(['name' => $name]), $names);
}

/**
 * @return list<string>
 */
function tableNames(TestResponse $response): array
{
    /** @var list<string> */
    return array_column($response->json('data'), 'name');
}

describe('pagination', function () {
    it('returns the first page in the smallest page size with the page meta', function () {
        // Arrange
        tableItems(array_map(fn (int $n): string => sprintf('Item %02d', $n), range(1, 12)));

        // Act
        $response = $this->getJson('/_table-test/items');

        // Assert
        $response->assertOk()->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.last_page', 2);
    });

    it('returns the requested page', function () {
        // Arrange
        tableItems(array_map(fn (int $n): string => sprintf('Item %02d', $n), range(1, 12)));

        // Act
        $response = $this->getJson('/_table-test/items?page=2');

        // Assert
        $response->assertOk();
        expect(tableNames($response))->toBe(['Item 11', 'Item 12']);
    });

    it('accepts a page size from the shared list', function () {
        // Arrange
        tableItems(array_map(fn (int $n): string => sprintf('Item %02d', $n), range(1, 12)));

        // Act
        $response = $this->getJson('/_table-test/items?per_page=25');

        // Assert
        $response->assertOk()->assertJsonCount(12, 'data')->assertJsonPath('meta.per_page', 25);
    });

    it('refuses a page size outside the shared list', function () {
        // Act & Assert
        $this->getJson('/_table-test/items?per_page=7')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
    });

    it('keeps the query in the page links', function () {
        // Arrange
        tableItems(array_map(fn (int $n): string => sprintf('Item %02d', $n), range(1, 12)));

        // Act
        $response = $this->getJson('/_table-test/items?sort=-name');

        // Assert
        expect($response->json('links.next'))->toContain('sort=-name')->toContain('page=2');
    });
});

describe('sorting', function () {
    it('uses the default sort when none is asked for', function () {
        // Arrange
        tableItems(['Cedar', 'Ash', 'Birch']);

        // Act
        $response = $this->getJson('/_table-test/items');

        // Assert
        expect(tableNames($response))->toBe(['Ash', 'Birch', 'Cedar']);
    });

    it('sorts descending when the key starts with a minus', function () {
        // Arrange
        tableItems(['Cedar', 'Ash', 'Birch']);

        // Act
        $response = $this->getJson('/_table-test/items?sort=-name');

        // Assert
        expect(tableNames($response))->toBe(['Cedar', 'Birch', 'Ash']);
    });

    it('keeps rows with equal values in key order', function () {
        // Arrange: the update moves the first row to the end of the table on disk.
        $items = tableItems(['Same', 'Same', 'Same']);
        $items[0]->update(['secret' => 'moved']);

        // Act
        $response = $this->getJson('/_table-test/items?sort=-name');

        // Assert
        expect(array_column($response->json('data'), 'id'))->toBe(array_map(fn (LifecycleTestItem $item): int => $item->id, $items));
    });

    it('refuses a column that is not sortable', function () {
        // Act & Assert
        $this->getJson('/_table-test/items?sort=secret')
            ->assertUnprocessable()->assertJsonValidationErrors('sort');
    });
});

describe('filters', function () {
    it('keeps only rows with the filtered value', function () {
        // Arrange
        [, $birch] = tableItems(['Ash', 'Birch', 'Cedar']);
        $birch->transitionTo('inactive');

        // Act
        $response = $this->getJson('/_table-test/items?filter[status]=inactive');

        // Assert
        expect(tableNames($response))->toBe(['Birch']);
    });

    it('accepts several comma-separated values', function () {
        // Arrange
        [$ash, $birch] = tableItems(['Ash', 'Birch', 'Cedar']);
        $ash->transitionTo('inactive');
        $birch->transitionTo('archived');

        // Act
        $response = $this->getJson('/_table-test/items?filter[status]=inactive,archived');

        // Assert
        expect(tableNames($response))->toBe(['Ash', 'Birch']);
    });

    it('ignores an empty filter value', function () {
        // Arrange
        tableItems(['Ash', 'Birch']);

        // Act
        $response = $this->getJson('/_table-test/items?filter[status]=');

        // Assert
        expect(tableNames($response))->toBe(['Ash', 'Birch']);
    });

    it('refuses a filter that is not allowed', function () {
        // Act & Assert
        $this->getJson('/_table-test/items?filter[name]=Ash')
            ->assertUnprocessable()->assertJsonValidationErrors('filter');
    });
});

describe('search', function () {
    it('matches the searchable columns case-insensitively', function () {
        // Arrange
        tableItems(['Lake View Tower', 'Green Residency']);

        // Act
        $response = $this->getJson('/_table-test/items?search=view');

        // Assert
        expect(tableNames($response))->toBe(['Lake View Tower']);
    });

    it('treats like wildcards in the search as plain text', function () {
        // Arrange
        tableItems(['A_1', 'AB1', '100%', '1000']);

        // Act
        $underscore = $this->getJson('/_table-test/items?search=_');
        $percent = $this->getJson('/_table-test/items?search=%25');

        // Assert
        expect(tableNames($underscore))->toBe(['A_1'])
            ->and(tableNames($percent))->toBe(['100%']);
    });

    it('refuses a search longer than the limit', function () {
        // Act & Assert
        $this->getJson('/_table-test/items?search='.str_repeat('a', 101))
            ->assertUnprocessable()->assertJsonValidationErrors('search');
    });
});
