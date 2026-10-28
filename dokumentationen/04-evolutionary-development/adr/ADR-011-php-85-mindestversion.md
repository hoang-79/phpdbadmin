# ADR-011 — PHP 8.5 als Mindestversion

| Feld | Wert |
|---|---|
| Status | Angenommen (Hoang, 28.10.2026) |
| Datum | 28.10.2026 (Foundation) |
| Betrifft | Installation, Hosting, `composer.json` |

## Kontext

Der Schutz von SQLite gegen `ATTACH` fremder Dateien braucht `Pdo\Sqlite::setAuthorizer()`. Geprüft in Herd: PHP 8.4.27 hat `Pdo\Sqlite`, aber keinen Authorizer; PHP 8.5.12 hat ihn. Laravel 13 verlangt nur PHP 8.3, `composer.json` steht auf `^8.3`.

## Optionen

| Option | Vorteile | Nachteile |
|---|---|---|
| **A — PHP 8.5 vorschreiben** | Schutz vollständig, ein Weg | Hosting mit PHP 8.4 fällt weg |
| B — PHP 8.4 erlauben, SQLite-`ATTACH` dort per Textprüfung sperren | breiter einsetzbar | Textprüfung ist schwächer; zwei Wege testen |
| C — PHP 8.4 erlauben, SQLite-Verbindungen dort nur lesend und ohne Editor | einfach | halbe Funktion |

## Entscheid

**Option A** (entschieden von Hoang am 28.10.2026). `composer.json` auf `^8.5` in TB 00; Herd läuft bereits mit 8.5.

## Konsequenzen

- Die Installationsanleitung nennt PHP 8.5 und `pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`.

Siehe [Solution-Architektur](../../03-foundation/solution-architecture.md) · [Feasibility](../../02-feasibility/feasibility.md)
