# ADR-001 — Dialekt-Schicht: ein Vertrag, vier Umsetzungen

| Feld | Wert |
|---|---|
| Status | Angenommen |
| Datum | 28.10.2026 (Foundation; F-1, F-3) |
| Betrifft | Katalog, Struktur, SQL-Editor, Server-Verwaltung |

## Kontext

phpdbadmin soll vier Datenbanken mit derselben Bedienung abdecken (P-01). Laravel 13 liefert Verbindungen, Query Builder, Schema-Grammatiken und Introspektion für alle vier; die [Feasibility](../../02-feasibility/feasibility.md) zeigt, wo es nicht reicht: Check-Constraints, Trigger, Routinen, eigene Typen (`getTypes()` nur PostgreSQL), Prozesse, Rechte, Zeitlimits, Kontorechte. Ausserdem liefert MariaDB ohne Schemaangabe die Tabellen aller Datenbanken des Servers (3'603 statt 1'000).

## Optionen

| Option | Vorteile | Nachteile |
|---|---|---|
| A — eigene Abstraktion für alles | volle Kontrolle | baut nach, was Laravel schon kann und testet; viermal so viel Code |
| **B — Laravel zuerst, Dialekt nur für Unterschiede** | wenig eigener Code, Laravel-Updates kommen mit | Grenze zwischen Laravel und Dialekt muss klar sein |
| C — Paket eines Dritten (z. B. Adminer-Treiber) | viel fertig | fremde Struktur, Lizenzfrage, Bedienkonzept passt nicht |

## Entscheid

**Option B.** Ein Vertrag `App\Dialects\Dialect` mit vier Umsetzungen (`MySqlDialect`, `MariaDbDialect` als Ableitung, `PostgresDialect`, `SqliteDialect`). Was Laravel kann, ruft der Dienst direkt; der Dialekt liefert nur, was sich unterscheidet. Der Katalog übergibt **immer** das Schema.

## Konsequenzen

- Jede Dialekt-Methode hat einen Vertragstest, der gegen alle vier Herd-Dienste läuft; die Proben aus `tests/Feature/Feasibility` werden dazu umgebaut.
- Neue Datenbanken (SQL Server, R-14) bekommen eine fünfte Umsetzung, die Oberfläche bleibt.
- Typnamen erscheinen im Original und mit einem gemeinsamen Oberbegriff.

Siehe [Solution-Architektur](../../03-foundation/solution-architecture.md) · [Feasibility](../../02-feasibility/feasibility.md)
