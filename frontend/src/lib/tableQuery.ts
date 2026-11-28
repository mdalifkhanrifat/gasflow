import { readJson } from '@/api/client';

// The list query shared with Laravel's App\Http\Requests\TableRequest:
//   ?search=rahim&filter[status]=paid,overdue&sort=-due_date&page=2&per_page=25
// Keep pageSizes and maxSearchLength equal to TableRequest's constants.
export const pageSizes = [10, 25, 50, 100] as const;
export const maxSearchLength = 100;

export type SortDirection = 'ascending' | 'descending';

export interface TableQuery {
    search: string;
    // Filter key => selected values; an empty list means no filter.
    filters: Readonly<Record<string, readonly string[]>>;
    sort?: { key: string; direction: SortDirection };
    page: number;
    perPage: number;
}

export interface TablePage<Row> {
    rows: Row[];
    total: number;
    page: number;
    lastPage: number;
}

export interface TableSource<Row> {
    load: (query: TableQuery) => Promise<TablePage<Row>>;
    // Every row that matches the query, for CSV export. Server sources leave
    // this out until the list has an export endpoint.
    loadAll?: (query: TableQuery) => Promise<Row[]>;
}

export function toSearchParams(query: TableQuery): URLSearchParams {
    const params = new URLSearchParams();
    const search = query.search.trim().slice(0, maxSearchLength);
    if (search) {
        params.set('search', search);
    }
    for (const [key, values] of Object.entries(query.filters)) {
        const selected = values.filter(Boolean);
        if (selected.length) {
            params.set(`filter[${key}]`, selected.join(','));
        }
    }
    if (query.sort) {
        const prefix = query.sort.direction === 'descending' ? '-' : '';
        params.set('sort', prefix + query.sort.key);
    }
    if (query.page > 1) {
        params.set('page', String(query.page));
    }
    params.set('per_page', String(query.perPage));
    return params;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function positiveInteger(value: unknown): number {
    if (typeof value !== 'number' || !Number.isInteger(value) || value < 0) {
        throw new Error('Invalid table page');
    }
    return value;
}

// Laravel's paginated resource: { data, links, meta: { current_page, last_page, total, ... } }.
export function readTablePage<Row>(
    payload: unknown,
    mapRow: (item: unknown) => Row,
): TablePage<Row> {
    if (
        !isRecord(payload) ||
        !Array.isArray(payload.data) ||
        !isRecord(payload.meta)
    ) {
        throw new Error('Invalid table page');
    }

    return {
        rows: payload.data.map(mapRow),
        total: positiveInteger(payload.meta.total),
        page: Math.max(1, positiveInteger(payload.meta.current_page)),
        lastPage: Math.max(1, positiveInteger(payload.meta.last_page)),
    };
}

// Rows from a list endpoint built on TableRequest.
export function serverTableSource<Row>(
    path: string,
    mapRow: (item: unknown) => Row,
): TableSource<Row> {
    return {
        load: async (query) =>
            readTablePage(
                await readJson(`${path}?${toSearchParams(query).toString()}`),
                mapRow,
            ),
    };
}

export interface LocalTableOptions<Row> {
    // Text that search matches, case-insensitively.
    searchText: (row: Row) => readonly string[];
    filterValues?: Readonly<Record<string, (row: Row) => string>>;
    sortValues?: Readonly<Record<string, (row: Row) => string | number>>;
    locale: () => string;
}

// Rows already in the browser (preview data), searched, filtered, sorted and
// paged the way TableRequest does it on the server.
export function localTableSource<Row>(
    rows: () => readonly Row[],
    options: LocalTableOptions<Row>,
): TableSource<Row> {
    function matching(query: TableQuery): Row[] {
        const locale = options.locale();
        const search = query.search
            .trim()
            .slice(0, maxSearchLength)
            .toLocaleLowerCase(locale);
        const result = rows().filter(
            (row) =>
                Object.entries(query.filters).every(([key, values]) => {
                    const selected = values.filter(Boolean);
                    const value = options.filterValues?.[key];
                    return !selected.length || !value
                        ? true
                        : selected.includes(value(row));
                }) &&
                (!search ||
                    options
                        .searchText(row)
                        .some((text) =>
                            text.toLocaleLowerCase(locale).includes(search),
                        )),
        );

        const sort = query.sort;
        const sortValue = sort && options.sortValues?.[sort.key];
        if (sort && sortValue) {
            const collator = new Intl.Collator(locale, {
                numeric: true,
                sensitivity: 'base',
            });
            const multiplier = sort.direction === 'ascending' ? 1 : -1;
            // Array.prototype.sort is stable, so equal values keep their order.
            result.sort((left, right) => {
                const a = sortValue(left);
                const b = sortValue(right);
                return (
                    multiplier *
                    (typeof a === 'number' && typeof b === 'number'
                        ? a - b
                        : collator.compare(String(a), String(b)))
                );
            });
        }

        return result;
    }

    return {
        load: (query) => {
            const matched = matching(query);
            const lastPage = Math.max(
                1,
                Math.ceil(matched.length / query.perPage),
            );
            const page = Math.min(Math.max(1, query.page), lastPage);
            const offset = (page - 1) * query.perPage;
            return Promise.resolve({
                rows: matched.slice(offset, offset + query.perPage),
                total: matched.length,
                page,
                lastPage,
            });
        },
        loadAll: (query) => Promise.resolve(matching(query)),
    };
}
