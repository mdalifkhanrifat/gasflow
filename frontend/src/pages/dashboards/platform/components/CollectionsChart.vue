<script setup lang="ts">
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';

const { locale, t } = useLocale();
const collected = [43, 56, 50, 66, 59, 78, 68, 82, 73, 92, 84, 96];
const months = computed(() => {
    const monthFormat = new Intl.DateTimeFormat(
        locale.value === 'bn' ? 'bn-BD' : 'en-GB',
        {
            month: 'short',
            timeZone: 'UTC',
        },
    );
    return collected.map((total, index) => ({
        label: monthFormat.format(new Date(Date.UTC(2026, index, 1))),
        invoiced: Math.round(total * 0.72),
        collected: total,
    }));
});
</script>

<template>
    <figure class="px-4 pb-4">
        <div class="overflow-x-auto">
            <div
                class="chart-grid grid h-44 min-w-72 grid-cols-12 gap-2 border-b border-line px-1"
            >
                <div
                    v-for="month in months"
                    :key="month.label"
                    class="flex min-w-0 items-end gap-0.5"
                >
                    <div
                        class="basis-1/2 rounded-t-sm bg-chart-secondary"
                        :style="{ height: month.invoiced + '%' }"
                        :title="month.label + ' · ' + t('dashboard.invoiced')"
                    ></div>
                    <div
                        class="basis-1/2 rounded-t-sm bg-chart-primary"
                        :style="{ height: month.collected + '%' }"
                        :title="month.label + ' · ' + t('dashboard.collected')"
                    ></div>
                </div>
            </div>
            <div
                class="grid min-w-72 grid-cols-12 gap-2 px-1 pt-2 text-center text-[0.625rem] text-ink-muted"
            >
                <span v-for="month in months" :key="month.label">{{
                    month.label
                }}</span>
            </div>
        </div>
        <figcaption class="mt-4 flex flex-wrap gap-4 text-xs text-ink-muted">
            <span class="flex items-center gap-1.5">
                <i
                    class="size-2 rounded-full bg-chart-primary"
                    aria-hidden="true"
                ></i
                >{{ t('dashboard.collected') }}
            </span>
            <span class="flex items-center gap-1.5">
                <i
                    class="size-2 rounded-full bg-chart-secondary"
                    aria-hidden="true"
                ></i
                >{{ t('dashboard.invoiced') }}
            </span>
        </figcaption>
    </figure>
</template>
