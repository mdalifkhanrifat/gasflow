import { readonly, ref } from 'vue';
import { readJson } from '@/api/client';
import { locales, useLocale } from '@/composables/useLocale';
import { statusTones, type StatusTone } from '@/config/status';

type Locale = (typeof locales)[number];

interface StatusEntry {
    tone: StatusTone;
    label: Partial<Record<Locale, string>>;
}

type StatusCatalogue = Record<string, Record<string, StatusEntry>>;

export interface StatusOption {
    code: string;
    label: string;
}

// The database's status_definitions, from GET /api/v1/statuses. Until it
// loads, badges show the status code in a neutral tone.
const catalogue = ref<StatusCatalogue>({});
const retryDelay = 30_000;
let request: Promise<void> | undefined;
let failedAt = 0;

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function parseEntry(value: unknown): StatusEntry {
    const entry = isRecord(value) ? value : {};
    const tone =
        typeof entry.tone === 'string' && entry.tone in statusTones
            ? (entry.tone as StatusTone)
            : 'neutral';
    const labels = isRecord(entry.label) ? entry.label : {};
    const label: StatusEntry['label'] = {};
    for (const language of locales) {
        const text = labels[language];
        if (typeof text === 'string') {
            label[language] = text;
        }
    }

    return { tone, label };
}

function parseCatalogue(payload: unknown): StatusCatalogue {
    if (!isRecord(payload) || !isRecord(payload.statuses)) {
        throw new Error('Invalid status catalogue');
    }

    return Object.fromEntries(
        Object.entries(payload.statuses).map(([entity, codes]) => [
            entity,
            Object.fromEntries(
                Object.entries(isRecord(codes) ? codes : {}).map(
                    ([code, entry]) => [code, parseEntry(entry)],
                ),
            ),
        ]),
    );
}

export function loadStatusCatalogue(): Promise<void> {
    if (!request && Date.now() - failedAt >= retryDelay) {
        request = readJson('/api/v1/statuses')
            .then((payload) => {
                catalogue.value = parseCatalogue(payload);
            })
            .catch(() => {
                failedAt = Date.now();
                request = undefined;
            });
    }

    return request ?? Promise.resolve();
}

// Tables without their own statuses use the generic "*" set, as
// StatusRegistry does on the server.
function statusSet(entity: string): Record<string, StatusEntry> {
    return catalogue.value[entity] ?? catalogue.value['*'] ?? {};
}

export function useStatusCatalogue() {
    const { locale } = useLocale();
    void loadStatusCatalogue();

    function statusLabel(entity: string, code: string): string {
        const label = statusSet(entity)[code]?.label;
        return label?.[locale.value] ?? label?.en ?? code;
    }

    function statusTone(entity: string, code: string): StatusTone {
        return statusSet(entity)[code]?.tone ?? 'neutral';
    }

    function statusOptions(entity: string): StatusOption[] {
        return Object.keys(statusSet(entity)).map((code) => ({
            code,
            label: statusLabel(entity, code),
        }));
    }

    return {
        catalogue: readonly(catalogue),
        statusLabel,
        statusTone,
        statusOptions,
    };
}
