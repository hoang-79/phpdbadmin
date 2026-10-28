#!/usr/bin/env python3
"""
Diagramm-Generator für die phpdbadmin-Dokumentation.

Erzeugt die SVG-Diagramme der Phasen aus einer Beschreibung in diesem Skript —
ohne Abhängigkeiten ausserhalb der Python-Standardbibliothek. Geändert wird hier,
nicht in den erzeugten Dateien. Grundformen nach dem Generator von Basalto (eigenes Projekt).

Aufruf (aus dem Projektverzeichnis):
    python3 dokumentationen/werkzeuge/diagramme.py              alle Diagramme
    python3 dokumentationen/werkzeuge/diagramme.py systemkontext nur Diagramme, deren Name so beginnt
    python3 dokumentationen/werkzeuge/diagramme.py --liste      Namen und Zielpfade ausgeben

Nach dem Erzeugen jedes Diagramm ansehen (rsvg-convert → PNG), bevor es ins Dokument kommt.
"""
from __future__ import annotations

import sys
from pathlib import Path
from xml.sax.saxutils import escape

WURZEL = Path(__file__).resolve().parent.parent

# Vorläufige Farben bis zur eigenen Marke (Foundation): Schiefer und Petrol.
# Kontraste: INK auf WEISS 14,6:1 · AKZENT auf WEISS 5,4:1 · WEISS auf INK 14,6:1 · ROT auf ROT_HELL 5,6:1.
INK = "#1e293b"          # Schiefer: Text, Kern-Kästen
GRAU = "#475569"         # Schiefer hell: Untertitel, Sekundärtext
LINIE = "#94a3b8"        # Rahmen, Linien
KERN = "#1e293b"
KERN_HELL = "#eef2f6"    # helle Kern-Kästen
AKZENT = "#0e7490"       # Petrol: Ausbau-Text, Pillen, Meilensteine
AKZENT_HELL = "#e6f4f7"
AKZENT_RAND = "#0e7490"
GRUEN = "#2f7d4f"
GRUEN_HELL = "#e7f4ec"
ROT = "#b42318"          # Gefahr, Produktion, Risiken
ROT_HELL = "#fdecea"
NEUTRAL_HELL = "#f5f6f8" # «nicht im Umfang», Grund
WEISS = "#ffffff"

STIL = f"""
    text {{ font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; fill: {INK}; }}
    .cap {{ font-size: 19px; font-weight: bold; }}
    .sub {{ font-size: 12px; fill: {GRAU}; }}
    .h {{ font-size: 14px; font-weight: bold; }}
    .hw {{ font-size: 14px; font-weight: bold; fill: {WEISS}; }}
    .t {{ font-size: 12px; }}
    .tw {{ font-size: 12px; fill: #e2e8f0; }}
    .s {{ font-size: 10.5px; fill: {GRAU}; }}
    .sw {{ font-size: 10.5px; fill: #cbd5e1; }}
    .badge {{ font-size: 10px; font-weight: bold; fill: {WEISS}; }}
    .note {{ font-size: 11.5px; fill: {GRAU}; font-style: italic; }}
    .mono {{ font-family: Menlo, "SF Mono", monospace; font-size: 11px; }}
    .monow {{ font-family: Menlo, "SF Mono", monospace; font-size: 11px; fill: #e2e8f0; }}
    .rotT {{ font-size: 12px; font-weight: bold; fill: {ROT}; }}
    .kern {{ fill: {KERN}; stroke: {KERN}; }}
    .kernhell {{ fill: {KERN_HELL}; stroke: {KERN}; stroke-width: 1.4; }}
    .ausbau {{ fill: {AKZENT_HELL}; stroke: {AKZENT_RAND}; stroke-width: 1.4; }}
    .gruen {{ fill: {GRUEN_HELL}; stroke: {GRUEN}; stroke-width: 1.4; }}
    .nein {{ fill: {NEUTRAL_HELL}; stroke: {LINIE}; stroke-width: 1.4; stroke-dasharray: 5 3; }}
    .neutral {{ fill: {WEISS}; stroke: {LINIE}; stroke-width: 1.4; }}
    .rot {{ fill: {ROT_HELL}; stroke: {ROT}; stroke-width: 1.4; }}
    .flaeche {{ fill: {NEUTRAL_HELL}; stroke: none; }}
    .pfeil {{ stroke: {KERN}; stroke-width: 1.6; fill: none; marker-end: url(#pf); }}
    .pfeilg {{ stroke: {GRAU}; stroke-width: 1.4; fill: none; marker-end: url(#pfg); stroke-dasharray: 5 3; }}
    .linie {{ stroke: {LINIE}; stroke-width: 1.2; fill: none; }}
    .achse {{ stroke: {GRAU}; stroke-width: 1.4; fill: none; marker-end: url(#pfg); }}
"""

DEFS = f"""
  <defs>
    <marker id="pf" markerWidth="9" markerHeight="9" refX="7" refY="4.5" orient="auto" markerUnits="userSpaceOnUse">
      <path d="M0 0 L9 4.5 L0 9 z" fill="{KERN}"/>
    </marker>
    <marker id="pfg" markerWidth="9" markerHeight="9" refX="7" refY="4.5" orient="auto" markerUnits="userSpaceOnUse">
      <path d="M0 0 L9 4.5 L0 9 z" fill="{GRAU}"/>
    </marker>
  </defs>
"""


class Zeichnung:
    """Sammelt SVG-Elemente und schreibt eine Datei mit Titel und Untertitel."""

    def __init__(self, breite: int, hoehe: int, titel: str, untertitel: str = "") -> None:
        self.breite, self.hoehe = breite, hoehe
        self.teile: list[str] = []
        self.text(30, 36, titel, "cap")
        if untertitel:
            self.text(30, 57, untertitel, "sub")

    # --- Grundformen -------------------------------------------------------
    def roh(self, s: str) -> None:
        self.teile.append(s)

    def text(self, x: float, y: float, s: str, cls: str = "t", anker: str = "start") -> None:
        self.teile.append(f'<text x="{x:.0f}" y="{y:.0f}" class="{cls}" text-anchor="{anker}">{escape(s)}</text>')

    def zeilen(self, x: float, y: float, zeilen: list[str], cls: str = "t", abstand: float = 18, anker: str = "start", punkt: bool = False) -> float:
        """Mehrere Zeilen untereinander; gibt die y-Position nach der letzten Zeile zurück."""
        for z in zeilen:
            if punkt and z.startswith("  "):          # Fortsetzung eines Punkts: eingerückt, ohne Punkt
                self.text(x + 9, y, z.strip(), cls, anker)
            else:
                self.text(x, y, ("• " + z) if punkt and z else z.strip(), cls, anker)
            y += abstand
        return y

    def rechteck(self, x: float, y: float, b: float, h: float, cls: str = "neutral", rx: float = 10) -> None:
        self.teile.append(f'<rect x="{x:.0f}" y="{y:.0f}" width="{b:.0f}" height="{h:.0f}" rx="{rx}" class="{cls}"/>')

    def kreis(self, x: float, y: float, r: float, cls: str = "kern") -> None:
        self.teile.append(f'<circle cx="{x:.0f}" cy="{y:.0f}" r="{r}" class="{cls}"/>')

    def pille(self, x: float, y: float, s: str, farbe: str = AKZENT_RAND, breite: float | None = None) -> float:
        b = breite or (len(s) * 6.4 + 18)
        self.teile.append(f'<rect x="{x:.0f}" y="{y:.0f}" width="{b:.0f}" height="20" rx="10" fill="{farbe}"/>')
        self.text(x + b / 2, y + 14, s, "badge", "middle")
        return b

    def nummer(self, x: float, y: float, n: str, farbe: str = AKZENT_RAND) -> None:
        """Runde Marke mit Ziffer (Legende im Text oder unter dem Bild)."""
        self.teile.append(f'<circle cx="{x:.0f}" cy="{y:.0f}" r="11" fill="{farbe}"/>')
        self.text(x, y + 4, n, "badge", "middle")

    def pfeil(self, x1: float, y1: float, x2: float, y2: float, cls: str = "pfeil", beschriftung: str = "", knick: float | None = None) -> None:
        if knick is None:
            d = f"M{x1:.0f} {y1:.0f} L{x2:.0f} {y2:.0f}"
        else:
            d = f"M{x1:.0f} {y1:.0f} L{knick:.0f} {y1:.0f} L{knick:.0f} {y2:.0f} L{x2:.0f} {y2:.0f}"
        self.teile.append(f'<path d="{d}" class="{cls}"/>')
        if beschriftung:
            mx, my = (x1 + x2) / 2, (y1 + y2) / 2 - 6
            tb = len(beschriftung) * 6 + 10
            self.teile.append(f'<rect x="{mx - tb / 2:.0f}" y="{my - 11:.0f}" width="{tb:.0f}" height="16" rx="4" fill="{WEISS}" opacity="0.95"/>')
            self.text(mx, my + 1, beschriftung, "s", "middle")

    # --- Zusammengesetzte Formen -----------------------------------------
    def kasten(self, x: float, y: float, b: float, h: float, titel: str, zeilen: list[str] | None = None,
               cls: str = "neutral", dunkel: bool = False, badge: str = "", abstand: float = 18, punkt: bool = True) -> None:
        self.rechteck(x, y, b, h, cls)
        ty = y + 26
        if badge:
            self.pille(x + 14, y + 12, badge, AKZENT_RAND if not dunkel else "#334155")
            ty = y + 50
        self.text(x + 14, ty, titel, "hw" if dunkel else "h")
        if zeilen:
            self.zeilen(x + 14, ty + 24, zeilen, "tw" if dunkel else "t", abstand, punkt=punkt)

    def legende(self, x: float, y: float, eintraege: list[tuple[str, str]]) -> None:
        for cls, s in eintraege:
            self.rechteck(x, y - 11, 22, 14, cls, 3)
            self.text(x + 30, y, s, "s")
            x += len(s) * 6.2 + 56

    def schreiben(self, ziel: Path) -> None:
        inhalt = "\n  ".join(self.teile)
        svg = (
            f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {self.breite} {self.hoehe}" '
            f'width="{self.breite}" height="{self.hoehe}" role="img">\n'
            f"  <style>{STIL}</style>\n{DEFS}\n"
            f'  <rect x="0" y="0" width="{self.breite}" height="{self.hoehe}" fill="{WEISS}"/>\n  {inhalt}\n</svg>\n'
        )
        ziel.parent.mkdir(parents=True, exist_ok=True)
        ziel.write_text(svg, encoding="utf-8")


