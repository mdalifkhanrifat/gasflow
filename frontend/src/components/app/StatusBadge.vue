<script setup lang="ts">
import UiBadge from '@/components/ui/UiBadge.vue';
import { useStatusCatalogue } from '@/composables/useStatusCatalogue';

// A record status: label and tone come from the database's status catalogue.
defineProps<{ entity: string; value: string; compact?: boolean }>();
const { statusLabel, statusTone } = useStatusCatalogue();
</script>

<template>
    <UiBadge :tone="statusTone(entity, value)" :compact="compact">{{
        statusLabel(entity, value)
    }}</UiBadge>
</template>
