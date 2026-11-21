<script setup lang="ts" generic="Row">
defineProps<{
    label: string;
    columns: readonly { label: string; align?: 'end'; action?: boolean }[];
    edge?: 'top' | 'bottom';
    rows: readonly Row[];
    rowKey: (row: Row) => string;
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
                        v-for="column in columns"
                        :key="column.label"
                        scope="col"
                        :class="[
                            column.align === 'end' ? 'text-end' : 'text-start',
                            { 'data-table-action-column': column.action },
                        ]"
                        class="font-semibold whitespace-nowrap"
                    >
                        {{ column.label }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <tr v-for="row in rows" :key="rowKey(row)" class="text-ink">
                    <slot :row="row" />
                </tr>
            </tbody>
        </table>
    </div>
</template>
