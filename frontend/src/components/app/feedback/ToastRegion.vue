<script setup lang="ts">
import { CircleAlert, CircleCheck, Info, TriangleAlert, X } from '@lucide/vue';
import { useLocale } from '@/composables/useLocale';
import { useToast } from '@/composables/useToast';

const { t } = useLocale();
const { toasts, dismiss, pause, resume } = useToast();
const icons = {
    success: CircleCheck,
    info: Info,
    warning: TriangleAlert,
    danger: CircleAlert,
};

function leave(event: FocusEvent): void {
    const region = event.currentTarget as HTMLElement;
    if (!region.contains(event.relatedTarget as Node | null)) {
        resume();
    }
}
</script>

<template>
    <section
        class="toast-region"
        role="status"
        aria-live="polite"
        :aria-label="t('toast.region')"
        @pointerenter="pause"
        @pointerleave="resume"
        @focusin="pause"
        @focusout="leave"
    >
        <TransitionGroup tag="ol" name="toast" class="toast-list">
            <li
                v-for="toast in toasts"
                :key="toast.id"
                class="toast"
                :data-tone="toast.tone"
            >
                <component :is="icons[toast.tone]" class="toast-icon" />
                <p class="min-w-0 flex-1">{{ toast.message }}</p>
                <button
                    type="button"
                    class="toast-dismiss"
                    :aria-label="t('toast.dismiss')"
                    @click="dismiss(toast.id)"
                >
                    <X />
                </button>
            </li>
        </TransitionGroup>
    </section>
</template>
