<script setup lang="ts">
import { watchEffect } from 'vue';
import DashboardPanel from '@/components/app/dashboard/DashboardPanel.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { useLocale } from '@/composables/useLocale';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { formatNumber } from '@/lib/format';

const { locale, t } = useLocale();
watchEffect(() => {
    document.title = t('public.title') + ' - GasFlow';
});

const setupSteps = [
    { title: 'public.org_title', detail: 'public.org_detail' },
    {
        title: 'public.subscription_title',
        detail: 'public.subscription_detail',
    },
    { title: 'public.activation_title', detail: 'public.activation_detail' },
] as const;

const operations = [
    { name: 'public.reading', status: 'dashboard.approved', tone: 'success' },
    { name: 'public.reconciliation', status: 'cycle.review', tone: 'warning' },
    { name: 'public.invoice', status: 'dashboard.issued', tone: 'info' },
] as const;
</script>

<template>
    <PublicLayout>
        <template #actions>
            <RouterLink :to="{ name: 'sign-in' }" class="ui-button">{{
                t('auth.sign_in')
            }}</RouterLink>
        </template>
        <main class="public-stage grow">
            <section class="border-b border-line">
                <div
                    class="mx-auto grid max-w-6xl items-center gap-8 px-4 py-12 sm:px-6 lg:grid-cols-[1.15fr_1fr] lg:gap-14 lg:py-20"
                >
                    <div class="min-w-0">
                        <p
                            class="text-page-eyebrow font-bold tracking-wide text-primary uppercase"
                        >
                            {{ t('public.category') }}
                        </p>
                        <h1
                            class="mt-3 max-w-xl text-marketing-title font-bold tracking-tight text-ink"
                        >
                            {{ t('public.title') }}
                        </h1>
                        <p
                            class="mt-4 max-w-lg text-sm leading-relaxed text-ink-muted"
                        >
                            {{ t('public.description') }}
                        </p>
                        <div class="mt-6 flex flex-wrap gap-2">
                            <a
                                href="#setup"
                                class="ui-button ui-button-primary"
                                >{{ t('public.setup_link') }}</a
                            >
                            <RouterLink
                                :to="{ name: 'platform-dashboard' }"
                                class="ui-button"
                                >{{ t('public.open_preview') }}</RouterLink
                            >
                        </div>
                    </div>
                    <DashboardPanel
                        :title="t('public.operations_title')"
                        :subtitle="t('public.operations_detail')"
                    >
                        <ul class="divide-y divide-line px-4 pb-2">
                            <li
                                v-for="operation in operations"
                                :key="operation.name"
                                class="flex flex-wrap items-center justify-between gap-2 py-3"
                            >
                                <span class="text-sm text-ink">{{
                                    t(operation.name)
                                }}</span>
                                <StatusBadge :tone="operation.tone">{{
                                    t(operation.status)
                                }}</StatusBadge>
                            </li>
                        </ul>
                    </DashboardPanel>
                </div>
            </section>

            <section
                id="setup"
                class="mx-auto max-w-6xl scroll-mt-5 px-4 py-10 sm:px-6"
            >
                <h2 class="text-page-title font-bold text-ink">
                    {{ t('public.setup_title') }}
                </h2>
                <p class="mt-2 max-w-xl text-sm text-ink-muted">
                    {{ t('public.setup_detail') }}
                </p>
                <ol class="mt-5 grid gap-3 md:grid-cols-3">
                    <li
                        v-for="(step, index) in setupSteps"
                        :key="step.title"
                        class="rounded-panel border border-line bg-surface p-4 shadow-panel"
                    >
                        <span
                            class="grid size-8 place-items-center rounded-control bg-primary-soft text-xs font-bold text-primary"
                            aria-hidden="true"
                            >{{ formatNumber(index + 1, locale) }}</span
                        >
                        <h3 class="mt-3 text-sm font-semibold text-ink">
                            {{ t(step.title) }}
                        </h3>
                        <p class="mt-1 text-xs leading-relaxed text-ink-muted">
                            {{ t(step.detail) }}
                        </p>
                    </li>
                </ol>
            </section>
        </main>
    </PublicLayout>
</template>
