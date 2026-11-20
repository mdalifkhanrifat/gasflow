<script setup lang="ts" generic="Row">
defineProps<{
    label: string;
    columns: readonly { label: string; align?: 'end'; action?: boolean }[];
    density?: 'compact';
    rows: readonly Row[];
    rowKey: (row: Row) => string;
}>();
</script>

<template>
    <div
        class="overflow-x-auto"
        :class="density === 'compact' ? 'rounded-t-panel' : 'rounded-b-panel'"
        :tabindex="density === 'compact' ? 0 : undefined"
        :role="density === 'compact' ? 'region' : undefined"
        :aria-label="density === 'compact' ? label : undefined"
    >
        <table
            :aria-label="label"
            class="w-full text-sm"
            :class="{ 'data-table-compact': density === 'compact' }"
        >
            <thead
                class="text-xs text-ink-muted"
                :class="{ 'bg-surface-muted': density !== 'compact' }"
            >
                <tr>
                    <th
                        v-for="column in columns"
                        :key="column.label"
                        scope="col"
                        :class="[
                            column.align === 'end' ? 'text-end' : 'text-start',
                            { 'data-table-action-column': column.action },
                        ]"
                        class="px-3 py-2.5 font-semibold whitespace-nowrap"
                    >
                        {{ column.label }}
                    </th>
                </tr>
            </thead>
            <tbody
                class="divide-y"
                :class="{ 'divide-line': density !== 'compact' }"
            >
                <tr v-for="row in rows" :key="rowKey(row)" class="text-ink">
                    <slot :row="row" />
                </tr>
            </tbody>
        </table>
    </div>
</template>