# ============================================================================
# 00 — Change Request
# ============================================================================

def problembild_ist_soll() -> Zeichnung:
    z = Zeichnung(1240, 640, "phpdbadmin — Problembild: heute und mit phpdbadmin",
                  "Wer MariaDB, MySQL, PostgreSQL und SQLite im Browser verwalten will, braucht heute mehrere Werkzeuge — keines führt durch die Arbeit")
    z.text(30, 95, "HEUTE", "h")
    spalten = [
        ("phpMyAdmin 5.2.3", ["nur MySQL und MariaDB", "offiziell nur PHP < 8.4", "Version 6 seit Jahren", "in Entwicklung", "viele Reiter und Formulare", "Anmeldung mit dem", "Datenbank-Passwort"]),
        ("phpPgAdmin 7.13", ["nur PostgreSQL", "seit Dezember 2023", "ohne Commit", "offene XSS-Meldung 2026", "neuere PostgreSQL-", "Versionen nur in Forks", "Oberfläche der 2000er"]),
        ("Adminer 6.2", ["alle vier, eine PHP-Datei", "aktiv gepflegt", "knapp und schnell", "Bedienung über Links", "und Formulare", "wenig Führung, keine", "Vorschau vor dem Ändern"]),
        ("Desktop-Clients", ["TablePlus, DBeaver,", "Beekeeper, HeidiSQL", "je Rechner installiert", "nicht im Browser,", "nicht auf dem Server", "teils kostenpflichtig", "oder mit Tab-Grenzen"]),
    ]
    x = 30
    for titel, zl in spalten:
        z.kasten(x, 108, 190, 240, titel, zl, "neutral", punkt=False)
        x += 202
    z.rechteck(30, 362, 796, 60, "rot")
    z.text(44, 386, "Gemeinsam: keine einheitliche Bedienung über vier Datenbanken · Änderungen ohne Vorschau des SQL", "t")
    z.text(44, 406, "· kein Schutz vor DROP auf der Produktion · Anmeldung mit Datenbank-Passwörtern statt mit eigenem Konto", "t")

    z.pfeil(836, 245, 880, 245)
    z.text(890, 95, "MIT PHPDBADMIN", "h")
    z.kasten(890, 108, 320, 314, "phpdbadmin", [
        "eine Oberfläche für alle vier Datenbanken",
        "Tabelle bearbeiten wie in einer",
        "Tabellenkalkulation",
        "SQL-Vorschau vor jedem Schreiben",
        "Befehlspalette ⌘K für alles",
        "Produktion farbig und schreibgeschützt",
        "Anmeldung mit Passkey oder Code",
        "Zugangsdaten verschlüsselt gespeichert",
        "Laravel 13, Inertia v3, Vue 3, Tailwind 4",
        "läuft, wo Laravel läuft (Herd, Server)",
    ], "kern", dunkel=True, abstand=19, punkt=False)

    z.text(30, 466, "Was sich ändert", "h")
    folgen = [
        ("Entwickler", "Lokale Datenbanken aus Herd in einer Oberfläche, egal ob MariaDB oder PostgreSQL; SQL lernen am gezeigten SQL"),
        ("Betrieb / Admin", "Ein Werkzeug auf dem Server statt phpMyAdmin und phpPgAdmin; Konten mit Passkey, Protokoll, Schreibschutz"),
        ("Nguyen Development", "Eigenes Werkzeug für alle Projekte (Basalto, Fristo, Kunden); Schaufenster für Laravel-Handwerk"),
    ]
    y = 494
    for wer, was in folgen:
        z.rechteck(30, y - 16, 1180, 36, "kernhell", 6)
        z.text(44, y + 6, wer, "h")
        z.text(210, y + 6, was, "t")
        y += 44
    return z


def marktpositionierung() -> Zeichnung:
    z = Zeichnung(1000, 660, "phpdbadmin — Marktpositionierung",
                  "Datenbank-Werkzeuge 2026: wie viele Datenbanken in einer Oberfläche, gegen wie geführt die Bedienung ist (Marktbild § 3)")
    ox, oy, b, h = 110, 580, 820, 440
    z.roh(f'<path d="M{ox} {oy} L{ox + b} {oy}" class="achse"/>')
    z.roh(f'<path d="M{ox} {oy} L{ox} {oy - h}" class="achse"/>')
    z.text(ox + b / 2, oy + 24, "Bedienung: technisch, Formulare und Links  →  geführt, Vorschau, Tastatur", "s", "middle")
    z.roh(f'<text x="{ox - 60}" y="{oy - h / 2}" class="s" text-anchor="middle" transform="rotate(-90 {ox - 60} {oy - h / 2})">Datenbanken in einer Oberfläche: eine  →  alle vier</text>')
    z.roh(f'<path d="M{ox} {oy - h / 2} L{ox + b} {oy - h / 2}" class="linie"/>')
    z.roh(f'<path d="M{ox + b / 2} {oy} L{ox + b / 2} {oy - h}" class="linie"/>')

    def punkt(px: float, py: float, name: str, zusatz: str, cls: str = "neutral", r: float = 48) -> None:
        z.kreis(ox + px * b, oy - py * h, r, cls)
        z.text(ox + px * b, oy - py * h - 2, name, "hw" if cls == "kern" else "h", "middle")
        z.text(ox + px * b, oy - py * h + 14, zusatz, "sw" if cls == "kern" else "s", "middle")

    punkt(0.10, 0.22, "phpPgAdmin", "ruht seit 2023", "neutral", 56)
    punkt(0.28, 0.22, "phpMyAdmin", "MySQL, MariaDB", "neutral", 56)
    punkt(0.50, 0.24, "pgAdmin 4", "Python, nur PG", "nein", 46)
    punkt(0.22, 0.80, "Adminer", "eine PHP-Datei", "neutral", 46)
    punkt(0.47, 0.62, "Jaxon DbAdmin", "PHP, v0.35", "neutral", 64)
    punkt(0.69, 0.74, "DbGate", "Node, Docker", "nein", 44)
    punkt(0.80, 0.40, "Desktop-Clients", "kein Browser", "nein", 66)
    punkt(0.90, 0.90, "phpdbadmin", "alle vier, geführt", "kern", 60)
    z.rechteck(ox + 0.14 * b, 84, 0.58 * b, 26, "kernhell", 6)
    z.text(ox + 0.43 * b, 102, "unbesetzt (oben rechts): im Browser · alle vier Datenbanken · geführt · auf Laravel", "s", "middle")
    z.pfeil(ox + 0.72 * b, 97, ox + 0.86 * b, oy - 0.90 * h - 50, "pfeilg")
    z.legende(ox, 640, [("neutral", "PHP, im Browser"), ("nein", "andere Technik (Python, Node, Desktop)"), ("kern", "phpdbadmin (Ziel)")])
    return z


