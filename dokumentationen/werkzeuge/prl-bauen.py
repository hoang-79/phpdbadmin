#!/usr/bin/env python3
"""PRL (Prioritised Requirements List) für phpdbadmin — erzeugt `03-foundation/prl.md` aus CR-001 § 4.

Nach dem Generator von Basalto (eigenes Projekt). Quelle sind die Tabellen A/V/O/D/S/Q/E/W/B/N im CR
(Nr. · Anforderung · Prio M/S/C · Stufe · Abnahme). Das Skript ergänzt je Anforderung Timebox, Grösse in Punkten
(S 1 · M 2 · L 3 · XL 5), die Priorität im Plan und den Stand aus der Feasibility. DSDM: Must-Anteil je Stufe höchstens
60 Prozent der Punkte — Must-Anforderungen des CR mit einem Rückfallweg laufen im Plan als «Should ↓» und dürfen bei
Zeitnot in die nächste Stufe rutschen.

Aufruf: `python3 dokumentationen/werkzeuge/prl-bauen.py` (schreibt prl.md) · `--pruefen` (nur Kennzahlen).
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

WURZEL = Path(__file__).resolve().parents[1]
CR = WURZEL / "00-change_request" / "CR-001-phpdbadmin.md"
ZIEL = WURZEL / "03-foundation" / "prl.md"

PUNKTE = {"S": 1, "M": 2, "L": 3, "XL": 5}
PRIO = {"M": "Must", "S": "Should", "C": "Could", "W": "Won't"}

# Personentage je Stufe (CR-001 § 9 nach der Feasibility, obere Schätzung 53 PT); je Timebox anteilig nach Punkten
STUFEN_PT = {"0": 8, "1": 11, "2": 14, "3": 10, "4": 10}
STUFEN_NAME = {"0": "0 Fundament", "1": "1 Lesen (Alpha)", "2": "2 Bearbeiten (v1.0)", "3": "3 Verwalten (v1.1)", "4": "4 Komfort und Team (v1.2)"}

# Timeboxes: Nummer → (Titel, Stufe)
TIMEBOXES: dict[str, tuple[str, str]] = {
    "00": ("Fundament: Starter-Kit, Anmeldung, Verbindungen, Dialekt-Schicht", "0"),
    "01": ("Katalog und Navigator", "1"),
    "02": ("Datenraster und Filter", "1"),
    "03": ("SQL-Editor und Befehlspalette → Alpha", "1"),
    "04": ("Bearbeiten mit Vorschau und Rückgängig", "2"),
    "05": ("Struktur ändern", "2"),
    "06": ("Export und Import", "2"),
    "07": ("Konten, Rechte, Teilen, Protokoll", "2"),
    "08": ("Sicherheit, Barrierefreiheit, Installation → v1.0", "2"),
    "09": ("Server-Verwaltung", "3"),
    "10": ("Weitere Objekte, Analyse, Sicherung → v1.1", "3"),
    "11": ("Komfort: Schemavergleich, Laravel, Herd, SSH", "4"),
    "12": ("Team und KI → v1.2", "4"),
}

# Anforderung → (Timebox, Grösse, Priorität im Plan oder None = wie CR, Stand aus der Feasibility oder "")
ZUORDNUNG: dict[str, tuple[str, str, str | None, str]] = {
    # A — Anmeldung und Zugang
    "A-01": ("00", "M", None, "F-6: Fortify-Routen gelesen, Muster Basalto"),
    "A-02": ("00", "L", None, "F-6: Vorlage Basalto (LoginController)"),
    "A-03": ("00", "M", None, "F-6: laravel/passkeys 0.2.1, Software-Authenticator"),
    "A-04": ("00", "S", None, ""),
    "A-05": ("00", "S", None, ""),
    "A-06": ("00", "M", "Should", "Rückfall: Konten per Konsole bis zur Einladung"),
    "A-07": ("07", "L", None, ""),
    "A-08": ("12", "M", None, ""),
    "A-09": ("00", "S", None, ""),
    # V — Verbindungen
    "V-01": ("00", "L", None, "F-1/F-2: DB::build() auf vier Treibern"),
    "V-02": ("00", "M", None, "F-2: Fehlerfälle je Treiber gemessen"),
    "V-03": ("00", "M", None, ""),
    "V-04": ("01", "M", None, "F-7: Wächter gegen Ausbruchsversuche"),
    "V-05": ("00", "M", None, "F-7: Authorizer für SQLite-ATTACH"),
    "V-06": ("01", "S", None, ""),
    "V-07": ("11", "M", None, ""),
    "V-08": ("11", "L", None, ""),
    "V-09": ("07", "M", None, ""),
    "V-10": ("01", "M", None, "F-7: Kontorechte gelesen (MariaDB, PostgreSQL)"),
    # O — Oberfläche
    "O-01": ("01", "L", None, "F-3: Katalog mit 1'000 Tabellen 1–13 ms"),
    "O-02": ("03", "M", "Should", "Rückfall: Navigator"),
    "O-03": ("02", "M", None, ""),
    "O-04": ("01", "M", None, ""),
    "O-05": ("02", "S", None, ""),
    "O-06": ("01", "M", "Should", "Rückfall: hell und Deutsch zuerst"),
    "O-07": ("08", "L", None, ""),
    "O-08": ("08", "M", None, ""),
    "O-09": ("02", "M", "Should", "Rückfall: Fehlerkarte zuerst, Leer- und Ladezustände danach"),
    # D — Daten
    "D-01": ("02", "L", None, "F-3: Blättern über den Schlüssel 0,1–0,6 ms; Raster-Probe"),
    "D-02": ("02", "L", None, ""),
    "D-03": ("02", "M", None, ""),
    "D-04": ("04", "XL", None, "F-5: pretend() für DML belegt"),
    "D-05": ("04", "L", "Should", "Rückfall: Werte als Text bearbeiten, Bildvorschau später"),
    "D-06": ("02", "S", None, "F-3: Schätzung innerhalb 0,4 %"),
    "D-07": ("04", "M", "Should", "Rückfall: Tabellen ohne Primärschlüssel nur lesen"),
    "D-08": ("10", "M", None, ""),
    "D-09": ("04", "L", None, ""),
    # S — Struktur
    "S-01": ("01", "L", None, "F-1: Introspektion und Dialekt-Abfragen"),
    "S-02": ("05", "L", None, "F-5: toSql() = Ausführung"),
    "S-03": ("05", "XL", None, "F-5: Anwenden je Treiber belegt"),
    "S-04": ("05", "M", "Should", "Rückfall: im SQL-Editor mit Bestätigung (Q-07)"),
    "S-05": ("05", "M", None, ""),
    "S-06": ("10", "XL", None, ""),
    "S-07": ("10", "L", None, ""),
    "S-08": ("11", "L", None, ""),
    "S-09": ("11", "M", None, ""),
    # Q — SQL
    "Q-01": ("03", "L", None, "F-4: CodeMirror 6 installiert, Vervollständigung belegt"),
    "Q-02": ("03", "L", None, "F-2: Abbrechen 22–29 ms, MySQL still"),
    "Q-03": ("03", "L", None, "F-2/F-7: Zeitlimits und Schreibschutz belegt"),
    "Q-04": ("03", "M", None, ""),
    "Q-05": ("10", "M", None, ""),
    "Q-06": ("10", "M", None, ""),
    "Q-07": ("04", "M", None, ""),
    "Q-08": ("12", "M", None, ""),
    "Q-09": ("03", "M", None, "F-2: SQLite ohne Zeitlimit gemessen"),
    # E — Export und Import
    "E-01": ("06", "L", None, "F-8: lazyById 3 MB je Million Zeilen"),
    "E-02": ("06", "L", "Should", "F-8: 100'000 Zeilen 143–502 ms; Rückfall: SQL-Datei im Editor"),
    "E-03": ("10", "L", None, ""),
    "E-04": ("10", "S", None, ""),
    "E-05": ("04", "S", None, ""),
    "E-06": ("06", "S", None, "F-3: Gegenprobe EXPLAIN vor und nach ANALYZE"),
    # W — Server-Verwaltung
    "W-01": ("09", "M", None, ""),
    "W-02": ("09", "M", None, "F-2: KILL QUERY und pg_cancel_backend belegt"),
    "W-03": ("09", "S", None, ""),
    "W-04": ("09", "L", None, ""),
    "W-05": ("09", "S", None, ""),
    # B — Betrieb und Technik
    "B-01": ("00", "S", None, "Pakete probeweise installiert (F-4)"),
    "B-02": ("00", "XL", None, "F-1/F-5: Proben werden Vertragstests"),
    "B-03": ("00", "M", None, "MySQL 8.4.11 in Herd; Proben laufen auf vier Treibern"),
    "B-04": ("07", "L", None, "F-7: Meldungen tragen Werte — Maskierung nötig"),
    "B-05": ("08", "M", None, ""),
    "B-06": ("00", "S", None, ""),
    "B-07": ("06", "M", None, ""),
    "B-08": ("00", "S", None, ""),
    # N — Nicht-funktional
    "N-01": ("02", "M", None, "F-3: erste Seite 0,1–0,6 ms (DB), 281 ms (Browser)"),
    "N-02": ("08", "L", None, "Bedrohungsmodell T-01 bis T-18"),
    "N-03": ("04", "L", None, "F-5: naiver Weg verliert Kinder, Weg je Treiber nicht"),
    "N-04": ("01", "M", "Should", "Rückfall: aktuelle Versionen lokal, Untergrenzen in der Wochen-Matrix"),
    "N-05": ("08", "M", None, ""),
    "N-06": ("01", "S", None, ""),
}


def anforderungen() -> list[dict[str, str]]:
    zeilen: list[dict[str, str]] = []
    for line in CR.read_text(encoding="utf-8").splitlines():
        m = re.match(r"^\| ([AVODSQEWBN]-\d{2}) \| (.+?) \| ([MSCW]) \| (\d) \| (.+) \|$", line)
        if not m:
            continue
        nr, text, prio, stufe, abnahme = (g.strip() for g in m.groups())
        fett = re.match(r"\*\*(.+?)\*\*", text)
        titel = (fett.group(1) if fett else re.split(r"[:;(]", re.sub(r"\*\*", "", text))[0]).replace("`", "").rstrip(":").strip()
        zeilen.append({"nr": nr, "titel": titel, "prio": PRIO[prio], "stufe": stufe, "abnahme": abnahme})
    return zeilen


def kurz(text: str, n: int = 100) -> str:
    t = text.split(";")[0].strip()
    return t if len(t) <= n else t[: n - 1].rstrip() + "…"


def bauen() -> tuple[str, dict[str, dict[str, int]]]:
    rows = anforderungen()
    fehlt = [r["nr"] for r in rows if r["nr"] not in ZUORDNUNG]
    ueberzaehlig = sorted(set(ZUORDNUNG) - {r["nr"] for r in rows})
    assert not fehlt, f"ohne Zuordnung: {fehlt}"
    assert not ueberzaehlig, f"Zuordnung ohne Anforderung im CR: {ueberzaehlig}"
    falsche_stufe = [r["nr"] for r in rows if TIMEBOXES[ZUORDNUNG[r["nr"]][0]][1] != r["stufe"]]
    assert not falsche_stufe, f"Timebox liegt nicht in der Stufe des CR: {falsche_stufe}"

    je_stufe: dict[str, dict[str, int]] = {}
    je_tb: dict[str, dict[str, int]] = {}
    for r in rows:
        tb, groesse, override, _ = ZUORDNUNG[r["nr"]]
        stufe = TIMEBOXES[tb][1]
        p = PUNKTE[groesse]
        prio = override or r["prio"]
        for ziel, schluessel in ((je_stufe, stufe), (je_tb, tb)):
            s = ziel.setdefault(schluessel, {"punkte": 0, "must": 0, "anzahl": 0, "must_cr": 0})
            s["punkte"] += p
            s["anzahl"] += 1
            s["must"] += p if prio == "Must" else 0
            s["must_cr"] += 1 if r["prio"] == "Must" else 0

    out: list[str] = []
    out.append("# PRL — Prioritised Requirements List (phpdbadmin)\n")
    out.append(f"> **Status:** v1.0 (28.10.2026) — erzeugt aus [CR-001 § 4](../00-change_request/CR-001-phpdbadmin.md) ({len(rows)} Anforderungen) mit `werkzeuge/prl-bauen.py`; **nicht von Hand bearbeiten**, Zuordnung und Grössen stehen im Skript. Jede Anforderung hat eine Timebox ([Timebox-Plan](timebox-plan.md)), eine Grösse (S 1 · M 2 · L 3 · XL 5 Punkte) und eine **Priorität im Plan**: Must-Anforderungen des CR mit einem Rückfallweg laufen als *Should ↓* und rutschen bei Zeitnot in die nächste Stufe. Spalte «Feasibility» nennt, was schon belegt ist.\n")
    out.append("## 1. Kennzahlen je Stufe\n")
    out.append("| Stufe | Anforderungen | Punkte | davon Must im Plan | Must-Anteil | Must im CR |")
    out.append("|---|---|---|---|---|---|")
    for stufe, name in STUFEN_NAME.items():
        s = je_stufe.get(stufe)
        if not s:
            continue
        anteil = s["must"] / s["punkte"] * 100
        marke = "✅" if anteil <= 60 else "⚠️"
        out.append(f"| {name} | {s['anzahl']} | {s['punkte']} | {s['must']} | **{anteil:.0f} %** {marke} | {s['must_cr']} von {s['anzahl']} |")
    bis_v1 = [je_stufe[s] for s in ("0", "1", "2")]
    p, m = sum(s["punkte"] for s in bis_v1), sum(s["must"] for s in bis_v1)
    out.append(f"| **bis v1.0 (Stufen 0–2)** | {sum(s['anzahl'] for s in bis_v1)} | {p} | {m} | **{m / p * 100:.0f} %** {'✅' if m / p <= 0.6 else '⚠️'} | {sum(s['must_cr'] for s in bis_v1)} von {sum(s['anzahl'] for s in bis_v1)} |")
    out.append("")
    out.append("**Stufe 0 bleibt über 60 Prozent — bewusst:** Das Fundament hat keinen Rückfallweg. Ohne Anmeldung, Verbindungen, Dialekt-Schicht und Tests auf vier Datenbanken gibt es keine Stufe 1; die Entfernung von Passwort, Dashboard und Profil ist eine ausdrückliche Vorgabe. Der Puffer liegt deshalb in den Stufen 1 und 2; bis v1.0 zusammen bleibt der Must-Anteil unter 60 Prozent.\n")
    out.append("**Personentage je Timebox** (Budget je Stufe nach CR-001 § 9, obere Schätzung 53 PT, anteilig nach Punkten):\n")
    out.append("| TB | Titel | Stufe | Anforderungen | Punkte | Must | PT |")
    out.append("|---|---|---|---|---|---|---|")
    for tb, (titel, stufe) in TIMEBOXES.items():
        t = je_tb.get(tb)
        if not t:
            continue
        pt = STUFEN_PT[stufe] * t["punkte"] / je_stufe[stufe]["punkte"]
        out.append(f"| {tb} | {titel} | {stufe} | {t['anzahl']} | {t['punkte']} | {t['must']} | ~{pt:.1f} |".replace(f"~{pt:.1f}", f"~{pt:.1f}".replace(".", ",")))
    out.append("")
    out.append("## 2. Anforderungen je Timebox\n")
    for tb, (titel, stufe) in TIMEBOXES.items():
        eigene = [r for r in rows if ZUORDNUNG[r["nr"]][0] == tb]
        if not eigene:
            continue
        t = je_tb[tb]
        out.append(f"### TB {tb} — {titel} (Stufe {stufe}; {t['anzahl']} Anforderungen, {t['punkte']} Punkte, Must {t['must']})\n")
        out.append("| Nr. | Anforderung | CR | Plan | Grösse | Feasibility / Rückfall | Abnahme (Kurzform, vollständig im CR) |")
        out.append("|---|---|---|---|---|---|---|")
        for r in eigene:
            _, groesse, override, stand = ZUORDNUNG[r["nr"]]
            prio = override or r["prio"]
            plan = f"**{prio}**" if prio == "Must" else (f"{prio} ↓" if override else prio)
            out.append(f"| {r['nr']} | {r['titel']} | {r['prio']} | {plan} | {groesse} | {stand or '—'} | {kurz(r['abnahme'])} |")
        out.append("")
    out.append("## 3. Regeln\n")
    out.append("- **Must im Plan** trägt den Meilenstein der Stufe (Alpha, v1.0, v1.1, v1.2); **Should ↓** ist im CR Must, hat aber einen Rückfallweg und darf bei Zeitnot in die nächste Stufe rutschen — der CR bleibt unverändert, die Verschiebung steht im Timebox-Protokoll.")
    out.append("- Grösse ist eine relative Schätzung; die Personentage je Timebox ergeben sich aus dem Budget der Stufe anteilig nach Punkten — der [Timebox-Plan](timebox-plan.md) übernimmt sie.")
    out.append("- Das Skript prüft, dass jede Anforderung genau eine Timebox in ihrer Stufe hat; neue Anforderungen im CR brechen den Lauf, bis sie zugeordnet sind.")
    out.append("- Änderungen an Zuordnung oder Grösse nur im Skript; danach `python3 dokumentationen/werkzeuge/prl-bauen.py` und das PDF neu bauen.")
    out.append("")
    return "\n".join(out), je_stufe


if __name__ == "__main__":
    text, kennzahlen = bauen()
    if "--pruefen" not in sys.argv:
        ZIEL.write_text(text, encoding="utf-8")
        print(f"{ZIEL.name} geschrieben")
    for stufe, s in kennzahlen.items():
        print(stufe, s, f"{s['must'] / s['punkte'] * 100:.0f} %")
