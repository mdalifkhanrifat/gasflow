<script setup lang="ts">
import { watchEffect } from 'vue';
import { useLocale } from '@/composables/useLocale';

const { t } = useLocale();
watchEffect(() => {
    document.title = t('errors.not_found') + ' - GasFlow';
});
</script>

<template>
    <main class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
        <h1 class="text-page-title font-bold text-ink">
            {{ t('errors.not_found') }}
        </h1>
        <RouterLink
            :to="{ name: 'platform-dashboard' }"
            class="mt-4 inline-block text-primary underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
        >
            {{ t('dashboard.title') }}
        </RouterLink>
    </main>
</template>