def scope_abgrenzung() -> Zeichnung:
    z = Zeichnung(1180, 480, "phpdbadmin — Abgrenzung des Funktionsumfangs",
                  "Was v1.0 braucht, was danach folgt — und was bewusst nicht gebaut wird (Funktionsraster, CR-001 § 3)")
    z.kasten(30, 80, 300, 330, "Kern bis v1.0 (Stufe 0–2)", [
        "Anmeldung: Passkey und Code", "Verbindungen für vier Treiber", "Navigator Server → Schema → Tabelle",
        "Datenraster: blättern, sortieren,", "  filtern, suchen", "Bearbeiten mit SQL-Vorschau",
        "Struktur: Spalten, Indizes,", "  Fremdschlüssel, Tabellen anlegen", "SQL-Editor mit Vervollständigung",
        "Verlauf und gespeicherte Abfragen", "Export und Import: CSV, SQL, JSON", "Schutz vor Gefährlichem",
        "Protokoll, DE/EN, hell/dunkel", "Befehlspalette ⌘K",
    ], "kern", dunkel=True, badge="v1.0", abstand=17)
    z.kasten(348, 80, 260, 330, "Verwalten (Stufe 3)", [
        "Benutzer und Rechte", "Prozesse und Sitzungen", "Server-Status, Variablen", "Views, Routinen, Trigger,",
        "  Sequenzen, Typen", "Beziehungsdiagramm (ER)", "EXPLAIN als Bild", "Sicherung und", "  Wiederherstellung",
    ], "ausbau", badge="v1.1", abstand=18)
    z.kasten(626, 80, 270, 330, "Komfort und Team (Stufe 4)", [
        "Schemavergleich → Migration", "Laravel-Migration aus Tabelle", "Herd-Dienste und .env erkennen",
        "SSH-Tunnel", "geteilte Verbindungen, Rollen", "Abfragen im Team teilen", "SQL-Hilfe mit KI", "  (eigener Schlüssel, optional)",
    ], "ausbau", badge="v1.2", abstand=18)
    z.kasten(914, 80, 236, 330, "Nicht — bewusst", [
        "SQL Server, Oracle (vorerst)", "NoSQL: MongoDB, Redis", "SaaS mit fremden", "  Zugangsdaten", "Desktop-App",
        "Berichte, BI, Diagramme", "  aus Daten", "Replikation und Cluster", "  einrichten", "Hosting-Panel", "  (Konten, Domains)",
        "Passwort-Anmeldung", "Dashboard, Profilseite",
    ], "nein", abstand=17)
    z.legende(30, 450, [("kern", "Kern bis v1.0"), ("ausbau", "Ausbau nach v1.0"), ("nein", "nicht im Umfang")])
    return z


def systemkontext() -> Zeichnung:
    z = Zeichnung(1180, 640, "phpdbadmin — Systemkontext (C4 Level 1)",
                  "Eine Installation (lokal in Herd oder auf einem Server) mit ihren Personen und Umsystemen")
    z.rechteck(440, 200, 300, 280, "kern", 16)
    z.text(590, 232, "phpdbadmin", "hw", "middle")
    z.zeilen(590, 262, ["Laravel-13-Anwendung", "Oberfläche Inertia v3 + Vue 3", "Navigator · Raster · SQL-Editor",
                        "Struktur · Export/Import", "Verbindungen (verschlüsselt)", "Protokoll aller Änderungen"], "tw", 22, "middle")
    z.text(590, 410, "eigene App-Datenbank:", "sw", "middle")
    z.text(590, 426, "Konten, Passkeys, Verbindungen,", "sw", "middle")
    z.text(590, 442, "Verlauf, Protokoll (SQLite oder MariaDB)", "sw", "middle")

    personen = [
        (40, 110, "Entwickler", "lokal in Herd, eigene Datenbanken"),
        (40, 240, "Administrator", "Konten, Verbindungen, Rechte"),
        (40, 370, "Mitarbeitende", "lesen oder bearbeiten je Verbindung"),
    ]
    for x, y, n, s in personen:
        z.kreis(x + 22, y + 12, 12, "kernhell")
        z.rechteck(x, y + 30, 320, 48, "kernhell", 8)
        z.text(x + 12, y + 50, n, "h")
        z.text(x + 12, y + 68, s, "s")
        z.pfeil(x + 320, y + 54, 440, 270 + (y - 110) * 0.45)
    z.rechteck(40, 520, 320, 70, "neutral", 8)
    z.text(52, 544, "Authenticator", "h")
    z.text(52, 562, "Touch ID, Passwortmanager, Schlüssel", "s")
    z.text(52, 578, "(WebAuthn, Passkey)", "s")
    z.pfeil(360, 552, 470, 480, "pfeilg")

    systeme = [
        (800, 80, "MariaDB-Server", "10.6 bis 11.x (lokal Herd 10.11)", "neutral"),
        (800, 160, "MySQL-Server", "8.0 und 8.4 LTS", "neutral"),
        (800, 240, "PostgreSQL-Server", "14 bis 18 (lokal Herd 18)", "neutral"),
        (800, 320, "SQLite-Dateien", "auf dem Rechner bzw. Server", "neutral"),
        (800, 400, "Mail-Versand", "Anmeldecode, Einladungen", "neutral"),
        (800, 480, "SSH-Bastion", "Tunnel zu internen Servern (Stufe 4)", "nein"),
    ]
    for x, y, n, s, cls in systeme:
        z.rechteck(x, y, 340, 56, cls, 8)
        z.text(x + 12, y + 22, n, "h")
        z.text(x + 12, y + 42, s, "s")
        z.pfeil(740, 240 + (y - 80) * 0.45, x, y + 28, "pfeil" if cls == "neutral" else "pfeilg")
    z.text(800, 580, "Verbindung je Anfrage, mit Zeit- und Mengengrenzen;", "note")
    z.text(800, 598, "erlaubte Hosts je Installation (kein Sprungbrett ins Netz)", "note")
    return z


def architektur_treiber() -> Zeichnung:
    z = Zeichnung(1180, 640, "phpdbadmin — Schichten: eine Oberfläche, vier Dialekte",
                  "Was Laravel für alle Treiber kann, nutzen wir; nur die Unterschiede liegen in einem Dialekt je Datenbank")
    # Schichten
    schichten = [
        (80, "Browser — Vue 3 + Inertia v3", "Navigator · Datenraster · SQL-Editor · Strukturformulare · Befehlspalette · SQL-Vorschau", "kernhell"),
        (170, "Laravel 13 — Controller und Dienste", "Katalog (lesen) · Daten (blättern, ändern) · Struktur (DDL bauen) · Abfrage ausführen · Export/Import (Queue) · Protokoll", "kernhell"),
        (260, "Dialekt-Schicht — ein Vertrag, vier Umsetzungen", "Bezeichner quoten · Typen · Schemas · Prozesse · Rechte · EXPLAIN · Routinen · Server-Werte · Grenzen setzen", "ausbau"),
        (350, "Laravel-Datenbankschicht", "Schema::getTables(), getColumns(), getIndexes(), getForeignKeys(), getViews() · Grammatiken · PDO · Verbindung je Anfrage", "kernhell"),
    ]
    for y, titel, text, cls in schichten:
        z.rechteck(30, y, 820, 72, cls, 10)
        z.text(46, y + 28, titel, "h")
        z.text(46, y + 52, text, "s")
    for y in (152, 242, 332, 422):
        z.pfeil(440, y, 440, y + 16)
    # Dialekte
    dialekte = [("MySQL", "SET max_execution_time"), ("MariaDB", "max_statement_time"), ("PostgreSQL", "statement_timeout, Schemas"), ("SQLite", "Datei, PRAGMA, kein Server")]
    x = 30
    for n, s in dialekte:
        z.rechteck(x, 440, 196, 64, "neutral", 8)
        z.text(x + 12, 464, n, "h")
        z.text(x + 12, 484, s, "s")
        x += 208
    z.text(30, 530, "Datenbank-Server bzw. -Dateien", "note")
    # Querschnitt
    z.kasten(880, 80, 270, 424, "Querschnitt (für jede Anfrage)", [
        "Verbindung nur aus gespeicherten", "Angaben, Hosts nach Liste",
        "Zugangsdaten verschlüsselt", "(APP_KEY), nie im Browser",
        "Schreibschutz je Verbindung", "Zeitlimit je Anweisung",
        "Zeilengrenze je Ergebnis", "SQL-Vorschau vor DDL/DML",
        "Protokoll: wer, wann, was", "Rechte: lesen / schreiben /", "verwalten je Verbindung",
    ], "kern", dunkel=True, abstand=19, punkt=False)
    z.text(30, 580, "Beispiel: «Spalte umbenennen» baut der Dienst Struktur mit dem Dialekt der Verbindung; die Oberfläche zeigt das SQL, erst «Anwenden» führt es aus.", "note")
    z.text(30, 600, "SQLite kennt kein ALTER COLUMN — dort baut der Dialekt die Tabelle neu (wie Laravel), die Vorschau zeigt alle Schritte.", "note")
    return z


