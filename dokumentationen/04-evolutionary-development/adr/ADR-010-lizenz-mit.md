# ADR-010 — Lizenz MIT

| Feld | Wert |
|---|---|
| Status | Angenommen |
| Datum | 28.10.2026 (Entscheid Hoang, R-40; F-9) |
| Betrifft | Repository, Abhängigkeiten |

## Kontext

Hoang hat MIT gewählt (Markt überwiegend kostenlos, Schaufenster für Nguyen Development). Geprüft: Composer 101 Pakete MIT/BSD/Apache, `nette/*` wahlweise BSD-3; npm-Laufzeit MIT/ISC/BSD/Apache; neue Pakete MIT.

## Optionen

| Option | Vorteile | Nachteile |
|---|---|---|
| **A — MIT** | einfach, verbreitet | kein Schutz gegen geschlossene Ableitungen |
| B — GPL-3 | Ableitungen bleiben offen | schreckt Einsatz in Firmen ab |

## Entscheid

**Option A.** `LICENSE` mit MIT vor dem ersten öffentlichen Push; Code aus GPL-Projekten (phpMyAdmin, phpPgAdmin, Adminer) wird nicht übernommen.

## Konsequenzen

- Lizenzliste vor jedem Release erneut erzeugen.
- Hinweis «nicht verbunden mit phpMyAdmin» in README und Website (F-10).

Siehe [Solution-Architektur](../../03-foundation/solution-architecture.md) · [Feasibility](../../02-feasibility/feasibility.md)
