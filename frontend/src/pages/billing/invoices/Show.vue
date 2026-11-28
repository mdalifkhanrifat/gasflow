<script setup lang="ts">
import { ArrowLeft } from '@lucide/vue';
import { computed, watchEffect } from 'vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import DataTable from '@/components/app/tables/DataTable.vue';
import { useLocale } from '@/composables/useLocale';
import AppShell from '@/layouts/AppShell.vue';
import ServiceNotice from '@/pages/errors/ServiceNotice.vue';
import {
    formatDate,
    formatMoney,
    formatMonth,
    formatNumber,
} from '@/lib/format';
import { invoiceTotal, lineAmount, previewInvoices } from './previewInvoices';

const props = defineProps<{ number: string }>();
const { locale, t } = useLocale();
const invoice = computed(() =>
    previewInvoices.value.find((record) => record.number === props.number),
);
const total = computed(() => (invoice.value ? invoiceTotal(invoice.value) : 0));
const columns = computed(() => [
    { label: t('invoice.charge') },
    { label: t('invoice.quantity'), align: 'end' as const },
    { label: t('invoice.rate'), align: 'end' as const },
    { label: t('dashboard.amount'), align: 'end' as const },
]);

watchEffect(() => {
    if (invoice.value) {
        document.title = invoice.value.number + ' - GasFlow';
    }
});

function printInvoice(): void {
    window.print();
}
</script>