def bedienkonzept() -> Zeichnung:
    z = Zeichnung(1180, 720, "phpdbadmin — Bedienkonzept (Skizze, keine Gestaltung)",
                  "Ein Bildschirm für die tägliche Arbeit: wo bin ich, was sehe ich, was ändere ich — und was passiert, bevor es passiert")
    # Rahmen
    z.rechteck(30, 76, 1120, 560, "neutral", 8)
    # Kopfleiste
    z.rechteck(30, 76, 1120, 46, "kernhell", 8)
    z.rechteck(46, 86, 210, 26, "rot", 13)
    z.text(60, 104, "● produktion · shop (MySQL)", "rotT")
    z.rechteck(420, 86, 340, 26, "neutral", 6)
    z.text(434, 104, "Suchen oder Befehl …                 ⌘K", "s")
    z.text(1130, 104, "HN ▾", "h", "end")
    z.nummer(270, 99, "1")
    z.nummer(775, 99, "2")
    # Navigator
    z.rechteck(30, 122, 230, 514, "flaeche", 0)
    nav = [(0, "▾ shop"), (1, "▾ Tabellen"), (2, "customers"), (2, "orders"), (2, "order_items"), (2, "products"),
           (1, "▸ Views"), (1, "▸ Routinen"), (0, "▸ analytics"), (0, ""), (0, "★ Gespeichert"), (1, "offene Bestellungen"), (0, "◷ Verlauf")]
    y = 150
    for ebene, eintrag in nav:
        if eintrag == "orders":
            z.rechteck(40, y - 16, 210, 23, "kernhell", 4)
        z.text(46 + ebene * 16, y, eintrag, "h" if eintrag == "orders" else "t")
        y += 22
    z.nummer(240, 145, "3")
    # Reiter
    reiter = [("Daten", True), ("Struktur", False), ("Beziehungen", False), ("SQL", False)]
    x = 280
    for n, aktiv in reiter:
        z.rechteck(x, 134, len(n) * 8 + 28, 28, "kern" if aktiv else "neutral", 6)
        z.text(x + 14, 153, n, "hw" if aktiv else "t")
        x += len(n) * 8 + 36
    z.nummer(x + 4, 148, "4")
    # Filterleiste
    z.rechteck(280, 172, 850, 30, "neutral", 6)
    z.text(292, 192, "status = 'offen'   ·   created_at ≥ letzte 7 Tage   ·   + Filter", "t")
    z.text(1118, 192, "1 248 Zeilen", "s", "end")
    # Raster
    kopf = ["id", "customer", "status", "total", "created_at"]
    breiten = [80, 260, 150, 140, 220]
    y0 = 214
    x = 280
    for k, bb in zip(kopf, breiten):
        z.rechteck(x, y0, bb, 26, "kernhell", 0)
        z.text(x + 8, y0 + 18, k, "h")
        x += bb
    zeilen = [
        ["10231", "→ Anna Muster", "offen", "149.00", "2026-10-27 09:12"],
        ["10232", "→ Beat Keller", "bezahlt", "89.50", "2026-10-27 10:40"],
        ["10233", "→ Carla Rossi", "offen", "1 020.00", "2026-10-27 11:03"],
        ["10234", "→ Daniel Weber", "offen", "35.00", "2026-10-28 08:15"],
    ]
    y = y0 + 26
    for i, zl in enumerate(zeilen):
        x = 280
        for j, (w, bb) in enumerate(zip(zl, breiten)):
            geaendert = (i == 1 and j == 2) or (i == 2 and j == 3)
            z.rechteck(x, y, bb, 28, "ausbau" if geaendert else "neutral", 0)
            z.text(x + 8, y + 19, w, "h" if geaendert else "t")
            x += bb
        y += 28
    z.nummer(1110, y0 + 26 + 28 + 14, "5")
    z.text(282, y + 22, "Zelle doppelt klicken = bearbeiten · Pfeil → springt über den Fremdschlüssel zum Kunden", "s")
    # Änderungsleiste
    z.rechteck(280, 400, 850, 44, "ausbau", 8)
    z.text(296, 427, "2 Änderungen noch nicht angewendet", "h")
    for xb, t, cls in [(700, "SQL ansehen", "neutral"), (830, "Verwerfen", "neutral"), (950, "Anwenden", "kern")]:
        z.rechteck(xb, 408, 110, 28, cls, 6)
        z.text(xb + 55, 427, t, "hw" if cls == "kern" else "t", "middle")
    z.nummer(1096, 422, "6")
    # SQL-Vorschau
    z.rechteck(280, 456, 850, 150, "kern", 8)
    z.text(296, 480, "SQL-Vorschau — wird in einer Transaktion ausgeführt", "hw")
    z.zeilen(296, 506, [
        "UPDATE `orders` SET `status` = 'bezahlt' WHERE `id` = 10232;",
        "UPDATE `orders` SET `total` = 1020.00 WHERE `id` = 10233;",
        "",
        "Produktion: Anwenden verlangt die Eingabe von «shop».",
    ], "monow", 20)
    z.nummer(1096, 476, "7")
    z.text(30, 664, "1 Verbindung mit Umgebungsfarbe (Produktion rot, schreibgeschützt möglich) · 2 Befehlspalette: Tabelle öffnen, Abfrage, Verbindung wechseln · 3 Navigator mit Gespeichertem und Verlauf", "s")
    z.text(30, 682, "4 gleiche Reiter für jede Tabelle in jeder Datenbank · 5 geänderte Zellen markiert, nichts ist sofort geschrieben · 6 Änderungen sammeln, ansehen, verwerfen · 7 das SQL vor dem Ausführen", "s")
    return z


def stufen_fahrplan() -> Zeichnung:
    z = Zeichnung(1180, 430, "phpdbadmin — Stufen und Meilensteine",
                  "Reihenfolge, ohne Termine. Jede Stufe ist für sich nutzbar; phpdbadmin läuft neben Basalto, das bis zur Beta Vorrang hat (R-03)")
    stufen = [
        ("0", "Fundament", ["Starter-Kit bereinigt", "Passkey und Code", "Verbindungen, Dialekte", "Tests auf vier DBs"], "6–8 PT"),
        ("1", "Lesen", ["Navigator, Raster", "Filter, Suche", "Struktur ansehen", "SQL-Editor, ⌘K"], "8–10 PT"),
        ("2", "Bearbeiten", ["Zellen mit Vorschau", "Tabellen anlegen/ändern", "Export und Import", "Schutz, Protokoll"], "10–12 PT"),
        ("3", "Verwalten", ["Benutzer und Rechte", "Prozesse, Status", "Views, Routinen, ER", "Sicherung"], "8–10 PT"),
        ("4", "Komfort, Team", ["Schemavergleich", "Laravel-Migration", "Herd, SSH-Tunnel", "Team-Funktionen"], "8–10 PT"),
    ]
    x = 30
    for n, t, zl, pt in stufen:
        z.rechteck(x, 90, 204, 190, "kernhell", 10)
        z.kreis(x + 26, 116, 16, "kern")
        z.text(x + 26, 121, n, "hw", "middle")
        z.text(x + 50, 121, t, "h")
        z.zeilen(x + 14, 150, zl, "t", 18, punkt=True)
        z.pille(x + 14, 246, pt, KERN, 90)
        if n != "4":
            z.pfeil(x + 204, 185, x + 220, 185)
        x += 220
    for xm, t, s in [(30 + 1 * 220 + 204, "Alpha", "lesend im eigenen Alltag"),
                     (30 + 2 * 220 + 204, "v1.0", "ersetzt phpMyAdmin und phpPgAdmin"),
                     (30 + 3 * 220 + 204, "v1.1", "Verwaltung komplett"),
                     (30 + 4 * 220 + 204, "v1.2", "Team und Laravel")]:
        z.roh(f'<path d="M{xm} 90 L{xm} 300" stroke="{AKZENT_RAND}" stroke-width="2" stroke-dasharray="4 3"/>')
        z.rechteck(xm - 36, 300, 72, 24, "ausbau", 12)
        z.text(xm, 316, t, "h", "middle")
        z.text(xm, 344, s, "s", "end" if t == "v1.2" else "middle")
    z.text(30, 396, "Summe rund 40–50 Personentage bis v1.2 (Schätzung vor Feasibility). Rückfallposition: nach Stufe 2 ist das Werkzeug vollständig nutzbar — Verwalten und Team folgen.", "note")
    return z


# ============================================================================
# 01 — Pre-Project
# ============================================================================

