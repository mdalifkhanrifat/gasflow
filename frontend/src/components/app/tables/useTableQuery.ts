import { computed, ref, shallowRef, watch } from 'vue';
import {
    pageSizes,
    type SortDirection,
    type TableQuery,
    type TableSource,
} from '@/lib/tableQuery';

export interface TableQueryOptions {
    // Wait this long after typing before searching (server lists).
    searchDelay?: number;
}

// The state of one list: query, loaded page and selection. The same code
// runs against preview rows in the browser and a TableRequest endpoint.
export function useTableQuery<Row>(
    source: TableSource<Row> & { changes?: () => unknown },
    rowKey: (row: Row) => string,
    { searchDelay = 0 }: TableQueryOptions = {},
) {
    const search = ref('');
    const appliedSearch = ref('');
    const filters = ref<Record<string, string[]>>({});
    const sort = ref<{ key: string; direction: SortDirection }>();
    const page = ref(1);
    const perPage = ref<number>(pageSizes[0]);

    const rows = shallowRef<Row[]>([]);
    const total = ref(0);
    const lastPage = ref(1);
    const loading = ref(false);
    const failed = ref(false);
    const selected = shallowRef(new Map<string, Row>());

    const query = computed<TableQuery>(() => ({
        search: appliedSearch.value,
        filters: filters.value,
        sort: sort.value,
        page: page.value,
        perPage: perPage.value,
    }));

    let searchTimer: ReturnType<typeof setTimeout> | undefined;
    watch(search, (value) => {
        clearTimeout(searchTimer);
        if (searchDelay > 0) {
            searchTimer = setTimeout(() => {
                appliedSearch.value = value;
            }, searchDelay);
        } else {
            appliedSearch.value = value;
        }
    });

    // A new search or filter starts a new selection on its first page.
    watch([appliedSearch, filters], () => {
        selected.value = new Map();
    });
    watch([appliedSearch, filters, sort, perPage], () => {
        page.value = 1;
    });

    let revision = 0;
    async function load(): Promise<void> {
        const current = ++revision;
        loading.value = true;
        try {
            const result = await source.load(query.value);
            if (current !== revision) {
                return;
            }
            rows.value = result.rows;
            total.value = result.total;
            lastPage.value = result.lastPage;
            failed.value = false;
            if (result.page !== page.value) {
                page.value = result.page;
            }
        } catch {
            if (current === revision) {
                failed.value = true;
            }
        } finally {
            if (current === revision) {
                loading.value = false;
            }
        }
    }

    watch(query, load, { immediate: true });
    if (source.changes) {
        watch(source.changes, load);
    }

    function sortBy(key: string): void {
        sort.value = {
            key,
            direction:
                sort.value?.key === key && sort.value.direction === 'ascending'
                    ? 'descending'
                    : 'ascending',
        };
    }

    function setFilter(key: string, values: string[]): void {
        filters.value = { ...filters.value, [key]: values };
    }

    function reset(): void {
        search.value = '';
        appliedSearch.value = '';
        filters.value = {};
        sort.value = undefined;
        page.value = 1;
    }

    function isSelected(row: Row): boolean {
        return selected.value.has(rowKey(row));
    }

    function setSelected(row: Row, value: boolean): void {
        const next = new Map(selected.value);
        if (value) {
            next.set(rowKey(row), row);
        } else {
            next.delete(rowKey(row));
        }
        selected.value = next;
    }

    const selectedOnPage = computed(
        () => rows.value.filter((row) => isSelected(row)).length,
    );
    const allPageSelected = computed(
        () =>
            rows.value.length > 0 && selectedOnPage.value === rows.value.length,
    );

    function selectPage(): void {
        const select = !allPageSelected.value;
        const next = new Map(selected.value);
        for (const row of rows.value) {
            if (select) {
                next.set(rowKey(row), row);
            } else {
                next.delete(rowKey(row));
            }
        }
        selected.value = next;
    }

    // Keep the selection to rows that still exist after a reload.
    watch(rows, () => {
        if (!source.changes || !selected.value.size) {
            return;
        }
        const current = source.changes();
        if (!Array.isArray(current)) {
            return;
        }
        const keys = new Set(current.map((row) => rowKey(row as Row)));
        const next = new Map(
            [...selected.value].filter(([key]) => keys.has(key)),
        );
        if (next.size !== selected.value.size) {
            selected.value = next;
        }
    });

    return {
        search,
        filters,
        sort,
        page,
        perPage,
        query,
        rows,
        total,
        lastPage,
        loading,
        failed,
        selected,
        selectedOnPage,
        allPageSelected,
        load,
        sortBy,
        setFilter,
        reset,
        isSelected,
        setSelected,
        selectPage,
        clearSelection: () => {
            selected.value = new Map();
        },
    };
}