<template>
    <AppShell v-if="invoice" :page-label="t('dashboard.invoice')">
        <div class="mx-auto grid max-w-5xl min-w-0 gap-4 print:max-w-none">
            <div
                class="flex flex-wrap items-center justify-between gap-2 print:hidden"
            >
                <RouterLink
                    :to="{ name: 'customer-invoices' }"
                    class="ui-button"
                >
                    <ArrowLeft />
                    {{ t('invoice.back') }}
                </RouterLink>
                <button
                    type="button"
                    class="ui-button ui-button-primary"
                    @click="printInvoice"
                >
                    {{ t('invoice.print') }}
                </button>
            </div>

            <article
                class="invoice-sheet min-w-0 rounded-panel border border-line bg-surface text-ink shadow-panel print:rounded-none print:shadow-none"
            >
                <header
                    class="flex flex-wrap items-start justify-between gap-5 border-b border-line p-4 sm:p-6 print:p-4"
                >
                    <div class="min-w-0">
                        <p
                            class="text-page-eyebrow font-bold tracking-wide text-ink-muted uppercase"
                        >
                            {{ t('invoice.demo') }}
                        </p>
                        <h1 class="mt-1 text-page-title font-bold text-ink">
                            {{ t('dashboard.invoice') }}
                        </h1>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <p class="text-field-output font-semibold text-ink">
                                {{ invoice.number }}
                            </p>
                            <UiBadge :tone="invoice.tone">{{
                                t(invoice.status)
                            }}</UiBadge>
                        </div>
                    </div>
                    <div class="flex min-w-0 items-start gap-3">
                        <span
                            aria-hidden="true"
                            class="grid size-10 shrink-0 place-items-center rounded-control bg-primary-soft text-xs font-bold text-primary"
                            >{{ invoice.issuer.initials }}</span
                        >
                        <div class="min-w-0">
                            <p class="text-field-output font-semibold text-ink">
                                {{ invoice.issuer.name }}
                            </p>
                            <address
                                class="mt-1 text-xs wrap-anywhere text-ink-muted not-italic"
                            >
                                {{ invoice.issuer.address }}<br />
                                {{ invoice.issuer.email }}
                            </address>
                        </div>
                    </div>
                </header>

                <div
                    class="grid gap-4 p-4 sm:grid-cols-2 sm:p-6 lg:grid-cols-3 print:grid-cols-3 print:p-4"
                >
                    <div class="text-xs text-ink-muted">
                        <h2 class="text-field-label font-medium">
                            {{ t('invoice.bill_to') }}
                        </h2>
                        <p
                            class="mt-1 text-field-output font-semibold text-ink"
                        >
                            {{ invoice.customer }}
                        </p>
                        <p class="mt-1">
                            {{ invoice.building }} · {{ t('module.flat') }}
                            {{ invoice.flat }}
                        </p>
                    </div>
                    <dl class="grid content-start gap-2 text-xs">
                        <div class="grid grid-cols-[1fr_auto] gap-2">
                            <dt class="text-ink-muted">
                                {{ t('module.period') }}
                            </dt>
                            <dd class="font-medium text-ink">
                                {{
                                    invoice.period
                                        ? formatMonth(invoice.period, locale)
                                        : t('module.october_period')
                                }}
                            </dd>
                        </div>
                        <div class="grid grid-cols-[1fr_auto] gap-2">
                            <dt class="text-ink-muted">
                                {{ t('invoice.issue_date') }}
                            </dt>
                            <dd class="font-medium text-ink">
                                {{
                                    invoice.issuedOn
                                        ? formatDate(
                                              invoice.issuedOn,
                                              locale,
                                              true,
                                          )
                                        : '—'
                                }}
                            </dd>
                        </div>
                        <div class="grid grid-cols-[1fr_auto] gap-2">
                            <dt class="text-ink-muted">
                                {{ t('dashboard.due') }}
                            </dt>
                            <dd class="font-medium text-ink">
                                {{ formatDate(invoice.due, locale, true) }}
                            </dd>
                        </div>
                    </dl>
                    <div
                        class="rounded-control border border-line bg-surface-muted p-3 sm:col-span-2 lg:col-span-1 print:col-span-1"
                    >
                        <p class="text-field-label font-medium text-ink-muted">
                            {{ t('invoice.balance') }}
                        </p>
                        <p class="mt-1 text-lg font-bold text-ink tabular-nums">
                            {{ formatMoney(total - invoice.paid, locale) }}
                        </p>
                    </div>
                </div>

                <section class="min-w-0 px-4 sm:px-6 print:px-4">
                    <h2 class="mb-2 text-field-output font-semibold text-ink">
                        {{ t('invoice.charges') }}
                    </h2>
                    <div
                        class="overflow-hidden rounded-panel border border-line max-[30rem]:[--table-padding-inline:0.3125rem]"
                    >
                        <DataTable
                            :label="t('invoice.charges')"
                            :columns="columns"
                            :rows="invoice.lines"
                            :row-key="(line) => line.charge"
                        >
                            <template #default="{ row: line }">
                                <th scope="row" class="text-start font-medium">
                                    {{ t(line.charge) }}
                                </th>
                                <td
                                    class="text-end whitespace-nowrap tabular-nums"
                                >
                                    {{ formatNumber(line.quantity, locale) }}
                                    <span
                                        v-if="
                                            line.charge === 'invoice.gas_usage'
                                        "
                                        >m³</span
                                    >
                                </td>
                                <td
                                    class="text-end whitespace-nowrap tabular-nums"
                                >
                                    {{ formatMoney(line.rate, locale) }}
                                </td>
                                <td
                                    class="text-end font-semibold whitespace-nowrap tabular-nums"
                                >
                                    {{ formatMoney(lineAmount(line), locale) }}
                                </td>
                            </template>
                        </DataTable>
                    </div>
                </section>

                <footer
                    class="flex justify-end p-4 sm:p-6 print:break-inside-avoid print:p-4"
                >
                    <dl class="grid w-full max-w-xs gap-2.5 text-field-label">
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-muted">
                                {{ t('invoice.total') }}
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{ formatMoney(total, locale) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-muted">
                                {{ t('invoice.payment') }}
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{ formatMoney(invoice.paid, locale) }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between gap-2 border-t border-line pt-2.5 text-field-output font-semibold"
                        >
                            <dt>{{ t('invoice.balance') }}</dt>
                            <dd class="tabular-nums">
                                {{ formatMoney(total - invoice.paid, locale) }}
                            </dd>
                        </div>
                    </dl>
                </footer>
            </article>
        </div>
    </AppShell>
    <ServiceNotice
        v-else
        mode="missing"
        :heading="t('invoice.not_found')"
        :description="t('invoice.not_found_description')"
        :return-to="{ name: 'customer-invoices' }"
    />
</template>
