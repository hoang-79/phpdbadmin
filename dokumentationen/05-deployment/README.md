# 05 — Deployment

> **PDF der Phase:** [deployment.pdf](deployment.pdf) — bei jeder Änderung neu gebaut (`python3 dokumentationen/werkzeuge/pdf-bauen.py --nur 05`).

📋 **Platzhalter.** Zielbild nach R-11 (entschieden 28.10.2026: lokal und selbst gehostet) und der Annahme R-30:

| Stufe | Ort | Bemerkung |
|---|---|---|
| Dev | `https://phpdbadmin.test` | Laravel Herd (macOS), PHP 8.5; MySQL 8.4.11 :3307, MariaDB 10.11 :3306, PostgreSQL 18 :5432 |
| Demo | offen | öffentliche Demo mit SQLite-Beispieldatenbanken nach v1.0 (cyon oder Laravel Cloud) |
| Produktion | beim Anwender | selbst gehostet neben den eigenen Datenbanken; Installationsanleitung, Einrichtungsbefehl, Hosts-Liste |

**Pipeline (Regel «GitHub Actions sparsam»):** je Push ein schlanker Auftrag (Pint, Larastan, vue-tsc, `vp check`, Tests auf SQLite); Matrix MySQL/MariaDB/PostgreSQL wöchentlich und von Hand; `paths-ignore` für Doku, `concurrency` mit `cancel-in-progress`, `timeout-minutes`, Caches für Composer und npm.
