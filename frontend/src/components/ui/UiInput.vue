<script setup lang="ts">
import { useTemplateRef } from 'vue';

const model = defineModel<string | number>();
const element = useTemplateRef<HTMLInputElement>('element');

defineExpose({ focus: () => element.value?.focus() });
</script>

<template>
    <input ref="element" v-model="model" class="ui-control" />
</template>
