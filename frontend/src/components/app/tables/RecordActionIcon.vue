<script setup lang="ts">
defineProps<{ action: 'edit' | 'view' | 'delete' }>();
</script>

<template>
    <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <path
            v-if="action === 'edit'"
            d="M12 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-6M10 14l.6-3 7.2-7.2a1.7 1.7 0 0 1 2.4 2.4L13 13.4z"
        />
        <template v-else-if="action === 'view'">
            <path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z" />
            <circle cx="12" cy="12" r="2.5" />
        </template>
        <path v-else d="M4 7h16m-14 0 1 13h10l1-13M9 7V4h6v3m-5 3v6m4-6v6" />
    </svg>
</template>
