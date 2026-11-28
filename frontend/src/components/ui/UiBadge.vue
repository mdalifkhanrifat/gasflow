<script setup lang="ts">
import { statusTones, type StatusTone } from '@/config/status';

defineProps<{
    tone: StatusTone;
    compact?: boolean;
}>();
</script>

<template>
    <span
        :class="[
            statusTones[tone],
            compact
                ? 'px-1.5 py-0.5 text-status-compact'
                : 'px-2.5 py-1 text-xs',
        ]"
        class="inline-flex rounded-full leading-tight font-semibold"
    >
        <slot />
    </span>
</template>
