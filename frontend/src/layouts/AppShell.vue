<script setup lang="ts">
import { onMounted, onUnmounted, ref, useTemplateRef } from 'vue';
import AppSidebar from '@/components/app/navigation/AppSidebar.vue';
import AppTopbar from '@/components/app/navigation/AppTopbar.vue';
import { useLocale } from '@/composables/useLocale';

const { t } = useLocale();
defineProps<{ pageLabel: string; workspace: string }>();
const desktopOpen = ref(true);
const mobileOpen = ref(false);
const drawer = useTemplateRef<HTMLDialogElement>('drawer');
const desktop = window.matchMedia('(min-width: 64rem)');

function openMobile(): void {
    drawer.value?.showModal();
    mobileOpen.value = true;
}

function closeMobile(): void {
    drawer.value?.close();
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
    <div class="min-h-dvh lg:flex">
        <aside
            v-show="desktopOpen"
            id="desktop-navigation"
            class="sticky top-0 hidden h-dvh w-sidebar shrink-0 lg:block"
        >
            <AppSidebar :workspace="workspace" />
        </aside>
        <div class="min-w-0 grow">
            <AppTopbar
                :page-label="pageLabel"
                :workspace="workspace"
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
            class="fixed inset-y-0 left-0 m-0 h-dvh max-h-dvh w-sidebar max-w-[calc(100%-2rem)] overflow-hidden bg-nav p-0 text-nav-ink backdrop:bg-overlay"
            @click.self="closeMobile"
            @close="mobileOpen = false"
        >
            <div class="relative h-full">
                <button
                    type="button"
                    autofocus
                    :aria-label="t('navigation.close')"
                    class="absolute top-4 right-3 grid size-control place-items-center rounded-control text-nav-ink focus-visible:outline-2 focus-visible:outline-nav-focus"
                    @click="closeMobile"
                >
                    <span aria-hidden="true">×</span>
                </button>
                <AppSidebar :workspace="workspace" @navigate="closeMobile" />
            </div>
        </dialog>
    </div>
</template>
