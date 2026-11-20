<script setup lang="ts">
import { computed, ref, useTemplateRef } from 'vue';
import { useRouter } from 'vue-router';
import { useLocale } from '@/composables/useLocale';
import { searchableNavigation } from '@/config/navigation';

const emit = defineEmits<{ navigate: [] }>();
const input = useTemplateRef<HTMLInputElement>('input');
defineExpose({ focus: () => input.value?.focus() });

const { t } = useLocale();
const router = useRouter();
const query = ref('');
const focused = ref(false);
const invoiceQuery = computed(() => /^inv-/i.test(query.value.trim()));
const matches = computed(() => {
    const value = query.value.trim().toLocaleLowerCase();
    return searchableNavigation.filter((entry) =>
        invoiceQuery.value
            ? entry.route === 'customer-invoices'
            : t(entry.label).toLocaleLowerCase().includes(value),
    );
});
const visible = computed(() => focused.value && !!query.value.trim());

function destination(name: string) {
    return {
        name,
        query:
            invoiceQuery.value && name === 'customer-invoices'
                ? { q: query.value.trim() }
                : {},
    };
}

function clearSearch(): void {
    focused.value = false;
    query.value = '';
}

function followLink(): void {
    clearSearch();
    emit('navigate');
}

function onBlur(event: FocusEvent): void {
    const container = event.currentTarget as HTMLElement;
    if (
        !(event.relatedTarget instanceof Node) ||
        !container.contains(event.relatedTarget)
    ) {
        focused.value = false;
    }
}

function submit(): void {
    const match = matches.value[0];
    if (match) {
        void router.push(destination(match.route));
        followLink();
    }
}
</script>

<template>
    <form
        class="mt-2"
        role="search"
        @focusin="focused = true"
        @focusout="onBlur"
        @keydown.esc.stop="focused = false"
        @submit.prevent="submit"
    >
        <label class="relative block">
            <span class="sr-only">{{ t('topbar.search') }}</span>
            <span
                class="pointer-events-none absolute inset-y-0 left-2.5 grid place-items-center text-base text-nav-muted"
                aria-hidden="true"
                >⌕</span
            >
            <input
                ref="input"
                v-model="query"
                type="search"
                autocomplete="off"
                :placeholder="t('topbar.search')"
                class="min-h-control w-full rounded-control border border-nav-line bg-nav-workspace pr-2 pl-7 text-xs text-nav-ink placeholder:text-nav-muted focus-visible:outline-2 focus-visible:outline-nav-focus"
                @input="focused = true"
            />
        </label>
        <div
            v-if="visible"
            class="mt-1 rounded-control border border-nav-line bg-nav-workspace p-1 text-xs text-nav-ink"
        >
            <RouterLink
                v-for="entry in matches"
                :key="entry.route"
                :to="destination(entry.route)"
                class="block rounded-control px-2.5 py-2 hover:bg-nav-hover focus-visible:outline-2 focus-visible:outline-nav-focus"
                @click="followLink"
            >
                {{ t(entry.label)
                }}<template v-if="invoiceQuery"> · {{ query }}</template>
            </RouterLink>
            <p v-if="!matches.length" class="p-2 text-nav-muted">
                {{ t('module.empty') }}
            </p>
        </div>
    </form>
</template>
