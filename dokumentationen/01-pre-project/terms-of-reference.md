# Terms of Reference — phpdbadmin

| Feld | Wert |
|---|---|
| Status | 🟡 **v1.0 zur Verabschiedung** (28.10.2026) — nach CR-001 Fassung 3 (Weiterführen) und Feasibility v1.0 (6 bestanden, 4 bedingt); verabschiedet, sobald Hoang zustimmt |
| Eingang | [CR-001](../00-change_request/CR-001-phpdbadmin.md) Fassung 3 (Weiterführen 28.10.2026), [Feasibility](../02-feasibility/feasibility.md) v1.0, [Ausgangslage und Rückfragen](ausgangslage-und-rueckfragen.md), [Ideen-Backlog](ideen-backlog.md) |
| Ausgang | [Foundation](../03-foundation/README.md): Gestaltung, PRL, Solution-Architektur, Datenmodell, Timebox-Plan, ADR-001 bis ADR-010 |
| Träger | Nguyen Development, Einzelfirma von Hoang Nguyen, Zürich (entschieden 28.10.2026, R-01) |

## 1. Projektziel

Nguyen Development baut **phpdbadmin**, ein Werkzeug im Browser, mit dem man **MariaDB, MySQL, PostgreSQL und SQLite mit derselben Bedienung** ansieht, bearbeitet, strukturiert und austauscht — geführt (Vorschau, Sammeln, Rückgängig), sicher (Konten ohne Passwort, Rechte, Schreibschutz, Protokoll) und schnell, auf Laravel 13, lokal in Herd und selbst gehostet auf jedem PHP-Server. v1.0 ersetzt phpMyAdmin und phpPgAdmin im Alltag von Hoang.

## 2. Hintergrund

Die PHP-Klassiker stehen technisch still (phpMyAdmin 5.2 nicht für PHP 8.4+ freigegeben, phpPgAdmin seit 2023 ohne Commit), Adminer ist breit, aber knapp, und die modernen Werkzeuge sind nicht PHP oder nicht im Browser ([Marktbild](../00-change_request/marktbild.md)). Laravel 13 bringt Verbindungen zur Laufzeit, Introspektion für alle Treiber und DDL mit Vorschau bereits mit; die passwortlose Anmeldung und die Tests auf mehreren Datenbanken sind aus Basalto erprobt. Die [Feasibility](../02-feasibility/feasibility.md) hat alle zehn Prüfpunkte mit Proben auf MySQL 8.4, MariaDB 10.11, PostgreSQL 18 und SQLite belegt: sechs bestanden, vier bedingt — die Auflagen (Anwenden je Treiber, SQLite im Arbeitsprozess, Kontowarnung, maskierte Meldungen, Name absichern) stehen als Anforderungen in CR-001.

## 3. Systemkontext

![Systemkontext (C4 Level 1)](../00-change_request/diagrams/systemkontext-c4-l1.svg)

