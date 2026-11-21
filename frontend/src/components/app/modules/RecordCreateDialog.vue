<script lang="ts">
export interface RecordCreateDefinition {
    label: string;
    fields: readonly {
        name: string;
        label: string;
        type?: 'text' | 'month' | 'date' | 'number';
        defaultValue?: string;
        options?: readonly { value: string; label: string }[];
        min?: number | string;
        step?: number;
    }[];
    save: (values: Record<string, string>) => string | void;
}
</script>

<script setup lang="ts">
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import FormDialog from '@/components/app/forms/FormDialog.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useLocale } from '@/composables/useLocale';

const props = defineProps<{ definition: RecordCreateDefinition }>();
const emit = defineEmits<{ created: [] }>();
const { t } = useLocale();
const dialog = useTemplateRef('dialog');
const draft = ref<Record<string, string | number>>({});
const error = ref('');
const values = computed(() =>
    Object.fromEntries(
        props.definition.fields.map(({ name }) => [
            name,
            String(draft.value[name] ?? '').trim(),
        ]),
    ),
);
const canSubmit = computed(() => Object.values(values.value).every(Boolean));

async function open(): Promise<void> {
    draft.value = Object.fromEntries(
        props.definition.fields.map(({ name, defaultValue }) => [
            name,
            defaultValue ?? '',
        ]),
    );
    error.value = '';
    await nextTick();
    dialog.value?.open();
}

function submit(): void {
    if (!canSubmit.value) {
        return;
    }
    const invalidMonth = props.definition.fields.some(
        ({ name, type }) =>
            type === 'month' &&
            !/^[1-9]\d{3}-(0[1-9]|1[0-2])$/.test(values.value[name]),
    );
    if (invalidMonth) {
        error.value = t('module.invalid_month');
        return;
    }
    error.value = props.definition.save(values.value) ?? '';
    if (!error.value) {
        dialog.value?.close();
        emit('created');
    }
}

defineExpose({ open });
</script>

<template>
    <FormDialog
        ref="dialog"
        title-id="module-create-title"
        :title="definition.label"
        :description="t('module.preview_changes')"
    >
        <form class="grid gap-4" @submit.prevent="submit">
            <label
                v-for="(field, index) in definition.fields"
                :key="field.name"
                class="grid gap-1.5 text-field-label font-medium"
            >
                {{ field.label }}
                <NativeSelect
                    v-if="field.options"
                    class="w-full"
                    :name="field.name"
                    :model-value="String(draft[field.name] ?? '')"
                    :autofocus="index === 0"
                    required
                    @update:model-value="draft[field.name] = $event"
                >
                    <option
                        v-for="option in field.options"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </NativeSelect>
                <UiInput
                    v-else
                    v-model="draft[field.name]"
                    class="w-full"
                    :name="field.name"
                    :type="field.type ?? 'text'"
                    :min="field.min"
                    :step="field.step"
                    :placeholder="
                        field.type === 'month' ? 'YYYY-MM' : undefined
                    "
                    :autofocus="index === 0"
                    required
                    maxlength="120"
                />
            </label>
            <p v-if="error" role="alert" class="text-xs text-danger">
                {{ error }}
            </p>
            <div class="flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="ui-button"
                    @click="dialog?.close()"
                >
                    {{ t('module.cancel') }}
                </button>
                <button
                    type="submit"
                    class="ui-button ui-button-primary"
                    :disabled="!canSubmit"
                >
                    {{ t('module.save') }}
                </button>
            </div>
        </form>
    </FormDialog>
</template>
