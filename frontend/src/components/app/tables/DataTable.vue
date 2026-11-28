<script setup lang="ts" generic="Row">
defineProps<{
    label: string;
    columns: readonly {
        label: string;
        // Sort key for sortable headings.
        key?: string;
        sortable?: boolean;
        align?: 'end';
        action?: boolean;
        ariaSort?: 'ascending' | 'descending';
    }[];
    edge?: 'top' | 'bottom';
    rows: readonly Row[];
    rowKey: (row: Row) => string;
    isRowSelected?: (row: Row) => boolean;
}>();
</script>

<template>
    <div
        class="overflow-x-auto"
        :class="edge === 'top' ? 'rounded-t-panel' : 'rounded-b-panel'"
        tabindex="0"
        role="region"
        :aria-label="label"
    >
        <!-- Explicit roles keep table semantics when small screens show rows as cards. -->
        <table :aria-label="label" role="table" class="data-table w-full">
            <thead role="rowgroup" class="text-ink-muted">
                <tr role="row">
                    <th
                        v-for="(column, index) in columns"
                        :key="column.key ?? column.label"
                        scope="col"
                        role="columnheader"
                        :aria-sort="column.ariaSort"
                        :class="[
                            column.action
                                ? 'text-center'
                                : column.align === 'end'
                                  ? 'text-end'
                                  : 'text-start',
                            { 'data-table-action-column': column.action },
                        ]"
                        class="font-semibold whitespace-nowrap"
                    >
                        <slot name="heading" :column="column" :index="index">
                            {{ column.label }}
                        </slot>
                    </th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y">
                <tr
                    v-for="row in rows"
                    role="row"
                    :key="rowKey(row)"
                    :data-selected="isRowSelected?.(row) || undefined"
                    class="text-ink"
                >
                    <slot :row="row" />
                </tr>
            </tbody>
        </table>
    </div>
</template>
