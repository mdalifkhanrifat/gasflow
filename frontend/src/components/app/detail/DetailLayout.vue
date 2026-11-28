<script setup lang="ts">
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';

export interface DetailTab {
    key: string;
    label: string;
}

// A record page: actions on top, tabs (arrow keys move between them), the
// active tab's content and an optional side summary. The active tab lives in
// the URL (?tab=history), so links and reloads open the same tab. Each tab's
// content is the slot with the tab's key.
const props = defineProps<{
    label: string;
    tabs: readonly DetailTab[];
}>();
const route = useRoute();
const router = useRouter();
const active = computed({
    get: () => {
        const requested = route.query.tab;
        return props.tabs.some((tab) => tab.key === requested)
            ? String(requested)
            : (props.tabs[0]?.key ?? '');
    },
    set: (key: string) => void show(key),
});

async function show(key: string): Promise<void> {
    const first = props.tabs[0]?.key;
    await router.replace({
        query: { ...route.query, tab: key === first ? undefined : key },
    });
}

defineExpose({ show });
</script>

<template>
    <TabsRoot
        v-model="active"
        class="mx-auto grid w-full max-w-5xl min-w-0 gap-4 print:max-w-none"
    >
        <div
            v-if="$slots.actions"
            class="flex flex-wrap items-center justify-between gap-2 print:hidden"
        >
            <slot name="actions" />
        </div>
        <TabsList :aria-label="label" class="detail-tabs print:hidden">
            <TabsTrigger
                v-for="tab in tabs"
                :key="tab.key"
                :value="tab.key"
                class="detail-tab"
            >
                {{ tab.label }}
            </TabsTrigger>
        </TabsList>
        <div
            class="grid min-w-0 gap-4"
            :class="{ 'lg:grid-cols-[minmax(0,1fr)_18rem]': $slots.summary }"
        >
            <div class="min-w-0">
                <TabsContent
                    v-for="tab in tabs"
                    :key="tab.key"
                    :value="tab.key"
                    class="detail-tab-panel"
                >
                    <slot :name="tab.key" />
                </TabsContent>
            </div>
            <aside v-if="$slots.summary" class="min-w-0 print:hidden">
                <slot name="summary" />
            </aside>
        </div>
    </TabsRoot>
</template>
