import { readonly, ref } from 'vue';

export const accentOptions = ['blue', 'teal', 'violet'] as const;
export const backgroundOptions = [
    'blue-teal',
    'violet-rose',
    'green-mint',
    'peach-amber',
] as const;
export const headingOptions = ['small', 'standard', 'large'] as const;
export const tableTextOptions = ['standard', 'large', 'extra-large'] as const;
export const sidebarTextOptions = ['small', 'standard', 'large'] as const;

function storedPreference<const Value extends string>(
    key: string,
    options: readonly Value[],
    fallback: Value,
) {
    const preference = ref<Value>(fallback);

    try {
        const saved = window.localStorage.getItem(key);
        preference.value =
            options.find((option) => option === saved) ?? fallback;
    } catch {
        // Use the default when storage is unavailable.
    }

    function set(value: string): void {
        const option = options.find((item) => item === value);
        if (!option) {
            return;
        }

        preference.value = option;
        try {
            window.localStorage.setItem(key, option);
        } catch {
            // Keep the selection for this visit.
        }
    }

    return { value: readonly(preference), set, reset: () => set(fallback) };
}

const accent = storedPreference('gasflow-admin-accent', accentOptions, 'blue');
const background = storedPreference(
    'gasflow-admin-background',
    backgroundOptions,
    'blue-teal',
);
const heading = storedPreference(
    'gasflow-admin-heading',
    headingOptions,
    'standard',
);
const tableText = storedPreference(
    'gasflow-admin-table-text',
    tableTextOptions,
    'standard',
);
const sidebarText = storedPreference(
    'gasflow-admin-sidebar-text',
    sidebarTextOptions,
    'standard',
);

export function useAdminAppearance() {
    return {
        accent: accent.value,
        setAccent: accent.set,
        background: background.value,
        setBackground: background.set,
        heading: heading.value,
        setHeading: heading.set,
        tableText: tableText.value,
        setTableText: tableText.set,
        sidebarText: sidebarText.value,
        setSidebarText: sidebarText.set,
        reset() {
            accent.reset();
            background.reset();
            heading.reset();
            tableText.reset();
            sidebarText.reset();
        },
    };
}
