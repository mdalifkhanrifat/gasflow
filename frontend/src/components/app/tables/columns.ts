import { type locales, useLocale } from '@/composables/useLocale';
import { useStatusCatalogue } from '@/composables/useStatusCatalogue';
import {
    formatDate,
    formatMoney,
    formatMonth,
    formatNumber,
} from '@/lib/format';
import { localTableSource, type TableSource } from '@/lib/tableQuery';

type Locale = (typeof locales)[number];

// How a cell is shown. Numbers and money align to the end of the cell.
export type ColumnType =
    | 'text'
    | 'number'
    | 'money'
    | 'date'
    | 'month'
    | 'status';

export interface TableColumn<Row> {
    // The sort key sent to the server (TableRequest::$sortable).
    key: string;
    label: string;
    // The raw value: a number for number/money, an ISO date for date,
    // "YYYY-MM" for month and the status code for status.
    value: (row: Row) => string | number;
    type?: ColumnType;
    sortable?: boolean;
    // A different value to sort by, such as the count inside "48 / 48".
    sortValue?: (row: Row) => string | number;
    // status_definitions.entity_name, for status columns.
    statusEntity?: string;
}

export interface CellFormatting {
    locale: Locale;
    statusLabel: (entity: string, code: string) => string;
}

export function isNumericColumn(column: TableColumn<never>): boolean {
    return column.type === 'number' || column.type === 'money';
}

export function formatCell<Row>(
    column: TableColumn<Row>,
    row: Row,
    { locale, statusLabel }: CellFormatting,
): string {
    const value = column.value(row);
    switch (column.type) {
        case 'number':
            return formatNumber(Number(value), locale);
        case 'money':
            return formatMoney(Number(value), locale);
        case 'date':
            return formatDate(String(value), locale);
        case 'month':
            return formatMonth(String(value), locale);
        case 'status':
            return statusLabel(column.statusEntity ?? '*', String(value));
        default:
            return String(value);
    }
}

export interface ColumnSourceOptions extends CellFormatting {
    statusRank: (entity: string, code: string) => number;
}

// Preview rows in the browser, searched, filtered and sorted by the same
// column definitions the page shows. The status column's key is its filter.
export function columnTableSource<Row>(
    rows: () => readonly Row[],
    columns: () => readonly TableColumn<Row>[],
    options: () => ColumnSourceOptions,
): TableSource<Row> & { changes: () => readonly Row[] } {
    function sortValue(column: TableColumn<Row>, row: Row): string | number {
        if (column.sortValue) {
            return column.sortValue(row);
        }
        if (column.type === 'status') {
            return options().statusRank(
                column.statusEntity ?? '*',
                String(column.value(row)),
            );
        }
        if (column.type === 'date') {
            return Date.parse(String(column.value(row)));
        }
        return column.value(row);
    }

    const source = localTableSource(rows, {
        searchText: (row) =>
            columns()
                .filter((column) => column.type !== 'status')
                .map((column) => formatCell(column, row, options())),
        get filterValues() {
            return Object.fromEntries(
                columns()
                    .filter((column) => column.type === 'status')
                    .map((column) => [
                        column.key,
                        (row: Row) => String(column.value(row)),
                    ]),
            );
        },
        get sortValues() {
            return Object.fromEntries(
                columns()
                    .filter((column) => column.sortable)
                    .map((column) => [
                        column.key,
                        (row: Row) => sortValue(column, row),
                    ]),
            );
        },
        locale: () => options().locale,
    });

    return { ...source, changes: rows };
}

// A local source for preview pages, reloaded when the rows or the language change.
export function usePreviewTableSource<Row>(
    rows: () => readonly Row[],
    columns: () => readonly TableColumn<Row>[],
): TableSource<Row> & { changes: () => readonly Row[] } {
    const { locale } = useLocale();
    const { statusLabel, statusRank } = useStatusCatalogue();
    const source = columnTableSource(rows, columns, () => ({
        locale: locale.value,
        statusLabel,
        statusRank,
    }));

    return {
        ...source,
        changes: () => {
            void locale.value;
            return rows();
        },
    };
}
