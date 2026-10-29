import type { DiagramTable } from '@/types';

/**
 * Beziehungsdiagramm (S-07): Masse und Linien wie auf dem Server (DiagramLayout, DiagramSvg) — die Oberfläche zeigt,
 * was die SVG-Datei enthalten wird.
 */
export const HEADER = 30;
export const ROW = 20;

/** Mitte der Zeile einer Spalte; ist sie ausgeblendet, die Mitte des Kopfes. */
export function rowY(table: DiagramTable, column: string): number {
    const index = table.columns.findIndex((item) => item.name === column);

    return index < 0
        ? table.y + Math.floor(HEADER / 2)
        : table.y + HEADER + index * ROW + Math.floor(ROW / 2);
}

/** Linie vom ersten Feld des Fremdschlüssels zum ersten verwiesenen Feld, von der Seite, die zur anderen zeigt. */
export function relationPath(
    child: DiagramTable,
    column: string,
    parent: DiagramTable,
    foreignColumn: string,
): string {
    const childY = rowY(child, column);
    const parentY = rowY(parent, foreignColumn);

    if (child.name === parent.name) {
        const x = child.x + child.width;

        return `M${x} ${childY} C${x + 48} ${childY}, ${x + 48} ${parentY}, ${x} ${parentY}`;
    }

    const childRight = child.x + child.width / 2 < parent.x + parent.width / 2;
    const startX = childRight ? child.x + child.width : child.x;
    const endX = childRight ? parent.x : parent.x + parent.width;
    const bend = Math.max(40, Math.floor(Math.abs(endX - startX) / 2));
    const direction = childRight ? 1 : -1;

    return `M${startX} ${childY} C${startX + direction * bend} ${childY}, ${endX - direction * bend} ${parentY}, ${endX} ${parentY}`;
}

/** Text kürzen mit «…», wenn er breiter als der Platz wäre. */
export function shortened(text: string, space: number, char = 7.3): string {
    const fits = Math.max(3, Math.floor(space / char));

    return text.length > fits ? `${text.slice(0, fits - 1)}…` : text;
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * SVG-Datei vom Server holen und als Download anbieten. Wirft bei einem Fehler — die Seite meldet ihn.
 */
export async function downloadSvg(
    url: string,
    body: unknown,
    fallbackName: string,
): Promise<void> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'image/svg+xml, application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    const disposition = response.headers.get('Content-Disposition') ?? '';
    const encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
    const name = encoded ? decodeURIComponent(encoded) : fallbackName;
    const link = document.createElement('a');
    link.href = URL.createObjectURL(await response.blob());
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
}
