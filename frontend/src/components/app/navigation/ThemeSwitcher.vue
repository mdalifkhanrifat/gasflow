<script setup lang="ts">
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { useLocale } from '@/composables/useLocale';
import { useTheme } from '@/composables/useTheme';

const { t } = useLocale();
const { preference, setPreference } = useTheme();
const themes = ['system', 'light', 'dark'] as const;

function changeTheme(value: string): void {
    const theme = themes.find((option) => option === value);
    if (theme) {
        setPreference(theme);
    }
}
</script>

<template>
    <label class="grid gap-1.5 text-field-label font-medium text-ink">
        {{ t('preferences.theme') }}
        <NativeSelect
            class="min-w-0"
            :model-value="preference"
            @update:model-value="changeTheme"
        >
            <option v-for="theme in themes" :key="theme" :value="theme">
                {{ t(`preferences.theme.${theme}`) }}
            </option>
        </NativeSelect>
    </label>
</template>
