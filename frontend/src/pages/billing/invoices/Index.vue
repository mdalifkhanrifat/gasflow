<script setup lang="ts">
import { computed, watchEffect } from 'vue';
import ModuleListing from '@/components/app/modules/ModuleListing.vue';
import { useLocale } from '@/composables/useLocale';
import { formatDate, formatMoney } from '@/lib/format';

const { locale, t } = useLocale();
watchEffect(() => {
    document.title = t('navigation.invoices') + ' - GasFlow';
});

const invoices = [
    {
        number: 'INV-2026-1042',
        customer: 'Nusrat Jahan',
        flat: 'A-4B',
        amount: 2450,
        due: '2026-11-20',
        status: 'dashboard.issued',
        tone: 'info',
    },
    {
        number: 'INV-2026-1041',
        customer: 'Rahim Ahmed',
        flat: 'B-3A',
        amount: 3180,
        due: '2026-11-20',
        status: 'dashboard.part_paid',
        tone: 'warning',
    },
    {
        number: 'INV-2026-1040',
        customer: 'Imran Kabir',
        flat: 'A-4C',
        amount: 2120,
        due: '2026-11-15',
        status: 'dashboard.overdue',
        tone: 'danger',
    },
    {
        number: 'INV-2026-1039',
        customer: 'Mitu Akter',
        flat: 'C-2A',
        amount: 1980,
        due: '2026-11-15',
        status: 'dashboard.paid',
        tone: 'success',
    },
] as const;

const rows = computed(() =>
    invoices.map((row) => ({
        cells: [
            row.number,
            row.customer,
            row.flat,
            t('module.october_period'),
            formatMoney(row.amount, locale.value),
            formatDate(row.due, locale.value),
        ],
        statusCode: row.status,
        status: t(row.status),
        tone: row.tone,
    })),
);
</script>

<template>
    <ModuleListing
        export-filename="customer-invoices.csv"
        :title="t('navigation.invoices')"
        :description="t('module.invoices_description')"
        :columns="[
            t('dashboard.invoice'),
            t('module.customer'),
            t('module.flat'),
            t('module.period'),
            t('dashboard.amount'),
            t('dashboard.due'),
        ]"
        :rows="rows"
    />
</template>
