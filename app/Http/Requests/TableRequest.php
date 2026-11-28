<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The shared query of every list endpoint behind the UI's DataTable:
 *
 *     ?search=rahim&filter[status]=paid,overdue&sort=-due_date&page=2&per_page=25
 *
 * Each endpoint extends this request and allow-lists what may be sorted,
 * filtered and searched; anything else is a 422. Answer with
 * `XResource::collection($request->paginate($query))`, Laravel's paginated
 * resource shape: data, links and meta (current_page, last_page, per_page, total).
 */
abstract class TableRequest extends FormRequest
{
    /** @var list<int> */
    public const array PAGE_SIZES = [10, 25, 50, 100];

    public const int MAX_SEARCH_LENGTH = 100;

    /**
     * Sort key in the URL => column.
     *
     * @var array<string, string>
     */
    protected array $sortable = [];

    /** Sort without a `sort` parameter, such as "-created_at". */
    protected ?string $defaultSort = null;

    /**
     * Filter key in the URL => column; a value may list several codes with commas.
     *
     * @var array<string, string>
     */
    protected array $filterable = [];

    /**
     * Columns that `search` matches case-insensitively.
     *
     * @var list<string>
     */
    protected array $searchable = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sorts = array_keys($this->sortable);
        $filters = array_keys($this->filterable);

        return [
            'search' => $this->searchable === []
                ? ['prohibited']
                : ['nullable', 'string', 'max:'.self::MAX_SEARCH_LENGTH],
            'sort' => ['nullable', 'string', Rule::in([...$sorts, ...array_map(fn (string $key): string => '-'.$key, $sorts)])],
            'filter' => $filters === [] ? ['prohibited'] : ['nullable', 'array:'.implode(',', $filters)],
            'filter.*' => ['nullable', 'string', 'max:500'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in(self::PAGE_SIZES)],
        ];
    }

    /**
     * Search, filter, sort and paginate the query. A final sort on the key
     * keeps rows with equal values in the same order on every page.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(Builder $query): LengthAwarePaginator
    {
        $this->applySearch($query);
        $this->applyFilters($query);
        $this->applySort($query);

        return $query
            ->orderBy($query->getModel()->getQualifiedKeyName())
            ->paginate($this->perPage(), ['*'], 'page', $this->integer('page', 1))
            ->withQueryString();
    }

    public function perPage(): int
    {
        return $this->integer('per_page', self::PAGE_SIZES[0]);
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applySearch(Builder $query): void
    {
        $search = trim($this->string('search')->toString());
        if ($search === '') {
            return;
        }

        // The term is bound, so only LIKE's own wildcards need escaping.
        $pattern = '%'.addcslashes($search, '\\%_').'%';
        $query->where(function (Builder $where) use ($pattern): void {
            foreach ($this->searchable as $column) {
                $where->orWhere($column, 'ilike', $pattern);
            }
        });
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applyFilters(Builder $query): void
    {
        /** @var array<string, ?string> $filters */
        $filters = $this->array('filter');

        foreach ($filters as $key => $value) {
            $values = array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $item): bool => $item !== ''));
            if ($values !== []) {
                $query->whereIn($this->filterable[$key], $values);
            }
        }
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applySort(Builder $query): void
    {
        $sort = $this->string('sort')->toString() ?: $this->defaultSort;
        if ($sort === null || $sort === '') {
            return;
        }

        $descending = str_starts_with($sort, '-');
        $key = ltrim($sort, '-');
        if (isset($this->sortable[$key])) {
            $query->orderBy($this->sortable[$key], $descending ? 'desc' : 'asc');
        }
    }
}
