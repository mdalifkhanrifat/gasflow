<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { update } from '@/routes/locale';

const page = usePage();
const pending = ref(false);
const failed = ref(false);
const locales = ['bn', 'en'] as const;

function changeLocale(event: Event): void {
    const select = event.target as HTMLSelectElement;
    const locale = select.value;
    failed.value = false;

    router.post(
        update.url(),
        { locale },
        {
            preserveScroll: true,
            onStart: () => {
                pending.value = true;
            },
            onError: () => {
                failed.value = true;
                select.value = page.props.locale;
            },
            onFinish: () => {
                pending.value = false;
            },
        },
    );
}
</script>

<template>
    <label class="grid gap-1.5 text-sm font-medium text-ink">
        {{ $t('preferences.language') }}
        <NativeSelect
            :value="page.props.locale"
            :disabled="pending"
            @change="changeLocale"
        >
            <option
                v-for="locale in locales"
                :key="locale"
                :value="locale"
                :lang="locale"
            >
                {{ $t(`preferences.language.${locale}`) }}
            </option>
        </NativeSelect>
        <span v-if="failed" role="alert">{{
            $t('preferences.language_error')
        }}</span>
    </label>
</template>
