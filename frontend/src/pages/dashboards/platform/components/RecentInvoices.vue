<script setup lang="ts">
import { computed } from 'vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import DataTable from '@/components/app/tables/DataTable.vue';
import { useLocale } from '@/composables/useLocale';
import { formatDate, formatMoney } from '@/lib/format';
import {
    invoiceTotal,
    previewInvoices,
} from '@/pages/billing/invoices/previewInvoices';

const { locale, t } = useLocale();
const columns = computed(() => [
    { label: t('dashboard.invoice') },
    { label: t('dashboard.customer') },
    { label: t('dashboard.amount'), align: 'end' as const },
    { label: t('dashboard.due') },
    { label: t('dashboard.status') },
]);
const rows = computed(() => previewInvoices.value.slice(0, 3));
</script>

<template>
    <DataTable
        :label="t('dashboard.recent')"
        :columns="columns"
        :rows="rows"
        :row-key="(row) => row.number"
    >
        <template #default="{ row }">
            <th scope="row" class="text-start font-semibold whitespace-nowrap">
                <RouterLink
                    :to="{
                        name: 'customer-invoice',
                        params: { number: row.number },
                    }"
                    class="ui-record-link"
                    >{{ row.number }}</RouterLink
                >
            </th>
            <td class="whitespace-nowrap">
                {{ row.customer
                }}<span class="mt-0.5 block text-ink-muted">{{
                    row.building + ' · ' + row.flat
                }}</span>
            </td>
            <td class="text-end whitespace-nowrap tabular-nums">
                {{ formatMoney(invoiceTotal(row), locale) }}
            </td>
            <td class="whitespace-nowrap">
                {{ formatDate(row.due, locale) }}
            </td>
            <td class="whitespace-nowrap">
                <StatusBadge entity="invoices" :value="row.status" compact />
            </td>
        </template>
    </DataTable>
</template>
