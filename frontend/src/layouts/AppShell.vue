<script lang="ts">
import { ref } from 'vue';

const desktopOpen = ref(true);
</script>

<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, useTemplateRef } from 'vue';
import AppSidebar from '@/components/app/navigation/AppSidebar.vue';
import AppTopbar from '@/components/app/navigation/AppTopbar.vue';
import { useAdminAppearance } from '@/composables/useAdminAppearance';
import { useLocale } from '@/composables/useLocale';
import { usePreviewWorkspace } from '@/composables/usePreviewWorkspace';

const { t } = useLocale();
const { accent, background, heading, tableText, sidebarText } =
    useAdminAppearance();
const copyrightYear = String(new Date().getFullYear());
defineProps<{ pageLabel: string }>();
const { workspace } = usePreviewWorkspace();
const mobileOpen = ref(false);
const drawer = useTemplateRef<HTMLDialogElement>('drawer');
const topbar = useTemplateRef('topbar');
const sidebar = useTemplateRef('sidebar');

async function expandSearch(): Promise<void> {
    desktopOpen.value = true;
    await nextTick();
    sidebar.value?.focusSearch();
}
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
    <div
        class="relative isolate min-h-dvh md:flex print:block print:min-h-0"
        :data-admin-accent="accent"
        :data-heading-size="heading"
        :data-table-text="tableText"
        :data-sidebar-text="sidebarText"
    >
        <div
            aria-hidden="true"
            class="public-stage shell-backdrop pointer-events-none absolute inset-0 -z-10 print:hidden"
            :data-background-preset="background"
        ></div>
        <aside
            id="desktop-navigation"
            class="sticky top-0 hidden h-dvh shrink-0 motion-safe:transition-[width] motion-safe:duration-200 md:block print:hidden"
            :class="
                desktopOpen
                    ? 'w-sidebar-tablet min-[68.8125rem]:w-sidebar'
                    : 'w-sidebar-rail'
            "
        >
            <AppSidebar
                ref="sidebar"
                @expand-search="expandSearch"
                :workspace="workspace"
                :compact="!desktopOpen"
                @profile="openProfile"
            />
        </aside>
        <div
            class="flex min-h-dvh min-w-0 grow flex-col print:block print:min-h-0"
        >
            <AppTopbar
                ref="topbar"
                class="print:hidden"
                :page-label="pageLabel"
                :desktop-open="desktopOpen"
                :mobile-open="mobileOpen"
                @toggle-desktop="desktopOpen = !desktopOpen"
                @open-mobile="openMobile"
            />
            <main
                class="shell-content py-5 lg:py-6 print:m-0 print:max-w-none print:p-0"
            >
                <slot />
            </main>
            <footer
                class="shell-content mt-auto py-4 text-footer text-ink-muted print:hidden"
            >
                {{ t('footer.copyright').replace('{year}', copyrightYear) }}
            </footer>
        </div>
        <dialog
            id="mobile-navigation"
            ref="drawer"
            :aria-label="t('navigation.main')"
            class="fixed inset-y-0 left-0 m-0 h-dvh max-h-dvh w-sidebar-drawer max-w-[calc(100%-2rem)] overflow-hidden bg-nav p-0 text-nav-ink backdrop:bg-overlay print:hidden"
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
