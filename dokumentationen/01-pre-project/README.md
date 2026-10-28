# 01 — Pre-Project

> **PDF der Phase:** [terms-of-reference.pdf](terms-of-reference.pdf) — bei jeder Änderung neu gebaut (`python3 dokumentationen/werkzeuge/pdf-bauen.py --nur 01`).

Projekteingang ist [CR-001](../00-change_request/CR-001-phpdbadmin.md). Das Pre-Project klärt mit Hoang, was feststeht und was offen ist, sammelt die Ideen und hält den Auftrag in den Terms of Reference fest.

| Dokument | Inhalt | Status |
|---|---|---|
| [Ausgangslage und Rückfragen](ausgangslage-und-rueckfragen.md) | Was feststeht, was nachgelesen ist, **Interview R-01 bis R-42** mit Annahmen | 🔨 erste Runde 28.10.2026 — R-01, R-03, R-04, R-11, R-40 entschieden |
| [Ideen-Backlog](ideen-backlog.md) | Alle Ideen mit Nutzen, Herkunft und MoSCoW-Vorschlag | 🔨 erste Sammlung |
| [Terms of Reference](terms-of-reference.md) | Projektziel, Hintergrund, Systemkontext, Rahmen, Umfang, Priorisierung, Fahrplan, Erfolgskriterien, Risiken, Vorbedingungen, DSDM-Check | ✅ **v1.0 verabschiedet 28.10.2026** |

**Diagramme** (`diagrams/`): Anmeldung ohne Passwort (Code und Passkey, Entferntes aus dem Starter-Kit). Systemkontext und Fahrplan liegen in `00-change_request/diagrams/`.

**Entschieden am 28.10.2026 (Hoang):** Admin-Werkzeug für MariaDB, MySQL, PostgreSQL und SQLite auf Laravel · Starter-Kit ohne Passwort (nur Passkey und Code), ohne Dashboard, ohne Profil · Doku nach DSDM wie Fristo und Basalto · Träger Nguyen Development, Lizenz MIT (R-01, R-40) · lokal und selbst gehostet, kein SaaS (R-11) · neben Basalto, das bis zur Beta Vorrang hat (R-03) · Name phpdbadmin endgültig (R-04).
