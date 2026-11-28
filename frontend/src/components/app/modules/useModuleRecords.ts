import { computed, ref, watch, type Ref } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import { useLocale } from '@/composables/useLocale';

export interface RecordRow {
    key?: string;
    label?: string;
    to?: RouteLocationRaw;
    cells: readonly string[];
    sortValues?: Partial<Record<number, string | number>>;
    statusCode: string;
}

export function recordKey(row: RecordRow): string {
    return row.key ?? row.cells[0] ?? '';
}

export function useModuleRecords(
    rows: () => readonly RecordRow[],
    search: Ref<string>,
    status: Ref<string>,
    statusColumn: () => number,
    statusRank: (code: string) => number,
) {
    const { locale } = useLocale();
    const collator = computed(
        () =>
            new Intl.Collator(locale.value, {
                numeric: true,
                sensitivity: 'base',
            }),
    );
    const sort = ref<{
        column: number;
        direction: 'ascending' | 'descending';
    }>();
    const pageSizes = [5, 10, 25, 50];
    const pageSize = ref('10');
    const page = ref(1);
    const selectedKeys = ref(new Set<string>());

    function sortBy(column: number): void {
        sort.value = {
            column,
            direction:
                sort.value?.column === column &&
                sort.value.direction === 'ascending'
                    ? 'descending'
                    : 'ascending',
        };
    }

    function sortValue(row: RecordRow, column: number): string | number {
        return column === statusColumn()
            ? statusRank(row.statusCode)
            : (row.sortValues?.[column] ?? row.cells[column] ?? '');
    }

    const matchedRows = computed(() => {
        const query = search.value.trim().toLocaleLowerCase(locale.value);
        const result = rows().filter(
            (row) =>
                (!status.value || status.value === row.statusCode) &&
                row.cells.some((cell) =>
                    cell.toLocaleLowerCase(locale.value).includes(query),
                ),
        );

        const ordering = sort.value;
        if (ordering) {
            const multiplier = ordering.direction === 'ascending' ? 1 : -1;
            result.sort((left, right) => {
                const a = sortValue(left, ordering.column);
                const b = sortValue(right, ordering.column);
                const comparison =
                    typeof a === 'number' && typeof b === 'number'
                        ? a - b
                        : collator.value.compare(String(a), String(b));
                return multiplier * comparison;
            });
        }

        return result;
    });

    const pageCount = computed(() =>
        Math.max(
            1,
            Math.ceil(matchedRows.value.length / Number(pageSize.value)),
        ),
    );
    const offset = computed(() => (page.value - 1) * Number(pageSize.value));
    const pageRows = computed(() =>
        matchedRows.value.slice(
            offset.value,
            offset.value + Number(pageSize.value),
        ),
    );
    const pageNumbers = computed(() => {
        const first = Math.max(
            1,
            Math.min(page.value - 2, pageCount.value - 4),
        );
        return Array.from(
            { length: Math.min(pageCount.value, 5) },
            (_, index) => first + index,
        );
    });

    watch([search, status], () => selectedKeys.value.clear());
    watch([search, status, pageSize, sort], () => {
        page.value = 1;
    });
    watch(pageCount, (count) => {
        page.value = Math.min(page.value, count);
    });
    watch(rows, (records) => {
        const keys = new Set(records.map(recordKey));
        for (const key of selectedKeys.value) {
            if (!keys.has(key)) {
                selectedKeys.value.delete(key);
            }
        }
    });

    function isSelected(row: RecordRow): boolean {
        return selectedKeys.value.has(recordKey(row));
    }

    const selectedRows = computed(() => matchedRows.value.filter(isSelected));
    const selectedPageCount = computed(
        () => pageRows.value.filter(isSelected).length,
    );
    const allPageSelected = computed(
        () =>
            pageRows.value.length > 0 &&
            selectedPageCount.value === pageRows.value.length,
    );

    function setSelected(row: RecordRow, selected: boolean): void {
        const key = recordKey(row);
        if (selected) {
            selectedKeys.value.add(key);
        } else {
            selectedKeys.value.delete(key);
        }
    }

    function selectPage(): void {
        const select = !allPageSelected.value;
        for (const row of pageRows.value) {
            setSelected(row, select);
        }
    }

    return {
        sort,
        sortBy,
        matchedRows,
        page,
        pageSize,
        pageSizes,
        pageCount,
        offset,
        pageRows,
        pageNumbers,
        selectedRows,
        selectedPageCount,
        allPageSelected,
        isSelected,
        setSelected,
        selectPage,
        clearSelection: () => selectedKeys.value.clear(),
    };
}
