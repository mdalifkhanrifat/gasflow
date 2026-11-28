<script setup lang="ts">
import { computed, watchEffect } from 'vue';
import ModuleListing from '@/components/app/modules/ModuleListing.vue';
import type { RecordCreateDefinition } from '@/components/app/modules/RecordCreateDialog.vue';
import { useLocale } from '@/composables/useLocale';
import { formatDate, formatMoney, formatMonth } from '@/lib/format';
import {
    addPreviewInvoice,
    invoiceTotal,
    previewInvoices,
    removePreviewInvoice,
    updatePreviewInvoice,
} from './previewInvoices';

const { locale, t } = useLocale();
watchEffect(() => {
    document.title = t('navigation.invoices') + ' - GasFlow';
});

const previewActions = {
    editColumn: 1,
    onEdit: (key: string, value: string) =>
        updatePreviewInvoice(key, { customer: value }),
    onRemove: removePreviewInvoice,
};

const createRecord = computed<RecordCreateDefinition>(() => ({
    label: t('module.add_invoice'),
    fields: [
        { name: 'customer', label: t('module.customer') },
        { name: 'building', label: t('topbar.building') },
        { name: 'flat', label: t('module.flat') },
        {
            name: 'period',
            label: t('module.period'),
            type: 'month',
            defaultValue: '2026-10',
        },
        { name: 'due', label: t('dashboard.due'), type: 'date' },
        {
            name: 'amount',
            label: t('invoice.service_charge'),
            type: 'number',
            min: 0.01,
            step: 0.01,
        },
    ],
    save: ({ customer, building, flat, period, due, amount }) => {
        addPreviewInvoice({
            customer,
            building,
            flat,
            period,
            due,
            amount: Number(amount),
        });
    },
}));

const rows = computed(() =>
    previewInvoices.value.map((row) => {
        const amount = invoiceTotal(row);
        return {
            to: { name: 'customer-invoice', params: { number: row.number } },
            cells: [
                row.number,
                row.customer,
                row.flat,
                row.period
                    ? formatMonth(row.period, locale.value)
                    : t('module.october_period'),
                formatMoney(amount, locale.value),
                formatDate(row.due, locale.value),
            ],
            sortValues: {
                3: row.period ?? '2026-10',
                4: amount,
                5: Date.parse(row.due),
            },
            statusCode: row.status,
        };
    }),
);
</script>

<template>
    <ModuleListing
        export-filename="customer-invoices.csv"
        :title="t('navigation.invoices')"
        :columns="[
            t('dashboard.invoice'),
            t('module.customer'),
            t('module.flat'),
            t('module.period'),
            t('dashboard.amount'),
            t('dashboard.due'),
        ]"
        :rows="rows"
        status-entity="invoices"
        :preview-actions="previewActions"
        :create-record="createRecord"
        :numeric-columns="[4]"
    />
</template>
