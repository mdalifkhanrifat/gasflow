import { shallowRef } from 'vue';

export function createPreviewRecords<Record>(
    initial: readonly Record[],
    key: (record: Record) => string,
) {
    const rows = shallowRef(initial);

    function add(record: Record): void {
        rows.value = [record, ...rows.value];
    }

    function update(id: string, changes: Partial<Record>): void {
        rows.value = rows.value.map((record) =>
            key(record) === id ? { ...record, ...changes } : record,
        );
    }

    function remove(id: string): void {
        rows.value = rows.value.filter((record) => key(record) !== id);
    }

    return { rows, add, update, remove };
}
