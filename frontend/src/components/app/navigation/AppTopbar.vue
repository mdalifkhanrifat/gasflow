<script setup lang="ts">
import LanguageSwitcher from '@/components/app/navigation/LanguageSwitcher.vue';
import ThemeSwitcher from '@/components/app/navigation/ThemeSwitcher.vue';
import { useLocale } from '@/composables/useLocale';

defineProps<{
    pageLabel: string;
    workspace: string;
    desktopOpen: boolean;
    mobileOpen: boolean;
}>();
const emit = defineEmits<{ toggleDesktop: []; openMobile: [] }>();
const { t } = useLocale();
</script>

<template>
    <header class="border-b border-line bg-surface lg:sticky lg:top-0 lg:z-10">
        <div
            class="mx-auto flex max-w-screen-2xl flex-wrap items-center justify-between gap-x-5 gap-y-3 px-4 py-3 sm:px-6"
        >
            <div class="flex min-w-0 items-center gap-3">
                <button
                    type="button"
                    aria-controls="mobile-navigation"
                    :aria-expanded="mobileOpen"
                    :aria-label="t('navigation.open')"
                    class="inline-grid size-control shrink-0 place-items-center rounded-control border border-line text-ink hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus md:hidden"
                    @click="emit('openMobile')"
                >
                    <span aria-hidden="true">☰</span>
                </button>
                <button
                    type="button"
                    aria-controls="desktop-navigation"
                    :aria-expanded="desktopOpen"
                    :aria-label="t('navigation.toggle')"
                    class="hidden size-control shrink-0 place-items-center rounded-control border border-line text-ink hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus md:inline-grid"
                    @click="emit('toggleDesktop')"
                >
                    <span aria-hidden="true">☰</span>
                </button>
                <p class="min-w-0 text-sm font-medium text-ink">
                    <span class="hidden text-ink-muted md:inline"
                        >{{ t('navigation.workspace_label') }} / </span
                    >{{ pageLabel }}
                </p>
            </div>
            <div
                class="flex w-full min-w-0 flex-wrap items-center gap-2 sm:w-auto"
            >
                <span class="preview-control hidden xl:inline-flex">{{
                    workspace
                }}</span>
                <div class="grid min-w-0 grow grid-cols-2 gap-2">
                    <LanguageSwitcher />
                    <ThemeSwitcher />
                </div>
            </div>
        </div>
    </header>
</template>
