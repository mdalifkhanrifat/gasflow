<script setup lang="ts">
import { Search } from '@lucide/vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useLocale } from '@/composables/useLocale';
import type { StatusOption } from '@/composables/useStatusCatalogue';

// Search box and, when the list has a status column, the status filter.
defineProps<{ statuses?: readonly StatusOption[] }>();
const search = defineModel<string>('search', { required: true });
const status = defineModel<string>('status', { default: '' });
const { t } = useLocale();
</script>

<template>
    <label class="relative min-w-0 flex-1 basis-40 sm:w-44 sm:flex-none">
        <span class="sr-only">{{ t('module.search') }}</span>
        <span
            class="pointer-events-none absolute inset-y-0 left-2.5 grid place-items-center text-ink-muted"
            ><Search
        /></span>
        <UiInput
            v-model="search"
            type="search"
            :placeholder="t('module.search')"
            class="ui-control-compact w-full pl-7"
        />
    </label>
    <label v-if="statuses?.length">
        <span class="sr-only">{{ t('module.status') }}</span>
        <NativeSelect class="ui-control-compact" v-model="status">
            <option value="">
                {{ t('module.all_statuses') }}
            </option>
            <option
                v-for="option in statuses"
                :key="option.code"
                :value="option.code"
            >
                {{ option.label }}
            </option>
        </NativeSelect>
    </label>
</template>
