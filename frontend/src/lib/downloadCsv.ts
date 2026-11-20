export function downloadCsv(
    filename: string,
    rows: readonly (readonly string[])[],
): void {
    const content = rows
        .map((row) => row.map(escapeCell).join(','))
        .join('\r\n');
    const url = URL.createObjectURL(
        new Blob(['\uFEFF', content], { type: 'text/csv;charset=utf-8' }),
    );
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function escapeCell(value: string): string {
    const safe = /^[\s]*[=+@-]/.test(value) ? "'" + value : value;
    return '"' + safe.replaceAll('"', '""') + '"';
}