Eine Installation mit ihren Personen (Entwickler, Administrator, Mitarbeitende, Authenticator) und Umsystemen (MariaDB-, MySQL- und PostgreSQL-Server, SQLite-Dateien, Mail, später SSH-Bastion). Details: [CR-001 § 3.2](../00-change_request/CR-001-phpdbadmin.md#32-architektur).

## 4. Rahmen

| Aspekt | Festlegung |
|---|---|
| Träger | Nguyen Development (R-01) |
| Business Sponsor, Visionary, Projektleitung, Technical Coordinator | Hoang Nguyen in Personalunion (R-02) |
| Team | Hoang Nguyen + Claude Code (Pair); Rückfragen interaktiv mit Empfehlung zuerst, Defaults ohne Rückfrage |
| Business Ambassador | Hoang als erster Nutzer; Gegenleser ab Alpha gesucht |
| Vorrang | Basalto bis zur Beta vor phpdbadmin; phpdbadmin in kleinen Timeboxes daneben (entschieden, R-03) |
| Zeitmodell | Stufen statt Termine; rund 42–53 PT bis v1.2 (nach Feasibility) |
| Technischer Rahmen | Laravel 13.39 (PHP 8.5), Inertia v3, Vue 3, TypeScript, Tailwind 4, Wayfinder, Fortify nur für Passkeys; App-Datenbank SQLite; CodeMirror 6 und TanStack Virtual (probeweise installiert, ADR-005/006); Herd lokal mit MySQL 8.4, MariaDB 10.11, PostgreSQL 18 |
| Starter-Kit | Passwort, Dashboard und Profil werden entfernt (Vorgabe Hoang 28.10.2026) |
| Betriebsmodell | lokal und selbst gehostet, kein SaaS (entschieden, R-11) |
| Lizenz | Open Source unter MIT (entschieden, R-40); Abhängigkeiten MIT-verträglich |
| Name | phpdbadmin, endgültig (entschieden, R-04); Markenrecherche vor dem ersten öffentlichen Auftritt (F-10) |
| Sprache | Oberfläche Deutsch und Englisch (Sie-Form); Bezeichner englisch; Doku Deutsch mit echten Umlauten |
| Doku | DSDM in `dokumentationen/`, bei jedem Commit nachgeführt; ein PDF je Phase; Diagramme nur aus `werkzeuge/diagramme.py`; Vault-Projektnotiz |

## 5. Umfang

### 5.1 Enthalten

| | Stufe 0–2 — v1.0 | Stufe 3 — v1.1 | Stufe 4 — v1.2 |
|---|---|---|---|
| **Zugang** | Passkey, Code per Mail, Einladung, Rollen und Rechte je Verbindung, Protokoll | — | Sitzungen verwalten |
| **Verbindungen** | vier Treiber, testen, verschlüsselt, Umgebung mit Schreibschutz, erlaubte Hosts | — | Herd-Erkennung, SSH-Tunnel |
| **Arbeiten** | Navigator, Befehlspalette, Reiter, Raster, Filter, Bearbeiten mit Vorschau und Rückgängig, Struktur ansehen und ändern, SQL-Editor mit Grenzen | Views, Routinen, Trigger, Sequenzen, Beziehungsdiagramm, EXPLAIN | Schemavergleich, Laravel-Migration, KI optional |
| **Austausch** | Export CSV, JSON, SQL; Import CSV und SQL | Sicherung und Wiederherstellung, Excel | — |
| **Server** | — | Übersicht, Prozesse, Variablen, Benutzer und Rechte, Wartung | — |

### 5.2 Nicht enthalten

SQL Server und Oracle (vorerst) · NoSQL · SaaS mit fremden Zugangsdaten · Desktop-App · Berichte und BI · Replikation und Cluster · Hosting-Panel · Passwort-Anmeldung, Dashboard, Profilseite.

## 6. Priorisierung: Stufen statt Termine

1. **Stufe 0 — Fundament:** Starter-Kit bereinigt (Passwort, Dashboard, Profil weg), Passkey und Code, Verbindungen, Dialekt-Schicht, Tests auf vier Datenbanken
2. **Stufe 1 — Lesen:** Navigator, Raster, Filter, Struktur ansehen, SQL-Editor, Befehlspalette → **Alpha**
3. **Stufe 2 — Bearbeiten:** Bearbeiten mit Vorschau und Rückgängig, Struktur ändern, Export und Import, Rechte, Protokoll → **v1.0**
4. **Stufe 3 — Verwalten:** Server-Verwaltung, weitere Objekte, Beziehungsdiagramm, EXPLAIN, Sicherung → **v1.1**
5. **Stufe 4 — Komfort und Team:** Schemavergleich, Laravel-Migration, Herd, SSH-Tunnel, KI optional → **v1.2**

Der Must-Anteil bis v1.0 liegt nach CR-001 Fassung 3 bei 68 % (62 Anforderungen, davon 42 Must); die PRL bringt ihn unter 60 %.

## 7. Fahrplan und Meilensteine

![Stufen und Meilensteine](../00-change_request/diagrams/stufen-fahrplan.svg)

| Meilenstein | Ergebnis | Abnahme |
|---|---|---|
| **Alpha** (nach Stufe 1) | lesend im eigenen Alltag auf allen vier Datenbanken | Hoang nutzt es eine Woche statt phpMyAdmin/TablePlus zum Nachsehen; Tests auf vier Treibern grün |
| **v1.0** (nach Stufe 2) | ersetzt phpMyAdmin und phpPgAdmin | Kernabläufe auf vier Treibern getestet; White-Box-Sicherheitsprüfung ohne hohen Befund; WCAG 2.2 AA in den Kernbildschirmen |
| **v1.1** (nach Stufe 3) | Server-Verwaltung vollständig | Benutzer und Rechte auf drei Server-Treibern getestet |
| **v1.2** (nach Stufe 4) | Team und Laravel | Schemavergleich und Migration auf zwei Treibern gegenseitig |

Rückfallposition: nach Stufe 2 ist das Werkzeug vollständig nutzbar; Verwalten und Team folgen bei Gelegenheit.

## 8. Erfolgskriterien

Hoang ersetzt phpMyAdmin, phpPgAdmin und den Desktop-Client im Alltag ab v1.0 · häufige Aufgaben in höchstens drei Klicks oder zwei Tastenkürzeln · dieselben Abläufe automatisch auf vier Datenbanken getestet · erste Seite einer Tabelle mit einer Million Zeilen lokal unter einer Sekunde · Sicherheitsprüfung ohne hohen Befund · WCAG 2.2 AA in den Kernbildschirmen.

## 9. Risiken

Aus [CR-001 § 10](../00-change_request/CR-001-phpdbadmin.md#10-risiken-auf-oberster-ebene): Zeit neben Basalto und Fristo (R1) · Treiberunterschiede (R2) · Sicherheit eines Werkzeugs mit Zugang zu allen Datenbanken (R3) · Leistung des Rasters (R4) · Mitbewerber Jaxon DbAdmin und phpMyAdmin 6 (R5) · Name (R6) · Arbeitsvertrag (R7). Durch die Feasibility gesenkt: R2 (Dialekt-Unterschiede gemessen und eingegrenzt), R4 (erste Seite 0,1–0,6 ms in der Datenbank). Neu sichtbar: Datenverlust beim SQLite-Umbau in einer Transaktion und stille Abbrüche bei MySQL — beides mit Anforderung (N-03, Q-02) und Probe belegt.

## 10. Entscheide und Vorbedingungen

| Reihe | Wo | Stand |
|---|---|---|
| R-01 bis R-42 | [Rückfragen-Katalog](ausgangslage-und-rueckfragen.md) | erste Runde 28.10.2026: R-01, R-03, R-04, R-11, R-40 entschieden, übrige mit Annahme |
| Starter-Kit ohne Passwort, Dashboard, Profil | Hoang 28.10.2026 | entschieden |
| CR-001 Weiterführen | Hoang 28.10.2026 («Feasibility starten») | entschieden |
| Feasibility F-1 bis F-10, Pakete CodeMirror und TanStack probeweise, MySQL 8.4 in Herd | [Feasibility](../02-feasibility/feasibility.md), Freigaben Hoang 28.10.2026 | abgeschlossen 28.10.2026 |

**Vorbedingungen vor Stufe 0:** Entscheid «Weiterführen» auf CR-001 ✓ · Feasibility mit F-1 bis F-10 ✓ · MySQL in Herd ✓ · Terms of Reference verabschiedet (Hoang) · Foundation (Gestaltung, PRL, Architektur, Datenmodell, Timebox-Plan, ADRs). **Vor dem ersten öffentlichen Auftritt:** Auflagen aus [Name und Marke](../02-feasibility/name-und-marke.md): Domains (`.ch`, nach Wahl `.org`, `.net`, `.io`, `.dev`) gesichert, Repo `hoang-79/phpdbadmin` (Organisation `phpdbadmin` ist belegt), Hinweis «nicht verbunden mit phpMyAdmin» · Lizenzdatei MIT im Repo · Nebenbeschäftigung gemeldet (R-05).

## 11. DSDM-Prinzipien-Check

| Prinzip | Wie eingelöst |
|---|---|
| Geschäftsnutzen zuerst | Alpha und v1.0 lösen Hoangs eigenes Problem zuerst; jede Stufe ist für sich nutzbar |
| Rechtzeitig liefern | Stufen statt Termine; Alpha früh als nutzbarer Zwischenstand; Rückfallposition nach Stufe 2 |
| Zusammenarbeiten | Hoang + Claude im Pair; Rückfragen interaktiv mit Empfehlung; Gegenleser ab Alpha |
| Qualität nie opfern | Tests gegen vier Datenbanken, Vertragstests der Dialekt-Schicht, Sicherheitsprüfung, WCAG, Kontraste gerechnet |
| Inkrementell auf festem Fundament bauen | Feasibility-Proben auf allen vier Treibern, Stufe 0 Fundament vor jeder Funktion |
| Iterativ entwickeln | Timeboxes mit Bedienkonzept vorher, Durchlauf und Abnahme nachher, Protokoll je Timebox |
| Kontinuierlich und klar kommunizieren | Doku bei jedem Commit, ein PDF je Phase, Vault-Notiz |
| Kontrolle zeigen | Rückfragen-Katalog, PRL mit Must-Anteil, Timebox-Protokolle, ADRs |
