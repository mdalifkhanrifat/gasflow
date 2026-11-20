<script setup lang="ts">
import { computed, ref, useTemplateRef, watch } from 'vue';
import { useRoute } from 'vue-router';
import DashboardPanel from '@/components/app/dashboard/DashboardPanel.vue';
import FormDialog from '@/components/app/forms/FormDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import DataTable from '@/components/app/tables/DataTable.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { useLocale } from '@/composables/useLocale';
import AppShell from '@/layouts/AppShell.vue';

interface RecordRow {
    cells: readonly string[];
    statusCode: string;
    status: string;
    tone: 'info' | 'success' | 'warning' | 'danger';
}

const props = defineProps<{
    title: string;
    description: string;
    columns: readonly string[];
    rows: readonly RecordRow[];
}>();
const { t } = useLocale();
const search = ref('');
const route = useRoute();
watch(
    () => route.query.q,
    (value) => {
        search.value = typeof value === 'string' ? value : '';
    },
    { immediate: true },
);
const status = ref('');
const selected = ref<RecordRow>();
const dialog = useTemplateRef('recordDialog');
const statuses = computed(() => [
    ...new Map(props.rows.map((row) => [row.statusCode, row.status])).entries(),
]);
const columns = computed(() => [
    ...props.columns.map((label) => ({ label })),
    { label: t('module.status') },
    { label: t('module.details') },
]);
const visibleRows = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();
    return props.rows.filter(
        (row) =>
            (!status.value || status.value === row.statusCode) &&
            row.cells.some((cell) => cell.toLocaleLowerCase().includes(query)),
    );
});

function openRecord(row: RecordRow): void {
    selected.value = row;
    dialog.value?.open();
}
</script>

<template>
    <AppShell :page-label="title">
        <div class="grid min-w-0 gap-3.5">
            <header>
                <p
                    class="text-xs font-bold tracking-wide text-primary uppercase"
                >
                    {{ t('navigation.workspace_label') }} / {{ title }}
                </p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink">
                    {{ title }}
                </h1>
                <p class="mt-1 text-xs text-ink-muted">{{ description }}</p>
            </header>

            <div class="flex flex-wrap items-center gap-2">
                <label class="min-w-40">
                    <span class="sr-only">{{ t('module.search') }}</span>
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="t('module.search')"
                        class="min-h-control w-full rounded-control border border-line bg-surface px-3 text-sm text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    />
                </label>
                <label>
                    <span class="sr-only">{{ t('module.status') }}</span>
                    <NativeSelect
                        :value="status"
                        @change="
                            status = ($event.target as HTMLSelectElement).value
                        "
                    >
                        <option value="">{{ t('module.all_statuses') }}</option>
                        <option
                            v-for="[code, label] in statuses"
                            :key="code"
                            :value="code"
                        >
                            {{ label }}
                        </option>
                    </NativeSelect>
                </label>
            </div>

            <DashboardPanel :title="title">
                <DataTable
                    v-if="visibleRows.length"
                    :label="title"
                    :columns="columns"
                    :rows="visibleRows"
                    :row-key="(row) => row.cells[0] ?? ''"
                >
                    <template #default="{ row }">
                        <th
                            scope="row"
                            class="px-3 py-3 text-start text-xs font-semibold whitespace-nowrap"
                        >
                            {{ row.cells[0] }}
                        </th>
                        <td
                            v-for="(cell, index) in row.cells.slice(1)"
                            :key="index"
                            class="px-3 py-3 text-xs whitespace-nowrap"
                        >
                            {{ cell }}
                        </td>
                        <td class="px-3 py-3 whitespace-nowrap">
                            <StatusBadge :tone="row.tone">{{
                                row.status
                            }}</StatusBadge>
                        </td>
                        <td class="px-3 py-3">
                            <button
                                type="button"
                                class="preview-control hover:bg-surface-muted"
                                :aria-label="
                                    t('module.details') + ': ' + row.cells[0]
                                "
                                @click="openRecord(row)"
                            >
                                ↗
                            </button>
                        </td>
                    </template>
                </DataTable>
                <p v-else class="px-4 py-6 text-sm text-ink-muted">
                    {{ t('module.empty') }}
                </p>
                <p
                    class="border-t border-line px-4 py-3 text-xs text-ink-muted"
                >
                    {{ t('dashboard.preview') }}
                </p>
            </DashboardPanel>
        </div>

        <FormDialog
            ref="recordDialog"
            title-id="module-record-title"
            :title="selected?.cells[0] ?? title"
        >
            <dl v-if="selected" class="grid gap-3 sm:grid-cols-2">
                <div v-for="(column, index) in props.columns" :key="column">
                    <dt class="text-xs text-ink-muted">{{ column }}</dt>
                    <dd class="text-sm font-medium text-ink">
                        {{ selected.cells[index] }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-muted">
                        {{ t('module.status') }}
                    </dt>
                    <dd>
                        <StatusBadge :tone="selected.tone">{{
                            selected.status
                        }}</StatusBadge>
                    </dd>
                </div>
            </dl>
            <div class="mt-4 flex justify-end">
                <button
                    type="button"
                    autofocus
                    class="preview-control"
                    @click="dialog?.close()"
                >
                    {{ t('module.close') }}
                </button>
            </div>
        </FormDialog>
    </AppShell>
</template>
