const banglaNumbers = new Intl.NumberFormat('bn-BD');
const englishNumbers = new Intl.NumberFormat('en-IN');

export function formatNumber(value: number, locale: string): string {
    return (locale === 'bn' ? banglaNumbers : englishNumbers).format(value);
}
