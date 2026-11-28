<script setup lang="ts">
import { Bell, Menu, Moon, Sun } from '@lucide/vue';
import { useId, useTemplateRef } from 'vue';
import AdminAppearance from '@/components/app/navigation/AdminAppearance.vue';
import InstallAppButton from '@/components/app/navigation/InstallAppButton.vue';
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

let profileOpener: HTMLElement | undefined;

// The sidebar's account button opens this menu next to the avatar. When the
// menu closes, focus goes back to that button if it is still on screen (on
// phones the drawer has closed, so focus stays on the avatar).
function openProfile(opener: HTMLElement): void {
    profileButton.value?.focus();
    profileButton.value?.click();
    profileOpener = opener;
}

function restoreProfileFocus(event: ToggleEvent): void {
    if (event.newState !== 'closed') {
        return;
    }

    if (
        profileOpener?.checkVisibility() &&
        document.activeElement === profileButton.value
    ) {
        profileOpener.focus();
    }

    profileOpener = undefined;
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
                    <Menu />
                </button>
                <button
                    type="button"
                    aria-controls="desktop-navigation"
                    :aria-expanded="desktopOpen"
                    :aria-label="t('navigation.toggle')"
                    class="topbar-icon hidden md:inline-grid"
                    @click="emit('toggleDesktop')"
                >
                    <Menu />
                </button>
                <p class="min-w-0 truncate text-xs font-semibold text-ink">
                    {{ pageLabel }}
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-1.5 sm:gap-2.5">
                <label class="hidden min-[70rem]:block">
                    <span class="sr-only">{{ t('topbar.organization') }}</span>
                    <NativeSelect
                        class="topbar-select topbar-organization"
                        :model-value="workspace"
                        @update:model-value="selectWorkspace"
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
                        v-model="building"
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
                    class="topbar-theme topbar-icon"
                    :aria-label="
                        t(isDark ? 'topbar.use_light' : 'topbar.use_dark')
                    "
                    :title="t(isDark ? 'topbar.use_light' : 'topbar.use_dark')"
                    @click="setPreference(isDark ? 'light' : 'dark')"
                >
                    <component :is="isDark ? Sun : Moon" />
                </button>
                <AdminAppearance />
                <LanguageSwitcher />
                <button
                    type="button"
                    class="topbar-icon"
                    :aria-label="t('navigation.notifications')"
                    :title="t('navigation.notifications')"
                    :popovertarget="notificationsId"
                >
                    <Bell />
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
            @toggle="restoreProfileFocus"
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
            <InstallAppButton />
        </div>
    </header>
</template>
