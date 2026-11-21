<script setup lang="ts">
defineProps<{
    label: string;
    checked: boolean;
    indeterminate?: boolean;
}>();

const emit = defineEmits<{ change: [checked: boolean] }>();
</script>

<template>
    <label class="table-checkbox">
        <input
            type="checkbox"
            class="table-checkbox-input"
            :aria-label="label"
            :checked="checked"
            :indeterminate.prop="indeterminate ?? false"
            @change="
                emit('change', ($event.target as HTMLInputElement).checked)
            "
        />
        <span aria-hidden="true" class="table-checkbox-mark"></span>
    </label>
</template>
