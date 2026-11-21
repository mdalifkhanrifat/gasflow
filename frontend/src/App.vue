<script setup lang="ts">
import { onErrorCaptured } from 'vue';
import ServiceNotice from '@/pages/errors/ServiceNotice.vue';
import router, { navigationFailure } from '@/router';

onErrorCaptured((error) => {
    if (navigationFailure.value) {
        return;
    }

    console.error(error);
    navigationFailure.value = {
        retryHref: router.resolve(router.currentRoute.value.fullPath).href,
        resumeCurrentPage: false,
    };
    return false;
});
</script>

<template>
    <ServiceNotice
        v-if="navigationFailure"
        mode="error"
        :retry-href="navigationFailure.retryHref"
        :resume-current-page="navigationFailure.resumeCurrentPage"
        @resume="navigationFailure = undefined"
    />
    <RouterView v-else />
</template>
