<script setup lang="ts">
import { computed, ref } from 'vue';
import { useLocale } from '@/composables/useLocale';

const { locale, setLocale, t } = useLocale();
const nextLocale = computed(() => (locale.value === 'bn' ? 'en' : 'bn'));
const pending = ref(false);
const failed = ref(false);

async function changeLocale(): Promise<void> {
    pending.value = true;
    failed.value = false;

    try {
        await setLocale(nextLocale.value);
    } catch {
        failed.value = true;
    } finally {
        pending.value = false;
    }
}
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="topbar-icon text-xs font-bold disabled:opacity-60"
            :disabled="pending"
            :aria-label="t(`topbar.switch_language.${nextLocale}`)"
            :title="t(`topbar.switch_language.${nextLocale}`)"
            @click="changeLocale"
        >
            <span :lang="nextLocale">{{
                nextLocale === 'en' ? 'EN' : 'বাংলা'
            }}</span>
        </button>
        <p
            v-if="failed"
            role="alert"
            class="absolute top-full right-0 mt-2 w-36 rounded-control border border-line bg-surface p-2 text-xs text-danger shadow-panel"
        >
            {{ t('preferences.language_error') }}
        </p>
    </div>
</template>
