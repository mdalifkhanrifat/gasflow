<script setup lang="ts">
import { CircleAlert, Inbox, LoaderCircle, Lock } from '@lucide/vue';

export type PanelState = 'empty' | 'loading' | 'error' | 'forbidden';

// What a panel shows instead of its content: nothing found, still loading,
// failed to load, or not allowed. The action slot holds a retry or similar.
const props = defineProps<{
    state: PanelState;
    title: string;
    description?: string;
}>();
const icons = {
    empty: Inbox,
    loading: LoaderCircle,
    error: CircleAlert,
    forbidden: Lock,
};
</script>

<template>
    <div
        class="state-panel"
        :data-state="state"
        :role="props.state === 'error' ? 'alert' : 'status'"
        :aria-busy="state === 'loading' || undefined"
    >
        <span class="state-panel-icon"><component :is="icons[state]" /></span>
        <p class="font-semibold text-ink">{{ title }}</p>
        <p v-if="description" class="text-ink-muted">{{ description }}</p>
        <div v-if="$slots.default" class="mt-2">
            <slot />
        </div>
    </div>
</template>
