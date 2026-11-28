<script setup lang="ts" generic="Row">
import { ArrowDown, ArrowUp, ArrowUpDown, Download, Plus } from '@lucide/vue';
import { computed, ref, useTemplateRef, watch } from 'vue';
import { useRoute, type RouteLocationRaw } from 'vue-router';
import PageHeading from '@/components/app/PageHeading.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import DataTable from '@/components/app/tables/DataTable.vue';
import RecordActions from '@/components/app/tables/RecordActions.vue';
import TableCheckbox from '@/components/app/tables/TableCheckbox.vue';
import TableFilters from '@/components/app/tables/TableFilters.vue';
import TablePagination from '@/components/app/tables/TablePagination.vue';
import {
    formatCell,
    isNumericColumn,
    type TableColumn,
} from '@/components/app/tables/columns';
import { useTableQuery } from '@/components/app/tables/useTableQuery';
import { useLocale } from '@/composables/useLocale';
import { useStatusCatalogue } from '@/composables/useStatusCatalogue';
import { useToast } from '@/composables/useToast';
import AppShell from '@/layouts/AppShell.vue';
import { downloadCsv } from '@/lib/downloadCsv';
import { formatNumber } from '@/lib/format';
import type { TableSource } from '@/lib/tableQuery';
import RecordCreateDialog, {
    type RecordCreateDefinition,
} from './RecordCreateDialog.vue';
import RecordDialog, { type RecordAction } from './RecordDialog.vue';

const props = defineProps<{
    title: string;
    exportFilename: string;
    // The first column names the row; a status column also gives the filter.
    columns: readonly TableColumn<Row>[];
    source: TableSource<Row> & { changes?: () => unknown };
    rowKey: (row: Row) => string;
    rowLabel?: (row: Row) => string;
    rowLink?: (row: Row) => RouteLocationRaw | undefined;
    searchDelay?: number;
    createRecord?: RecordCreateDefinition;
    previewActions?: {
        // Key of the column the preview edit changes.
        editColumn: string;
        onEdit: (key: string, value: string) => void;
        onRemove: (key: string) => void;
    };
}>();
const { locale, t } = useLocale();
const { notify } = useToast();
const { statusLabel, statusOptions } = useStatusCatalogue();
const table = useTableQuery(props.source, props.rowKey, {
    searchDelay: props.searchDelay,
});
const {
    search,
    sort,
    page,
    perPage,
    rows,
    total,
    lastPage,
    loading,
    failed,
    selected,
    selectedOnPage,
    allPageSelected,
    sortBy,
    setFilter,
    isSelected,
    setSelected,
    selectPage,
    clearSelection,
} = table;

const route = useRoute();
watch(
    () => route.query.q,
    (value) => {
        search.value = typeof value === 'string' ? value : '';
    },
    { immediate: true },
);

const formatting = computed(() => ({ locale: locale.value, statusLabel }));
function cell(column: TableColumn<Row>, row: Row): string {
    return formatCell(column, row, formatting.value);
}

const primaryColumn = computed(() => props.columns[0]);
const statusColumn = computed(() =>
    props.columns.find((column) => column.type === 'status'),
);
const status = ref('');
watch(status, (value) => {
    if (statusColumn.value) {
        setFilter(statusColumn.value.key, value ? [value] : []);
    }
});
const statuses = computed(() =>
    statusColumn.value
        ? statusOptions(statusColumn.value.statusEntity ?? '*')
        : [],
);

const dialog = useTemplateRef('recordDialog');
const createDialog = useTemplateRef('createDialog');

const tableColumns = computed(() => [
    { label: t('module.select_page') },
    ...props.columns.map((column) => ({
        label: column.label,
        key: column.key,
        sortable: column.sortable,
        align: isNumericColumn(column) ? ('end' as const) : undefined,
        ariaSort:
            sort.value?.key === column.key ? sort.value.direction : undefined,
    })),
    { label: t('module.action'), action: true },
]);
const selectedCount = computed(() => selected.value.size);
const canExport = computed(
    () =>
        selectedCount.value > 0 || (!!props.source.loadAll && total.value > 0),
);

function showNewRecord(): void {
    notify(t('module.created'));
    status.value = '';
    table.reset();
}

async function exportRecords(): Promise<void> {
    const records = selectedCount.value
        ? [...selected.value.values()]
        : await props.source.loadAll?.(table.query.value);
    if (!records) {
        return;
    }
    downloadCsv(props.exportFilename, [
        props.columns.map((column) => column.label),
        ...records.map((row) =>
            props.columns.map((column) => cell(column, row)),
        ),
    ]);
}

function recordLabel(row: Row): string {
    return (
        props.rowLabel?.(row) ??
        (primaryColumn.value
            ? cell(primaryColumn.value, row)
            : props.rowKey(row))
    );
}

const editColumn = computed(() =>
    props.columns.find(
        (column) => column.key === props.previewActions?.editColumn,
    ),
);

function openRecord(row: Row, action: RecordAction): void {
    void dialog.value?.open(row, action);
}

function saveRecord(row: Row, value: string): void {
    props.previewActions?.onEdit(props.rowKey(row), value);
    notify(t('module.saved'));
}

function removeRecord(row: Row): void {
    props.previewActions?.onRemove(props.rowKey(row));
    notify(t('module.removed'));
}
</script>

