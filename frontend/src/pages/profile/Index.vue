<script setup lang="ts">
import { watchEffect } from 'vue';
import DashboardPanel from '@/components/app/dashboard/DashboardPanel.vue';
import PageHeading from '@/components/app/PageHeading.vue';
import ThemeSwitcher from '@/components/app/navigation/ThemeSwitcher.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import { useLocale } from '@/composables/useLocale';
import { usePreviewWorkspace } from '@/composables/usePreviewWorkspace';
import AppShell from '@/layouts/AppShell.vue';

const { t } = useLocale();
const { workspace } = usePreviewWorkspace();
watchEffect(() => {
    document.title = t('profile.title') + ' - GasFlow';
});
</script>

<template>
    <AppShell :page-label="t('profile.title')">
        <div class="grid gap-4">
            <PageHeading
                :title="t('profile.title')"
                :description="t('profile.description')"
            />
            <div
                class="grid min-w-0 items-start gap-3.5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.45fr)]"
            >
                <DashboardPanel :title="t('profile.account_title')">
                    <template #action
                        ><UiBadge tone="info">{{
                            t('profile.sample')
                        }}</UiBadge></template
                    >
                    <div class="px-4 pb-5">
                        <div
                            class="flex items-center gap-3 border-b border-line pb-4"
                        >
                            <span
                                class="grid size-12 shrink-0 place-items-center rounded-full bg-nav-avatar font-bold text-nav-avatar-ink"
                                aria-hidden="true"
                                >SA</span
                            >
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink">
                                    {{ t('navigation.admin') }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{ t('navigation.platform_admin') }}
                                </p>
                            </div>
                        </div>
                        <dl class="mt-4 grid gap-3">
                            <div>
                                <dt class="text-field-label text-ink-muted">
                                    {{ t('topbar.organization') }}
                                </dt>
                                <dd
                                    class="mt-1 text-field-output font-medium text-ink"
                                >
                                    {{ workspace }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-field-label text-ink-muted">
                                    {{ t('profile.access') }}
                                </dt>
                                <dd
                                    class="mt-1 text-field-output font-medium text-ink"
                                >
                                    {{ t('profile.access_value') }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </DashboardPanel>
                <div class="grid min-w-0 gap-3.5">
                    <DashboardPanel
                        :title="t('profile.appearance')"
                        :subtitle="t('profile.appearance_detail')"
                    >
                        <div class="max-w-xs px-4 pb-4"><ThemeSwitcher /></div>
                    </DashboardPanel>
                    <DashboardPanel
                        :title="t('profile.org_setup')"
                        :subtitle="t('profile.org_setup_detail')"
                    >
                        <div class="px-4 pb-4">
                            <RouterLink
                                :to="{ name: 'public-landing', hash: '#setup' }"
                                class="ui-button"
                                >{{ t('public.setup_link') }}</RouterLink
                            >
                        </div>
                    </DashboardPanel>
                </div>
            </div>
        </div>
    </AppShell>
</template>
