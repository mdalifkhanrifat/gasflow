<script lang="ts">
import { ref } from 'vue';

const expanded = ref(new Set<string>(['navigation.platform']));
</script>

<script setup lang="ts">
import { computed, nextTick, useId, useTemplateRef, watch } from 'vue';
import { useRoute } from 'vue-router';
import SidebarSearch from '@/components/app/navigation/SidebarSearch.vue';
import { useLocale } from '@/composables/useLocale';
import {
    dashboardNavigationItem,
    navigation,
    type SearchEntry,
} from '@/config/navigation';

defineProps<{ workspace: string; compact?: boolean }>();
const { t } = useLocale();
const emit = defineEmits<{ navigate: []; profile: []; expandSearch: [] }>();
const search = useTemplateRef('sidebarSearch');
const sidebarNav = useTemplateRef<HTMLElement>('sidebarNav');
defineExpose({ focusSearch: () => search.value?.focus() });

const route = useRoute();
const sidebarId = useId();
const activeGroup = computed(
    () =>
        navigation.find((group) =>
            group.items.some(
                (item) => 'route' in item && item.route === route.name,
            ),
        )?.label,
);

watch(
    activeGroup,
    (group) => {
        if (group) {
            expanded.value.add(group);
        }
    },
    { immediate: true },
);

function toggleGroup(group: string): void {
    if (expanded.value.has(group)) {
        expanded.value.delete(group);
    } else {
        expanded.value.add(group);
    }
}

async function revealSearchItem(entry: SearchEntry): Promise<void> {
    if (!entry.group) {
        return;
    }

    expanded.value.add(entry.group);
    await nextTick();
    const item = sidebarNav.value?.querySelector<HTMLElement>(
        `[data-menu-item="${entry.label}"]`,
    );
    item?.focus({ preventScroll: true });
    item?.scrollIntoView({ block: 'nearest' });
}
</script>

<template>
    <div
        class="sidebar flex h-full min-h-0 flex-col bg-nav text-nav-ink"
        :data-compact="compact || undefined"
    >
        <div
            class="sidebar-brand flex shrink-0 items-center gap-3 px-5 pt-5 pb-4"
        >
            <span
                class="sidebar-brand-mark grid size-9 shrink-0 place-items-center font-bold text-nav-ink"
                aria-hidden="true"
                >G</span
            >
            <span v-if="!compact" class="text-base font-semibold">GasFlow</span>
        </div>
        <div
            v-if="!compact"
            class="mx-3 mb-2 shrink-0 rounded-control border border-nav-line bg-nav-workspace px-3 py-2.5"
        >
            <p
                class="text-xs font-semibold tracking-wide text-nav-muted uppercase"
            >
                {{ t('navigation.workspace') }}
            </p>
            <p class="mt-0.5 text-sm font-semibold">{{ workspace }}</p>
        </div>
        <nav
            ref="sidebarNav"
            :aria-label="t('navigation.main')"
            class="sidebar-scroll min-h-0 grow overflow-x-hidden overflow-y-auto px-3 pb-3"
        >
            <RouterLink
                :to="{ name: dashboardNavigationItem.route }"
                class="sidebar-item"
                exact-active-class="sidebar-item-active"
                :title="compact ? t(dashboardNavigationItem.label) : undefined"
                @click="emit('navigate')"
            >
                <span class="sidebar-icon" aria-hidden="true">{{
                    dashboardNavigationItem.icon
                }}</span>
                <span :class="{ 'sr-only': compact }">{{
                    t(dashboardNavigationItem.label)
                }}</span>
            </RouterLink>
            <button
                v-if="compact"
                type="button"
                class="sidebar-item mt-2"
                :aria-label="t('topbar.search')"
                :title="t('topbar.search')"
                @click="emit('expandSearch')"
            >
                <span class="sidebar-icon" aria-hidden="true">⌕</span>
            </button>
            <SidebarSearch
                v-else
                ref="sidebarSearch"
                @navigate="emit('navigate')"
                @reveal="revealSearchItem"
            />
            <section
                v-for="(group, index) in navigation"
                :key="group.label"
                class="sidebar-group"
            >
                <h2>
                    <button
                        type="button"
                        class="sidebar-item sidebar-group-trigger"
                        :class="{
                            'sidebar-group-current':
                                activeGroup === group.label,
                        }"
                        :aria-expanded="expanded.has(group.label)"
                        :aria-controls="`${sidebarId}-group-${index}`"
                        :title="compact ? t(group.label) : undefined"
                        @click="toggleGroup(group.label)"
                    >
                        <span class="sidebar-icon" aria-hidden="true">{{
                            group.icon
                        }}</span>
                        <span :class="{ 'sr-only': compact }">{{
                            t(group.label)
                        }}</span>
                        <span
                            v-if="!compact"
                            aria-hidden="true"
                            class="sidebar-group-chevron"
                            :class="{
                                'sidebar-group-chevron-open': expanded.has(
                                    group.label,
                                ),
                            }"
                        ></span>
                    </button>
                </h2>
                <ul
                    v-show="expanded.has(group.label)"
                    :id="`${sidebarId}-group-${index}`"
                    class="sidebar-submenu grid gap-0.5"
                >
                    <li v-for="item in group.items" :key="item.label">
                        <RouterLink
                            v-if="'route' in item"
                            :to="{ name: item.route }"
                            class="sidebar-item"
                            exact-active-class="sidebar-item-active"
                            :title="compact ? t(item.label) : undefined"
                            @click="emit('navigate')"
                        >
                            <span class="sidebar-icon" aria-hidden="true">{{
                                item.icon
                            }}</span>
                            <span :class="{ 'sr-only': compact }">{{
                                t(item.label)
                            }}</span>
                        </RouterLink>
                        <span
                            v-else
                            class="sidebar-item"
                            tabindex="-1"
                            :data-menu-item="item.label"
                            :title="compact ? t(item.label) : undefined"
                        >
                            <span class="sidebar-icon" aria-hidden="true">{{
                                item.icon
                            }}</span>
                            <span :class="{ 'sr-only': compact }">{{
                                t(item.label)
                            }}</span>
                        </span>
                    </li>
                </ul>
            </section>
        </nav>
        <div
            class="sidebar-footer flex shrink-0 items-center gap-2.5 border-t border-nav-line px-5 pt-3"
        >
            <span
                class="grid size-8 shrink-0 place-items-center rounded-full bg-nav-avatar text-xs font-bold text-nav-avatar-ink"
                aria-hidden="true"
                >SA</span
            >
            <div v-if="!compact">
                <p class="text-xs font-semibold">{{ t('navigation.admin') }}</p>
                <p class="text-footer text-nav-muted">
                    {{ t('navigation.platform_admin') }}
                </p>
            </div>
            <button
                v-if="!compact"
                type="button"
                class="sidebar-profile-more ml-auto grid size-7 shrink-0 place-items-center rounded-control text-lg text-nav-muted"
                :aria-label="t('topbar.account')"
                :title="t('topbar.account')"
                @click="emit('profile')"
            >
                <span aria-hidden="true">⋯</span>
            </button>
        </div>
    </div>
</template>
