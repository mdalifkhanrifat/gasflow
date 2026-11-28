<script setup lang="ts" generic="Row">
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ChevronLeft,
    ChevronRight,
    Download,
    Eye,
    Pencil,
    Plus,
    Search,
    Trash2,
} from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import { useRoute, type RouteLocationRaw } from 'vue-router';
import PageHeading from '@/components/app/PageHeading.vue';
import FormDialog from '@/components/app/forms/FormDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import DataTable from '@/components/app/tables/DataTable.vue';
import TableCheckbox from '@/components/app/tables/TableCheckbox.vue';
import {
    formatCell,
    isNumericColumn,
    type TableColumn,
} from '@/components/app/tables/columns';
import { useTableQuery } from '@/components/app/tables/useTableQuery';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useLocale } from '@/composables/useLocale';
import { useStatusCatalogue } from '@/composables/useStatusCatalogue';
import { useToast } from '@/composables/useToast';
import AppShell from '@/layouts/AppShell.vue';
import { downloadCsv } from '@/lib/downloadCsv';
import { formatNumber } from '@/lib/format';
import { pageSizes, type TableSource } from '@/lib/tableQuery';
import RecordCreateDialog, {
    type RecordCreateDefinition,
} from './RecordCreateDialog.vue';

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

const selectedRecord = ref<Row>();
const recordAction = ref<'view' | 'edit' | 'delete'>('view');
const draft = ref('');
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
const offset = computed(() => (page.value - 1) * perPage.value);
const recordCount = computed(() =>
    t('module.record_count')
        .replace(
            '{first}',
            formatNumber(
                rows.value.length ? offset.value + 1 : 0,
                locale.value,
            ),
        )
        .replace(
            '{last}',
            formatNumber(offset.value + rows.value.length, locale.value),
        )
        .replace('{total}', formatNumber(total.value, locale.value)),
);
const pageNumbers = computed(() => {
    const first = Math.max(1, Math.min(page.value - 2, lastPage.value - 4));
    return Array.from(
        { length: Math.min(lastPage.value, 5) },
        (_, index) => first + index,
    );
});
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

const dialogTitle = computed(() => {
    if (recordAction.value === 'view') {
        return selectedRecord.value
            ? recordLabel(selectedRecord.value)
            : props.title;
    }

    return t(recordAction.value === 'edit' ? 'module.edit' : 'module.delete');
});

async function openRecord(
    row: Row,
    action: typeof recordAction.value,
): Promise<void> {
    selectedRecord.value = row;
    recordAction.value = action;
    if (action === 'edit' && editColumn.value) {
        draft.value = String(editColumn.value.value(row));
    }
    await nextTick();
    dialog.value?.open();
}

function saveRecord(): void {
    const value = draft.value.trim();
    if (selectedRecord.value && value) {
        props.previewActions?.onEdit(props.rowKey(selectedRecord.value), value);
        dialog.value?.close();
        notify(t('module.saved'));
    }
}

function removeRecord(): void {
    if (selectedRecord.value) {
        props.previewActions?.onRemove(props.rowKey(selectedRecord.value));
        dialog.value?.close();
        notify(t('module.removed'));
    }
}
</script>

