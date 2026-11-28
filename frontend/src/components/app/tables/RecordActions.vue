<script setup lang="ts">
import { Eye, Pencil, Trash2 } from '@lucide/vue';
import type { RouteLocationRaw } from 'vue-router';
import { useLocale } from '@/composables/useLocale';

// Edit, details and delete icons for one table row. Details is a link when
// the record has its own page.
defineProps<{
    // Names the record in each button's accessible label.
    record: string;
    detailsTo?: RouteLocationRaw;
    editable?: boolean;
    removable?: boolean;
}>();
const emit = defineEmits<{ edit: []; view: []; remove: [] }>();
const { t } = useLocale();
</script>

<template>
    <div class="record-actions">
        <button
            v-if="editable"
            type="button"
            class="table-action"
            :title="t('module.edit')"
            :aria-label="t('module.edit') + ': ' + record"
            @click="emit('edit')"
        >
            <Pencil />
        </button>
        <RouterLink
            v-if="detailsTo"
            :to="detailsTo"
            class="table-action"
            :title="t('module.details')"
            :aria-label="t('module.details') + ': ' + record"
        >
            <Eye />
        </RouterLink>
        <button
            v-else
            type="button"
            class="table-action"
            :title="t('module.details')"
            :aria-label="t('module.details') + ': ' + record"
            @click="emit('view')"
        >
            <Eye />
        </button>
        <button
            v-if="removable"
            type="button"
            class="table-action hover:text-danger"
            :title="t('module.delete')"
            :aria-label="t('module.delete') + ': ' + record"
            @click="emit('remove')"
        >
            <Trash2 />
        </button>
    </div>
</template>
