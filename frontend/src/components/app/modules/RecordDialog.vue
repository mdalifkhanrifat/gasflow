<script setup lang="ts" generic="Row">
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import FormDialog from '@/components/app/forms/FormDialog.vue';
import FormField from '@/components/app/forms/FormField.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { formatCell, type TableColumn } from '@/components/app/tables/columns';
import UiInput from '@/components/ui/UiInput.vue';
import { useLocale } from '@/composables/useLocale';
import { useStatusCatalogue } from '@/composables/useStatusCatalogue';

export type RecordAction = 'view' | 'edit' | 'delete';

// One record's details, its preview edit of one column, or delete confirmation.
const props = defineProps<{
    title: string;
    columns: readonly TableColumn<Row>[];
    recordLabel: (row: Row) => string;
    editColumn?: TableColumn<Row>;
}>();
const emit = defineEmits<{
    save: [row: Row, value: string];
    remove: [row: Row];
}>();
const { locale, t } = useLocale();
const { statusLabel } = useStatusCatalogue();
const dialog = useTemplateRef('dialog');
const selectedRecord = ref<Row>();
const recordAction = ref<RecordAction>('view');
const draft = ref('');

function cell(column: TableColumn<Row>, row: Row): string {
    return formatCell(column, row, { locale: locale.value, statusLabel });
}

const dialogTitle = computed(() => {
    if (recordAction.value === 'view') {
        return selectedRecord.value
            ? props.recordLabel(selectedRecord.value)
            : props.title;
    }

    return t(recordAction.value === 'edit' ? 'module.edit' : 'module.delete');
});

async function open(row: Row, action: RecordAction): Promise<void> {
    selectedRecord.value = row;
    recordAction.value = action;
    if (action === 'edit' && props.editColumn) {
        draft.value = String(props.editColumn.value(row));
    }
    await nextTick();
    dialog.value?.open();
}

function save(): void {
    const value = draft.value.trim();
    if (selectedRecord.value && value) {
        emit('save', selectedRecord.value, value);
        dialog.value?.close();
    }
}

function remove(): void {
    if (selectedRecord.value) {
        emit('remove', selectedRecord.value);
        dialog.value?.close();
    }
}

defineExpose({ open });
</script>

<template>
    <FormDialog
        ref="dialog"
        title-id="module-record-title"
        :title="dialogTitle"
        :description="
            recordAction === 'view' ? undefined : t('module.preview_changes')
        "
    >
        <template v-if="selectedRecord && recordAction === 'view'">
            <dl class="grid gap-3 sm:grid-cols-2">
                <div v-for="column in columns" :key="column.key">
                    <dt class="text-field-label text-ink-muted">
                        {{ column.label }}
                    </dt>
                    <dd class="text-field-output font-medium text-ink">
                        <StatusBadge
                            v-if="column.type === 'status'"
                            :entity="column.statusEntity ?? '*'"
                            :value="String(column.value(selectedRecord))"
                        />
                        <template v-else>{{
                            cell(column, selectedRecord)
                        }}</template>
                    </dd>
                </div>
            </dl>
            <div class="mt-4 flex justify-end">
                <button
                    type="button"
                    autofocus
                    class="ui-button"
                    @click="dialog?.close()"
                >
                    {{ t('module.close') }}
                </button>
            </div>
        </template>
        <form
            v-else-if="selectedRecord && recordAction === 'edit' && editColumn"
            class="grid gap-4"
            @submit.prevent="save"
        >
            <FormField v-slot="{ control }" :label="editColumn.label" required>
                <UiInput
                    v-bind="control"
                    v-model="draft"
                    maxlength="120"
                    autofocus
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
                    :disabled="!draft.trim()"
                >
                    {{ t('module.save') }}
                </button>
            </div>
        </form>
        <template v-else-if="selectedRecord && recordAction === 'delete'">
            <p class="text-sm text-ink">
                {{
                    t('module.confirm_remove').replace(
                        '{record}',
                        recordLabel(selectedRecord),
                    )
                }}
            </p>
            <div class="mt-4 flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="ui-button"
                    autofocus
                    @click="dialog?.close()"
                >
                    {{ t('module.cancel') }}
                </button>
                <button
                    type="button"
                    class="ui-button border-danger text-danger"
                    @click="remove"
                >
                    {{ t('module.delete') }}
                </button>
            </div>
        </template>
    </FormDialog>
</template>
