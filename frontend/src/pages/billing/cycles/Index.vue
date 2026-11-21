<script setup lang="ts">
import { computed, watchEffect } from 'vue';
import ModuleListing from '@/components/app/modules/ModuleListing.vue';
import { useLocale } from '@/composables/useLocale';
import { formatNumber } from '@/lib/format';

const { locale, t } = useLocale();
watchEffect(() => {
    document.title = t('navigation.cycles') + ' - GasFlow';
});

const cycles = [
    {
        building: 'Lake View Tower',
        mode: 'cycle.internal',
        received: 48,
        expected: 48,
        reconciliation: 'cycle.pending_review',
        status: 'cycle.review',
        tone: 'warning',
    },
    {
        building: 'Green Residency',
        mode: 'cycle.authorized',
        received: 36,
        expected: 36,
        reconciliation: 'cycle.accepted',
        status: 'dashboard.approved',
        tone: 'success',
    },
    {
        building: 'Sunrise Heights',
        mode: 'cycle.internal',
        received: 58,
        expected: 60,
        reconciliation: 'cycle.exception',
        status: 'cycle.blocked',
        tone: 'danger',
    },
] as const;

const rows = computed(() =>
    cycles.map((cycle) => ({
        key: cycle.building,
        cells: [
            'BC-2026-10',
            cycle.building,
            t('module.october_period'),
            t(cycle.mode),
            formatNumber(cycle.received, locale.value) +
                ' / ' +
                formatNumber(cycle.expected, locale.value),
            t(cycle.reconciliation),
        ],
        statusCode: cycle.status,
        status: t(cycle.status),
        tone: cycle.tone,
    })),
);
</script>

<template>
    <ModuleListing
        export-filename="billing-cycles.csv"
        :title="t('navigation.cycles')"
        :description="t('cycle.description')"
        :columns="[
            t('cycle.number'),
            t('topbar.building'),
            t('cycle.reading_period'),
            t('cycle.mode'),
            t('cycle.readings'),
            t('dashboard.reconciliation'),
        ]"
        :rows="rows"
    />
</template>
