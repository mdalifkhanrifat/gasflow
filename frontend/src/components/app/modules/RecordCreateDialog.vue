<script lang="ts">
export interface RecordCreateDefinition {
    label: string;
    // Use a sheet for longer forms.
    layout?: 'dialog' | 'sheet';
    fields: readonly {
        name: string;
        label: string;
        type?: 'text' | 'month' | 'date' | 'number';
        defaultValue?: string;
        options?: readonly { value: string; label: string }[];
        min?: number | string;
        step?: number;
    }[];
    // Field name => message for checks the page makes, such as duplicates.
    save: (values: Record<string, string>) => Record<string, string> | void;
}
</script>

<script setup lang="ts">
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import FormDialog from '@/components/app/forms/FormDialog.vue';
import FormField from '@/components/app/forms/FormField.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useFormErrors } from '@/composables/useFormErrors';
import { useLocale } from '@/composables/useLocale';

const props = defineProps<{ definition: RecordCreateDefinition }>();
const emit = defineEmits<{ created: [] }>();
const { t } = useLocale();
const dialog = useTemplateRef('dialog');
const draft = ref<Record<string, string | number>>({});
const { errors, clear, set, focusFirstError } = useFormErrors();
const form = useTemplateRef('form');
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
    clear();
    await nextTick();
    dialog.value?.open();
}

function submit(): void {
    if (!canSubmit.value) {
        return;
    }
    clear();
    for (const { name, type } of props.definition.fields) {
        if (
            type === 'month' &&
            !/^[1-9]\d{3}-(0[1-9]|1[0-2])$/.test(values.value[name])
        ) {
            set(name, t('module.invalid_month'));
        }
    }
    if (!Object.keys(errors.value).length) {
        for (const [field, message] of Object.entries(
            props.definition.save(values.value) ?? {},
        )) {
            set(field, message);
        }
    }
    if (Object.keys(errors.value).length) {
        void focusFirstError(form.value);
        return;
    }
    dialog.value?.close();
    emit('created');
}

defineExpose({ open });
</script>

<template>
    <FormDialog
        ref="dialog"
        title-id="module-create-title"
        :title="definition.label"
        :layout="definition.layout"
        :description="t('module.preview_changes')"
    >
        <form ref="form" class="grid gap-4" @submit.prevent="submit">
            <FormField
                v-for="(field, index) in definition.fields"
                :key="field.name"
                v-slot="{ control }"
                :label="field.label"
                :error="errors[field.name]"
                required
            >
                <NativeSelect
                    v-if="field.options"
                    v-bind="control"
                    class="w-full"
                    :name="field.name"
                    :model-value="String(draft[field.name] ?? '')"
                    :autofocus="index === 0"
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
                    v-bind="control"
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
                    maxlength="120"
                />
            </FormField>
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
