# ADR-007 — Export und Import in Paketen im Hintergrund

| Feld | Wert |
|---|---|
| Status | Angenommen |
| Datum | 28.10.2026 (Foundation; F-8, F-3) |
| Betrifft | Transfer |

## Kontext

Gemessen bei einer Million Zeilen: `lazyById(5000)` 3–3,3 MB auf allen vier; `cursor()` gepuffert 62 MB (MySQL/MariaDB), PostgreSQL +105 MB im Prozess. Import von 100'000 Zeilen in Paketen zu 1'000: 143–502 ms. Nach dem Füllen fehlt MySQL/MariaDB die Statistik — Sortieren über den Index 300 statt 2 ms.

## Optionen

| Option | Vorteile | Nachteile |
|---|---|---|
| **A — Pakete über den Schlüssel, im Hintergrund, Datei mit Frist** | gleich auf allen vier, wenig Speicher | Warteschlange nötig (ohne Worker synchron mit Grenze) |
| B — Server-Werkzeuge (`mysqldump`, `pg_dump`) | schnell, vollständig | nicht überall installiert, Versionen müssen passen — nur für Sicherung (E-03) |

## Entscheid

**Option A** für Export und Import; Probelauf beim Import in einer zurückgerollten Transaktion; nach eigenen Importen `ANALYZE` (E-06). Server-Werkzeuge nur für Sicherung und Wiederherstellung (E-03, v1.1).

## Konsequenzen

- Exportdateien unter `storage/app/private/transfers`, Frist 24 Stunden.
- Tabellen ohne Primärschlüssel exportieren über `cursor()` ungepuffert bzw. Server-Cursor.

Siehe [Solution-Architektur](../../03-foundation/solution-architecture.md) · [Feasibility](../../02-feasibility/feasibility.md)
