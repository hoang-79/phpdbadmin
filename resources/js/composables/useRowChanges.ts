import { computed, ref } from 'vue';

/**
 * Gesammelte Änderungen im Raster (D-04, Wireframe 5): geänderte Zellen je Zeile, neue Zeilen, zum Löschen markierte
 * Zeilen. Nichts davon ist geschrieben, bis die Vorschau bestätigt ist.
 */
export type EditValue = string | number | boolean | null;

export type RowChange =
    | {
          op: 'update';
          key: (string | null)[];
          values: Record<string, EditValue>;
      }
    | { op: 'insert'; key: null; values: Record<string, EditValue> }
    | { op: 'delete'; key: (string | null)[]; values: null };

export function rowId(key: (string | null)[]): string {
    return JSON.stringify(key);
}

export function useRowChanges() {
    const edits = ref(
        new Map<
            string,
            { key: (string | null)[]; values: Record<string, EditValue> }
        >(),
    );
    const inserts = ref<{ id: number; values: Record<string, EditValue> }[]>(
        [],
    );
    const deletes = ref(new Map<string, (string | null)[]>());
    let nextInsert = 1;

    function setCell(
        key: (string | null)[],
        column: string,
        value: EditValue,
        original: EditValue,
    ): void {
        const id = rowId(key);
        const current = edits.value.get(id) ?? { key, values: {} };
        const values = { ...current.values };

        if (value === original) {
            delete values[column];
        } else {
            values[column] = value;
        }

        const next = new Map(edits.value);

        if (Object.keys(values).length) {
            next.set(id, { key, values });
        } else {
            next.delete(id);
        }

        edits.value = next;
    }

    function insert(values: Record<string, EditValue> = {}): number {
        const id = nextInsert++;
        inserts.value = [{ id, values }, ...inserts.value];

        return id;
    }

    function setInsertCell(id: number, column: string, value: EditValue): void {
        inserts.value = inserts.value.map((row) =>
            row.id === id
                ? { ...row, values: { ...row.values, [column]: value } }
                : row,
        );
    }

    function removeInsert(id: number): void {
        inserts.value = inserts.value.filter((row) => row.id !== id);
    }

    function toggleDelete(key: (string | null)[]): void {
        const id = rowId(key);
        const next = new Map(deletes.value);

        if (next.has(id)) {
            next.delete(id);
        } else {
            next.set(id, key);
        }

        deletes.value = next;
    }

    function discard(): void {
        edits.value = new Map();
        inserts.value = [];
        deletes.value = new Map();
    }

    const counts = computed(() => ({
        cells: [...edits.value.values()].reduce(
            (sum, row) => sum + Object.keys(row.values).length,
            0,
        ),
        inserts: inserts.value.length,
        deletes: deletes.value.size,
    }));

    const total = computed(
        () => counts.value.cells + counts.value.inserts + counts.value.deletes,
    );

    /** Reihenfolge wie in der Vorschau des Wireframes: neue Zeilen, Änderungen, Löschungen. */
    function payload(): RowChange[] {
        return [
            ...[...inserts.value].reverse().map((row) => ({
                op: 'insert' as const,
                key: null,
                values: row.values,
            })),
            ...[...edits.value.values()]
                .filter((row) => !deletes.value.has(rowId(row.key)))
                .map((row) => ({
                    op: 'update' as const,
                    key: row.key,
                    values: row.values,
                })),
            ...[...deletes.value.values()].map((key) => ({
                op: 'delete' as const,
                key,
                values: null,
            })),
        ];
    }

    return {
        edits,
        inserts,
        deletes,
        setCell,
        insert,
        setInsertCell,
        removeInsert,
        toggleDelete,
        discard,
        counts,
        total,
        payload,
    };
}
