<script setup lang="ts">
import { ref, watchEffect } from 'vue';
import BrandLogo from '@/components/app/BrandLogo.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useLocale } from '@/composables/useLocale';
import PublicLayout from '@/layouts/PublicLayout.vue';

const { t } = useLocale();
const email = ref('');
const password = ref('');
const passwordVisible = ref(false);
const submitted = ref(false);

watchEffect(() => {
    document.title = t('auth.sign_in') + ' - GasFlow';
});

function submitPreview(): void {
    submitted.value = true;
    password.value = '';
}
</script>

<template>
    <PublicLayout>
        <main class="public-stage grid grow place-items-center px-4 py-12">
            <section
                class="w-full max-w-sm rounded-panel border border-line bg-surface p-5 shadow-panel sm:p-7"
            >
                <BrandLogo class="mb-2 text-ink" />
                <h1 class="mt-1 text-page-title font-bold text-ink">
                    {{ t('auth.sign_in') }}
                </h1>
                <p class="mt-2 text-sm text-ink-muted">
                    {{ t('auth.description') }}
                </p>

                <form class="mt-5 grid gap-4" @submit.prevent="submitPreview">
                    <label
                        class="grid gap-1.5 text-field-label font-medium text-ink"
                    >
                        {{ t('auth.email') }}
                        <UiInput
                            v-model="email"
                            required
                            type="email"
                            autocomplete="username"
                            class="w-full"
                        />
                    </label>
                    <div class="grid gap-1.5">
                        <label
                            for="gasflow-password"
                            class="text-field-label font-medium text-ink"
                            >{{ t('auth.password') }}</label
                        >
                        <div class="relative">
                            <UiInput
                                id="gasflow-password"
                                v-model="password"
                                required
                                :type="passwordVisible ? 'text' : 'password'"
                                autocomplete="current-password"
                                class="w-full pe-18"
                            />
                            <button
                                type="button"
                                class="absolute inset-y-0 end-0 rounded-control px-2.5 text-xs font-semibold text-primary focus-visible:outline-2 focus-visible:outline-focus"
                                :aria-pressed="passwordVisible"
                                aria-controls="gasflow-password"
                                @click="passwordVisible = !passwordVisible"
                            >
                                {{
                                    t(
                                        passwordVisible
                                            ? 'auth.hide'
                                            : 'auth.show',
                                    )
                                }}
                            </button>
                        </div>
                    </div>
                    <button
                        type="submit"
                        class="ui-button ui-button-primary justify-center"
                        :aria-describedby="'auth-preview-note'"
                    >
                        {{ t('auth.sign_in') }}
                    </button>
                    <p
                        id="auth-preview-note"
                        role="status"
                        class="text-xs text-ink-muted"
                    >
                        {{
                            t(
                                submitted
                                    ? 'auth.preview_submitted'
                                    : 'auth.preview',
                            )
                        }}
                    </p>
                </form>

                <p
                    class="mt-5 border-t border-line pt-4 text-xs text-ink-muted"
                >
                    {{ t('auth.need_access') }}
                    <RouterLink
                        :to="{ name: 'public-landing', hash: '#setup' }"
                        class="font-semibold text-primary underline-offset-2 hover:underline"
                        >{{ t('public.setup_link') }}</RouterLink
                    >
                </p>
            </section>
        </main>
    </PublicLayout>
</template>
