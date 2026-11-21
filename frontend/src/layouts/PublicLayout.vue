<script setup lang="ts">
import BrandLogo from '@/components/app/BrandLogo.vue';
import LanguageSwitcher from '@/components/app/navigation/LanguageSwitcher.vue';
import { useLocale } from '@/composables/useLocale';
import { useTheme } from '@/composables/useTheme';

const { t } = useLocale();
const { isDark, setPreference } = useTheme();
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-background">
        <header class="border-b border-line bg-surface">
            <div
                class="mx-auto flex min-h-16 max-w-6xl items-center justify-between gap-3 px-4 sm:px-6"
            >
                <RouterLink
                    :to="{ name: 'public-landing' }"
                    class="inline-flex rounded-control text-ink focus-visible:outline-2 focus-visible:outline-focus"
                >
                    <BrandLogo />
                </RouterLink>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="topbar-icon"
                        :aria-label="
                            t(isDark ? 'topbar.use_light' : 'topbar.use_dark')
                        "
                        @click="setPreference(isDark ? 'light' : 'dark')"
                    >
                        <span aria-hidden="true">{{ isDark ? '☀' : '☾' }}</span>
                    </button>
                    <LanguageSwitcher />
                    <slot name="actions" />
                </div>
            </div>
        </header>
        <slot />
        <footer class="border-t border-line bg-surface">
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-4 text-footer text-ink-muted sm:px-6"
            >
                <BrandLogo class="brand-logo-small text-ink" />
                <RouterLink
                    :to="{ name: 'public-landing', hash: '#setup' }"
                    class="underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-focus"
                    >{{ t('public.setup_link') }}</RouterLink
                >
            </div>
        </footer>
    </div>
</template>
