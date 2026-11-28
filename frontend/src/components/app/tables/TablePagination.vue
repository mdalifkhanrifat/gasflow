<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { useLocale } from '@/composables/useLocale';
import { formatNumber } from '@/lib/format';
import { pageSizes } from '@/lib/tableQuery';

// Record range, page size and page buttons under a list table.
const props = defineProps<{
    lastPage: number;
    total: number;
    // Rows shown on the current page.
    shown: number;
}>();
const page = defineModel<number>('page', { required: true });
const perPage = defineModel<number>('perPage', { required: true });
const { locale, t } = useLocale();

const offset = computed(() => (page.value - 1) * perPage.value);
const recordCount = computed(() =>
    t('module.record_count')
        .replace(
            '{first}',
            formatNumber(props.shown ? offset.value + 1 : 0, locale.value),
        )
        .replace(
            '{last}',
            formatNumber(offset.value + props.shown, locale.value),
        )
        .replace('{total}', formatNumber(props.total, locale.value)),
);
// Up to five page buttons around the current page.
const pageNumbers = computed(() => {
    const first = Math.max(1, Math.min(page.value - 2, props.lastPage - 4));
    return Array.from(
        { length: Math.min(props.lastPage, 5) },
        (_, index) => first + index,
    );
});
</script>

<template>
    <footer class="module-table-footer border-t border-line text-ink-muted">
        <div class="flex flex-wrap items-center gap-2.5">
            <p aria-live="polite">{{ recordCount }}</p>
            <label>
                <span class="sr-only">{{ t('module.rows_per_page') }}</span>
                <NativeSelect
                    :model-value="String(perPage)"
                    class="pagination-size"
                    @update:model-value="perPage = Number($event)"
                >
                    <option
                        v-for="size in pageSizes"
                        :key="size"
                        :value="String(size)"
                    >
                        {{ formatNumber(size, locale) }}
                    </option>
                </NativeSelect>
            </label>
        </div>
        <nav
            v-if="lastPage > 1"
            :aria-label="t('module.pagination')"
            class="ms-auto flex flex-wrap items-center justify-end gap-1 self-end"
        >
            <button
                type="button"
                class="pagination-button"
                :disabled="page === 1"
                :aria-label="t('module.previous_page')"
                @click="page--"
            >
                <ChevronLeft />
            </button>
            <button
                v-for="number in pageNumbers"
                :key="number"
                type="button"
                class="pagination-button"
                :aria-current="number === page ? 'page' : undefined"
                :aria-label="
                    t('module.page').replace(
                        '{page}',
                        formatNumber(number, locale),
                    )
                "
                @click="page = number"
            >
                {{ formatNumber(number, locale) }}
            </button>
            <button
                type="button"
                class="pagination-button"
                :disabled="page === lastPage"
                :aria-label="t('module.next_page')"
                @click="page++"
            >
                <ChevronRight />
            </button>
        </nav>
    </footer>
</template>
