<script setup lang="ts">
import { useId, useTemplateRef } from 'vue';
import LanguageSwitcher from '@/components/app/navigation/LanguageSwitcher.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { useLocale } from '@/composables/useLocale';
import { usePreviewWorkspace } from '@/composables/usePreviewWorkspace';
import { useTheme } from '@/composables/useTheme';

defineProps<{
    pageLabel: string;
    desktopOpen: boolean;
    mobileOpen: boolean;
}>();
const emit = defineEmits<{ toggleDesktop: []; openMobile: [] }>();
const { t } = useLocale();
const { workspace, workspaces, building, buildings, selectWorkspace } =
    usePreviewWorkspace();
const { isDark, setPreference } = useTheme();
const notificationsId = useId();
const profileId = useId();
const profile = useTemplateRef<HTMLElement>('profile');
const profileButton = useTemplateRef<HTMLButtonElement>('profileButton');

function openProfile(): void {
    profileButton.value?.focus();
    profileButton.value?.click();
}

defineExpose({ openProfile });
</script>

<template>
    <header class="topbar sticky top-0 z-20 border-b border-line bg-surface">
        <div class="topbar-row flex items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2.5">
                <button
                    type="button"
                    aria-controls="mobile-navigation"
                    :aria-expanded="mobileOpen"
                    :aria-label="t('navigation.open')"
                    class="topbar-icon md:hidden"
                    @click="emit('openMobile')"
                >
                    <span aria-hidden="true">☰</span>
                </button>
                <button
                    type="button"
                    aria-controls="desktop-navigation"
                    :aria-expanded="desktopOpen"
                    :aria-label="t('navigation.toggle')"
                    class="topbar-icon hidden md:inline-grid"
                    @click="emit('toggleDesktop')"
                >
                    <span aria-hidden="true">☰</span>
                </button>
                <p class="min-w-0 truncate text-xs font-semibold text-ink">
                    <span class="hidden font-normal text-ink-muted lg:inline"
                        >{{ t('navigation.workspace_label') }} / </span
                    >{{ pageLabel }}
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-1.5 sm:gap-2.5">
                <label class="hidden min-[70rem]:block">
                    <span class="sr-only">{{ t('topbar.organization') }}</span>
                    <NativeSelect
                        class="topbar-select topbar-organization"
                        :value="workspace"
                        @change="
                            selectWorkspace(
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option
                            v-for="option in workspaces"
                            :key="option.name"
                            :value="option.name"
                        >
                            {{ option.name }}
                        </option>
                    </NativeSelect>
                </label>
                <label class="hidden min-[70rem]:block">
                    <span class="sr-only">{{ t('topbar.building') }}</span>
                    <NativeSelect
                        class="topbar-select topbar-building"
                        :value="building"
                        @change="
                            building = ($event.target as HTMLSelectElement)
                                .value
                        "
                    >
                        <option value="">
                            {{ t('topbar.all_buildings') }}
                        </option>
                        <option v-for="option in buildings" :key="option">
                            {{ option }}
                        </option>
                    </NativeSelect>
                </label>
                <button
                    type="button"
                    class="topbar-icon text-base"
                    :aria-label="
                        t(isDark ? 'topbar.use_light' : 'topbar.use_dark')
                    "
                    :title="t(isDark ? 'topbar.use_light' : 'topbar.use_dark')"
                    @click="setPreference(isDark ? 'light' : 'dark')"
                >
                    <span aria-hidden="true">{{ isDark ? '☀' : '☾' }}</span>
                </button>
                <LanguageSwitcher />
                <button
                    type="button"
                    class="topbar-icon text-base"
                    :aria-label="t('navigation.notifications')"
                    :title="t('navigation.notifications')"
                    :popovertarget="notificationsId"
                >
                    <span aria-hidden="true">♧</span>
                </button>
                <button
                    type="button"
                    ref="profileButton"
                    class="topbar-avatar grid shrink-0 place-items-center rounded-full bg-nav-avatar text-xs font-bold text-nav-avatar-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    :aria-label="t('topbar.account')"
                    :title="t('topbar.account')"
                    :popovertarget="profileId"
                >
                    <span aria-hidden="true">SA</span>
                </button>
            </div>
        </div>

        <div
            :id="notificationsId"
            popover
            class="topbar-popover rounded-panel border border-line bg-surface p-4 text-ink shadow-panel"
        >
            <h2 class="text-sm font-semibold">
                {{ t('navigation.notifications') }}
            </h2>
            <p class="mt-1 text-xs text-ink-muted">
                {{ t('topbar.no_notifications') }}
            </p>
        </div>
        <div
            :id="profileId"
            ref="profile"
            popover
            class="topbar-popover rounded-panel border border-line bg-surface p-4 text-ink shadow-panel"
        >
            <h2 class="text-sm font-semibold">{{ t('navigation.admin') }}</h2>
            <p class="mb-3 text-xs text-ink-muted">
                {{ t('navigation.platform_admin') }} ·
                {{ t('navigation.preview') }}
            </p>
            <RouterLink
                :to="{ name: 'account-profile' }"
                class="block rounded-control px-2.5 py-2 text-sm hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-focus"
                @click="profile?.hidePopover()"
            >
                {{ t('profile.title') }}
            </RouterLink>
        </div>
    </header>
</template>
