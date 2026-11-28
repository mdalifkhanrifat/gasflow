<script lang="ts">
import { createPreviewRecords } from '@/lib/createPreviewRecords';

interface BillingCycle {
    id: string;
    building: string;
    period: string;
    mode: 'cycle.internal' | 'cycle.authorized';
    received: number;
    expected: number;
    reconciliation:
        | 'cycle.pending_review'
        | 'cycle.accepted'
        | 'cycle.exception';
    // billing_cycles statuses in status_definitions.
    status: 'draft' | 'review' | 'approved';
}

let nextCycleId = 0;
const {
    rows: cycles,
    add: addCycle,
    update: updateRecord,
    remove: removeRecord,
} = createPreviewRecords<BillingCycle>(
    [
        {
            id: 'lake-view',
            building: 'Lake View Tower',
            period: '2026-10',
            mode: 'cycle.internal',
            received: 48,
            expected: 48,
            reconciliation: 'cycle.pending_review',
            status: 'review',
        },
        {
            id: 'green-residency',
            building: 'Green Residency',
            period: '2026-10',
            mode: 'cycle.authorized',
            received: 36,
            expected: 36,
            reconciliation: 'cycle.accepted',
            status: 'approved',
        },
        {
            id: 'sunrise-heights',
            building: 'Sunrise Heights',
            period: '2026-10',
            mode: 'cycle.internal',
            received: 58,
            expected: 60,
            reconciliation: 'cycle.exception',
            status: 'draft',
        },
    ],
    (record) => record.id,
);
</script>

<script setup lang="ts">
import { computed, watchEffect } from 'vue';
import ModuleListing from '@/components/app/modules/ModuleListing.vue';
import {
    type TableColumn,
    usePreviewTableSource,
} from '@/components/app/tables/columns';
import type { RecordCreateDefinition } from '@/components/app/modules/RecordCreateDialog.vue';
import { useLocale } from '@/composables/useLocale';
import { formatNumber } from '@/lib/format';

const { locale, t } = useLocale();
watchEffect(() => {
    document.title = t('navigation.cycles') + ' - GasFlow';
});

const previewActions = {
    editColumn: 'building',
    onEdit: (key: string, value: string) =>
        updateRecord(key, { building: value }),
    onRemove: removeRecord,
};

const createRecord = computed<RecordCreateDefinition>(() => ({
    label: t('module.add_cycle'),
    fields: [
        { name: 'building', label: t('topbar.building') },
        {
            name: 'period',
            label: t('cycle.reading_period'),
            type: 'month',
            defaultValue: '2026-10',
        },
        {
            name: 'mode',
            label: t('cycle.mode'),
            defaultValue: 'cycle.internal',
            options: [
                { value: 'cycle.internal', label: t('cycle.internal') },
                { value: 'cycle.authorized', label: t('cycle.authorized') },
            ],
        },
    ],
    save: ({ building, period, mode }) => {
        if (
            cycles.value.some(
                (record) =>
                    record.period === period &&
                    record.building.toLocaleLowerCase() ===
                        building.toLocaleLowerCase(),
            )
        ) {
            return { period: t('module.existing_cycle') };
        }
        addCycle({
            id: 'preview-cycle-' + ++nextCycleId,
            building,
            period,
            mode:
                mode === 'cycle.authorized'
                    ? 'cycle.authorized'
                    : 'cycle.internal',
            received: 0,
            expected: 0,
            reconciliation: 'cycle.pending_review',
            status: 'draft',
        });
    },
}));

const columns = computed<TableColumn<BillingCycle>[]>(() => [
    {
        key: 'number',
        label: t('cycle.number'),
        value: (cycle) => 'BC-' + cycle.period,
        sortable: true,
    },
    {
        key: 'building',
        label: t('topbar.building'),
        value: (cycle) => cycle.building,
        sortable: true,
    },
    {
        key: 'period',
        label: t('cycle.reading_period'),
        value: (cycle) => cycle.period,
        type: 'month',
        sortable: true,
    },
    {
        key: 'mode',
        label: t('cycle.mode'),
        value: (cycle) => t(cycle.mode),
        sortable: true,
    },
    {
        key: 'readings',
        label: t('cycle.readings'),
        value: (cycle) =>
            formatNumber(cycle.received, locale.value) +
            ' / ' +
            formatNumber(cycle.expected, locale.value),
        sortValue: (cycle) => cycle.received,
        sortable: true,
    },
    {
        key: 'reconciliation',
        label: t('dashboard.reconciliation'),
        value: (cycle) => t(cycle.reconciliation),
        sortable: true,
    },
    {
        key: 'status',
        label: t('module.status'),
        value: (cycle) => cycle.status,
        type: 'status',
        statusEntity: 'billing_cycles',
        sortable: true,
    },
]);
const source = usePreviewTableSource(
    () => cycles.value,
    () => columns.value,
);
</script>

<template>
    <ModuleListing
        export-filename="billing-cycles.csv"
        :title="t('navigation.cycles')"
        :columns="columns"
        :source="source"
        :row-key="(cycle) => cycle.id"
        :row-label="(cycle) => cycle.building"
        :preview-actions="previewActions"
        :create-record="createRecord"
    />
</template>
