<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
import { navigation } from '@/config/navigation';

defineProps<{ workspace: string }>();
const { t } = useLocale();
const emit = defineEmits<{ navigate: [] }>();
</script>

<template>
    <div class="flex h-full min-h-0 flex-col bg-nav text-nav-ink">
        <div class="flex items-center gap-3 px-5 pt-5 pb-4">
            <span
                aria-hidden="true"
                class="grid size-9 place-items-center rounded-control bg-primary font-bold text-primary-ink"
                >G</span
            >
            <span class="text-lg font-semibold">GasFlow</span>
        </div>
        <div
            class="mx-3 rounded-control border border-nav-line bg-nav-active/45 px-3 py-2.5"
        >
            <p class="text-xs text-nav-muted">
                {{ t('navigation.workspace') }}
            </p>
            <p class="mt-0.5 text-sm font-semibold">{{ workspace }}</p>
        </div>
        <nav
            :aria-label="t('navigation.main')"
            class="sidebar-scroll min-h-0 grow overflow-y-auto px-3 py-4"
        >
            <RouterLink
                :to="{ name: 'platform-dashboard' }"
                class="flex min-h-control items-center gap-2.5 rounded-control px-3 text-sm font-semibold text-nav-ink outline-offset-2 focus-visible:outline-2 focus-visible:outline-nav-focus"
                exact-active-class="bg-nav-active"
                @click="emit('navigate')"
            >
                <span class="w-4 text-center" aria-hidden="true">▦</span
                >{{ t('navigation.dashboard') }}
            </RouterLink>
            <section
                v-for="group in navigation"
                :key="group.label"
                class="mt-4"
            >
                <h2 class="px-3 text-xs font-semibold text-nav-muted">
                    {{ t(group.label) }}
                </h2>
                <ul class="mt-1">
                    <li
                        v-for="item in group.items"
                        :key="item.label"
                        class="flex items-start gap-2.5 px-3 py-1.5 text-xs text-nav-muted"
                    >
                        <span
                            class="w-4 shrink-0 text-center"
                            aria-hidden="true"
                            >{{ item.icon }}</span
                        >{{ t(item.label) }}
                    </li>
                </ul>
            </section>
        </nav>
        <div
            class="flex items-center gap-2.5 border-t border-nav-line px-5 py-3"
        >
            <span
                class="grid size-8 shrink-0 place-items-center rounded-full bg-primary-soft text-xs font-bold text-primary"
                aria-hidden="true"
                >SA</span
            >
            <div>
                <p class="text-xs font-semibold">{{ t('navigation.admin') }}</p>
                <p class="text-xs text-nav-muted">
                    {{ t('navigation.platform_admin') }} ·
                    {{ t('navigation.preview') }}
                </p>
            </div>
        </div>
    </div>
</template>
