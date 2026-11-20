const locales = { bn: 'bn-BD', en: 'en-IN' } as const;
type Locale = keyof typeof locales;

const numbers = {
    bn: new Intl.NumberFormat(locales.bn),
    en: new Intl.NumberFormat(locales.en),
};
const currency = {
    style: 'currency',
    currency: 'BDT',
    currencyDisplay: 'narrowSymbol',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
} as const;
const amounts = {
    bn: new Intl.NumberFormat(locales.bn, currency),
    en: new Intl.NumberFormat(locales.en, currency),
};
const date = { day: 'numeric', month: 'short', timeZone: 'UTC' } as const;
const dates = {
    bn: new Intl.DateTimeFormat(locales.bn, date),
    en: new Intl.DateTimeFormat(locales.en, date),
};

export function formatNumber(value: number, locale: Locale): string {
    return numbers[locale].format(value);
}

export function formatMoney(value: number, locale: Locale): string {
    return amounts[locale].format(value);
}

export function formatDate(value: string, locale: Locale): string {
    return dates[locale].format(new Date(value));
}