<template>
    <AppShell :page-label="title">
        <div class="grid min-w-0 gap-3.5">
            <PageHeading :title="title">
                <div
                    class="flex w-full min-w-0 flex-wrap items-center gap-2 self-center sm:w-auto sm:justify-end"
                >
                    <TableFilters
                        v-model:search="search"
                        v-model:status="status"
                        :statuses="statuses"
                    />
                    <div class="ms-auto flex flex-wrap items-center gap-2">
                        <template v-if="selectedCount">
                            <p role="status" class="text-xs text-ink-muted">
                                {{
                                    t('module.selected_count').replace(
                                        '{count}',
                                        formatNumber(selectedCount, locale),
                                    )
                                }}
                            </p>
                            <button
                                type="button"
                                class="ui-button"
                                @click="clearSelection"
                            >
                                {{ t('module.clear_selection') }}
                            </button>
                        </template>
                        <button
                            type="button"
                            class="ui-button"
                            :disabled="!canExport"
                            @click="exportRecords"
                        >
                            <Download />
                            {{
                                t(
                                    selectedCount
                                        ? 'module.export_selected'
                                        : 'module.export',
                                )
                            }}
                        </button>
                        <button
                            v-if="createRecord"
                            type="button"
                            class="ui-button ui-button-primary"
                            @click="createDialog?.open()"
                        >
                            <Plus />
                            {{ createRecord.label }}
                        </button>
                    </div>
                </div>
            </PageHeading>

            <section
                :aria-label="title"
                class="min-w-0 rounded-panel border border-line bg-surface shadow-panel"
            >
                <DataTable
                    v-if="rows.length"
                    class="module-listing-table"
                    :label="title"
                    edge="top"
                    :columns="tableColumns"
                    :rows="rows"
                    :row-key="rowKey"
                    :is-row-selected="isSelected"
                    :aria-busy="loading || undefined"
                >
                    <template #heading="{ column, index }">
                        <TableCheckbox
                            v-if="index === 0"
                            :label="column.label"
                            :checked="allPageSelected"
                            :indeterminate="
                                selectedOnPage > 0 && !allPageSelected
                            "
                            @change="selectPage"
                        />
                        <button
                            v-else-if="column.sortable && column.key"
                            type="button"
                            class="data-table-sort"
                            @click="sortBy(column.key)"
                        >
                            {{ column.label }}
                            <component
                                :is="
                                    column.ariaSort === 'ascending'
                                        ? ArrowUp
                                        : column.ariaSort === 'descending'
                                          ? ArrowDown
                                          : ArrowUpDown
                                "
                            />
                        </button>
                        <span v-else>{{ column.label }}</span>
                    </template>
                    <template #default="{ row }">
                        <td>
                            <TableCheckbox
                                :label="
                                    t('module.select_row').replace(
                                        '{record}',
                                        recordLabel(row),
                                    )
                                "
                                :checked="isSelected(row)"
                                @change="setSelected(row, $event)"
                            />
                        </td>
                        <template
                            v-for="(column, index) in columns"
                            :key="column.key"
                        >
                            <th
                                v-if="index === 0"
                                scope="row"
                                class="text-start font-semibold whitespace-nowrap"
                            >
                                <RouterLink
                                    v-if="rowLink?.(row)"
                                    :to="rowLink(row)!"
                                    class="ui-record-link"
                                    >{{ cell(column, row) }}</RouterLink
                                >
                                <template v-else>{{
                                    cell(column, row)
                                }}</template>
                            </th>
                            <td
                                v-else
                                class="whitespace-nowrap"
                                :class="{
                                    'text-end tabular-nums':
                                        isNumericColumn(column),
                                }"
                            >
                                <StatusBadge
                                    v-if="column.type === 'status'"
                                    :entity="column.statusEntity ?? '*'"
                                    :value="String(column.value(row))"
                                    compact
                                />
                                <template v-else>{{
                                    cell(column, row)
                                }}</template>
                            </td>
                        </template>
                        <td>
                            <RecordActions
                                :record="recordLabel(row)"
                                :details-to="rowLink?.(row)"
                                :editable="!!previewActions"
                                :removable="!!previewActions"
                                @edit="openRecord(row, 'edit')"
                                @view="openRecord(row, 'view')"
                                @remove="openRecord(row, 'delete')"
                            />
                        </td>
                    </template>
                </DataTable>
                <p
                    v-else
                    role="status"
                    class="px-4 py-6 text-sm text-ink-muted"
                >
                    {{
                        t(
                            failed
                                ? 'module.load_failed'
                                : loading
                                  ? 'module.loading'
                                  : 'module.empty',
                        )
                    }}
                </p>
                <TablePagination
                    v-model:page="page"
                    v-model:per-page="perPage"
                    :last-page="lastPage"
                    :total="total"
                    :shown="rows.length"
                />
            </section>
        </div>

        <RecordCreateDialog
            v-if="createRecord"
            ref="createDialog"
            :definition="createRecord"
            @created="showNewRecord"
        />

        <RecordDialog
            ref="recordDialog"
            :title="title"
            :columns="columns"
            :record-label="recordLabel"
            :edit-column="editColumn"
            @save="saveRecord"
            @remove="removeRecord"
        />
    </AppShell>
</template>
