<script setup lang="ts">
import { computed } from 'vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import DataTable from '@/components/app/tables/DataTable.vue';
import { useLocale } from '@/composables/useLocale';
import { formatDate, formatMoney } from '@/lib/format';

const { locale, t } = useLocale();
const columns = computed(() => [
    { label: t('dashboard.invoice') },
    { label: t('dashboard.customer') },
    { label: t('dashboard.amount'), align: 'end' as const },
    { label: t('dashboard.due') },
    { label: t('dashboard.status') },
]);
const rows = [
    {
        number: 'INV-2026-1042',
        customer: 'Nusrat Jahan',
        flat: 'Lake View · A-4B',
        amount: 2450,
        due: '2026-11-20',
        status: 'dashboard.issued',
        tone: 'info',
    },
    {
        number: 'INV-2026-1041',
        customer: 'Rahim Ahmed',
        flat: 'Green Residency · B-3A',
        amount: 3180,
        due: '2026-11-20',
        status: 'dashboard.part_paid',
        tone: 'warning',
    },
    {
        number: 'INV-2026-1040',
        customer: 'Imran Kabir',
        flat: 'Lake View · A-4C',
        amount: 2120,
        due: '2026-11-15',
        status: 'dashboard.overdue',
        tone: 'danger',
    },
] as const;
</script>

<template>
    <DataTable
        :label="t('dashboard.recent')"
        :columns="columns"
        :rows="rows"
        :row-key="(row) => row.number"
    >
        <template #default="{ row }">
            <th
                scope="row"
                class="px-3 py-3 text-left text-xs font-semibold whitespace-nowrap"
            >
                {{ row.number }}
            </th>
            <td class="px-3 py-3 text-xs whitespace-nowrap">
                {{ row.customer
                }}<span class="mt-0.5 block text-ink-muted">{{
                    row.flat
                }}</span>
            </td>
            <td
                class="px-3 py-3 text-right text-xs whitespace-nowrap tabular-nums"
            >
                {{ formatMoney(row.amount, locale) }}
            </td>
            <td class="px-3 py-3 text-xs whitespace-nowrap">
                {{ formatDate(row.due, locale) }}
            </td>
            <td class="px-3 py-3 whitespace-nowrap">
                <StatusBadge :tone="row.tone">{{ t(row.status) }}</StatusBadge>
            </td>
        </template>
    </DataTable>
</template>
