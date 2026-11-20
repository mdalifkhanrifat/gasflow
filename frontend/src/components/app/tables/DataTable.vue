<script setup lang="ts" generic="Row">
defineProps<{
    label: string;
    columns: readonly { label: string; align?: 'end' }[];
    rows: readonly Row[];
    rowKey: (row: Row) => string;
}>();
</script>

<template>
    <div class="overflow-x-auto rounded-b-panel">
        <table :aria-label="label" class="w-full text-sm">
            <thead class="bg-surface-muted text-xs text-ink-muted">
                <tr>
                    <th
                        v-for="column in columns"
                        :key="column.label"
                        scope="col"
                        :class="
                            column.align === 'end' ? 'text-end' : 'text-start'
                        "
                        class="px-3 py-2.5 font-semibold whitespace-nowrap"
                    >
                        {{ column.label }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="row in rows" :key="rowKey(row)" class="text-ink">
                    <slot :row="row" />
                </tr>
            </tbody>
        </table>
    </div>
</template>
