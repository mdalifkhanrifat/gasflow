<script setup lang="ts">
import { Search } from '@lucide/vue';
import { computed, ref, useTemplateRef } from 'vue';
import { useRouter } from 'vue-router';
import UiInput from '@/components/ui/UiInput.vue';
import { locales, useLocale } from '@/composables/useLocale';
import { searchableNavigation, type SearchEntry } from '@/config/navigation';

const emit = defineEmits<{ navigate: []; reveal: [entry: SearchEntry] }>();
const input = useTemplateRef('input');
defineExpose({ focus: () => input.value?.focus() });

const { t } = useLocale();
const router = useRouter();
const query = ref('');
const focused = ref(false);
const term = computed(() => query.value.trim().replace(/\s+/g, ' '));
const recordRoute = computed(() => {
    if (/^bc-/i.test(term.value)) {
        return 'billing-cycles';
    }
    if (/^(?:inv-|[0-9]{4}$)/i.test(term.value)) {
        return 'customer-invoices';
    }
    return undefined;
});
const normalize = (value: string) => value.normalize().toLocaleLowerCase();
const matches = computed(() => {
    if (recordRoute.value) {
        return searchableNavigation.filter(
            (entry) => entry.route === recordRoute.value,
        );
    }

    const value = normalize(term.value);
    const exact: SearchEntry[] = [];
    const partial: SearchEntry[] = [];

    for (const entry of searchableNavigation) {
        const labels = locales.map((language) =>
            normalize(t(entry.label, language)),
        );
        if (labels.includes(value)) {
            exact.push(entry);
        } else if (labels.some((label) => label.includes(value))) {
            partial.push(entry);
        }
    }

    return [...exact, ...partial];
});
const visible = computed(() => focused.value && !!term.value);

function destination(name: string) {
    return {
        name,
        query: name === recordRoute.value ? { q: term.value } : {},
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

function reveal(entry: SearchEntry): void {
    clearSearch();
    emit('reveal', entry);
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
    if (match?.route) {
        void router.push(destination(match.route));
        followLink();
    } else if (match) {
        reveal(match);
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
        <div class="relative">
            <label class="block">
                <span class="sr-only">{{ t('topbar.search') }}</span>
                <UiInput
                    ref="input"
                    v-model="query"
                    type="search"
                    autocomplete="off"
                    :placeholder="t('topbar.search')"
                    class="ui-control-sidebar w-full pl-7"
                    @input="focused = true"
                />
            </label>
            <button
                type="submit"
                :aria-label="t('topbar.search')"
                :title="t('topbar.search')"
                class="absolute inset-y-0 left-0 grid w-7 place-items-center rounded-control text-nav-muted hover:text-nav-ink focus-visible:outline-2 focus-visible:outline-nav-focus"
            >
                <Search />
            </button>
        </div>
        <div
            v-if="visible"
            class="mt-1 rounded-control border border-nav-line bg-nav-workspace p-1 text-xs text-nav-ink"
        >
            <template v-for="entry in matches" :key="entry.label">
                <RouterLink
                    v-if="entry.route"
                    :to="destination(entry.route)"
                    class="block rounded-control px-2.5 py-2 hover:bg-nav-hover focus-visible:outline-2 focus-visible:outline-nav-focus"
                    @mousedown.prevent
                    @click="followLink"
                >
                    {{ t(entry.label)
                    }}<template v-if="recordRoute"> · {{ term }}</template>
                </RouterLink>
                <button
                    v-else
                    type="button"
                    class="block w-full rounded-control px-2.5 py-2 text-start hover:bg-nav-hover focus-visible:outline-2 focus-visible:outline-nav-focus"
                    @mousedown.prevent
                    @click="reveal(entry)"
                >
                    {{ t(entry.label) }}
                    <span class="block text-nav-muted">{{
                        t('navigation.menu_preview')
                    }}</span>
                </button>
            </template>
            <p v-if="!matches.length" class="p-2 text-nav-muted">
                {{ t('module.empty') }}
            </p>
        </div>
    </form>
</template>
