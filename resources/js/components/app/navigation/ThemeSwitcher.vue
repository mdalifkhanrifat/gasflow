<script setup lang="ts">
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { useTheme } from '@/composables/useTheme';

const { preference, setPreference } = useTheme();
const themes = ['system', 'light', 'dark'] as const;

function changeTheme(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    const theme = themes.find((option) => option === value);
    if (theme) {
        setPreference(theme);
    }
}
</script>

<template>
    <label class="grid gap-1.5 text-sm font-medium text-ink">
        {{ $t('preferences.theme') }}
        <NativeSelect :value="preference" @change="changeTheme">
            <option v-for="theme in themes" :key="theme" :value="theme">
                {{ $t(`preferences.theme.${theme}`) }}
            </option>
        </NativeSelect>
    </label>
</template>
