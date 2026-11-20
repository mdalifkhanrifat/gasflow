<script setup lang="ts" generic="Value extends string | string[]">
const model = defineModel<Value>({ required: true });
</script>

<template>
    <select v-model="model" class="ui-control ui-select">
        <slot />
    </select>
</template>
