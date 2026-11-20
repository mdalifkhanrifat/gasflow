<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
import { navigation } from '@/config/navigation';

const { t } = useLocale();
const emit = defineEmits<{ navigate: [] }>();
</script>

<template>
    <div class="flex h-full min-h-0 flex-col bg-nav text-nav-ink">
        <div class="flex items-center gap-3 border-b border-nav-line px-5 py-5">
            <span
                aria-hidden="true"
                class="grid size-9 place-items-center rounded-control bg-primary font-bold text-primary-ink"
                >G</span
            >
            <span class="text-lg font-semibold">GasFlow</span>
        </div>
        <nav
            :aria-label="t('navigation.main')"
            class="min-h-0 grow overflow-y-auto px-3 py-4"
        >
            <RouterLink
                :to="{ name: 'platform-dashboard' }"
                class="flex min-h-control items-center rounded-control px-3 text-sm font-semibold text-nav-ink outline-offset-2 focus-visible:outline-2 focus-visible:outline-nav-focus"
                exact-active-class="bg-nav-active"
                @click="emit('navigate')"
            >
                {{ t('navigation.dashboard') }}
            </RouterLink>
            <section
                v-for="group in navigation"
                :key="group.label"
                class="mt-5"
            >
                <h2 class="px-3 text-xs font-semibold text-nav-muted">
                    {{ t(group.label) }}
                </h2>
                <ul class="mt-1">
                    <li
                        v-for="item in group.items"
                        :key="item"
                        class="px-3 py-1.5 text-sm text-nav-muted"
                    >
                        {{ t(item) }}
                    </li>
                </ul>
            </section>
        </nav>
        <p class="border-t border-nav-line px-5 py-4 text-xs text-nav-muted">
            {{ t('navigation.preview') }}
        </p>
    </div>
</template>
