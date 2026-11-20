<script setup lang="ts">
import { ref, useTemplateRef, watchEffect } from 'vue';
import DashboardPanel from '@/components/app/dashboard/DashboardPanel.vue';
import StatCard from '@/components/app/dashboard/StatCard.vue';
import FormDialog from '@/components/app/forms/FormDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useLocale } from '@/composables/useLocale';
import AppShell from '@/layouts/AppShell.vue';
import { formatMoney, formatNumber } from '@/lib/format';
import CollectionsChart from './components/CollectionsChart.vue';
import InvoiceStatusChart from './components/InvoiceStatusChart.vue';
import RecentInvoices from './components/RecentInvoices.vue';

const { locale, t } = useLocale();
watchEffect(() => {
    document.title = t('dashboard.title') + ' - GasFlow';
});

const cycleDialog = useTemplateRef('cycleDialog');
const cycleMonth = ref('2026-11');
const previewSaved = ref(false);

const metrics = [
    {
        label: 'dashboard.organizations',
        value: 18,
        note: 'dashboard.organizations_note',
        icon: '▣',
    },
    {
        label: 'dashboard.buildings',
        value: 42,
        note: 'dashboard.buildings_note',
        icon: '⌂',
    },
    {
        label: 'dashboard.meters',
        value: 1284,
        note: 'dashboard.meters_note',
        icon: '◉',
    },
    {
        label: 'dashboard.monthly_collected',
        value: 842000,
        note: 'dashboard.collected_note',
        icon: '৳',
    },
] as const;

const pipeline = [
    { label: 'dashboard.submitted', value: 1240, total: 1284 },
    { label: 'dashboard.approved', value: 1198 },
    { label: 'dashboard.reconciliation', value: 38, total: 42 },
    { label: 'dashboard.billing_ready', value: 36 },
    { label: 'dashboard.issued', value: 1102 },
] as const;

const attention = [
    {
        label: 'dashboard.attention_reconcile',
        detail: 'dashboard.attention_reconcile_detail',
        icon: '⇄',
    },
    {
        label: 'dashboard.attention_readings',
        detail: 'dashboard.attention_readings_detail',
        icon: '⌁',
    },
    {
        label: 'dashboard.attention_overdue',
        detail: 'dashboard.attention_overdue_detail',
        icon: '৳',
    },
    {
        label: 'dashboard.attention_renewal',
        detail: 'dashboard.attention_renewal_detail',
        icon: '◇',
    },
] as const;

function savePreview(): void {
    previewSaved.value = true;
    cycleDialog.value?.close();
}
</script>

