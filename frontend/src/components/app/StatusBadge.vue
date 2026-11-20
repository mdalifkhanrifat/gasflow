<script setup lang="ts">
defineProps<{
    tone: 'info' | 'success' | 'warning' | 'danger';
    compact?: boolean;
}>();

const tones = {
    info: 'bg-primary-soft text-primary',
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-danger-soft text-danger',
};
</script>

<template>
    <span
        :class="[
            tones[tone],
            compact ? 'px-1.5 py-0.5 text-[0.5625rem]' : 'px-2.5 py-1 text-xs',
        ]"
        class="inline-flex rounded-full leading-tight font-semibold"
    >
        <slot />
    </span>
</template>
