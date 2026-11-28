<script setup lang="ts">
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
import { useRoute } from 'vue-router';
import PageHeading from '@/components/app/PageHeading.vue';
import FormDialog from '@/components/app/forms/FormDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import DataTable from '@/components/app/tables/DataTable.vue';
import TableCheckbox from '@/components/app/tables/TableCheckbox.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useLocale } from '@/composables/useLocale';
import AppShell from '@/layouts/AppShell.vue';
import { downloadCsv } from '@/lib/downloadCsv';
import { formatNumber } from '@/lib/format';
import RecordCreateDialog, {
    type RecordCreateDefinition,
} from './RecordCreateDialog.vue';

import {
    recordKey,
    useModuleRecords,
    type RecordRow,
} from './useModuleRecords';

const props = defineProps<{
    title: string;
    exportFilename: string;
    columns: readonly string[];
    rows: readonly RecordRow[];
    numericColumns?: readonly number[];
    createRecord?: RecordCreateDefinition;
    previewActions?: {
        editColumn: number;
        onEdit: (key: string, value: string) => void;
        onRemove: (key: string) => void;
    };
}>();
const { locale, t } = useLocale();
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
const recordAction = ref<'view' | 'edit' | 'delete'>('view');
const draft = ref('');
const dialog = useTemplateRef('recordDialog');
const createDialog = useTemplateRef('createDialog');
const statuses = computed(() => [
    ...new Map(props.rows.map((row) => [row.statusCode, row.status])).entries(),
]);
watch(statuses, (options) => {
    if (status.value && !options.some(([code]) => code === status.value)) {
        status.value = '';
    }
});
const {
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
    clearSelection,
} = useModuleRecords(
    () => props.rows,
    search,
    status,
    () => props.columns.length,
);

const columns = computed(() => [
    { label: t('module.select_page') },
    ...[...props.columns, t('module.status')].map((label, index) => ({
        label,
        align: props.numericColumns?.includes(index)
            ? ('end' as const)
            : undefined,
        ariaSort:
            sort.value?.column === index ? sort.value.direction : undefined,
    })),
    { label: t('module.action'), action: true },
]);
const selectedCount = computed(() => selectedRows.value.length);
const recordCount = computed(() =>
    t('module.record_count')
        .replace(
            '{first}',
            formatNumber(
                pageRows.value.length ? offset.value + 1 : 0,
                locale.value,
            ),
        )
        .replace(
            '{last}',
            formatNumber(offset.value + pageRows.value.length, locale.value),
        )
        .replace(
            '{total}',
            formatNumber(matchedRows.value.length, locale.value),
        ),
);

function showNewRecord(): void {
    search.value = '';
    status.value = '';
    sort.value = undefined;
    page.value = 1;
}

function exportRecords(): void {
    const records = selectedCount.value
        ? selectedRows.value
        : matchedRows.value;
    downloadCsv(props.exportFilename, [
        [...props.columns, t('module.status')],
        ...records.map((row) => [...row.cells, row.status]),
    ]);
}

function recordLabel(row: RecordRow): string {
    return row.label ?? row.cells[0] ?? recordKey(row);
}

const dialogTitle = computed(() => {
    if (recordAction.value === 'view') {
        return selected.value ? recordLabel(selected.value) : props.title;
    }

    return t(recordAction.value === 'edit' ? 'module.edit' : 'module.delete');
});

async function openRecord(
    row: RecordRow,
    action: typeof recordAction.value,
): Promise<void> {
    selected.value = row;
    recordAction.value = action;
    if (action === 'edit' && props.previewActions) {
        draft.value = row.cells[props.previewActions.editColumn] ?? '';
    }
    await nextTick();
    dialog.value?.open();
}

function saveRecord(): void {
    const value = draft.value.trim();
    if (selected.value && value) {
        props.previewActions?.onEdit(recordKey(selected.value), value);
        dialog.value?.close();
    }
}

function removeRecord(): void {
    if (selected.value) {
        props.previewActions?.onRemove(recordKey(selected.value));
        dialog.value?.close();
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
                    <label>
                        <span class="sr-only">{{ t('module.status') }}</span>
                        <NativeSelect
                            class="ui-control-compact"
                            v-model="status"
                        >
                            <option value="">
                                {{ t('module.all_statuses') }}
                            </option>
                            <option
                                v-for="[code, label] in statuses"
                                :key="code"
                                :value="code"
                            >
                                {{ label }}
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
                            :disabled="!matchedRows.length"
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
                    v-if="pageRows.length"
                    class="module-listing-table"
                    :label="title"
                    edge="top"
                    :columns="columns"
                    :rows="pageRows"
                    :row-key="recordKey"
                    :is-row-selected="isSelected"
                >
                    <template #heading="{ column, index }">
                        <TableCheckbox
                            v-if="index === 0"
                            :label="column.label"
                            :checked="allPageSelected"
                            :indeterminate="
                                selectedPageCount > 0 && !allPageSelected
                            "
                            @change="selectPage"
                        />
                        <span v-else-if="column.action">{{
                            column.label
                        }}</span>
                        <button
                            v-else
                            type="button"
                            class="data-table-sort"
                            @click="sortBy(index - 1)"
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
                        <th
                            scope="row"
                            class="text-start font-semibold whitespace-nowrap"
                        >
                            <RouterLink
                                v-if="row.to"
                                :to="row.to"
                                class="ui-record-link"
                                >{{ row.cells[0] }}</RouterLink
                            >
                            <template v-else>{{ row.cells[0] }}</template>
                        </th>
                        <td
                            v-for="(cell, index) in row.cells.slice(1)"
                            :key="index"
                            class="whitespace-nowrap"
                            :class="{
                                'text-end tabular-nums':
                                    numericColumns?.includes(index + 1),
                            }"
                        >
                            {{ cell }}
                        </td>
                        <td class="whitespace-nowrap">
                            <StatusBadge :tone="row.tone" compact>{{
                                row.status
                            }}</StatusBadge>
                        </td>
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
                                    v-if="row.to"
                                    :to="row.to"
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
                <p v-else class="px-4 py-6 text-sm text-ink-muted">
                    {{ t('module.empty') }}
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
                                v-model="pageSize"
                                class="pagination-size"
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
                        v-if="pageCount > 1"
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
                            :disabled="page === pageCount"
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
            <template v-if="selected && recordAction === 'view'">
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div v-for="(column, index) in props.columns" :key="column">
                        <dt class="text-field-label text-ink-muted">
                            {{ column }}
                        </dt>
                        <dd class="text-field-output font-medium text-ink">
                            {{ selected.cells[index] }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-field-label text-ink-muted">
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
                        class="ui-button"
                        @click="dialog?.close()"
                    >
                        {{ t('module.close') }}
                    </button>
                </div>
            </template>
            <form
                v-else-if="
                    selected && recordAction === 'edit' && previewActions
                "
                class="grid gap-4"
                @submit.prevent="saveRecord"
            >
                <label class="grid gap-1.5 text-field-label font-medium">
                    {{ props.columns[previewActions.editColumn] }}
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
            <template v-else-if="selected && recordAction === 'delete'">
                <p class="text-sm text-ink">
                    {{
                        t('module.confirm_remove').replace(
                            '{record}',
                            recordLabel(selected),
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
