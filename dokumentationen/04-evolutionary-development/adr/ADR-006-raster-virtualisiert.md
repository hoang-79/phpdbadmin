# ADR-006 — Raster: TanStack Virtual und Blättern über den Schlüssel

| Feld | Wert |
|---|---|
| Status | Angenommen — bestätigt von Hoang am 28.10.2026 (Endlos scrollen statt Seiten zu 50) |
| Datum | 28.10.2026 (Foundation; F-3) |
| Betrifft | Datenraster |

## Kontext

Tabellen mit Millionen Zeilen müssen sofort erscheinen (N-01). Gemessen: erste Seite über den Schlüssel 0,1–0,6 ms in der Datenbank, `OFFSET 900000` 18–232 ms; im Browser 281 ms für die erste Seite, davon fast alles Laravel und Herd; im DOM bleiben rund 25 Zeilen.

## Optionen

| Option | Vorteile | Nachteile |
|---|---|---|
| **A — eigenes Raster mit `@tanstack/vue-virtual`** (MIT, 34 KB Chunk) | volle Kontrolle über Bearbeiten, Zustände, Gestaltung | Raster selbst bauen |
| B — fertiges Datenraster (AG Grid Community u. a.) | viel fertig | schwer, eigene Bedienlogik, Gestaltung schwer anzugleichen |
| C — ohne Virtualisierung, Seiten zu 100 | einfach | kein flüssiges Scrollen, Seitenwechsel |

## Entscheid

**Option A.** Seiten zu 200 Zeilen über den Schlüssel, die nächste vorgeladen; `OFFSET` nur ohne Primärschlüssel; erste Spalte fixiert, waagrecht scrollen nur hier.

## Konsequenzen

- Geänderte, neue und zu löschende Zeilen sind Zustand im Browser bis «Anwenden» (D-04).
- Kartenliste unter 768 px (G-07).

Siehe [Solution-Architektur](../../03-foundation/solution-architecture.md) · [Feasibility](../../02-feasibility/feasibility.md)
