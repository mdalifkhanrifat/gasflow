<script lang="ts">
import { ref } from 'vue';

const desktopOpen = ref(true);
</script>

<script setup lang="ts">
import { onMounted, onUnmounted, useTemplateRef } from 'vue';
import AppSidebar from '@/components/app/navigation/AppSidebar.vue';
import AppTopbar from '@/components/app/navigation/AppTopbar.vue';
import { useLocale } from '@/composables/useLocale';
import { usePreviewWorkspace } from '@/composables/usePreviewWorkspace';

const { t } = useLocale();
defineProps<{ pageLabel: string }>();
const { workspace } = usePreviewWorkspace();
const mobileOpen = ref(false);
const drawer = useTemplateRef<HTMLDialogElement>('drawer');
const topbar = useTemplateRef('topbar');
const desktop = window.matchMedia('(min-width: 48rem)');

function openMobile(): void {
    drawer.value?.showModal();
    mobileOpen.value = true;
}

function closeMobile(): void {
    drawer.value?.close();
}

function openProfile(): void {
    closeMobile();
    topbar.value?.openProfile();
}

function closeOnDesktop(event: MediaQueryListEvent): void {
    if (event.matches) {
        closeMobile();
    }
}

onMounted(() => desktop.addEventListener('change', closeOnDesktop));
onUnmounted(() => desktop.removeEventListener('change', closeOnDesktop));
</script>

<template>
    <div class="min-h-dvh md:flex">
        <aside
            id="desktop-navigation"
            class="sticky top-0 hidden h-dvh shrink-0 motion-safe:transition-[width] motion-safe:duration-200 md:block"
            :class="
                desktopOpen
                    ? 'w-sidebar-tablet min-[68.8125rem]:w-sidebar'
                    : 'w-sidebar-rail'
            "
        >
            <AppSidebar
                :workspace="workspace"
                :compact="!desktopOpen"
                @profile="openProfile"
            />
        </aside>
        <div class="min-w-0 grow">
            <AppTopbar
                ref="topbar"
                :page-label="pageLabel"
                :desktop-open="desktopOpen"
                :mobile-open="mobileOpen"
                @toggle-desktop="desktopOpen = !desktopOpen"
                @open-mobile="openMobile"
            />
            <main class="mx-auto max-w-screen-2xl px-4 py-5 sm:px-6 lg:py-6">
                <slot />
            </main>
        </div>
        <dialog
            id="mobile-navigation"
            ref="drawer"
            :aria-label="t('navigation.main')"
            class="fixed inset-y-0 left-0 m-0 h-dvh max-h-dvh w-sidebar-drawer max-w-[calc(100%-2rem)] overflow-hidden bg-nav p-0 text-nav-ink backdrop:bg-overlay"
            @click.self="closeMobile"
            @close="mobileOpen = false"
        >
            <div class="relative h-full">
                <button
                    type="button"
                    autofocus
                    :aria-label="t('navigation.close')"
                    class="absolute top-[max(1rem,env(safe-area-inset-top))] right-3 grid size-control place-items-center rounded-control text-nav-ink focus-visible:outline-2 focus-visible:outline-nav-focus"
                    @click="closeMobile"
                >
                    <span aria-hidden="true">×</span>
                </button>
                <AppSidebar
                    :workspace="workspace"
                    @navigate="closeMobile"
                    @profile="openProfile"
                />
            </div>
        </dialog>
    </div>
</template>
