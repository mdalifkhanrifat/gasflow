<script setup lang="ts" generic="Row">
defineProps<{
    label: string;
    columns: readonly {
        label: string;
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
        <table :aria-label="label" class="data-table w-full">
            <thead class="text-ink-muted">
                <tr>
                    <th
                        v-for="(column, index) in columns"
                        :key="column.label"
                        scope="col"
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
            <tbody class="divide-y">
                <tr
                    v-for="row in rows"
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