def anmeldung_ohne_passwort() -> Zeichnung:
    z = Zeichnung(1180, 560, "phpdbadmin — Anmeldung ohne Passwort",
                  "Zwei Wege zum selben Konto; das Passwort des Starter-Kits entfällt ganz (A-01 bis A-08)")
    # Weg 1: Code
    z.text(30, 100, "WEG 1 — CODE PER MAIL (erste Anmeldung, neues Gerät)", "h")
    schritte1 = [("E-Mail-Adresse", "eingeben"), ("Code per Mail", "6 Ziffern, 10 Min."), ("Code eingeben", "Autofill aus der Mail"), ("Angemeldet", "Sitzung neu erzeugt"), ("Passkey anbieten", "einmal, abwählbar")]
    x = 30
    for i, (t, s) in enumerate(schritte1):
        cls = "kern" if t == "Angemeldet" else "kernhell"
        z.rechteck(x, 116, 190, 64, cls, 10)
        z.text(x + 14, 142, t, "hw" if cls == "kern" else "h")
        z.text(x + 14, 162, s, "sw" if cls == "kern" else "s")
        if i < len(schritte1) - 1:
            z.pfeil(x + 190, 148, x + 226, 148)
        x += 226
    # Weg 2: Passkey
    z.text(30, 230, "WEG 2 — PASSKEY (Alltag)", "h")
    schritte2 = [("«Mit Passkey»", "ein Knopf, keine Eingabe"), ("Browser fragt", "Touch ID, Passwortmanager"), ("Server prüft", "Signatur, Zähler, Herkunft"), ("Angemeldet", "Sitzung neu erzeugt")]
    x = 30
    for i, (t, s) in enumerate(schritte2):
        cls = "kern" if t == "Angemeldet" else "kernhell"
        z.rechteck(x, 246, 190, 64, cls, 10)
        z.text(x + 14, 272, t, "hw" if cls == "kern" else "h")
        z.text(x + 14, 292, s, "sw" if cls == "kern" else "s")
        if i < len(schritte2) - 1:
            z.pfeil(x + 190, 278, x + 226, 278)
        x += 226
    z.pfeil(30 + 3 * 226 + 95, 246, 30 + 3 * 226 + 95, 180, "pfeilg", "dasselbe Ziel")
    # Konten
    z.kasten(30, 350, 360, 160, "Neue Konten", [
        "kein «Registrieren» für alle",
        "erstes Konto: Erst-Setup per Konsole",
        "weitere: Einladung durch einen",
        "  Administrator (Link mit Ablauf)",
        "Konto = E-Mail + Name + Rolle",
    ], "neutral", abstand=20)
    z.kasten(410, 350, 360, 160, "Schutz", [
        "Code einmalig, gedrosselt je",
        "  Adresse und IP, gehasht gespeichert",
        "gleiche Antwort für bekannte und",
        "  unbekannte Adressen",
        "Passkeys verwalten: eigener Bereich",
    ], "neutral", abstand=20)
    z.kasten(790, 350, 360, 160, "Entfernt aus dem Starter-Kit", [
        "Passwort-Anmeldung, Passwort ändern",
        "Passwort vergessen, Registrierung",
        "Zwei-Faktor (TOTP), Passwort bestätigen",
        "E-Mail-Bestätigung (Code bestätigt)",
        "Dashboard, Profil, Konto löschen",
    ], "nein", abstand=20, punkt=True)
    z.text(30, 540, "Fortify bleibt nur für die Passkey-Zeremonie; seine Passwort-Endpunkte lehnen ab oder antworten 404 (Muster aus Basalto TB 00, eigenes Projekt).", "note")
    return z


# ============================================================================
# 02 — Feasibility
# ============================================================================

def pruefmatrix() -> Zeichnung:
    z = Zeichnung(1180, 620, "phpdbadmin — Feasibility: zehn Prüfpunkte auf vier Datenbanken",
                  "Jeder Punkt mit Probe im Repo belegt (tests/Feature/Feasibility) — 28.10.2026, MySQL 8.4.11, MariaDB 10.11.6, PostgreSQL 18, SQLite 3.45")
    spalten = [("Prüfpunkt", 30, 330), ("MySQL", 360, 120), ("MariaDB", 480, 120), ("PostgreSQL", 600, 120), ("SQLite", 720, 120), ("Ergebnis", 840, 310)]
    for titel, x, b in spalten:
        z.rechteck(x, 76, b - 4, 30, "kern", 4)
        z.text(x + 10, 96, titel, "hw")
    zeilen = [
        ("F-1 Katalog", "jjjj", "bestanden", "Introspektion reicht; Check/Trigger per Dialekt"),
        ("F-2 Verbindungen", "hjjh", "bedingt", "MySQL bricht still ab; SQLite ohne Zeitlimit"),
        ("F-3 Raster, 1 Mio. Zeilen", "jjjj", "bestanden", "erste Seite 0,1–0,6 ms in der DB"),
        ("F-4 SQL-Editor", "jjjj", "bestanden", "CodeMirror 6; Sichtprüfung im Browser offen"),
        ("F-5 DDL mit Vorschau", "hhjh", "bedingt", "toSql() stimmt; Anwenden je Treiber anders"),
        ("F-6 Anmeldung", "----", "bestanden", "Muster aus Basalto, gleiche Versionen"),
        ("F-7 Sicherheit", "jjjj", "bedingt", "Schutz hält; Herd-Konten lesen Dateien"),
        ("F-8 Export und Import", "hhhj", "bestanden", "lazyById: 3 MB auf allen vier"),
        ("F-9 Lizenzen", "----", "bestanden", "alles MIT-verträglich"),
        ("F-10 Name absichern", "----", "bedingt", "GitHub-Org belegt, US-Marke phpMyAdmin"),
    ]
    y = 110
    for name, zellen, ergebnis, notiz in zeilen:
        z.rechteck(30, y, 326, 44, "kernhell", 4)
        z.text(42, y + 27, name, "h")
        for i, c in enumerate(zellen):
            x = 360 + i * 120
            cls, zeichen = {"j": ("gruen", "✓"), "h": ("ausbau", "◐"), "-": ("nein", "—")}[c]
            z.rechteck(x, y, 116, 44, cls, 4)
            z.text(x + 58, y + 29, zeichen, "h", "middle")
        cls = "gruen" if ergebnis == "bestanden" else "ausbau"
        z.rechteck(840, y, 306, 44, cls, 4)
        z.text(852, y + 19, ergebnis.upper(), "h")
        z.text(852, y + 36, notiz, "s")
        y += 48
    z.legende(30, 604, [("gruen", "✓ belegt"), ("ausbau", "◐ belegt mit Auflage"), ("nein", "— nicht treiberabhängig")])
    z.text(1146, 604, "6 bestanden · 4 bedingt · 0 nicht bestanden", "h", "end")
    return z


def messwerte() -> Zeichnung:
    z = Zeichnung(1180, 664, "phpdbadmin — Messwerte mit einer Million Zeilen (F-3)",
                  "Median aus drei Läufen, Zeit in der Datenbank in Millisekunden (Laravel-Abfrage, lokal auf Herd); Ziel: erste Seite unter 1 s")
    werte = [
        ("Erste Seite (nach Schlüssel)", [0.3, 0.3, 0.6, 0.1]),
        ("Ab Zeile 900'000, Schlüssel", [0.4, 0.3, 0.5, 0.1]),
        ("Ab Zeile 900'000, OFFSET", [232.4, 142.6, 115.4, 18.1]),
        ("Index absteigend, ohne Statistik¹", [322.8, 277.2, 0.5, 0.1]),
        ("Sortiert ohne Index", [705.7, 194.1, 185.7, 73.5]),
        ("Suche LIKE '%…%'", [301.2, 282.2, 122.9, 82.0]),
        ("Genau zählen", [86.6, 100.1, 29.3, 8.6]),
    ]
    farben = ["#1e293b", "#475569", "#0e7490", "#94a3b8"]
    namen = ["MySQL 8.4", "MariaDB 10.11", "PostgreSQL 18", "SQLite 3.45"]
    x0, breite, skala = 290, 820, 820 / 1000
    z.roh(f'<path d="M{x0 + 1000 * skala:.0f} 80 L{x0 + 1000 * skala:.0f} 576" stroke="{ROT}" stroke-width="1.5" stroke-dasharray="5 4"/>')
    z.text(x0 + 1000 * skala - 6, 92, "1 s", "rotT", "end")
    for t in (0, 250, 500, 750):
        z.roh(f'<path d="M{x0 + t * skala:.0f} 100 L{x0 + t * skala:.0f} 576" class="linie"/>')
        z.text(x0 + t * skala, 588, f"{t} ms", "s", "middle")
    y = 104
    for name, ms in werte:
        z.text(30, y + 36, name, "h")
        for i, v in enumerate(ms):
            b = max(2, v * skala)
            z.roh(f'<rect x="{x0}" y="{y + i * 16:.0f}" width="{b:.0f}" height="13" fill="{farben[i]}"/>')
            z.text(x0 + b + 6, y + i * 16 + 11, f"{v:g} ms".replace(".", ","), "s")
        y += 68
    x = 30
    for f, n in zip(farben, namen):
        z.roh(f'<rect x="{x}" y="612" width="22" height="13" fill="{f}"/>')
        z.text(x + 30, 623, n, "s")
        x += 150
    z.text(1146, 623, "Browser: erste Seite 281 ms, Nachladen 193 ms (Raster-Probe, SQLite)", "s", "end")
    z.text(30, 652, "¹ direkt nach dem Füllen wählen MySQL und MariaDB filesort; nach ANALYZE TABLE nehmen sie den Index: 2,1 ms bzw. 3,5 ms (Gegenprobe mit EXPLAIN).", "note")
    return z


