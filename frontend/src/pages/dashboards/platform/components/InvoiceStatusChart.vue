<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
import { useStatusCatalogue } from '@/composables/useStatusCatalogue';
import { formatNumber } from '@/lib/format';

const { locale, t } = useLocale();
const { statusLabel } = useStatusCatalogue();
const statuses = [
    { code: 'paid', value: 47, color: 'bg-chart-primary' },
    { code: 'partially_paid', value: 26, color: 'bg-chart-success' },
    { code: 'overdue', value: 17, color: 'bg-chart-warning' },
    { code: 'draft', value: 10, color: 'bg-chart-neutral' },
] as const;
</script>

<template>
    <div
        class="flex flex-wrap items-center justify-center gap-x-6 gap-y-4 px-4 pt-5 pb-7"
    >
        <div
            class="invoice-status-chart grid size-34 shrink-0 place-items-center rounded-full p-5"
        >
            <div
                class="grid size-full place-content-center rounded-full bg-surface text-center"
            >
                <strong class="text-xl font-bold text-ink">{{
                    formatNumber(1284, locale)
                }}</strong>
                <span class="text-xs text-ink-muted">{{
                    t('dashboard.invoices')
                }}</span>
            </div>
        </div>
        <ul class="grid min-w-32 gap-2.5 text-xs text-ink-muted">
            <li
                v-for="status in statuses"
                :key="status.code"
                class="flex items-center gap-2"
            >
                <i
                    :class="status.color"
                    class="size-2 rounded-full"
                    aria-hidden="true"
                ></i>
                <span class="grow">{{
                    statusLabel('invoices', status.code)
                }}</span>
                <b class="text-ink"
                    >{{ formatNumber(status.value, locale) }}%</b
                >
            </li>
        </ul>
    </div>
</template>
