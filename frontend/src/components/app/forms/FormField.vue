<script setup lang="ts">
import { computed, useId } from 'vue';

// Label, control, hint and error laid out the same in every form. The slot
// receives the attributes the control needs so assistive technology links
// the label, hint and error to it.
const props = defineProps<{
    label: string;
    hint?: string;
    error?: string;
    required?: boolean;
}>();
const id = useId();
const hintId = id + '-hint';
const errorId = id + '-error';
const control = computed(() => ({
    id,
    'aria-invalid': props.error ? ('true' as const) : undefined,
    'aria-describedby':
        [props.hint && hintId, props.error && errorId]
            .filter(Boolean)
            .join(' ') || undefined,
    required: props.required || undefined,
}));
</script>

<template>
    <div class="grid gap-1.5" :data-invalid="error ? '' : undefined">
        <label :for="id" class="text-field-label font-medium text-ink">
            {{ label }}
        </label>
        <slot :control="control" />
        <p v-if="hint" :id="hintId" class="text-field-hint text-ink-muted">
            {{ hint }}
        </p>
        <p v-if="error" :id="errorId" class="text-field-hint text-danger">
            {{ error }}
        </p>
    </div>
</template>
