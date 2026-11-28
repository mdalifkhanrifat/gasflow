<script setup lang="ts">
import {
    Activity,
    AlarmClock,
    ArrowLeftRight,
    ArrowRight,
    Banknote,
    Building,
    Building2,
    Gauge,
    Package,
    Plus,
} from '@lucide/vue';
import { ref, useTemplateRef, watchEffect } from 'vue';
import DashboardPanel from '@/components/app/dashboard/DashboardPanel.vue';
import StatCard from '@/components/app/dashboard/StatCard.vue';
import PageHeading from '@/components/app/PageHeading.vue';
import FormDialog from '@/components/app/forms/FormDialog.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useLocale } from '@/composables/useLocale';
import { useToast } from '@/composables/useToast';
import AppShell from '@/layouts/AppShell.vue';
import { formatMoney, formatNumber } from '@/lib/format';
import CollectionsChart from './components/CollectionsChart.vue';
import InvoiceStatusChart from './components/InvoiceStatusChart.vue';
import RecentInvoices from './components/RecentInvoices.vue';

const { locale, t } = useLocale();
const { notify } = useToast();
watchEffect(() => {
    document.title = t('dashboard.title') + ' - GasFlow';
});

const cycleDialog = useTemplateRef('cycleDialog');
const cycleMonth = ref('2026-11');

const metrics = [
    {
        label: 'dashboard.organizations',
        value: 18,
        note: 'dashboard.organizations_note',
        icon: Building2,
        money: false,
    },
    {
        label: 'dashboard.buildings',
        value: 42,
        note: 'dashboard.buildings_note',
        icon: Building,
        money: false,
    },
    {
        label: 'dashboard.meters',
        value: 1284,
        note: 'dashboard.meters_note',
        icon: Gauge,
        money: false,
    },
    {
        label: 'dashboard.monthly_collected',
        value: 842000,
        note: 'dashboard.collected_note',
        icon: Banknote,
        money: true,
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
        icon: ArrowLeftRight,
    },
    {
        label: 'dashboard.attention_readings',
        detail: 'dashboard.attention_readings_detail',
        icon: Activity,
    },
    {
        label: 'dashboard.attention_overdue',
        detail: 'dashboard.attention_overdue_detail',
        icon: AlarmClock,
    },
    {
        label: 'dashboard.attention_renewal',
        detail: 'dashboard.attention_renewal_detail',
        icon: Package,
    },
] as const;

function savePreview(): void {
    cycleDialog.value?.close();
    notify(t('dashboard.cycle_preview_saved'));
}
</script>

<template>
    <AppShell :page-label="t('navigation.dashboard')">
        <div class="grid gap-3.5">
            <PageHeading :title="t('dashboard.title')">
                <button
                    type="button"
                    class="ui-button ui-button-primary self-center"
                    @click="cycleDialog?.open()"
                >
                    <Plus />{{ t('dashboard.create_cycle') }}
                </button>
            </PageHeading>

            <dl
                class="grid grid-cols-1 gap-3.5 min-[25rem]:grid-cols-2 xl:grid-cols-4"
            >
                <StatCard
                    v-for="metric in metrics"
                    :key="metric.label"
                    :label="t(metric.label)"
                    :value="
                        metric.money
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
                        ><RouterLink
                            :to="{ name: 'customer-invoices' }"
                            class="ui-button"
                            >{{ t('dashboard.view_invoices') }}
                            <ArrowRight /></RouterLink
                    ></template>
                    <InvoiceStatusChart />
                </DashboardPanel>
            </div>

            <DashboardPanel
                :title="t('dashboard.pipeline_title')"
                :subtitle="t('dashboard.pipeline_subtitle')"
            >
                <template #action
                    ><UiBadge tone="info">{{
                        t('dashboard.cycle_october')
                    }}</UiBadge></template
                >
                <ol class="flex flex-wrap gap-2 px-4 pb-4">
                    <li
                        v-for="(step, index) in pipeline"
                        :key="step.label"
                        class="flex flex-wrap items-center gap-1.5 rounded-control border border-line bg-surface-muted px-2.5 py-2 text-xs text-ink-muted"
                    >
                        <span
                            aria-hidden="true"
                            class="grid size-4 place-items-center rounded-full border border-line text-chart-label"
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
                        ><UiBadge tone="warning">{{
                            t('dashboard.attention_count')
                        }}</UiBadge></template
                    >
                    <ul class="grid gap-3 px-4 pb-4">
                        <li
                            v-for="item in attention"
                            :key="item.label"
                            class="flex items-start gap-2.5"
                        >
                            <span
                                class="grid size-7 shrink-0 place-items-center rounded-control bg-primary-soft text-primary"
                                ><component :is="item.icon"
                            /></span>
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
            <p class="text-footer text-ink-muted">
                {{ t('dashboard.preview') }}
            </p>
        </div>

        <FormDialog
            ref="cycleDialog"
            title-id="cycle-title"
            :title="t('dashboard.create_cycle')"
            :description="t('dashboard.cycle_preview')"
        >
            <form class="grid gap-4" @submit.prevent="savePreview">
                <label class="grid gap-1 text-field-label">
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
