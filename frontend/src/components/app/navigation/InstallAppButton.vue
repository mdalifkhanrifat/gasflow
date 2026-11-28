<script setup lang="ts">
import { Download, Share } from '@lucide/vue';
import { useTemplateRef } from 'vue';
import FormDialog from '@/components/app/forms/FormDialog.vue';
import { useInstallPrompt } from '@/composables/useInstallPrompt';
import { useLocale } from '@/composables/useLocale';
import { useToast } from '@/composables/useToast';

// "Install app": the browser's own dialog where it offers one, step-by-step
// help on iPhone and iPad, and nothing once the app is installed.
const { canPrompt, needsIosSteps, install } = useInstallPrompt();
const { t } = useLocale();
const { notify } = useToast();
const steps = useTemplateRef('steps');

async function start(): Promise<void> {
    if (canPrompt.value) {
        if (await install()) {
            notify(t('pwa.installed'));
        }
    } else {
        steps.value?.open();
    }
}
</script>

<template>
    <template v-if="canPrompt || needsIosSteps">
        <button type="button" class="install-app-button" @click="start">
            <Download />
            {{ t('pwa.install') }}
        </button>
        <!-- Opening a modal closes the menu it sits in, so it lives in <body>. -->
        <Teleport to="body">
            <FormDialog
                ref="steps"
                title-id="install-app-title"
                :title="t('pwa.install')"
                :description="t('pwa.ios_intro')"
            >
                <ol class="install-steps">
                    <li>
                        <Share />
                        <span>{{ t('pwa.ios_share') }}</span>
                    </li>
                    <li>{{ t('pwa.ios_add') }}</li>
                    <li>{{ t('pwa.ios_confirm') }}</li>
                </ol>
                <div class="mt-4 flex justify-end">
                    <button
                        type="button"
                        class="ui-button"
                        autofocus
                        @click="steps?.close()"
                    >
                        {{ t('module.close') }}
                    </button>
                </div>
            </FormDialog>
        </Teleport>
    </template>
</template>
