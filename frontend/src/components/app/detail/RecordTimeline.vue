<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
import { formatDate } from '@/lib/format';

export interface TimelineEntry {
    key: string;
    // ISO date or date-time.
    at: string;
    title: string;
    detail?: string;
}

// What happened to a record, oldest first. The History tab of every record
// page uses it; Phase 5 feeds it from the status and data change logs.
defineProps<{ entries: readonly TimelineEntry[] }>();
const { locale } = useLocale();
</script>

<template>
    <ol class="record-timeline">
        <li
            v-for="entry in entries"
            :key="entry.key"
            class="record-timeline-item"
        >
            <time :datetime="entry.at" class="text-ink-muted">{{
                formatDate(entry.at, locale, true)
            }}</time>
            <p class="font-semibold text-ink">{{ entry.title }}</p>
            <p v-if="entry.detail" class="text-ink-muted">{{ entry.detail }}</p>
        </li>
    </ol>
</template>
