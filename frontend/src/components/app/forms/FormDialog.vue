<script setup lang="ts">
import { useTemplateRef } from 'vue';

defineProps<{
    titleId: string;
    title: string;
    description?: string;
}>();
const drawer = useTemplateRef<HTMLDialogElement>('dialog');

function open(): void {
    drawer.value?.showModal();
}

function close(): void {
    drawer.value?.close();
}

defineExpose({ open, close });
</script>

<template>
    <dialog
        ref="dialog"
        :aria-labelledby="titleId"
        class="m-auto max-h-dvh w-[calc(100%-2rem)] max-w-md overflow-auto rounded-panel border border-line bg-surface p-0 text-ink shadow-panel backdrop:bg-overlay"
        @click.self="close"
    >
        <div class="p-5">
            <header class="mb-4">
                <h2 :id="titleId" class="text-lg font-semibold">{{ title }}</h2>
                <p v-if="description" class="mt-1 text-xs text-ink-muted">
                    {{ description }}
                </p>
            </header>
            <slot />
        </div>
    </dialog>
</template>
