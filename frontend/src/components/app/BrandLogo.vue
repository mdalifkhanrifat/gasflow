<script setup lang="ts">
defineProps<{ compact?: boolean }>();
</script>

<template>
    <span class="brand-logo inline-flex shrink-0 items-center">
        <img
            v-if="compact"
            src="/favicon.svg?v=gasflow-blue"
            alt="GasFlow"
            width="36"
            height="36"
            class="size-9 shrink-0"
        />
        <span v-else class="brand-wordmark" role="img" aria-label="GasFlow" />
    </span>
</template>
