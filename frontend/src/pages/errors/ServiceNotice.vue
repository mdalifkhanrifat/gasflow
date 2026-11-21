<script setup lang="ts">
import { computed, useTemplateRef, watch, watchEffect } from 'vue';
import { useRouter, type RouteLocationRaw } from 'vue-router';
import { useLocale } from '@/composables/useLocale';
import interrupted from './service-interrupted.webp';
import maintenance from './service-maintenance.webp';

const statuses = {
    forbidden: {
        code: '403',
        title: 'service.forbidden_title',
        description: 'service.forbidden_description',
    },
    missing: {
        code: '404',
        title: 'errors.not_found',
        description: 'service.missing_description',
    },
    error: {
        code: '500',
        title: 'service.error_title',
        description: 'service.error_description',
    },
    maintenance: {
        code: '503',
        title: 'service.maintenance_title',
        description: 'service.maintenance_description',
    },
} as const;

const props = defineProps<{
    mode: keyof typeof statuses;
    heading?: string;
    description?: string;
    returnTo?: RouteLocationRaw;
    retryHref?: string;
    resumeCurrentPage?: boolean;
}>();
const emit = defineEmits<{ resume: [] }>();

const router = useRouter();
const { t } = useLocale();
const status = computed(() => statuses[props.mode]);
const title = computed(() => props.heading ?? t(status.value.title));
const pageTitle = useTemplateRef('pageTitle');

watchEffect(() => {
    document.title = status.value.code + ' · ' + title.value + ' - GasFlow';
});

watch(
    [pageTitle, () => props.mode, () => props.heading],
    () => {
        pageTitle.value?.focus({ preventScroll: true });
    },
    { flush: 'post' },
);

function goBack(): void {
    if (props.resumeCurrentPage) {
        emit('resume');
        return;
    }

    const previous = router.options.history.state.back;
    if (
        typeof previous === 'string' &&
        previous.startsWith('/') &&
        !previous.startsWith('//')
    ) {
        const destination = router.resolve(previous);
        if (destination.name && !destination.meta.statusScreen) {
            router.back();
            return;
        }
    }

    const fallback = props.returnTo ?? { name: 'platform-dashboard' };
    const failedAtFallback = props.retryHref === router.resolve(fallback).href;
    void router.replace(
        failedAtFallback ? { name: 'public-landing' } : fallback,
    );
}

function retry(): void {
    if (props.retryHref) {
        window.location.assign(window.location.origin + props.retryHref);
    } else {
        window.location.reload();
    }
}
</script>

<template>
    <main class="service-screen grid min-h-dvh place-items-center px-5">
        <section
            class="relative grid w-full max-w-xl justify-items-center text-center"
            aria-labelledby="service-title"
        >
            <div
                class="service-visual"
                :class="{
                    'service-visual-maintenance': mode === 'maintenance',
                }"
                aria-hidden="true"
            >
                <span v-if="mode !== 'maintenance'" class="service-code">{{
                    status.code
                }}</span>
                <img
                    :src="mode === 'maintenance' ? maintenance : interrupted"
                    alt=""
                    class="service-illustration"
                    fetchpriority="high"
                />
            </div>
            <h1
                id="service-title"
                ref="pageTitle"
                tabindex="-1"
                class="service-heading font-semibold outline-none"
                :class="{ 'service-heading-accent': mode === 'maintenance' }"
            >
                <span class="sr-only">{{ status.code }}. </span>{{ title }}
            </h1>
            <p class="service-description mt-3 max-w-md leading-relaxed">
                {{ description ?? t(status.description) }}
            </p>
            <div
                class="mt-7 flex flex-wrap items-center justify-center gap-2.5"
            >
                <button
                    type="button"
                    class="service-back-button ui-button"
                    @click="goBack"
                >
                    {{ t('service.back') }}
                </button>
                <button
                    v-if="mode === 'error'"
                    type="button"
                    class="service-retry-button ui-button"
                    @click="retry"
                >
                    {{ t('service.retry') }}
                </button>
            </div>
        </section>
    </main>
</template>
