<script setup lang="ts">
defineProps<{ title: string; eyebrow?: string; description?: string }>();
</script>

<template>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p
                v-if="eyebrow"
                class="text-page-eyebrow font-bold tracking-wide text-primary uppercase"
            >
                {{ eyebrow }}
            </p>
            <h1
                class="text-page-title font-bold tracking-tight text-ink"
                :class="{ 'mt-1': eyebrow }"
            >
                {{ title }}
            </h1>
            <p
                v-if="description"
                class="mt-1 text-page-subtitle text-ink-muted"
            >
                {{ description }}
            </p>
        </div>
        <slot />
    </header>
</template>
