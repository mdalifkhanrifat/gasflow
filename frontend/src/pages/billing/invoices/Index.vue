<script setup lang="ts">
import { computed, watchEffect } from 'vue';
import ModuleListing from '@/components/app/modules/ModuleListing.vue';
import {
    type TableColumn,
    usePreviewTableSource,
} from '@/components/app/tables/columns';
import type { RecordCreateDefinition } from '@/components/app/modules/RecordCreateDialog.vue';
import { useLocale } from '@/composables/useLocale';
import {
    addPreviewInvoice,
    invoiceTotal,
    previewInvoices,
    removePreviewInvoice,
    updatePreviewInvoice,
} from './previewInvoices';

const { t } = useLocale();
watchEffect(() => {
    document.title = t('navigation.invoices') + ' - GasFlow';
});

const previewActions = {
    editColumn: 'customer',
    onEdit: (key: string, value: string) =>
        updatePreviewInvoice(key, { customer: value }),
    onRemove: removePreviewInvoice,
};

const createRecord = computed<RecordCreateDefinition>(() => ({
    label: t('module.add_invoice'),
    layout: 'sheet',
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

type Invoice = (typeof previewInvoices.value)[number];

const columns = computed<TableColumn<Invoice>[]>(() => [
    {
        key: 'number',
        label: t('dashboard.invoice'),
        value: (row) => row.number,
        sortable: true,
    },
    {
        key: 'customer',
        label: t('module.customer'),
        value: (row) => row.customer,
        sortable: true,
    },
    {
        key: 'flat',
        label: t('module.flat'),
        value: (row) => row.flat,
        sortable: true,
    },
    {
        key: 'period',
        label: t('module.period'),
        value: (row) => row.period ?? '2026-10',
        type: 'month',
        sortable: true,
    },
    {
        key: 'amount',
        label: t('dashboard.amount'),
        value: (row) => invoiceTotal(row),
        type: 'money',
        sortable: true,
    },
    {
        key: 'due_date',
        label: t('dashboard.due'),
        value: (row) => row.due,
        type: 'date',
        sortable: true,
    },
    {
        key: 'status',
        label: t('module.status'),
        value: (row) => row.status,
        type: 'status',
        statusEntity: 'invoices',
        sortable: true,
    },
]);
const source = usePreviewTableSource(
    () => previewInvoices.value,
    () => columns.value,
);
</script>

<template>
    <ModuleListing
        export-filename="customer-invoices.csv"
        :title="t('navigation.invoices')"
        :columns="columns"
        :source="source"
        :row-key="(row) => row.number"
        :row-link="
            (row) => ({
                name: 'customer-invoice',
                params: { number: row.number },
            })
        "
        :preview-actions="previewActions"
        :create-record="createRecord"
    />
</template>