def anwenden_je_treiber() -> Zeichnung:
    z = Zeichnung(1180, 500, "phpdbadmin — «Anwenden» einer Strukturänderung je Treiber (F-5)",
                  "Die Vorschau kommt immer aus Blueprint::toSql() — ausgeführt wird genau sie, aber nicht überall gleich")
    z.kasten(30, 80, 360, 244, "MySQL und MariaDB", [
        "DDL schliesst jede Transaktion",
        "  implizit ab",
        "Anweisung für Anweisung,",
        "  ohne Transaktion",
        "Fehler in Schritt 3: Schritte 1–2",
        "  bleiben — Vorschau sagt das",
        "Daten (DML) weiterhin in",
        "  einer Transaktion",
    ], "ausbau", badge="NICHT RÜCKROLLBAR")
    z.kasten(410, 80, 360, 244, "PostgreSQL", [
        "DDL ist transaktional",
        "alle Anweisungen in einer",
        "  Transaktion",
        "Fehler: alles zurück,",
        "  nichts halb geändert",
    ], "gruen", badge="RÜCKROLLBAR")
    z.kasten(790, 80, 360, 244, "SQLite", [
        "Spalte ändern = Tabelle umbauen",
        "  (Kopie, Daten, Umbenennen)",
        "1 PRAGMA foreign_keys = OFF",
        "  ausserhalb der Transaktion",
        "2 Umbau in der Transaktion",
        "3 PRAGMA foreign_key_check",
        "4 Commit, foreign_keys = ON",
    ], "gruen", badge="RÜCKROLLBAR, MIT VERFAHREN")
    z.rechteck(30, 340, 1120, 110, "rot", 8)
    z.text(46, 366, "Gemessen, wenn man naiv alles in eine Transaktion legt:", "rotT")
    z.zeilen(46, 392, [
        "MySQL/MariaDB: die Änderung ist geschrieben, danach wirft Laravel «There is no active transaction» — die Oberfläche hielte sie für gescheitert.",
        "SQLite: PRAGMA foreign_keys wirkt in einer Transaktion nicht; DROP TABLE der Elterntabelle löscht per ON DELETE CASCADE alle Kinder (0 von 2 übrig).",
        "Und: pretend() taugt auf SQLite nicht als Vorschau — die Lesezugriffe liefern leer, heraus kommt «create table … ()».",
    ], "t", 20)
    z.text(30, 480, "Belegt in DdlPreviewProbeTest: naiver Weg (Gefahr) und Weg je Treiber (2 von 2 Kindern bleiben) auf allen vier Datenbanken.", "note")
    return z


def schreibschutz() -> Zeichnung:
    z = Zeichnung(1180, 600, "phpdbadmin — Schreibschutz in Schichten (F-7)",
                  "Eine schreibgeschützte Verbindung hält auch, wenn der Mensch im SQL-Editor ausbrechen will")
    schichten = [
        ("1  Konto mit Leserechten", "empfohlen: eigenes Datenbank-Konto je schreibgeschützter Verbindung — das einzige Schloss, das der Server selbst hält", "kernhell"),
        ("2  Nur-lesen-Transaktion je Anweisung", "MySQL/MariaDB: SET SESSION TRANSACTION READ ONLY + Transaktion · PostgreSQL: SET TRANSACTION READ ONLY · immer zurückrollen", "kernhell"),
        ("3  Eine Anweisung je Aufruf, vorbereitet", "keine Mehrfachanweisungen: «COMMIT; INSERT …» scheitert am Server (Syntaxfehler bzw. «multiple commands»)", "kernhell"),
        ("4  SQLite: Datei nur lesend öffnen", "Pdo\\Sqlite::OPEN_READONLY — «PRAGMA query_only = OFF» ändert daran nichts", "kernhell"),
        ("5  SQLite: Authorizer", "Pdo\\Sqlite::setAuthorizer() — ATTACH nur im erlaubten Ordner; ohne lesbaren Dateinamen (ATTACH ?) verweigert", "kernhell"),
    ]
    y = 80
    for titel, text, cls in schichten:
        z.rechteck(30, y, 760, 62, cls, 8)
        z.text(46, y + 26, titel, "h")
        z.text(46, y + 46, text, "s")
        y += 72
    z.kasten(810, 80, 340, 350, "Ausbruchsversuche", [
        "MySQL: 9 Versuche, 7 abgewiesen",
        "MariaDB: 9 Versuche, 7 abgewiesen",
        "PostgreSQL: 12, davon 8 abgewiesen",
        "SQLite: 5 Versuche, 4 abgewiesen",
        "",
        "durchgelassen wurden nur SET … READ",
        "WRITE, COMMIT und PRAGMA —",
        "ohne Wirkung, weil jede Anweisung",
        "neu geschützt wird",
        "",
        "Bestand danach: unverändert",
    ], "gruen", abstand=20, punkt=False)
    z.rechteck(30, 450, 1120, 100, "rot", 8)
    z.text(46, 476, "Gefahr ausserhalb des Schreibschutzes: was das Konto auf dem Server darf", "rotT")
    z.zeilen(46, 500, [
        "Herd-Konten «root»: MariaDB liest mit LOAD_FILE('/etc/hosts') Dateien des Rechners, PostgreSQL ist Superuser und liest mit pg_read_file().",
        "MySQL 8.4 nicht (secure_file_priv). LOAD DATA LOCAL INFILE verweigert der Client von selbst. → phpdbadmin warnt bei solchen Konten sichtbar.",
    ], "t", 20)
    z.text(30, 580, "Belegt in SecurityProbeTest; Fehlermeldungen von Laravel enthalten gebundene Werte — vor dem Protokollieren maskieren.", "note")
    return z


# ============================================================================
# 03 — Foundation
# ============================================================================

def architektur_l2() -> Zeichnung:
    z = Zeichnung(1180, 660, "phpdbadmin — Container (C4 Level 2)",
                  "Eine Laravel-Anwendung mit Arbeitsprozess und eigener App-Datenbank; Zieldatenbanken sind Umsysteme")
    z.rechteck(250, 76, 640, 470, "nein", 16)
    z.text(266, 98, "phpdbadmin-Installation (lokal in Herd oder selbst gehostet)", "s")
    z.kasten(30, 120, 190, 110, "Person", ["Browser", "Passkey/Authenticator"], "kernhell", punkt=False)
    z.kasten(270, 110, 290, 150, "Oberfläche (Browser)", ["Vue 3 + Inertia v3", "Raster (TanStack Virtual)", "SQL-Editor (CodeMirror 6)", "Befehlspalette, Schubladen"], "kernhell", abstand=19)
    z.kasten(580, 110, 290, 150, "Laravel-Anwendung (PHP 8.5)", ["Controller, Policies", "Dienste: Katalog, Daten, Struktur,", "  Abfragen, Austausch, Protokoll", "Dialekte: MySQL, MariaDB, PG, SQLite"], "kern", dunkel=True, abstand=19)
    z.kasten(270, 300, 290, 120, "Arbeitsprozess (queue:work)", ["Export und Import in Paketen", "SQLite-Abfragen mit Zeitgrenze", "Aufräumläufe (model:prune)"], "kernhell", abstand=19)
    z.kasten(580, 300, 290, 120, "App-Datenbank (SQLite)", ["Konten, Passkeys, Codes", "Verbindungen (verschlüsselt)", "Verlauf, Protokoll, Sicherungen"], "ausbau", abstand=19)
    z.kasten(270, 440, 600, 86, "Dateien (storage/app/private)", ["Exportdateien mit Frist 24 h · SQLite-Zieldateien nur in erlaubten Ordnern"], "neutral", punkt=False)
    z.pfeil(220, 175, 270, 175, beschriftung="HTTPS")
    z.pfeil(560, 185, 580, 185)
    z.pfeil(725, 260, 725, 300)
    z.pfeil(580, 360, 560, 360)
    z.kasten(940, 90, 210, 300, "Zieldatenbanken", ["MySQL 8.4", "MariaDB 10.11", "PostgreSQL 18", "SQLite-Dateien", "", "Verbindung je Anfrage,", "mit Zeitlimit und", "Schreibschutz"], "neutral", abstand=22, punkt=False)
    z.pfeil(870, 160, 940, 160, beschriftung="DB::build()")
    z.roh('<path d="M415 300 L415 282 L938 282" class="pfeilg"/>')
    z.kasten(940, 410, 210, 100, "Mail (SMTP)", ["Anmeldecode, Einladung"], "neutral", punkt=False)
    z.pfeil(870, 230, 940, 450, "pfeilg")
    z.text(30, 590, "Querschnitt hinter den Controllern: Recht vor Routenbindung (404 statt 403) · Grenzen · Maskierung von Meldungen · Protokoll schreibender Aktionen.", "note")
    z.text(30, 610, "Kein Container für Suche, Cache-Server oder Push: Cache und Warteschlange laufen über die App-Datenbank.", "note")
    return z


