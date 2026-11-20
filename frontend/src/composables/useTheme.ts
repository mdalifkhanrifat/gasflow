import { readonly, ref } from 'vue';

type ThemePreference = 'system' | 'light' | 'dark';

const storageKey = 'gasflow-theme';
const preference = ref<ThemePreference>('system');
const isDark = ref(false);

function readPreference(value: string | null | undefined): ThemePreference {
    return value === 'light' || value === 'dark' ? value : 'system';
}

export function initializeTheme(): void {
    const root = document.documentElement;
    preference.value = readPreference(root.dataset.themePreference);
    applyTheme();

    window
        .matchMedia('(prefers-color-scheme: dark)')
        .addEventListener('change', applyTheme);
}

function applyTheme(): void {
    const root = document.documentElement;
    const dark =
        preference.value === 'dark' ||
        (preference.value === 'system' &&
            window.matchMedia('(prefers-color-scheme: dark)').matches);

    isDark.value = dark;
    root.dataset.themePreference = preference.value;
    root.classList.toggle('dark', dark);
}

function setPreference(value: ThemePreference): void {
    preference.value = value;
    applyTheme();

    try {
        window.localStorage.setItem(storageKey, value);
    } catch {
        // Keep the selection for this visit if storage is unavailable.
    }
}

export function useTheme() {
    return {
        preference: readonly(preference),
        isDark: readonly(isDark),
        setPreference,
    };
}
