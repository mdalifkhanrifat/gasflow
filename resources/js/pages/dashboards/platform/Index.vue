<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import StatCard from '@/components/app/dashboard/StatCard.vue';
import LanguageSwitcher from '@/components/app/navigation/LanguageSwitcher.vue';
import ThemeSwitcher from '@/components/app/navigation/ThemeSwitcher.vue';
import { formatNumber } from '@/lib/format';

const page = usePage();
const metrics = [
    { label: 'dashboard.organizations', value: 12 },
    { label: 'dashboard.buildings', value: 48 },
    { label: 'dashboard.open_invoices', value: 126 },
];
</script>

<template>
    <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-10 lg:px-8">
        <Head :title="$t('dashboard.title')" />
        <header
            class="flex flex-col gap-5 border-b border-line pb-6 md:flex-row md:items-end md:justify-between"
        >
            <div>
                <p class="text-sm font-semibold text-primary">
                    {{ page.props.name }}
                </p>
                <h1 class="mt-2 text-3xl font-semibold text-ink">
                    {{ $t('dashboard.title') }}
                </h1>
                <p class="mt-2 text-ink-muted">
                    {{ $t('dashboard.description') }}
                </p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <LanguageSwitcher />
                <ThemeSwitcher />
            </div>
        </header>
        <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <StatCard
                v-for="metric in metrics"
                :key="metric.label"
                :label="$t(metric.label)"
                :value="formatNumber(metric.value, page.props.locale)"
            />
        </dl>
        <p class="mt-4 text-sm text-ink-muted">{{ $t('dashboard.preview') }}</p>
    </main>
</template>