<template>
    <AppShell :page-label="t('navigation.dashboard')">
        <div class="grid gap-3.5">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p
                        class="text-xs font-bold tracking-wide text-primary uppercase"
                    >
                        {{ t('dashboard.overview') }}
                    </p>
                    <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink">
                        {{ t('dashboard.title') }}
                    </h1>
                    <p class="mt-1 text-xs text-ink-muted">
                        {{ t('dashboard.description') }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="ui-button">{{
                        t('dashboard.current_period')
                    }}</span>
                    <button
                        type="button"
                        class="ui-button ui-button-primary"
                        @click="cycleDialog?.open()"
                    >
                        <span aria-hidden="true">+</span
                        >{{ t('dashboard.create_cycle') }}
                    </button>
                </div>
            </header>

            <p
                v-if="previewSaved"
                role="status"
                class="rounded-control bg-success-soft px-3 py-2 text-sm text-success"
            >
                {{ t('dashboard.cycle_preview_saved') }}
            </p>

            <dl
                class="grid grid-cols-1 gap-3.5 min-[25rem]:grid-cols-2 xl:grid-cols-4"
            >
                <StatCard
                    v-for="metric in metrics"
                    :key="metric.label"
                    :label="t(metric.label)"
                    :value="
                        metric.icon === '৳'
                            ? formatMoney(metric.value, locale)
                            : formatNumber(metric.value, locale)
                    "
                    :note="t(metric.note)"
                    :icon="metric.icon"
                />
            </dl>

            <div
                class="grid min-w-0 gap-3.5 xl:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]"
            >
                <DashboardPanel
                    :title="t('dashboard.collections_title')"
                    :subtitle="t('dashboard.collections_subtitle')"
                >
                    <template #action
                        ><span class="ui-button">{{
                            (2026).toLocaleString(locale, {
                                useGrouping: false,
                            })
                        }}</span></template
                    >
                    <CollectionsChart />
                </DashboardPanel>

                <DashboardPanel
                    :title="t('dashboard.invoice_status_title')"
                    :subtitle="t('dashboard.invoice_status_subtitle')"
                >
                    <template #action
                        ><a
                            :to="{ name: 'customer-invoices' }"
                            class="ui-button"
                            >{{ t('dashboard.view_invoices') }}
                            <span aria-hidden="true">→</span></a
                        ></template
                    >
                    <InvoiceStatusChart />
                </DashboardPanel>
            </div>

            <DashboardPanel
                :title="t('dashboard.pipeline_title')"
                :subtitle="t('dashboard.pipeline_subtitle')"
            >
                <template #action
                    ><StatusBadge tone="info">{{
                        t('dashboard.cycle_october')
                    }}</StatusBadge></template
                >
                <ol class="flex flex-wrap gap-2 px-4 pb-4">
                    <li
                        v-for="(step, index) in pipeline"
                        :key="step.label"
                        class="flex flex-wrap items-center gap-1.5 rounded-control border border-line bg-surface-muted px-2.5 py-2 text-xs text-ink-muted"
                    >
                        <span
                            aria-hidden="true"
                            class="grid size-4 place-items-center rounded-full border border-line text-[0.625rem]"
                            >{{ formatNumber(index + 1, locale) }}</span
                        >
                        {{ t(step.label) }}
                        <strong class="text-ink tabular-nums"
                            >{{ formatNumber(step.value, locale)
                            }}<template v-if="'total' in step">
                                /
                                {{ formatNumber(step.total, locale) }}</template
                            ></strong
                        >
                    </li>
                </ol>
            </DashboardPanel>

            <div
                class="grid min-w-0 items-start gap-3.5 xl:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)]"
            >
                <DashboardPanel
                    id="recent-invoices"
                    class="scroll-mt-20"
                    :title="t('dashboard.recent')"
                    :subtitle="t('dashboard.recent_subtitle')"
                >
                    <RecentInvoices />
                </DashboardPanel>

                <DashboardPanel
                    :title="t('dashboard.attention_title')"
                    :subtitle="t('dashboard.attention_subtitle')"
                >
                    <template #action
                        ><StatusBadge tone="warning">{{
                            t('dashboard.attention_count')
                        }}</StatusBadge></template
                    >
                    <ul class="grid gap-3 px-4 pb-4">
                        <li
                            v-for="item in attention"
                            :key="item.label"
                            class="flex items-start gap-2.5"
                        >
                            <span
                                class="grid size-7 shrink-0 place-items-center rounded-control bg-primary-soft text-primary"
                                aria-hidden="true"
                                >{{ item.icon }}</span
                            >
                            <div>
                                <p class="text-xs font-semibold text-ink">
                                    {{ t(item.label) }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{ t(item.detail) }}
                                </p>
                            </div>
                        </li>
                    </ul>
                </DashboardPanel>
            </div>
            <p class="text-xs text-ink-muted">{{ t('dashboard.preview') }}</p>
        </div>

        <FormDialog
            ref="cycleDialog"
            title-id="cycle-title"
            :title="t('dashboard.create_cycle')"
            :description="t('dashboard.cycle_preview')"
        >
            <form class="grid gap-4" @submit.prevent="savePreview">
                <label class="grid gap-1 text-sm">
                    {{ t('dashboard.billing_month') }}
                    <UiInput
                        v-model="cycleMonth"
                        autofocus
                        required
                        type="month"
                        class="w-full"
                    />
                </label>
                <div class="flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        class="ui-button"
                        @click="cycleDialog?.close()"
                    >
                        {{ t('dashboard.cancel') }}
                    </button>
                    <button type="submit" class="ui-button ui-button-primary">
                        {{ t('dashboard.save_preview') }}
                    </button>
                </div>
            </form>
        </FormDialog>
    </AppShell>
</template>
