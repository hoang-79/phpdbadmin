import { formatBytes } from '@/lib/format';
import { t, tc } from '@/lib/i18n';
import type {
    Cell,
    FilterOperatorOption,
    GridColumn,
    GridFilter,
} from '@/types';

/**
 * Darstellung im Datenraster (D-01, O-04): gleiche Schreibweise für alle vier Datenbanken. NULL ist etwas anderes als
 * ein leerer Text und wird deshalb kursiv und blass als «NULL» gezeigt.
 */
export type SortItem = { column: string; direction: 'asc' | 'desc' };

export function isNullCell(cell: Cell): boolean {
    return cell === null;
}

export function isBinaryCell(
    cell: Cell,
): cell is { binary: number; hex: string } {
    return typeof cell === 'object' && cell !== null && 'binary' in cell;
}

export function isTruncatedCell(
    cell: Cell,
): cell is { text: string; length: number } {
    return typeof cell === 'object' && cell !== null && 'text' in cell;
}

/** Text einer Zelle für die Anzeige in einer Zeile. */
export function cellText(cell: Cell): string {
    if (cell === null) {
        return 'NULL';
    }

    if (typeof cell === 'boolean') {
        return cell ? 'true' : 'false';
    }

    if (typeof cell === 'number') {
        return String(cell);
    }

    if (isBinaryCell(cell)) {
        return t('Binär · :size', { size: formatBytes(cell.binary) });
    }

    if (isTruncatedCell(cell)) {
        return `${cell.text}…`;
    }

    return cell.replace(/\s+/g, ' ');
}

/** Der Wert, der in einen Filter oder eine Adresse passt — Schlüssel und Fremdschlüssel. */
export function cellValue(cell: Cell): string | null {
    if (cell === null || typeof cell === 'object') {
        return null;
    }

    return typeof cell === 'boolean' ? (cell ? '1' : '0') : String(cell);
}

export function isNumericColumn(column: GridColumn): boolean {
    return column.kind === 'number';
}

export function parseSort(sort: string | undefined): SortItem[] {
    return (sort ?? '')
        .split(',')
        .filter((part) => part !== '')
        .map((part) =>
            part.startsWith('-')
                ? { column: part.slice(1), direction: 'desc' }
                : { column: part, direction: 'asc' },
        );
}

export function formatSort(items: SortItem[]): string | undefined {
    const text = items
        .map((item) => `${item.direction === 'desc' ? '-' : ''}${item.column}`)
        .join(',');

    return text === '' ? undefined : text;
}

/**
 * Klick auf eine Überschrift: aufsteigend → absteigend → ohne. Mit Umschalt bleibt die übrige Sortierung und die
 * Spalte kommt dazu.
 */
export function toggleSort(
    current: SortItem[],
    column: string,
    keepOthers: boolean,
): SortItem[] {
    const existing = current.find((item) => item.column === column);
    const others = keepOthers
        ? current.filter((item) => item.column !== column)
        : [];

    if (!existing) {
        return [...others, { column, direction: 'asc' }];
    }

    if (existing.direction === 'asc') {
        return keepOthers
            ? current.map((item) =>
                  item.column === column
                      ? { column, direction: 'desc' as const }
                      : item,
              )
            : [{ column, direction: 'desc' }];
    }

    return others;
}

/** «status = offen», «created_at ≥ 21.10.2026», «notes ist NULL». */
export function filterLabel(
    filter: GridFilter,
    operators: FilterOperatorOption[],
): string {
    const operator = operators.find((option) => option.value === filter.o);

    return [
        filter.c,
        operator?.symbol ?? filter.o,
        operator?.needsValue ? filter.v : null,
    ]
        .filter((part) => part !== null && part !== undefined)
        .join(' ');
}

/** «212 Zeilen», «≈ 48’210 Zeilen» — genau oder geschätzt. */
export function totalLabel(total: {
    count: number | null;
    exact: boolean;
}): string {
    if (total.count === null) {
        return t('Anzahl offen');
    }

    return total.exact
        ? tc(':count Zeile|:count Zeilen', total.count)
        : tc('≈ :count Zeile|≈ :count Zeilen', total.count);
}
