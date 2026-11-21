<script setup lang="ts">
defineProps<{ title: string; subtitle?: string }>();
</script>

<template>
    <section
        class="min-w-0 rounded-panel border border-line bg-surface shadow-panel"
    >
        <header
            class="flex flex-wrap items-start justify-between gap-2 px-4 pt-4 pb-3"
        >
            <div>
                <h2 class="text-panel-title font-semibold text-ink">
                    {{ title }}
                </h2>
                <p
                    v-if="subtitle"
                    class="mt-0.5 text-panel-subtitle text-ink-muted"
                >
                    {{ subtitle }}
                </p>
            </div>
            <slot name="action" />
        </header>
        <slot />
    </section>
</template>
