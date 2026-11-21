<script setup lang="ts">
import { watchEffect } from 'vue';
import DashboardPanel from '@/components/app/dashboard/DashboardPanel.vue';
import ThemeSwitcher from '@/components/app/navigation/ThemeSwitcher.vue';
import { useLocale } from '@/composables/useLocale';
import AppShell from '@/layouts/AppShell.vue';

const { t } = useLocale();
watchEffect(() => {
    document.title = t('profile.title') + ' - GasFlow';
});
</script>

<template>
    <AppShell :page-label="t('profile.title')">
        <div class="grid max-w-xl gap-4">
            <h1 class="text-page-title font-bold text-ink">
                {{ t('profile.title') }}
            </h1>
            <DashboardPanel
                :title="t('navigation.admin')"
                :subtitle="t('navigation.platform_admin')"
            >
                <p class="px-4 pb-4 text-sm text-ink-muted">
                    {{ t('profile.preview') }}
                </p>
            </DashboardPanel>
            <DashboardPanel :title="t('profile.appearance')">
                <div class="max-w-xs px-4 pb-4">
                    <ThemeSwitcher />
                </div>
            </DashboardPanel>
        </div>
    </AppShell>
</template>
