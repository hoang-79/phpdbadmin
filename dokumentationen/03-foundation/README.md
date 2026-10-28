# 03 — Foundation

> **PDF der Phase:** [foundation.pdf](foundation.pdf) — bei jeder Änderung neu gebaut (`python3 dokumentationen/werkzeuge/pdf-bauen.py --nur 03`).

📋 **Platzhalter** — folgt nach der Feasibility.

| Dokument (geplant) | Inhalt |
|---|---|
| Gestaltung | Marke, Farben (Kontraste gerechnet), Schrift, Bausteine, hell und dunkel, Umgebungsfarben |
| PRL | Anforderungen aus CR-001 § 4 auf Timeboxes verteilt, Must-Anteil je Stufe unter 60 % |
| Solution-Architektur | C4 Level 2, Dialekt-Schicht, Querschnitt (Rechte, Grenzen, Vorschau, Protokoll), Sequenzen |
| Datenmodell | App-Datenbank: Konten, Passkeys, Anmeldecodes, Einladungen, Verbindungen, Rechte, Verlauf, gespeicherte Abfragen, Protokoll, Rückgängig-Sicherungen — ER-Diagramm |
| Timebox-Plan | Schnitt der Stufen 0–2 in Timeboxes |
| Mockups | Wireframes Desktop und Mobil für Anmeldung, Verbindungen, Arbeitsbildschirm, Struktur, SQL, Export/Import |

**Pflichtdiagramme** (Doku-Regel 4): C4 L2 · Use Cases je Rolle · ER der App-Datenbank · Sequenzen Anmeldung, Schreiben mit SQL-Vorschau, Export im Hintergrund.