def anfrageverlauf() -> Zeichnung:
    z = Zeichnung(1180, 660, "phpdbadmin — Ändern mit Vorschau und Anwenden",
                  "Ablauf einer Datenänderung: sammeln im Browser, Vorschau vom Server, Anwenden in einer Transaktion mit Sicherung für Rückgängig")
    spuren = [("Person", 90), ("Oberfläche", 300), ("Laravel: ChangeSet", 540), ("Dialekt / Verbindung", 790), ("Zieldatenbank", 1040)]
    for name, x in spuren:
        z.rechteck(x - 90, 74, 180, 34, "kern", 6)
        z.text(x, 96, name, "hw", "middle")
        z.roh(f'<path d="M{x} 108 L{x} 575" stroke="{LINIE}" stroke-width="1.2" stroke-dasharray="4 4"/>')
    schritte = [
        (90, 300, 140, "Zellen ändern (bleibt im Browser)"),
        (90, 300, 180, "«SQL ansehen»"),
        (300, 540, 220, "Änderungen (Spalte, alt, neu, Schlüssel)"),
        (540, 790, 260, "pretend(): Anweisungen + Bindungen"),
        (540, 300, 300, "Vorschau, Hinweis je Treiber, betroffene Zeilen"),
        (90, 300, 350, "«Anwenden» (Produktion: Name eintippen)"),
        (300, 540, 390, "anwenden (mit Prüfsumme der Vorschau)"),
        (540, 790, 430, "Verbindung je Anfrage, Schreibschutz?"),
        (790, 1040, 470, "BEGIN · SELECT Vorher-Werte · UPDATE … · COMMIT"),
        (540, 540, 510, "change_snapshots + audit_log (maskiert)"),
        (540, 300, 550, "Ergebnis: n Zeilen, «Rückgängig» möglich"),
    ]
    for x1, x2, y, text in schritte:
        if x1 == x2:
            z.rechteck(x1 - 110, y - 16, 220, 26, "ausbau", 6)
            z.text(x1, y + 2, text, "s", "middle")
            continue
        z.pfeil(x1, y, x2 - (8 if x2 > x1 else -8), y)
        mx = (x1 + x2) / 2
        tb = len(text) * 5.9 + 12
        z.rechteck(mx - tb / 2, y - 22, tb, 16, "neutral", 4)
        z.text(mx, y - 10, text, "s", "middle")
    z.text(30, 600, "Strukturänderungen laufen gleich, aber die Vorschau kommt aus Blueprint::toSql() und «Anwenden» folgt dem Verfahren je Treiber (ADR-003).", "note")
    z.text(30, 620, "Weicht die Prüfsumme der Vorschau vom neu gebauten SQL ab (jemand hat inzwischen geändert), wird nichts ausgeführt und die Vorschau neu gezeigt.", "note")
    return z


def er_app_datenbank() -> Zeichnung:
    z = Zeichnung(1180, 720, "phpdbadmin — App-Datenbank v0.1 (ER)",
                  "Eigene Tabellen von phpdbadmin; Zieldatenbanken haben hier kein Modell. Zeitpunkte UTC, Geheimnisse verschlüsselt, Einmalwerte als Prüfsumme")
    def tabelle(x, y, name, spalten, cls="kernhell", b=230):
        h = 30 + len(spalten) * 17 + 8
        z.rechteck(x, y, b, h, cls, 6)
        z.rechteck(x, y, b, 26, "kern", 6)
        z.text(x + 10, y + 18, name, "hw")
        for i, sp in enumerate(spalten):
            z.text(x + 10, y + 44 + i * 17, sp, "mono")
        return (x, y, b, h)
    u = tabelle(30, 80, "users", ["id", "name", "email ∪", "role", "locale, theme, density", "timezone", "last_login_at"])
    pk = tabelle(30, 290, "passkeys", ["id", "user_id →", "name", "credential_id ∪", "credential (json)", "last_used_at"])
    lc = tabelle(30, 480, "login_codes", ["id", "email_hash", "code_hash", "attempts", "expires_at, consumed_at", "ip_hash"])
    inv = tabelle(290, 80, "invitations", ["id", "email", "role", "token_hash", "invited_by →", "expires_at, accepted_at"])
    c = tabelle(560, 80, "connections", ["id (ULID)", "owner_id →, shared", "name, group, driver", "host, port, database", "username, password 🔒", "sqlite_path, options", "environment, read_only", "server_version", "account_warnings (json)"], "ausbau", 250)
    cu = tabelle(290, 290, "connection_user", ["connection_id →", "user_id →", "permission", "favorite", "last_opened_at"])
    qh = tabelle(850, 80, "query_history", ["id", "user_id →", "connection_id →", "sql (longText)", "status, duration_ms", "row_count"], "kernhell", 300)
    sq = tabelle(850, 250, "saved_queries", ["id", "user_id →", "connection_id →", "name, sql", "parameters (json), shared"], "kernhell", 300)
    tv = tabelle(850, 400, "table_views", ["id", "user_id →, connection_id →", "schema, table", "columns, filters, sort (json)"], "kernhell", 300)
    al = tabelle(560, 360, "audit_log", ["id", "user_id →", "connection_id →", "action, target", "sql (maskiert)", "affected_rows, ip_hash"], "kernhell", 250)
    cs = tabelle(560, 560, "change_snapshots", ["id, audit_log_id →", "target, key (json)", "before, after (json)", "expires_at"], "kernhell", 250)
    tr = tabelle(290, 480, "transfers", ["id", "user_id →, connection_id →", "kind, format, status", "progress, rows", "file_path, expires_at"], "kernhell", 240)
    # Pfeile immer von der verweisenden zur referenzierten Tabelle
    z.pfeil(290, 160, 262, 160)            # invitations.invited_by → users
    z.pfeil(145, 290, 145, 228)            # passkeys.user_id → users
    z.pfeil(290, 330, 262, 236)            # connection_user.user_id → users
    z.pfeil(530, 330, 558, 200)            # connection_user.connection_id → connections
    z.pfeil(850, 130, 812, 130)            # query_history → connections
    z.pfeil(850, 290, 812, 190)            # saved_queries → connections
    z.pfeil(850, 440, 812, 230)            # table_views → connections
    z.pfeil(685, 360, 685, 258)            # audit_log → connections
    z.pfeil(685, 560, 685, 498)            # change_snapshots → audit_log
    z.pfeil(530, 540, 558, 240)            # transfers → connections
    z.text(30, 676, "Pfeile: Fremdschlüssel von der verweisenden zur referenzierten Tabelle; die Arbeitstabellen verweisen zusätzlich über user_id auf users (nicht gezeichnet).", "s")
    z.text(30, 694, "∪ eindeutig · 🔒 verschlüsselt (encrypted-Cast) · Fristen: Codes 1 Tag, Verlauf 90 Tage, Sicherungen 7 Tage, Exporte 24 h, Protokoll 365 Tage", "s")
    return z


