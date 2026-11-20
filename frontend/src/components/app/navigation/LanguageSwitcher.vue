<script setup lang="ts">
import { ref } from 'vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { locales, useLocale } from '@/composables/useLocale';

const { locale, setLocale, t } = useLocale();
const pending = ref(false);
const failed = ref(false);
async function changeLocale(event: Event): Promise<void> {
    const select = event.target as HTMLSelectElement;
    failed.value = false;
    pending.value = true;

    try {
        await setLocale(select.value);
    } catch {
        failed.value = true;
        select.value = locale.value;
    } finally {
        pending.value = false;
    }
}
</script>

<template>
    <label class="grid gap-1.5 text-sm font-medium text-ink">
        {{ t('preferences.language') }}
        <NativeSelect
            :value="locale"
            :disabled="pending"
            @change="changeLocale"
        >
            <option
                v-for="locale in locales"
                :key="locale"
                :value="locale"
                :lang="locale"
            >
                {{ t(`preferences.language.${locale}`) }}
            </option>
        </NativeSelect>
        <span v-if="failed" role="alert">{{
            t('preferences.language_error')
        }}</span>
    </label>
</template>
