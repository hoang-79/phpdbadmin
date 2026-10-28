#!/usr/bin/env python3
"""
Farben und Kontraste der Gestaltung von phpdbadmin (03-foundation/gestaltung.md).

Die Palette steht hier und nur hier; das Skript rechnet für jedes Paar aus Vorder- und Hintergrund das
Kontrastverhältnis nach WCAG 2.2 und setzt die Tabelle zwischen die Marken in gestaltung.md.

Aufruf (aus dem Projektverzeichnis):
    python3 dokumentationen/werkzeuge/kontraste.py            Tabelle ausgeben
    python3 dokumentationen/werkzeuge/kontraste.py --schreiben Tabelle in gestaltung.md einsetzen
    python3 dokumentationen/werkzeuge/kontraste.py --pruefen   Rückgabe 1, wenn ein Paar seine Grenze verfehlt
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

WURZEL = Path(__file__).resolve().parent.parent
ZIEL = WURZEL / "03-foundation" / "gestaltung.md"

# Name → (hell, dunkel)
PALETTE: dict[str, tuple[str, str]] = {
    "grund": ("#ffffff", "#0b1120"),
    "flaeche": ("#f6f7f9", "#111a2c"),
    "flaeche-stark": ("#eceff3", "#1a2438"),
    "rand": ("#dde2e8", "#2a3550"),
    "text": ("#1c2534", "#e5e9f0"),
    "text-zweit": ("#4a5568", "#a9b4c6"),
    "text-leise": ("#5f6b7d", "#8c98ad"),
    "akzent": ("#0b6e82", "#4fc3d9"),
    "akzent-text": ("#ffffff", "#062a33"),
    "akzent-hell": ("#e3f2f5", "#0f3540"),
    "erfolg": ("#1d7a46", "#5bd08f"),
    "warnung": ("#9a5b00", "#f2b84b"),
    "gefahr": ("#b3261e", "#ff8a80"),
    "gefahr-text": ("#ffffff", "#3b0a07"),
    "gefahr-hell": ("#fbeaea", "#3a1416"),
    "umgebung-lokal": ("#4a5568", "#a9b4c6"),
    "umgebung-test": ("#9a5b00", "#f2b84b"),
    "umgebung-produktion": ("#b3261e", "#ff8a80"),
    "zelle-geaendert": ("#e3f2f5", "#0f3540"),
    "zelle-neu": ("#e6f4ea", "#103522"),
    "zelle-geloescht": ("#fbeaea", "#3a1416"),
}

# (Vordergrund, Hintergrund, Mindestwert, Verwendung)
PAARE: list[tuple[str, str, float, str]] = [
    ("text", "grund", 7.0, "Fliesstext, Zellwerte"),
    ("text", "flaeche", 7.0, "Text im Navigator und in Leisten"),
    ("text-zweit", "grund", 4.5, "Beschriftungen, Spaltentypen"),
    ("text-leise", "grund", 4.5, "NULL, Hilfetexte"),
    ("text-leise", "flaeche", 4.5, "Hilfetexte auf Flächen"),
    ("akzent", "grund", 4.5, "Links, Fokus, aktive Reiter"),
    ("akzent-text", "akzent", 4.5, "Text auf Hauptknopf"),
    ("text", "akzent-hell", 7.0, "Text in geänderter Zelle"),
    ("erfolg", "grund", 4.5, "Erfolgsmeldung"),
    ("warnung", "grund", 4.5, "Warnung, Umgebung Test"),
    ("gefahr", "grund", 4.5, "Fehler, Umgebung Produktion"),
    ("gefahr-text", "gefahr", 4.5, "Text auf Gefahr-Knopf"),
    ("gefahr", "gefahr-hell", 4.5, "Produktions-Chip, Fehlerkarte"),
    ("text", "zelle-neu", 7.0, "Text in neuer Zeile"),
    ("text", "zelle-geloescht", 7.0, "Text in gelöschter Zeile"),
    ("rand", "grund", 1.3, "Rasterlinien (Schmuck, keine Information)"),
    ("akzent", "flaeche", 3.0, "Fokusrahmen (Nicht-Text, WCAG 1.4.11)"),
]


def leuchtdichte(hexwert: str) -> float:
    werte = [int(hexwert[i:i + 2], 16) / 255 for i in (1, 3, 5)]
    lin = [c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4 for c in werte]
    return 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2]


def kontrast(a: str, b: str) -> float:
    la, lb = sorted((leuchtdichte(a), leuchtdichte(b)), reverse=True)
    return (la + 0.05) / (lb + 0.05)


def tabelle() -> tuple[str, list[str]]:
    zeilen = ["| Vordergrund auf Hintergrund | Verwendung | Grenze | hell | dunkel |", "|---|---|---|---|---|"]
    fehler: list[str] = []
    for vorne, hinten, grenze, zweck in PAARE:
        werte = []
        for i, modus in enumerate(("hell", "dunkel")):
            k = kontrast(PALETTE[vorne][i], PALETTE[hinten][i])
            ok = k >= grenze
            if not ok:
                fehler.append(f"{vorne} auf {hinten} ({modus}): {k:.2f} < {grenze}")
            werte.append(f"{k:.2f} {'✅' if ok else '❌'}".replace(".", ","))
        grenze_text = f"{grenze:g}".replace(".", ",")
        zeilen.append(f"| `{vorne}` auf `{hinten}` | {zweck} | {grenze_text}:1 | {werte[0]} | {werte[1]} |")
    farben = ["", "| Name | hell | dunkel |", "|---|---|---|"]
    farben += [f"| `{n}` | `{h}` | `{d}` |" for n, (h, d) in PALETTE.items()]
    return "\n".join(farben[1:]) + "\n\n" + "\n".join(zeilen), fehler


def main(argv: list[str]) -> int:
    text, fehler = tabelle()
    if "--schreiben" in argv:
        inhalt = ZIEL.read_text(encoding="utf-8")
        muster = re.compile(r"(<!-- kontraste:start -->\n).*?(<!-- kontraste:end -->)", re.S)
        assert muster.search(inhalt), "Marken kontraste:start/end fehlen in gestaltung.md"
        ZIEL.write_text(muster.sub(lambda m: m.group(1) + text + "\n" + m.group(2), inhalt), encoding="utf-8")
        print(f"Tabelle in {ZIEL.relative_to(WURZEL.parent)} eingesetzt")
    elif "--pruefen" not in argv:
        print(text)
    for f in fehler:
        print("Grenze verfehlt:", f, file=sys.stderr)
    return 1 if fehler else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
