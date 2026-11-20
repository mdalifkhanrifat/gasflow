<script setup lang="ts">
import LanguageSwitcher from '@/components/app/navigation/LanguageSwitcher.vue';
import ThemeSwitcher from '@/components/app/navigation/ThemeSwitcher.vue';
import { useLocale } from '@/composables/useLocale';

defineProps<{
    pageLabel: string;
    desktopOpen: boolean;
    mobileOpen: boolean;
}>();
const emit = defineEmits<{ toggleDesktop: []; openMobile: [] }>();
const { t } = useLocale();
</script>

<template>
    <header class="border-b border-line bg-surface">
        <div
            class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-5 gap-y-3 px-4 py-3 sm:px-6"
        >
            <div class="flex min-w-0 items-center gap-3">
                <button
                    type="button"
                    aria-controls="mobile-navigation"
                    :aria-expanded="mobileOpen"
                    :aria-label="t('navigation.open')"
                    class="inline-grid size-control shrink-0 place-items-center rounded-control border border-line text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus lg:hidden"
                    @click="emit('openMobile')"
                >
                    <span aria-hidden="true">☰</span>
                </button>
                <button
                    type="button"
                    aria-controls="desktop-navigation"
                    :aria-expanded="desktopOpen"
                    :aria-label="t('navigation.toggle')"
                    class="hidden size-control shrink-0 place-items-center rounded-control border border-line text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus lg:inline-grid"
                    @click="emit('toggleDesktop')"
                >
                    <span aria-hidden="true">☰</span>
                </button>
                <p class="min-w-0 text-sm font-medium text-ink">
                    {{ pageLabel }}
                </p>
            </div>
            <div class="grid w-full grid-cols-2 gap-3 sm:w-auto">
                <LanguageSwitcher />
                <ThemeSwitcher />
            </div>
        </div>
    </header>
</template>
