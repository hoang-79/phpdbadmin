# ADR-005 — SQL-Editor: CodeMirror 6

| Feld | Wert |
|---|---|
| Status | Angenommen |
| Datum | 28.10.2026 (Foundation; F-4, Freigabe der Pakete durch Hoang) |
| Betrifft | Oberfläche SQL-Reiter |

## Kontext

Der Editor braucht Hervorhebung und Vervollständigung je Dialekt aus dem Schema (Q-01). Probeweise installiert: `codemirror` 6.0.2, `@codemirror/lang-sql` 6.10.0 (MIT, 17 Pakete). Belegt: Tabellen aus 1'002 Tabellen in höchstens 2 ms, Aliasse aufgelöst, dialekteigene Schlüsselwörter (`ILIKE` nur PostgreSQL); Chunk 425 KB (138 KB gzip).

## Optionen

| Option | Vorteile | Nachteile |
|---|---|---|
| **A — CodeMirror 6** | modular, MIT, Dialekte für alle vier, klein genug | eigene Vue-Hülle |
| B — Monaco | VS-Code-Gefühl | mehrere MB, SQL-Dialekte schwächer, Web-Worker |
| C — Eigenbau mit Textarea | keine Abhängigkeit | keine Vervollständigung, viel Arbeit |

## Entscheid

**Option A**, als eigener Chunk nur auf dem SQL-Reiter geladen.

## Konsequenzen

- Sichtprüfung im Browser durch Hoang steht aus (Probe-Seite `/probe/editor`).
- Bei Updates der Pakete Lizenzliste neu erzeugen (ADR-010).

Siehe [Solution-Architektur](../../03-foundation/solution-architecture.md) · [Feasibility](../../02-feasibility/feasibility.md)
