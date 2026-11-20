<script setup lang="ts">
defineProps<{ label: string; value: string; note?: string; icon?: string }>();
</script>

<template>
    <div
        class="min-w-0 rounded-panel border border-line bg-surface px-4 py-3.5 shadow-panel"
    >
        <div class="flex items-start justify-between gap-2">
            <dt class="pt-1 text-xs font-medium text-ink-muted">{{ label }}</dt>
            <span
                v-if="icon"
                class="grid size-8 shrink-0 place-items-center rounded-control bg-primary-soft text-primary"
                aria-hidden="true"
                >{{ icon }}</span
            >
        </div>
        <dd
            class="mt-1.5 text-2xl font-bold tracking-tight text-ink tabular-nums"
        >
            {{ value }}
        </dd>
        <dd v-if="note" class="mt-1 text-xs text-success">{{ note }}</dd>
    </div>
</template>
