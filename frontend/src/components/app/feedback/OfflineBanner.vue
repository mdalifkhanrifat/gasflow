<script setup lang="ts">
import { WifiOff } from '@lucide/vue';
import { watch } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { useOnlineStatus } from '@/composables/useOnlineStatus';
import { useToast } from '@/composables/useToast';

// Shown while the device is offline. The app shell still works from the
// service worker; data needs the connection, so pages say so when it fails.
const { online } = useOnlineStatus();
const { t } = useLocale();
const { notify } = useToast();
watch(online, (value, previous) => {
    if (value && !previous) {
        notify(t('offline.restored'));
    }
});
</script>

<template>
    <p v-if="!online" role="status" class="offline-banner print:hidden">
        <WifiOff />
        <span>{{ t('offline.message') }}</span>
    </p>
</template>
