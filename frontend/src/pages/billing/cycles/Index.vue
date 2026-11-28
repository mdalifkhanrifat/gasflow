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
import type { RecordCreateDefinition } from '@/components/app/modules/RecordCreateDialog.vue';
import { useLocale } from '@/composables/useLocale';
import { formatMonth, formatNumber } from '@/lib/format';

const { locale, t } = useLocale();
watchEffect(() => {
    document.title = t('navigation.cycles') + ' - GasFlow';
});

const previewActions = {
    editColumn: 1,
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
            return t('module.existing_cycle');
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

const rows = computed(() =>
    cycles.value.map((cycle) => ({
        key: cycle.id,
        label: cycle.building,
        cells: [
            'BC-' + cycle.period,
            cycle.building,
            formatMonth(cycle.period, locale.value),
            t(cycle.mode),
            formatNumber(cycle.received, locale.value) +
                ' / ' +
                formatNumber(cycle.expected, locale.value),
            t(cycle.reconciliation),
        ],
        sortValues: { 2: cycle.period, 4: cycle.received },
        statusCode: cycle.status,
    })),
);
</script>

<template>
    <ModuleListing
        export-filename="billing-cycles.csv"
        :title="t('navigation.cycles')"
        :columns="[
            t('cycle.number'),
            t('topbar.building'),
            t('cycle.reading_period'),
            t('cycle.mode'),
            t('cycle.readings'),
            t('dashboard.reconciliation'),
        ]"
        :rows="rows"
        status-entity="billing_cycles"
        :preview-actions="previewActions"
        :create-record="createRecord"
    />
</template>
