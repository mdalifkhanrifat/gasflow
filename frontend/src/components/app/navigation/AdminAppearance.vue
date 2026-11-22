<script setup lang="ts">
import { useId } from 'vue';
import ThemeSwitcher from '@/components/app/navigation/ThemeSwitcher.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import {
    accentOptions,
    backgroundOptions,
    headingOptions,
    sidebarTextOptions,
    tableTextOptions,
    useAdminAppearance,
} from '@/composables/useAdminAppearance';
import { useLocale } from '@/composables/useLocale';
import { useTheme } from '@/composables/useTheme';

const { t } = useLocale();
const {
    accent,
    setAccent,
    background,
    setBackground,
    heading,
    setHeading,
    tableText,
    setTableText,
    sidebarText,
    setSidebarText,
    reset,
} = useAdminAppearance();
const { setPreference } = useTheme();
const id = useId();

function resetAppearance(): void {
    reset();
    setPreference('system');
}
</script>

<template>
    <div class="contents">
        <button
            type="button"
            class="topbar-icon appearance-trigger text-sm print:hidden"
            :aria-label="t('preferences.appearance')"
            :title="t('preferences.appearance')"
            :popovertarget="id"
        >
            <span aria-hidden="true">⚙︎</span>
        </button>
        <section
            :id="id"
            popover
            role="dialog"
            :aria-labelledby="id + '-title'"
            class="appearance-panel rounded-panel border border-line bg-surface p-4 text-ink shadow-panel print:hidden"
        >
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 :id="id + '-title'" class="text-sm font-semibold">
                    {{ t('preferences.appearance') }}
                </h2>
                <button
                    type="button"
                    class="ui-button-icon"
                    :aria-label="t('module.close')"
                    :popovertarget="id"
                    popovertargetaction="hide"
                >
                    <span aria-hidden="true">×</span>
                </button>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <ThemeSwitcher class="min-w-0" />
                <label
                    class="grid min-w-0 gap-1.5 text-field-label font-medium"
                >
                    {{ t('preferences.accent') }}
                    <NativeSelect
                        class="min-w-0"
                        :model-value="accent"
                        @update:model-value="setAccent"
                    >
                        <option
                            v-for="option in accentOptions"
                            :key="option"
                            :value="option"
                        >
                            {{ t(`preferences.accent.${option}`) }}
                        </option>
                    </NativeSelect>
                </label>
            </div>

            <fieldset class="mt-4 min-w-0">
                <legend class="mb-2 text-field-label font-medium">
                    {{ t('preferences.background') }}
                </legend>
                <div class="grid grid-cols-2 gap-2">
                    <label
                        v-for="option in backgroundOptions"
                        :key="option"
                        class="background-option grid cursor-pointer gap-1.5 rounded-control border border-line p-1.5 text-xs font-medium hover:border-primary"
                    >
                        <input
                            type="radio"
                            class="sr-only"
                            :name="id + '-background'"
                            :value="option"
                            :checked="background === option"
                            @change="setBackground(option)"
                        />
                        <span
                            aria-hidden="true"
                            class="background-swatch h-8 rounded-sm border border-line"
                            :data-background-preset="option"
                        ></span>
                        <span class="px-0.5">{{
                            t(`preferences.background.${option}`)
                        }}</span>
                    </label>
                </div>
            </fieldset>

            <fieldset class="mt-4 min-w-0">
                <legend class="mb-2 text-xs font-semibold text-ink-muted">
                    {{ t('preferences.typography') }}
                </legend>
                <div class="grid grid-cols-2 gap-3">
                    <label
                        class="grid min-w-0 gap-1.5 text-field-label font-medium"
                    >
                        {{ t('preferences.heading_size') }}
                        <NativeSelect
                            class="min-w-0"
                            :model-value="heading"
                            @update:model-value="setHeading"
                        >
                            <option
                                v-for="option in headingOptions"
                                :key="option"
                                :value="option"
                            >
                                {{ t(`preferences.heading_size.${option}`) }}
                            </option>
                        </NativeSelect>
                    </label>
                    <label
                        class="grid min-w-0 gap-1.5 text-field-label font-medium"
                    >
                        {{ t('preferences.table_text') }}
                        <NativeSelect
                            class="min-w-0"
                            :model-value="tableText"
                            @update:model-value="setTableText"
                        >
                            <option
                                v-for="option in tableTextOptions"
                                :key="option"
                                :value="option"
                            >
                                {{ t(`preferences.table_text.${option}`) }}
                            </option>
                        </NativeSelect>
                    </label>
                    <label
                        class="col-span-2 grid min-w-0 gap-1.5 text-field-label font-medium"
                    >
                        {{ t('preferences.sidebar_text') }}
                        <NativeSelect
                            class="min-w-0"
                            :model-value="sidebarText"
                            @update:model-value="setSidebarText"
                        >
                            <option
                                v-for="option in sidebarTextOptions"
                                :key="option"
                                :value="option"
                            >
                                {{ t(`preferences.sidebar_text.${option}`) }}
                            </option>
                        </NativeSelect>
                    </label>
                </div>
            </fieldset>
            <button
                type="button"
                class="ui-button mt-4 w-full justify-center"
                @click="resetAppearance"
            >
                {{ t('preferences.reset_appearance') }}
            </button>
        </section>
    </div>
</template>
