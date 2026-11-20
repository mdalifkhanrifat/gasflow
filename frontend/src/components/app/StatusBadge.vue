<script setup lang="ts">
defineProps<{
    tone: 'info' | 'success' | 'warning' | 'danger';
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
        :class="tones[tone]"
        class="inline-flex rounded-full px-2.5 py-1 text-xs leading-tight font-semibold"
    >
        <slot />
    </span>
</template>