<template>
    <AppShell :page-label="title">
        <div class="grid min-w-0 gap-3.5">
            <PageHeading :title="title">
                <div
                    class="flex w-full min-w-0 flex-wrap items-center gap-2 self-center sm:w-auto sm:justify-end"
                >
                    <label
                        class="relative min-w-0 flex-1 basis-40 sm:w-44 sm:flex-none"
                    >
                        <span class="sr-only">{{ t('module.search') }}</span>
                        <span
                            class="pointer-events-none absolute inset-y-0 left-2.5 grid place-items-center text-ink-muted"
                            ><Search
                        /></span>
                        <UiInput
                            v-model="search"
                            type="search"
                            :placeholder="t('module.search')"
                            class="ui-control-compact w-full pl-7"
                        />
                    </label>
                    <label v-if="statusColumn">
                        <span class="sr-only">{{ t('module.status') }}</span>
                        <NativeSelect
                            class="ui-control-compact"
                            v-model="status"
                        >
                            <option value="">
                                {{ t('module.all_statuses') }}
                            </option>
                            <option
                                v-for="option in statuses"
                                :key="option.code"
                                :value="option.code"
                            >
                                {{ option.label }}
                            </option>
                        </NativeSelect>
                    </label>
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
                            <div class="flex justify-center gap-0.5">
                                <button
                                    v-if="previewActions"
                                    type="button"
                                    class="table-action"
                                    :title="t('module.edit')"
                                    :aria-label="
                                        t('module.edit') +
                                        ': ' +
                                        recordLabel(row)
                                    "
                                    @click="openRecord(row, 'edit')"
                                >
                                    <Pencil />
                                </button>
                                <RouterLink
                                    v-if="rowLink?.(row)"
                                    :to="rowLink(row)!"
                                    class="table-action"
                                    :title="t('module.details')"
                                    :aria-label="
                                        t('module.details') +
                                        ': ' +
                                        recordLabel(row)
                                    "
                                >
                                    <Eye />
                                </RouterLink>
                                <button
                                    v-else
                                    type="button"
                                    class="table-action"
                                    :title="t('module.details')"
                                    :aria-label="
                                        t('module.details') +
                                        ': ' +
                                        recordLabel(row)
                                    "
                                    @click="openRecord(row, 'view')"
                                >
                                    <Eye />
                                </button>
                                <button
                                    v-if="previewActions"
                                    type="button"
                                    class="table-action hover:text-danger"
                                    :title="t('module.delete')"
                                    :aria-label="
                                        t('module.delete') +
                                        ': ' +
                                        recordLabel(row)
                                    "
                                    @click="openRecord(row, 'delete')"
                                >
                                    <Trash2 />
                                </button>
                            </div>
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
                <footer
                    class="module-table-footer border-t border-line text-ink-muted"
                >
                    <div class="flex flex-wrap items-center gap-2.5">
                        <p aria-live="polite">{{ recordCount }}</p>
                        <label>
                            <span class="sr-only">{{
                                t('module.rows_per_page')
                            }}</span>
                            <NativeSelect
                                :model-value="String(perPage)"
                                class="pagination-size"
                                @update:model-value="perPage = Number($event)"
                            >
                                <option
                                    v-for="size in pageSizes"
                                    :key="size"
                                    :value="String(size)"
                                >
                                    {{ formatNumber(size, locale) }}
                                </option>
                            </NativeSelect>
                        </label>
                    </div>
                    <nav
                        v-if="lastPage > 1"
                        :aria-label="t('module.pagination')"
                        class="ms-auto flex flex-wrap items-center justify-end gap-1 self-end"
                    >
                        <button
                            type="button"
                            class="pagination-button"
                            :disabled="page === 1"
                            :aria-label="t('module.previous_page')"
                            @click="page--"
                        >
                            <ChevronLeft />
                        </button>
                        <button
                            v-for="number in pageNumbers"
                            :key="number"
                            type="button"
                            class="pagination-button"
                            :aria-current="number === page ? 'page' : undefined"
                            :aria-label="
                                t('module.page').replace(
                                    '{page}',
                                    formatNumber(number, locale),
                                )
                            "
                            @click="page = number"
                        >
                            {{ formatNumber(number, locale) }}
                        </button>
                        <button
                            type="button"
                            class="pagination-button"
                            :disabled="page === lastPage"
                            :aria-label="t('module.next_page')"
                            @click="page++"
                        >
                            <ChevronRight />
                        </button>
                    </nav>
                </footer>
            </section>
        </div>

        <RecordCreateDialog
            v-if="createRecord"
            ref="createDialog"
            :definition="createRecord"
            @created="showNewRecord"
        />

        <FormDialog
            ref="recordDialog"
            title-id="module-record-title"
            :title="dialogTitle"
            :description="
                recordAction === 'view'
                    ? undefined
                    : t('module.preview_changes')
            "
        >
            <template v-if="selectedRecord && recordAction === 'view'">
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div v-for="column in columns" :key="column.key">
                        <dt class="text-field-label text-ink-muted">
                            {{ column.label }}
                        </dt>
                        <dd class="text-field-output font-medium text-ink">
                            <StatusBadge
                                v-if="column.type === 'status'"
                                :entity="column.statusEntity ?? '*'"
                                :value="String(column.value(selectedRecord))"
                            />
                            <template v-else>{{
                                cell(column, selectedRecord)
                            }}</template>
                        </dd>
                    </div>
                </dl>
                <div class="mt-4 flex justify-end">
                    <button
                        type="button"
                        autofocus
                        class="ui-button"
                        @click="dialog?.close()"
                    >
                        {{ t('module.close') }}
                    </button>
                </div>
            </template>
            <form
                v-else-if="
                    selectedRecord && recordAction === 'edit' && editColumn
                "
                class="grid gap-4"
                @submit.prevent="saveRecord"
            >
                <label class="grid gap-1.5 text-field-label font-medium">
                    {{ editColumn.label }}
                    <UiInput
                        v-model="draft"
                        required
                        maxlength="120"
                        autofocus
                    />
                </label>
                <div class="flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        class="ui-button"
                        @click="dialog?.close()"
                    >
                        {{ t('module.cancel') }}
                    </button>
                    <button
                        type="submit"
                        class="ui-button ui-button-primary"
                        :disabled="!draft.trim()"
                    >
                        {{ t('module.save') }}
                    </button>
                </div>
            </form>
            <template v-else-if="selectedRecord && recordAction === 'delete'">
                <p class="text-sm text-ink">
                    {{
                        t('module.confirm_remove').replace(
                            '{record}',
                            recordLabel(selectedRecord),
                        )
                    }}
                </p>
                <div class="mt-4 flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        class="ui-button"
                        autofocus
                        @click="dialog?.close()"
                    >
                        {{ t('module.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="ui-button border-danger text-danger"
                        @click="removeRecord"
                    >
                        {{ t('module.delete') }}
                    </button>
                </div>
            </template>
        </FormDialog>
    </AppShell>
</template>
