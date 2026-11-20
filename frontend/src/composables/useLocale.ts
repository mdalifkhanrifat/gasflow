import { readonly, ref, watch } from 'vue';
import { readJson, writeJson } from '@/api/client';
import bangla from '@/locales/bn.json';
import english from '@/locales/en.json';

export const locales = ['bn', 'en'] as const;
type Locale = (typeof locales)[number];
type MessageKey = keyof typeof english;

const dictionaries: Record<Locale, Record<MessageKey, string>> = {
    bn: bangla,
    en: english,
};
const locale = ref<Locale>('bn');
let selectionRevision = 0;

watch(locale, (value) => {
    document.documentElement.lang = value;
});

function applyResponse(payload: unknown): void {
    if (
        typeof payload !== 'object' ||
        payload === null ||
        !('locale' in payload)
    ) {
        throw new Error('Invalid locale response');
    }

    const selected = locales.find((option) => option === payload.locale);
    if (!selected) {
        throw new Error('Unsupported locale received');
    }

    locale.value = selected;
}

export async function restoreLocale(): Promise<void> {
    const revision = selectionRevision;
    try {
        const payload = await readJson('/api/v1/locale');
        if (revision === selectionRevision) {
            applyResponse(payload);
        }
    } catch {
        // Use the default locale if the API is unavailable.
    }
}

async function setLocale(value: string): Promise<void> {
    selectionRevision++;
    applyResponse(await writeJson('/api/v1/locale', { locale: value }));
}

function t(key: MessageKey): string {
    return dictionaries[locale.value][key];
}

export function useLocale() {
    return { locale: readonly(locale), setLocale, t };
}
