<script setup lang="ts">
defineProps<{
    label: string;
    value: string;
}>();
</script>

<template>
    <div
        class="min-w-0 rounded-panel border border-line bg-surface p-5 shadow-panel"
    >
        <dt class="text-sm font-medium text-ink-muted">{{ label }}</dt>
        <dd class="mt-2 text-3xl font-semibold text-ink tabular-nums">
            {{ value }}
        </dd>
    </div>
</template>