def use_cases() -> Zeichnung:
    z = Zeichnung(1180, 470, "phpdbadmin — Rollen und Anwendungsfälle",
                  "Konto-Rolle (Administrator, Mitglied) plus Recht je Verbindung (lesen, schreiben, verwalten)")
    rollen = [
        ("Administrator", "Konten, Einladungen, alle Verbindungen", ["Konten einladen und entfernen", "Verbindungen anlegen und teilen", "Rechte je Verbindung vergeben", "Protokoll lesen, Fristen sehen"], "kern"),
        ("Recht «lesen»", "für eine Verbindung", ["Navigator, Raster, Filter", "Struktur ansehen", "SQL nur lesend (Wächter)", "Export"], "kernhell"),
        ("Recht «schreiben»", "dazu", ["Daten bearbeiten mit Vorschau", "Rückgängig", "Import", "SQL schreibend (nicht auf", "  schreibgeschützter Verbindung)"], "ausbau"),
        ("Recht «verwalten»", "dazu", ["Struktur ändern", "Datenbank/Schema anlegen", "Server-Verwaltung (v1.1)", "Statistik aktualisieren"], "ausbau"),
    ]
    x = 30
    for titel, unter, faelle, cls in rollen:
        z.kreis(x + 130, 104, 18, "kern" if cls == "kern" else "kernhell")
        z.text(x + 130, 146, titel, "h", "middle")
        z.text(x + 130, 164, unter, "s", "middle")
        z.rechteck(x, 180, 260, 160, cls, 10)
        z.zeilen(x + 16, 210, faelle, "tw" if cls == "kern" else "t", 24, punkt=True)
        x += 285
    z.rechteck(30, 360, 1115, 80, "nein", 10)
    z.text(46, 386, "Für alle Konten", "h")
    z.text(46, 410, "Anmelden mit Code oder Passkey · Passkeys und Sitzungen verwalten · Darstellung (hell/dunkel, Dichte, Sprache) · eigene Verbindungen anlegen (persönlich)", "t")
    z.text(46, 430, "Kein Profil (Name und E-Mail ändert ein Administrator), keine Selbstregistrierung, kein Dashboard — die Startseite ist die Verbindungsübersicht.", "s")
    return z


def export_im_hintergrund() -> Zeichnung:
    z = Zeichnung(1180, 360, "phpdbadmin — Export im Hintergrund",
                  "Pakete über den Schlüssel (lazyById), Datei mit Frist; gemessen 3 MB Speicher je Million Zeilen (F-8)")
    schritte = [
        ("Auftrag", "Format, Tabelle oder Abfrage,\nStruktur und/oder Daten;\ntransfers: queued", "kernhell"),
        ("Arbeitsprozess", "RunExport: lazyById(5000),\nFortschritt je Paket", "kern"),
        ("Datei", "storage/app/private/transfers,\nFrist 24 Stunden", "kernhell"),
        ("Benachrichtigung", "Oberfläche fragt den Stand ab;\n«Herunterladen» prüft\ndas Recht erneut", "kernhell"),
        ("Aufräumen", "model:prune löscht Datei\nund Eintrag nach Ablauf", "nein"),
    ]
    x = 30
    for i, (titel, text, cls) in enumerate(schritte):
        z.rechteck(x, 90, 205, 130, cls, 10)
        z.text(x + 14, 118, titel, "hw" if cls == "kern" else "h")
        z.zeilen(x + 14, 146, text.split("\n"), "tw" if cls == "kern" else "t", 20)
        if i < len(schritte) - 1:
            z.pfeil(x + 205, 150, x + 228, 150)
        x += 228
    z.rechteck(30, 236, 1115, 84, "ausbau", 10)
    z.text(46, 262, "Ohne laufenden Worker (lokal ohne queue:work)", "h")
    z.text(46, 286, "läuft der Export synchron in der Anfrage bis zu einer Grenze (Vorgabe 100'000 Zeilen); darüber verlangt phpdbadmin den Worker (B-07).", "t")
    z.text(46, 306, "Import gleich, mit Probelauf in einer zurückgerollten Transaktion und ANALYZE nach dem echten Lauf (E-02, E-06).", "t")
    return z


def timebox_plan() -> Zeichnung:
    z = Zeichnung(1180, 430, "phpdbadmin — Timebox-Plan",
                  "Dreizehn Timeboxes in fünf Stufen; Personentage aus der PRL (obere Schätzung 53 PT). Reihenfolge, keine Termine (neben Basalto, R-03)")
    tbs = [("00", "Fundament", 8.0, "0"), ("01", "Katalog", 4.0, "1"), ("02", "Raster", 3.6, "1"), ("03", "SQL-Editor", 3.4, "1"),
           ("04", "Bearbeiten", 4.4, "2"), ("05", "Struktur", 2.8, "2"), ("06", "Austausch", 2.1, "2"), ("07", "Rechte", 1.9, "2"), ("08", "Prüfung", 2.8, "2"),
           ("09", "Server", 3.3, "3"), ("10", "Objekte", 6.7, "3"), ("11", "Komfort", 7.1, "4"), ("12", "Team, KI", 2.9, "4")]
    farbe = {"0": "kern", "1": "kernhell", "2": "ausbau", "3": "gruen", "4": "nein"}
    skala = 1100 / 53
    x = 40
    for nr, name, pt, stufe in tbs:
        b = pt * skala
        z.rechteck(x, 110, b - 3, 70, farbe[stufe], 6)
        hell = stufe == "0"
        z.text(x + 6, 134, f"TB {nr}" if b > 58 else nr, "hw" if hell else "h")
        if b > 60:
            z.text(x + 6, 152, name, "tw" if hell else "s")
        z.text(x + 6, 170, (f"{pt:.1f} PT" if b > 50 else f"{pt:.1f}").replace(".", ","), "tw" if hell else "s")
        x += b
    marken = [("Alpha", 40 + (8 + 4 + 3.6 + 3.4) * skala), ("v1.0", 40 + (19 + 14) * skala), ("v1.1", 40 + (33 + 10) * skala), ("v1.2", 40 + 53 * skala)]
    for name, xm in marken:
        z.roh(f'<path d="M{xm:.0f} 96 L{xm:.0f} 206" stroke="{AKZENT_RAND}" stroke-width="2" stroke-dasharray="4 3"/>')
        z.rechteck(xm - 32, 210, 64, 24, "ausbau", 12)
        z.text(xm, 226, name, "h", "middle")
    z.legende(40, 280, [("kern", "Stufe 0 Fundament"), ("kernhell", "Stufe 1 Lesen"), ("ausbau", "Stufe 2 Bearbeiten"), ("gruen", "Stufe 3 Verwalten"), ("nein", "Stufe 4 Komfort, Team")])
    z.kasten(40, 310, 1100, 96, "Must-Anteil im Plan (PRL, nach Punkten)", [
        "Stufe 0: 90 % (Fundament ohne Rückfallweg, bewusst) · Stufe 1: 57 % · Stufe 2: 45 % · bis v1.0 zusammen: 59 % · Stufen 3 und 4: 0 %",
        "Neun Must-Anforderungen mit Rückfallweg laufen im Plan als «Should ↓» (z. B. Befehlspalette → Navigator, Import → SQL-Datei im Editor)",
    ], "neutral", abstand=22, punkt=False)
    return z


DIAGRAMME = {
    "problembild-ist-soll": ("00-change_request/diagrams/problembild-ist-soll.svg", problembild_ist_soll),
    "marktpositionierung": ("00-change_request/diagrams/marktpositionierung.svg", marktpositionierung),
    "scope-abgrenzung": ("00-change_request/diagrams/scope-abgrenzung.svg", scope_abgrenzung),
    "systemkontext-c4-l1": ("00-change_request/diagrams/systemkontext-c4-l1.svg", systemkontext),
    "architektur-treiber": ("00-change_request/diagrams/architektur-treiber.svg", architektur_treiber),
    "bedienkonzept": ("00-change_request/diagrams/bedienkonzept.svg", bedienkonzept),
    "stufen-fahrplan": ("00-change_request/diagrams/stufen-fahrplan.svg", stufen_fahrplan),
    "anmeldung-ohne-passwort": ("01-pre-project/diagrams/anmeldung-ohne-passwort.svg", anmeldung_ohne_passwort),
    "pruefmatrix": ("02-feasibility/diagrams/pruefmatrix.svg", pruefmatrix),
    "messwerte": ("02-feasibility/diagrams/messwerte.svg", messwerte),
    "anwenden-je-treiber": ("02-feasibility/diagrams/anwenden-je-treiber.svg", anwenden_je_treiber),
    "schreibschutz": ("02-feasibility/diagrams/schreibschutz.svg", schreibschutz),
    "architektur-l2": ("03-foundation/diagrams/architektur-l2.svg", architektur_l2),
    "anfrageverlauf": ("03-foundation/diagrams/anfrageverlauf.svg", anfrageverlauf),
    "er-app-datenbank": ("03-foundation/diagrams/er-app-datenbank.svg", er_app_datenbank),
    "use-cases": ("03-foundation/diagrams/use-cases.svg", use_cases),
    "export-im-hintergrund": ("03-foundation/diagrams/export-im-hintergrund.svg", export_im_hintergrund),
    "timebox-plan": ("03-foundation/diagrams/timebox-plan.svg", timebox_plan),
}


def main(argv: list[str]) -> int:
    if "--liste" in argv:
        for name, (pfad, _) in DIAGRAMME.items():
            print(f"{name:28s} {pfad}")
        return 0
    filter_ = argv[1] if len(argv) > 1 else ""
    n = 0
    for name, (pfad, fn) in DIAGRAMME.items():
        if filter_ and not name.startswith(filter_):
            continue
        fn().schreiben(WURZEL / pfad)
        print("geschrieben:", pfad)
        n += 1
    print(f"{n} Diagramme")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
