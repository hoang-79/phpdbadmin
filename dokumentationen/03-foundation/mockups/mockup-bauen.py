#!/usr/bin/env python3
"""
Wireframes von phpdbadmin (Desktop und Mobil) — Generator.

Baut je Bereich und Variante eine statische HTML-Seite unter `seiten/`, nimmt sie mit Chrome headless
als PNG unter `bilder/` auf und schreibt `index.html` mit allen Bereichen. Die Farben kommen aus
`dokumentationen/werkzeuge/kontraste.py` (einzige Quelle der Palette), Masse und Regeln aus
`03-foundation/gestaltung.md`.

Aufruf (aus diesem Ordner oder mit Pfad):
    python3 mockup-bauen.py              alles neu bauen (Seiten, Bilder, index.html)
    python3 mockup-bauen.py --ohne-bilder nur Seiten und index.html (schnell, ohne Chrome)
    python3 mockup-bauen.py --nur 05     nur Bereich 05 (Seiten und Bilder)

Voraussetzungen: Python 3.11+, Pillow (Zuschneiden der mobilen Aufnahmen), Google Chrome.
Die Seiten laden nichts aus dem Netz; Inter und JetBrains Mono werden genommen, wenn sie auf dem
Rechner installiert sind, sonst greift die Ersatzschrift.
"""
from __future__ import annotations

import argparse
import concurrent.futures
import html
import importlib.util
import re
import subprocess
import sys
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable

HIER = Path(__file__).resolve().parent
SEITEN = HIER / "seiten"
BILDER = HIER / "bilder"
KONTRASTE = HIER.parent.parent / "werkzeuge" / "kontraste.py"
CHROME = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"

DESKTOP = (1440, 900)
MOBIL = (375, 812)
MOBIL_FENSTER = 500  # Chrome headless klemmt Fenster unter 500 px Breite


# ---------------------------------------------------------------------------------------------------
# Palette aus kontraste.py
# ---------------------------------------------------------------------------------------------------

def palette() -> dict[str, tuple[str, str]]:
    spec = importlib.util.spec_from_file_location("kontraste", KONTRASTE)
    assert spec and spec.loader, f"{KONTRASTE} fehlt"
    modul = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(modul)
    return modul.PALETTE


def css_variablen() -> str:
    farben = palette()
    hell = "".join(f"--{n}:{h};" for n, (h, _) in farben.items())
    dunkel = "".join(f"--{n}:{d};" for n, (_, d) in farben.items())
    abgeleitet_hell = "--schleier:rgba(11,17,32,.42);--schatten:0 12px 32px rgba(11,17,32,.18),0 2px 6px rgba(11,17,32,.08);"
    abgeleitet_dunkel = "--schleier:rgba(0,0,0,.6);--schatten:0 12px 32px rgba(0,0,0,.55),0 2px 6px rgba(0,0,0,.4);"
    return f".mk-hell{{{hell}{abgeleitet_hell}}}.mk-dunkel{{{dunkel}{abgeleitet_dunkel}}}"


# ---------------------------------------------------------------------------------------------------
# Stylesheet — jeder Selektor beginnt mit .mk-
# ---------------------------------------------------------------------------------------------------

SCHRIFT = 'Inter, system-ui, -apple-system, Helvetica, Arial, sans-serif'
MONO = '"JetBrains Mono", Menlo, monospace'

CSS_REGELN = """
.mk-body{margin:0;background:var(--flaeche-stark);scrollbar-width:none}
.mk-body::-webkit-scrollbar{display:none}
.mk-rahmen{position:relative;overflow:hidden;background:var(--grund);color:var(--text);font-family:SCHRIFT;font-size:14px;line-height:20px;-webkit-font-smoothing:antialiased;font-feature-settings:"cv05"}
.mk-rahmen *{box-sizing:border-box}
.mk-rahmen *::-webkit-scrollbar{display:none}
.mk-rahmen h1,.mk-rahmen h2,.mk-rahmen h3,.mk-rahmen h4,.mk-rahmen p,.mk-rahmen ul,.mk-rahmen ol,.mk-rahmen pre,.mk-rahmen figure{margin:0;padding:0}
.mk-rahmen ul,.mk-rahmen ol{list-style:none}
.mk-rahmen b,.mk-rahmen strong{font-weight:600}
.mk-rahmen a{color:var(--akzent);text-decoration:underline;text-underline-offset:2px}
.mk-rahmen code{font-family:MONO;font-size:13px}
.mk-rahmen code,.mk-rahmen pre,.mk-mono,.mk-sql,.mk-editor,.mk-marke,.mk-raster th .mk-typ{font-variant-ligatures:none;font-feature-settings:"calt" 0,"liga" 0}
.mk-desktop{width:1440px;height:900px}
.mk-mobil{width:375px;height:812px;overflow-wrap:anywhere}
.mk-mono{font-family:MONO;font-size:13px;line-height:20px}
.mk-zahl{font-variant-numeric:tabular-nums}
.mk-zweit{color:var(--text-zweit)}
.mk-leise{color:var(--text-leise)}
.mk-klein{font-size:12px;line-height:16px}
.mk-fett{font-weight:600}
.mk-gefahr-text{color:var(--gefahr)}
.mk-erfolg-text{color:var(--erfolg)}
.mk-warnung-text{color:var(--warnung)}
.mk-akzent-text{color:var(--akzent)}
.mk-ic{width:16px;height:16px;flex-shrink:0;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;vertical-align:-3px}
.mk-ic-20{width:20px;height:20px}
.mk-ic-14{width:14px;height:14px}
.mk-zeile{display:flex;align-items:center;gap:8px;min-width:0}
.mk-zeile-wrap{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0}
.mk-stapel{display:flex;flex-direction:column;min-width:0}
.mk-luecke-4{gap:4px}.mk-luecke-8{gap:8px}.mk-luecke-12{gap:12px}.mk-luecke-16{gap:16px}.mk-luecke-24{gap:24px}
.mk-rechts-weg{margin-left:auto}
.mk-voll{flex:1;min-width:0}
.mk-nowrap{white-space:nowrap}
.mk-abschneiden{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.mk-trenner{width:1px;align-self:stretch;background:var(--rand);flex-shrink:0}

/* Umgebung: Punkt + Wort, nie Farbe allein */
.mk-punkt{width:10px;height:10px;flex-shrink:0;display:inline-block;vertical-align:-1px}
.mk-umg{display:inline-flex;align-items:center;gap:6px;font-weight:500;white-space:nowrap}
.mk-umg-lokal{color:var(--umgebung-lokal)}
.mk-umg-test{color:var(--umgebung-test)}
.mk-umg-prod{color:var(--umgebung-produktion)}
.mk-streifen-prod{position:absolute;top:0;left:0;right:0;height:2px;background:var(--umgebung-produktion);z-index:30}

/* Kopfleiste */
.mk-kopf{position:relative;height:52px;display:flex;align-items:center;gap:12px;padding:0 16px;border-bottom:1px solid var(--rand);background:var(--grund)}
.mk-marke{font-family:MONO;font-weight:600;font-size:15px;letter-spacing:-.2px;color:var(--text);white-space:nowrap}
.mk-marke b{color:var(--akzent)}
.mk-chip{display:inline-flex;align-items:center;gap:8px;height:32px;padding:0 10px;border-radius:999px;border:1px solid var(--rand);background:var(--grund);font-weight:500;white-space:nowrap;color:var(--text)}
.mk-chip.mk-umg-test{border-color:var(--umgebung-test);color:var(--umgebung-test)}
.mk-chip.mk-umg-prod{border-color:var(--umgebung-produktion);background:var(--gefahr-hell);color:var(--umgebung-produktion)}
.mk-chip .mk-name{font-weight:600}
.mk-chip.mk-chip-klein{height:24px;padding:0 8px;font-size:12px;line-height:16px;gap:6px}
.mk-treiber{display:inline-flex;align-items:center;font-size:12px;line-height:16px;padding:1px 6px;border-radius:4px;border:1px solid var(--rand);color:var(--text-zweit);background:var(--grund);font-weight:500;white-space:nowrap}
.mk-schild{display:inline-flex;align-items:center;gap:4px;font-size:12px;line-height:16px;padding:2px 8px;border-radius:999px;border:1px solid var(--rand);color:var(--text-zweit);background:var(--grund);font-weight:500;white-space:nowrap}
.mk-schild.mk-s-akzent{border-color:var(--akzent);color:var(--akzent)}
.mk-schild.mk-s-erfolg{border-color:var(--erfolg);color:var(--erfolg)}
.mk-schild.mk-s-warnung{border-color:var(--warnung);color:var(--warnung)}
.mk-schild.mk-s-gefahr{border-color:var(--gefahr);color:var(--gefahr);background:var(--gefahr-hell)}
.mk-palette-feld{flex:1;max-width:460px;margin:0 auto;height:34px;border:1px solid var(--rand);border-radius:6px;background:var(--flaeche);display:flex;align-items:center;gap:8px;padding:0 6px 0 10px;color:var(--text-leise);min-width:0}
.mk-taste{display:inline-flex;align-items:center;font-size:12px;line-height:16px;border:1px solid var(--rand);border-bottom-width:2px;border-radius:4px;padding:1px 5px;background:var(--grund);color:var(--text-zweit);font-weight:500;white-space:nowrap}
.mk-ikonknopf{width:34px;height:34px;display:inline-grid;place-items:center;border-radius:6px;border:1px solid var(--rand);background:var(--grund);color:var(--text-zweit);flex-shrink:0}
.mk-ikonknopf.mk-ohne-rand{border-color:transparent;background:transparent}
.mk-konto{display:inline-flex;align-items:center;gap:4px;color:var(--text-zweit)}
.mk-avatar{width:32px;height:32px;border-radius:999px;background:var(--flaeche-stark);color:var(--text);display:inline-grid;place-items:center;font-weight:600;font-size:12px;flex-shrink:0;border:1px solid var(--rand)}
.mk-entsperrt{display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 10px;border-radius:999px;border:1px dashed var(--umgebung-produktion);color:var(--umgebung-produktion);font-size:13px;font-weight:500;white-space:nowrap;background:var(--grund)}

/* Arbeitsfläche */
.mk-rumpf{display:flex;height:calc(100% - 52px);position:relative}
.mk-navi{width:256px;flex-shrink:0;background:var(--flaeche);border-right:1px solid var(--rand);display:flex;flex-direction:column;position:relative;overflow:hidden}
.mk-navi-suche{margin:12px 12px 8px;height:32px;border:1px solid var(--rand);border-radius:6px;background:var(--grund);display:flex;align-items:center;gap:8px;padding:0 10px;color:var(--text-leise);font-size:13px}
.mk-baum{flex:1;min-height:0;overflow:hidden;padding:0 8px;position:relative}
.mk-baum-e{display:flex;align-items:center;gap:6px;height:28px;padding:0 8px;border-radius:6px;color:var(--text);white-space:nowrap;font-size:13px}
.mk-baum-e .mk-ic{color:var(--text-leise)}
.mk-baum-e.mk-aktiv{background:var(--akzent-hell);font-weight:600;box-shadow:inset 3px 0 0 var(--akzent)}
.mk-baum-e.mk-aktiv .mk-ic{color:var(--akzent)}
.mk-baum-e.mk-gruppe{color:var(--text-zweit);font-weight:500}
.mk-zaehler{margin-left:auto;font-size:12px;line-height:16px;color:var(--text-leise);font-variant-numeric:tabular-nums;font-weight:400}
.mk-navi-unten{border-top:1px solid var(--rand);padding:8px;background:var(--flaeche)}
.mk-navi-titel{display:flex;align-items:center;gap:6px;font-size:12px;line-height:16px;font-weight:600;color:var(--text-zweit);padding:6px 8px 4px;text-transform:none}
.mk-haupt{flex:1;min-width:0;display:flex;flex-direction:column;position:relative;background:var(--grund)}
.mk-offen{display:flex;flex-wrap:wrap;align-items:flex-end;padding:0 8px;background:var(--flaeche);border-bottom:1px solid var(--rand);min-height:40px}
.mk-reiter{display:flex;align-items:center;gap:8px;height:39px;padding:0 12px;color:var(--text-zweit);font-size:13px;border-right:1px solid var(--rand);white-space:nowrap;position:relative}
.mk-reiter:first-child{border-left:1px solid var(--rand)}
.mk-reiter.mk-aktiv{background:var(--grund);color:var(--text);font-weight:600;box-shadow:inset 0 2px 0 var(--akzent);margin-bottom:-1px;height:40px}
.mk-reiter .mk-x{color:var(--text-leise)}
.mk-objkopf{display:flex;align-items:center;gap:12px;padding:12px 16px 0;flex-wrap:wrap}
.mk-objkopf h1{font-size:18px;line-height:24px;font-weight:600;font-family:MONO}
.mk-ansichten{display:flex;gap:4px;padding:0 16px;border-bottom:1px solid var(--rand);flex-wrap:wrap}
.mk-ansicht{display:inline-flex;align-items:center;gap:6px;padding:10px 10px 9px;color:var(--text-zweit);font-weight:500;border-bottom:2px solid transparent;white-space:nowrap}
.mk-ansicht.mk-aktiv{color:var(--akzent);border-bottom-color:var(--akzent);font-weight:600}
.mk-filter{display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding:8px 16px;border-bottom:1px solid var(--rand)}
.mk-fchip{display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 6px 0 10px;border-radius:999px;background:var(--flaeche);border:1px solid var(--rand);font-size:13px;white-space:nowrap;max-width:100%}
.mk-fchip .mk-sp{font-family:MONO;font-size:12px;font-weight:500}
.mk-fchip .mk-op{color:var(--text-zweit)}
.mk-fchip.mk-neu{background:var(--grund);border-style:dashed;color:var(--akzent);padding:0 10px;font-weight:500}
.mk-umschalter{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--text-zweit);white-space:nowrap}

/* Datenraster */
.mk-raster-huelle{position:relative;flex:1;min-height:0;overflow:hidden}
.mk-raster{border-collapse:separate;border-spacing:0;table-layout:fixed;font-size:13px;line-height:20px;font-variant-numeric:tabular-nums}
.mk-raster th,.mk-raster td{height:32px;padding:0 10px;border-bottom:1px solid var(--rand);border-right:1px solid var(--rand);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:left;vertical-align:middle;background:var(--grund)}
.mk-dicht .mk-raster td{height:28px}
.mk-raster th{height:44px;background:var(--flaeche);font-weight:600;line-height:16px;padding-top:4px;padding-bottom:4px}
.mk-raster th .mk-typ{display:block;font-weight:400;font-size:12px;line-height:16px;color:var(--text-leise);font-family:MONO}
.mk-raster th .mk-sort{float:right;color:var(--akzent)}
.mk-raster .mk-nr{text-align:center;color:var(--text-leise);background:var(--flaeche);padding:0 4px;font-weight:500}
.mk-raster .mk-fix-ende{box-shadow:3px 0 0 0 var(--rand)}
.mk-raster td.mk-geaendert{background:var(--zelle-geaendert);font-weight:600;position:relative}
.mk-raster td.mk-geaendert::after{content:"";position:absolute;top:4px;right:4px;width:6px;height:6px;border-radius:999px;background:var(--akzent)}
.mk-raster tr.mk-neu td{background:var(--zelle-neu)}
.mk-raster tr.mk-neu td.mk-nr{color:var(--erfolg);font-weight:700}
.mk-raster tr.mk-loeschen td{background:var(--zelle-geloescht);text-decoration:line-through;text-decoration-color:var(--gefahr)}
.mk-raster tr.mk-loeschen td.mk-nr{color:var(--gefahr);font-weight:700;text-decoration:none}
.mk-raster td.mk-fokus{outline:2px solid var(--akzent);outline-offset:-2px}
.mk-raster tr.mk-gewaehlt td{background:var(--akzent-hell)}
.mk-raster .mk-r{text-align:right}
.mk-null{font-style:italic;color:var(--text-leise);font-weight:400}
.mk-vorgabe{font-style:italic;color:var(--text-leise)}
.mk-fk{color:var(--akzent);margin-right:4px;font-weight:600}
.mk-fk-name{color:var(--text-zweit)}
.mk-kante-rechts{position:absolute;top:0;right:0;bottom:0;width:72px;background:linear-gradient(to right,transparent,var(--grund) 72%);display:flex;align-items:center;justify-content:flex-end;padding-right:10px;pointer-events:none;z-index:4}
.mk-kante-unten{position:absolute;left:0;right:0;bottom:0;height:56px;background:linear-gradient(to bottom,transparent,var(--grund) 78%);display:flex;align-items:flex-end;justify-content:center;padding-bottom:6px;pointer-events:none;z-index:4}
.mk-kante-unten.mk-auf-flaeche{background:linear-gradient(to bottom,transparent,var(--flaeche) 78%)}
.mk-kante-zeichen{width:26px;height:26px;border-radius:999px;background:var(--grund);border:1px solid var(--rand);display:grid;place-items:center;color:var(--akzent);box-shadow:0 1px 3px rgba(0,0,0,.08)}
.mk-statusleiste{display:flex;align-items:center;gap:12px;padding:0 16px;height:40px;border-top:1px solid var(--rand);background:var(--flaeche);font-size:13px;color:var(--text-zweit);flex-wrap:wrap}
.mk-aenderungen{display:flex;align-items:center;gap:12px;padding:10px 16px;background:var(--akzent-hell);border-top:1px solid var(--akzent);flex-wrap:wrap}
.mk-aenderungen .mk-anzahl{font-weight:600}
.mk-legende{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--text-zweit);white-space:nowrap}
.mk-lmarke{width:16px;height:16px;border-radius:4px;display:inline-grid;place-items:center;font-size:12px;font-weight:700;line-height:1;flex-shrink:0;font-style:normal}
.mk-lmarke.mk-lm-geaendert{background:var(--zelle-geaendert);border:1px solid var(--akzent)}
.mk-lmarke.mk-lm-geaendert::after{content:"";width:6px;height:6px;border-radius:999px;background:var(--akzent)}
.mk-lmarke.mk-lm-neu{background:var(--zelle-neu);border:1px solid var(--erfolg);color:var(--erfolg)}
.mk-lmarke.mk-lm-loeschen{background:var(--zelle-geloescht);border:1px solid var(--gefahr);color:var(--gefahr)}

/* Knöpfe und Felder */
.mk-knopf{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:34px;padding:0 14px;border-radius:6px;border:1px solid var(--rand);background:var(--grund);color:var(--text);font-weight:500;white-space:nowrap;font-size:14px;line-height:20px;flex-shrink:0}
.mk-knopf.mk-primaer{background:var(--akzent);border-color:var(--akzent);color:var(--akzent-text)}
.mk-knopf.mk-gefahr{background:var(--gefahr);border-color:var(--gefahr);color:var(--gefahr-text)}
.mk-knopf.mk-gefahr-rand{color:var(--gefahr);border-color:var(--gefahr);background:var(--grund)}
.mk-knopf.mk-leise{border-color:transparent;background:transparent;color:var(--text-zweit)}
.mk-knopf.mk-aus{background:var(--flaeche-stark);border-color:var(--rand);color:var(--text-leise)}
.mk-knopf.mk-klein{height:28px;padding:0 10px;font-size:13px}
.mk-knopf.mk-gross{height:40px;font-size:15px}
.mk-knopf.mk-breit{width:100%}
.mk-knopf.mk-fokusring{box-shadow:0 0 0 2px var(--grund),0 0 0 4px var(--akzent)}
.mk-mobil .mk-knopf{min-height:40px;height:auto;padding:8px 14px;white-space:normal;text-align:center}
.mk-mobil .mk-knopf.mk-klein{min-height:32px;padding:4px 10px}
.mk-feld{display:flex;flex-direction:column;gap:6px;min-width:0}
.mk-feld-titel{font-weight:500;font-size:13px;line-height:18px;color:var(--text);display:flex;align-items:center;gap:6px}
.mk-eingabe{min-height:36px;border:1px solid var(--rand);border-radius:6px;background:var(--grund);padding:0 10px;display:flex;align-items:center;gap:8px;color:var(--text);min-width:0}
.mk-eingabe.mk-fokus{border-color:var(--akzent);box-shadow:0 0 0 2px var(--grund),0 0 0 4px var(--akzent)}
.mk-eingabe.mk-fehler{border-color:var(--gefahr);box-shadow:inset 0 0 0 1px var(--gefahr)}
.mk-eingabe.mk-gesperrt{background:var(--flaeche);color:var(--text-zweit)}
.mk-eingabe .mk-platzhalter{color:var(--text-leise)}
.mk-eingabe .mk-praefix{color:var(--text-zweit);background:var(--flaeche);border-right:1px solid var(--rand);margin-left:-10px;padding:0 10px;align-self:stretch;display:flex;align-items:center;border-radius:6px 0 0 6px;white-space:nowrap}
.mk-cursor{display:inline-block;width:1.5px;height:18px;background:var(--text);vertical-align:-4px;margin-left:1px}
.mk-hilfe{font-size:12px;line-height:16px;color:var(--text-leise)}
.mk-fehlertext{font-size:13px;line-height:18px;color:var(--gefahr);display:flex;gap:6px;align-items:flex-start}
.mk-check{display:flex;gap:10px;align-items:flex-start}
.mk-kasten{width:18px;height:18px;border:1.5px solid var(--text-zweit);border-radius:4px;flex-shrink:0;margin-top:1px;display:grid;place-items:center;background:var(--grund);color:var(--akzent-text)}
.mk-kasten.mk-an{background:var(--akzent);border-color:var(--akzent)}
.mk-kasten .mk-ic{width:12px;height:12px;stroke-width:3}
.mk-radio{width:18px;height:18px;border-radius:999px;border:1.5px solid var(--text-zweit);flex-shrink:0;margin-top:1px;background:var(--grund)}
.mk-radio.mk-an{border:5px solid var(--akzent)}
.mk-schalter{width:36px;height:20px;border-radius:999px;background:var(--flaeche-stark);border:1px solid var(--text-leise);position:relative;flex-shrink:0;margin-top:1px}
.mk-schalter::after{content:"";position:absolute;top:2px;left:2px;width:14px;height:14px;border-radius:999px;background:var(--text-leise)}
.mk-schalter.mk-an{background:var(--akzent);border-color:var(--akzent)}
.mk-schalter.mk-an::after{left:18px;background:var(--akzent-text)}
.mk-segment{display:inline-flex;border:1px solid var(--rand);border-radius:6px;overflow:hidden;background:var(--grund);flex-wrap:wrap}
.mk-segment > span{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-right:1px solid var(--rand);white-space:nowrap;font-weight:500;color:var(--text-zweit)}
.mk-segment > span:last-child{border-right:0}
.mk-segment > span.mk-aktiv{background:var(--akzent-hell);color:var(--text);font-weight:600;box-shadow:inset 0 -2px 0 var(--akzent)}

/* Flächen */
.mk-karte{border:1px solid var(--rand);border-radius:10px;background:var(--grund)}
.mk-karte-kopf{display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1px solid var(--rand)}
.mk-karte-kopf h2,.mk-karte-kopf h3{font-size:15px;line-height:20px;font-weight:600}
.mk-karte-inhalt{padding:12px 16px}
.mk-titel-1{font-size:20px;line-height:28px;font-weight:600}
.mk-titel-2{font-size:16px;line-height:24px;font-weight:600}
.mk-titel-3{font-size:14px;line-height:20px;font-weight:600}
.mk-ueberschrift-klein{font-size:12px;line-height:16px;font-weight:600;color:var(--text-zweit);letter-spacing:.02em}
.mk-hinweis{display:flex;gap:10px;padding:10px 12px;border-radius:6px;border:1px solid var(--rand);border-left:3px solid var(--text-zweit);background:var(--grund);align-items:flex-start;font-size:13px;line-height:18px}
.mk-hinweis > .mk-ic{margin-top:1px}
.mk-hinweis.mk-h-info{border-left-color:var(--akzent)}
.mk-hinweis.mk-h-info > .mk-ic{color:var(--akzent)}
.mk-hinweis.mk-h-warnung{border-color:var(--warnung);border-left-color:var(--warnung)}
.mk-hinweis.mk-h-warnung > .mk-ic{color:var(--warnung)}
.mk-hinweis.mk-h-erfolg{border-left-color:var(--erfolg)}
.mk-hinweis.mk-h-erfolg > .mk-ic{color:var(--erfolg)}
.mk-hinweis.mk-h-gefahr{border-color:var(--gefahr);background:var(--gefahr-hell)}
.mk-hinweis.mk-h-gefahr > .mk-ic{color:var(--gefahr)}
.mk-fehlerkarte{border:1px solid var(--gefahr);background:var(--gefahr-hell);border-radius:10px;padding:12px 14px;display:flex;flex-direction:column;gap:8px}
.mk-fehlerkarte .mk-fk-titel{display:flex;gap:8px;align-items:center;color:var(--gefahr);font-weight:600}
.mk-servertext{border-top:1px solid var(--rand);padding-top:8px;display:flex;flex-direction:column;gap:6px}
.mk-servertext pre{font-family:MONO;font-size:12px;line-height:18px;white-space:pre-wrap;overflow-wrap:anywhere;color:var(--text);background:var(--grund);border:1px solid var(--rand);border-radius:6px;padding:8px 10px}
.mk-liste-trenn > *{border-bottom:1px solid var(--rand)}
.mk-liste-trenn > *:last-child{border-bottom:0}

/* SQL */
.mk-sql{font-family:MONO;font-size:13px;line-height:20px;background:var(--flaeche);border:1px solid var(--rand);border-radius:6px;padding:10px 12px;white-space:pre-wrap;overflow-wrap:anywhere;color:var(--text)}
.mk-t-kw{color:var(--akzent);font-weight:600}
.mk-t-str{color:var(--erfolg)}
.mk-t-zahl{color:var(--warnung)}
.mk-t-kom{color:var(--text-leise);font-style:italic}
.mk-t-fn{color:var(--text);font-weight:600}
.mk-t-id{color:var(--text)}

/* Schwebende Ebenen */
.mk-schleier{position:absolute;inset:0;background:var(--schleier);z-index:20}
.mk-schublade{position:absolute;top:0;right:0;bottom:0;background:var(--grund);border-left:1px solid var(--rand);box-shadow:var(--schatten);z-index:21;display:flex;flex-direction:column;border-radius:10px 0 0 10px}
.mk-blatt{position:absolute;left:0;right:0;bottom:0;background:var(--grund);border-top:1px solid var(--rand);box-shadow:var(--schatten);z-index:21;display:flex;flex-direction:column;border-radius:10px 10px 0 0}
.mk-griff{width:40px;height:4px;border-radius:999px;background:var(--rand);margin:8px auto 0;flex-shrink:0}
.mk-sch-kopf{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--rand);flex-wrap:wrap}
.mk-sch-inhalt{flex:1;min-height:0;overflow:hidden;padding:16px;position:relative;display:flex;flex-direction:column;gap:16px}
.mk-sch-fuss{display:flex;align-items:center;gap:8px;padding:12px 16px;border-top:1px solid var(--rand);flex-wrap:wrap;background:var(--grund)}
.mk-dialog{position:absolute;z-index:21;background:var(--grund);border:1px solid var(--rand);border-radius:10px;box-shadow:var(--schatten);display:flex;flex-direction:column;overflow:hidden}
.mk-menue{position:absolute;z-index:22;background:var(--grund);border:1px solid var(--rand);border-radius:10px;box-shadow:var(--schatten);padding:6px;display:flex;flex-direction:column}
.mk-menue-e{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:6px;white-space:nowrap}
.mk-menue-e.mk-aktiv{background:var(--akzent-hell)}

/* Anmeldung */
.mk-anmelde-grund{position:absolute;inset:0;background:var(--flaeche);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;padding:16px}
.mk-anmelde-karte{width:400px;max-width:100%;background:var(--grund);border:1px solid var(--rand);border-radius:10px;padding:32px;display:flex;flex-direction:column;gap:20px}
.mk-mobil .mk-anmelde-karte{padding:24px 20px}
.mk-oder{display:flex;align-items:center;gap:12px;color:var(--text-leise);font-size:13px}
.mk-oder::before,.mk-oder::after{content:"";flex:1;height:1px;background:var(--rand)}
.mk-code-feld{height:52px;font-size:26px;letter-spacing:.42em;font-variant-numeric:tabular-nums;font-weight:600;justify-content:center;padding-left:18px}

/* Verbindungsübersicht */
.mk-vkarte{border:1px solid var(--rand);border-radius:10px;background:var(--grund);padding:14px 16px;display:flex;flex-direction:column;gap:8px;position:relative;min-width:0}
.mk-vkarte.mk-umg-prod-karte{border-color:var(--umgebung-produktion);box-shadow:inset 0 3px 0 var(--umgebung-produktion)}
.mk-vkarte.mk-umg-test-karte{box-shadow:inset 0 3px 0 var(--umgebung-test)}
.mk-vkarte .mk-vname{font-size:16px;line-height:22px;font-weight:600;font-family:MONO;overflow-wrap:anywhere}
.mk-stern{color:var(--text-leise)}
.mk-stern.mk-an{color:var(--warnung)}
.mk-stern.mk-an .mk-ic{fill:currentColor}
.mk-warnschild{display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border-radius:6px;border:1px solid var(--warnung);font-size:13px;line-height:18px;background:var(--grund)}
.mk-warnschild > .mk-ic{color:var(--warnung);margin-top:1px}
.mk-gruppe-titel{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--text-zweit);margin:0 0 10px}
.mk-kachel{border:1px solid var(--rand);border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:4px;background:var(--grund);position:relative;min-width:0}
.mk-kachel.mk-aktiv{border:2px solid var(--akzent);padding:11px;background:var(--akzent-hell)}
.mk-kachel .mk-haken{position:absolute;top:10px;right:10px;width:18px;height:18px;border-radius:999px;background:var(--akzent);color:var(--akzent-text);display:grid;place-items:center}
.mk-kachel .mk-haken .mk-ic{width:12px;height:12px;stroke-width:3}
.mk-pruefschritt{display:flex;gap:10px;align-items:flex-start;padding:8px 0;font-size:13px;line-height:18px}
.mk-pruefschritt .mk-ic{margin-top:1px}

/* Tabellen in Seiten (nicht das Datenraster) */
.mk-tabelle{width:100%;border-collapse:collapse;font-size:13px;line-height:18px}
.mk-tabelle th{text-align:left;font-weight:600;font-size:12px;line-height:16px;color:var(--text-zweit);padding:8px 10px;border-bottom:1px solid var(--rand);background:var(--flaeche);white-space:nowrap}
.mk-tabelle td{padding:8px 10px;border-bottom:1px solid var(--rand);vertical-align:top}
.mk-tabelle tr:last-child td{border-bottom:0}
.mk-tabelle .mk-r{text-align:right}
.mk-tabelle tr.mk-neu td{background:var(--zelle-neu)}
.mk-tabelle tr.mk-geaendert td{background:var(--zelle-geaendert)}
.mk-tabelle tr.mk-loeschen td{background:var(--zelle-geloescht)}
.mk-tabelle tr.mk-loeschen td.mk-durch{text-decoration:line-through;text-decoration-color:var(--gefahr)}
.mk-tabelle tr.mk-gewaehlt td{background:var(--akzent-hell)}
.mk-tabelle.mk-eng td{padding:6px 10px}
.mk-tabelle.mk-einzeilig td{white-space:nowrap}
.mk-tabelle.mk-eng th{padding:6px 10px}
.mk-tabelle td.mk-durch code,.mk-tabelle td.mk-durch span{text-decoration:inherit}

/* Mobil */
.mk-mkopf{position:relative;height:52px;display:flex;align-items:center;gap:8px;padding:0 12px;border-bottom:1px solid var(--rand);background:var(--grund)}
.mk-minhalt{position:absolute;top:52px;left:0;right:0;bottom:0;overflow:hidden;padding:16px;display:flex;flex-direction:column;gap:16px}
.mk-mkarte{border:1px solid var(--rand);border-radius:10px;background:var(--grund);padding:10px 12px;display:flex;flex-direction:column;gap:4px}
.mk-mzeile{display:flex;gap:4px 8px;flex-wrap:wrap;align-items:baseline;min-width:0;font-variant-numeric:tabular-nums}
.mk-mzeile .mk-lab{font-size:12px;line-height:16px;color:var(--text-leise);font-family:MONO}
.mk-mzeile .mk-lab:not(:first-child){margin-left:8px}
.mk-mzeile .mk-wert.mk-geaendert{background:var(--zelle-geaendert);font-weight:600;border-radius:4px;padding:0 4px;box-shadow:inset 0 0 0 1px var(--akzent)}
.mk-mkarte.mk-neu{background:var(--zelle-neu)}
.mk-mkarte.mk-loeschen{background:var(--zelle-geloescht)}
.mk-mkarte.mk-loeschen .mk-wert{text-decoration:line-through;text-decoration-color:var(--gefahr)}
.mk-mkarte.mk-loeschen .mk-mono{text-decoration:line-through;text-decoration-color:var(--gefahr)}
.mk-mfelder{display:grid;grid-template-columns:1fr 1fr;gap:6px 12px}
.mk-mfeld{display:flex;flex-direction:column;min-width:0}
.mk-mfeld .mk-lab{font-size:12px;line-height:16px;color:var(--text-leise);font-family:MONO}
.mk-mfeld .mk-wert{font-variant-numeric:tabular-nums}
.mk-mfeld.mk-geaendert .mk-wert{background:var(--zelle-geaendert);font-weight:600;border-radius:4px;padding:0 4px;margin-left:-4px;align-self:flex-start}
.mk-unten-fest{position:absolute;left:0;right:0;bottom:0;z-index:10}
.mk-sch-inhalt > *,.mk-minhalt > *,.mk-stapel > .mk-karte,.mk-stapel > .mk-hinweis,.mk-stapel > .mk-anw-liste,.mk-stapel > .mk-bestaetigung{flex-shrink:0}
"""


def css() -> str:
    return css_variablen() + (CSS_REGELN + CSS_ARBEIT).replace("SCHRIFT", SCHRIFT).replace("MONO", MONO)


# ---------------------------------------------------------------------------------------------------
# Symbole (Lucide, ISC-Lizenz — nachgezeichnet als Pfade)
# ---------------------------------------------------------------------------------------------------

SYMBOLE = {
    "lock": '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    "lock-open": '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
    "star": '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    "search": '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    "chevron-down": '<path d="m6 9 6 6 6-6"/>',
    "chevron-right": '<path d="m9 18 6-6-6-6"/>',
    "chevron-left": '<path d="m15 18-6-6 6-6"/>',
    "chevron-up": '<path d="m18 15-6-6-6 6"/>',
    "database": '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
    "table": '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18M3 15h18M12 3v18"/>',
    "eye": '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
    "plus": '<path d="M5 12h14M12 5v14"/>',
    "minus": '<path d="M5 12h14"/>',
    "x": '<path d="M18 6 6 18M6 6l12 12"/>',
    "check": '<path d="M20 6 9 17l-5-5"/>',
    "alert": '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
    "key": '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5"/>',
    "pk": '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
    "copy": '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    "download": '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/>',
    "upload": '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5M12 3v12"/>',
    "play": '<polygon points="6 3 20 12 6 21 6 3"/>',
    "stop": '<rect width="14" height="14" x="5" y="5" rx="2"/>',
    "clock": '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    "history": '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5M12 7v5l4 2"/>',
    "bookmark": '<path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/>',
    "filter": '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
    "sliders": '<path d="M21 4h-7M10 4H3M21 12h-9M8 12H3M21 20h-5M12 20H3M14 2v4M8 10v4M16 18v4"/>',
    "user": '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    "users": '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    "logout": '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
    "sun": '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
    "moon": '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
    "monitor": '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8M12 17v4"/>',
    "smartphone": '<rect width="14" height="20" x="5" y="2" rx="2"/><path d="M12 18h.01"/>',
    "menu": '<path d="M4 6h16M4 12h16M4 18h16"/>',
    "more": '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
    "arrow-right": '<path d="M5 12h14M12 5l7 7-7 7"/>',
    "arrow-left": '<path d="M19 12H5M12 19l-7-7 7-7"/>',
    "arrow-down": '<path d="M12 5v14M19 12l-7 7-7-7"/>',
    "refresh": '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    "shield": '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    "file": '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
    "file-text": '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4M10 9H8M16 13H8M16 17H8"/>',
    "folder": '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
    "terminal": '<path d="m4 17 6-6-6-6M12 19h8"/>',
    "undo": '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5 5.5 5.5 0 0 1-5.5 5.5H11"/>',
    "link": '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    "mail": '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    "trash": '<path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2M10 11v6M14 11v6"/>',
    "pencil": '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/>',
    "ok": '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
    "nok": '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
    "info": '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
    "kreis": '<circle cx="12" cy="12" r="10"/>',
    "code": '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
    "save": '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7M7 3v4a1 1 0 0 0 1 1h7"/>',
    "command": '<path d="M15 6v12a3 3 0 1 0 3-3H6a3 3 0 1 0 3 3V6a3 3 0 1 0-3 3h12a3 3 0 1 0-3-3"/>',
    "server": '<rect width="20" height="8" x="2" y="2" rx="2"/><rect width="20" height="8" x="2" y="14" rx="2"/><path d="M6 6h.01M6 18h.01"/>',
    "grip": '<circle cx="9" cy="12" r="1"/><circle cx="9" cy="5" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="19" r="1"/>',
    "columns": '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M12 3v18"/>',
    "relation": '<rect width="8" height="8" x="2" y="2" rx="2"/><rect width="8" height="8" x="14" y="14" rx="2"/><path d="M6 10v4a2 2 0 0 0 2 2h6"/>',
    "send": '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    "panel": '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/>',
    "sort": '<path d="m21 16-4 4-4-4M17 20V4M3 8l4-4 4 4M7 4v16"/>',
    "enter": '<path d="M9 10l-5 5 5 5"/><path d="M20 4v7a4 4 0 0 1-4 4H4"/>',
    "wand": '<path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72M14 7l3 3"/>',
    "view": '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
    "fn": '<path d="M9 17c2 0 2.8-1 2.8-2.8V10c0-2 1-3.3 3.2-3"/><path d="M9 11.2h5.7"/><rect width="18" height="18" x="3" y="3" rx="2"/>',
    "zap": '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
    "calendar": '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    "image": '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
    "layers": '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65M22 12.65l-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
    "loader": '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
    "keyboard": '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="M6 8h.01M10 8h.01M14 8h.01M18 8h.01M8 12h.01M12 12h.01M16 12h.01M7 16h10"/>',
    "globe": '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20M2 12h20"/>',
    "list": '<path d="M3 12h.01M3 18h.01M3 6h.01M8 12h13M8 18h13M8 6h13"/>',
}


def ic(name: str, klasse: str = "") -> str:
    return f'<svg class="mk-ic {klasse}" viewBox="0 0 24 24" aria-hidden="true">{SYMBOLE[name]}</svg>'


def esc(text: str) -> str:
    return html.escape(text, quote=False)


# ---------------------------------------------------------------------------------------------------
# Bausteine
# ---------------------------------------------------------------------------------------------------

UMG = {
    "lokal": ("mk-umg-lokal", "lokal"),
    "test": ("mk-umg-test", "Test"),
    "prod": ("mk-umg-prod", "Produktion"),
}
UMG_KURZ = {"lokal": "lokal", "test": "test", "prod": "produktion"}


def punkt(umg: str) -> str:
    """Umgebungssymbol: ○ lokal, ◐ Test, ● Produktion — als SVG, damit es überall gleich aussieht."""
    if umg == "lokal":
        inner = '<circle cx="5" cy="5" r="3.9" fill="none" stroke="currentColor" stroke-width="1.6"/>'
    elif umg == "test":
        inner = ('<circle cx="5" cy="5" r="3.9" fill="none" stroke="currentColor" stroke-width="1.6"/>'
                 '<path d="M5 1.1A3.9 3.9 0 0 0 5 8.9Z" fill="currentColor"/>')
    else:
        inner = '<circle cx="5" cy="5" r="4.7" fill="currentColor"/>'
    return f'<svg class="mk-punkt" viewBox="0 0 10 10" aria-hidden="true">{inner}</svg>'


def umg_marke(umg: str) -> str:
    klasse, wort = UMG[umg]
    return f'<span class="mk-umg {klasse}">{punkt(umg)}{wort}</span>'


def treiber(name: str) -> str:
    return f'<span class="mk-treiber">{esc(name)}</span>'


def chip(umg: str, name: str, drv: str | None = None, schloss: bool = False, offen: bool = False,
         pfeil: bool = True, kurz: bool = False, klein: bool = False) -> str:
    klasse, _ = UMG[umg]
    wort = {"lokal": "lokal", "test": "test", "prod": "PROD" if kurz else "produktion"}[umg]
    teile = [punkt(umg), f'<span>{wort} · <span class="mk-name">{esc(name)}</span></span>']
    if drv:
        teile.append(treiber(drv))
    if schloss:
        teile.append(ic("lock", "mk-ic-14"))
    if offen:
        teile.append(ic("lock-open", "mk-ic-14"))
    if pfeil:
        teile.append(ic("chevron-down", "mk-ic-14"))
    return f'<span class="mk-chip {klasse}{" mk-chip-klein" if klein else ""}">{"".join(teile)}</span>'


def knopf(text: str, art: str = "", symbol: str | None = None, klasse: str = "", nach: str | None = None) -> str:
    arten = " ".join(f"mk-{a}" for a in art.split()) if art else ""
    vor = ic(symbol) if symbol else ""
    hinten = ic(nach) if nach else ""
    return f'<span class="mk-knopf {arten} {klasse}">{vor}{esc(text) if text else ""}{hinten}</span>'


def taste(text: str) -> str:
    return f'<span class="mk-taste">{esc(text)}</span>'


def marke() -> str:
    return '<span class="mk-marke">php<b>db</b>admin</span>'


def feld(titel: str, wert: str = "", hilfe: str = "", fokus: bool = False, fehler: str = "",
         platzhalter: str = "", mono: bool = False, praefix: str = "", nach: str = "", gesperrt: bool = False,
         roh: bool = False, klasse: str = "", titel_extra: str = "") -> str:
    zustand = " mk-fokus" if fokus else ""
    zustand += " mk-fehler" if fehler else ""
    zustand += " mk-gesperrt" if gesperrt else ""
    inhalt = ""
    if praefix:
        inhalt += f'<span class="mk-praefix">{praefix}</span>'
    if wert:
        w = wert if roh else esc(wert)
        inhalt += f'<span class="mk-voll mk-abschneiden{" mk-mono" if mono else ""}">{w}{"<i class=mk-cursor></i>" if fokus else ""}</span>'
    else:
        inhalt += f'<span class="mk-voll mk-platzhalter">{esc(platzhalter)}</span>'
    inhalt += nach
    teile = [f'<span class="mk-feld-titel">{esc(titel)}{titel_extra}</span>'] if titel else []
    teile.append(f'<span class="mk-eingabe{zustand}">{inhalt}</span>')
    if fehler:
        teile.append(f'<span class="mk-fehlertext">{ic("alert", "mk-ic-14")}<span>{fehler}</span></span>')
    if hilfe:
        teile.append(f'<span class="mk-hilfe">{hilfe}</span>')
    return f'<label class="mk-feld {klasse}">{"".join(teile)}</label>'


def auswahl(titel: str, wert: str, hilfe: str = "", mono: bool = False, klasse: str = "") -> str:
    return feld(titel, wert, hilfe=hilfe, mono=mono, nach=ic("chevron-down"), klasse=klasse)


def kasten(text: str, an: bool = False, hilfe: str = "") -> str:
    zeichen = ic("check") if an else ""
    h = f'<span class="mk-hilfe">{hilfe}</span>' if hilfe else ""
    return (f'<span class="mk-check"><span class="mk-kasten{" mk-an" if an else ""}">{zeichen}</span>'
            f'<span class="mk-stapel mk-luecke-4"><span>{text}</span>{h}</span></span>')


def radio(text: str, an: bool = False, hilfe: str = "") -> str:
    h = f'<span class="mk-hilfe">{hilfe}</span>' if hilfe else ""
    return (f'<span class="mk-check"><span class="mk-radio{" mk-an" if an else ""}"></span>'
            f'<span class="mk-stapel mk-luecke-4"><span>{text}</span>{h}</span></span>')


def schalter(text: str, an: bool = False, hilfe: str = "") -> str:
    h = f'<span class="mk-hilfe">{hilfe}</span>' if hilfe else ""
    return (f'<span class="mk-check"><span class="mk-schalter{" mk-an" if an else ""}"></span>'
            f'<span class="mk-stapel mk-luecke-4"><span>{text}</span>{h}</span></span>')


def segment(eintraege: list[str], aktiv: int) -> str:
    teile = [f'<span class="{"mk-aktiv" if i == aktiv else ""}">{e}</span>' for i, e in enumerate(eintraege)]
    return f'<span class="mk-segment">{"".join(teile)}</span>'


def hinweis(text: str, art: str = "info", symbol: str | None = None, klasse: str = "") -> str:
    zeichen = symbol or {"info": "info", "warnung": "alert", "erfolg": "ok", "gefahr": "alert"}[art]
    return f'<div class="mk-hinweis mk-h-{art} {klasse}">{ic(zeichen)}<div class="mk-stapel mk-luecke-4">{text}</div></div>'


def kante_unten(flaeche: bool = False) -> str:
    return (f'<div class="mk-kante-unten{" mk-auf-flaeche" if flaeche else ""}" aria-hidden="true">'
            f'<span class="mk-kante-zeichen">{ic("chevron-down", "mk-ic-14")}</span></div>')


def kante_rechts() -> str:
    return (f'<div class="mk-kante-rechts" aria-hidden="true"><span class="mk-kante-zeichen">'
            f'{ic("chevron-right", "mk-ic-14")}</span></div>')


SQL_SCHLUESSEL = {
    "SELECT", "FROM", "WHERE", "AND", "OR", "NOT", "NULL", "IS", "IN", "AS", "ON", "JOIN", "LEFT", "INNER", "GROUP",
    "BY", "ORDER", "DESC", "ASC", "LIMIT", "OFFSET", "HAVING", "UPDATE", "SET", "INSERT", "INTO", "VALUES", "DELETE",
    "START", "TRANSACTION", "COMMIT", "ROLLBACK", "BEGIN", "ALTER", "TABLE", "ADD", "COLUMN", "DROP", "RENAME", "TO",
    "CREATE", "INDEX", "UNIQUE", "PRIMARY", "KEY", "FOREIGN", "REFERENCES", "CONSTRAINT", "CHECK", "DEFAULT",
    "AUTO_INCREMENT", "UNSIGNED", "ENGINE", "CHARSET", "COLLATE", "TRIGGER", "BEFORE", "AFTER", "FOR", "EACH", "ROW",
    "CASCADE", "RESTRICT", "BETWEEN", "INTERVAL", "DAY", "CASE", "WHEN", "THEN", "ELSE", "END", "DISTINCT", "WITH",
    "ANALYZE", "IF", "EXISTS", "USING", "TYPE", "VARCHAR", "BIGINT", "DECIMAL", "CHAR", "TEXT", "JSON", "TIMESTAMP",
    "ENUM", "LONGBLOB", "INT", "INTEGER", "NUMERIC", "TIMESTAMPTZ", "BOOLEAN", "CURRENT_TIMESTAMP", "NEW", "EXPLAIN",
    "UNION", "ALL", "LIKE", "ILIKE", "COUNT", "SUM", "NOW", "TRUE", "FALSE", "ROW_FORMAT", "DYNAMIC", "DELIMITER",
}
SQL_FUNKTIONEN = {"COUNT", "SUM", "NOW", "DATE_FORMAT", "COALESCE", "ROUND", "AVG", "MAX", "MIN", "DATE"}
SQL_TOKEN = re.compile(
    r"(?P<kom>--[^\n]*)|(?P<str>'(?:[^'\\]|\\.)*')|(?P<id>`[^`]*`|\"[^\"]*\")|(?P<zahl>\b\d+(?:\.\d+)?\b)"
    r"|(?P<wort>\b[A-Za-z_][A-Za-z_0-9]*\b)|(?P<rest>\s+|.)", re.S)


def sql_hervorheben(code: str) -> str:
    teile = []
    for m in SQL_TOKEN.finditer(code):
        art = m.lastgroup
        t = esc(m.group())
        if art == "kom":
            teile.append(f'<span class="mk-t-kom">{t}</span>')
        elif art == "str":
            teile.append(f'<span class="mk-t-str">{t}</span>')
        elif art == "zahl":
            teile.append(f'<span class="mk-t-zahl">{t}</span>')
        elif art == "wort" and m.group().upper() in SQL_FUNKTIONEN and code[m.end():m.end() + 1] == "(":
            teile.append(f'<span class="mk-t-fn">{t}</span>')
        elif art == "wort" and m.group().upper() in SQL_SCHLUESSEL and m.group().isupper():
            teile.append(f'<span class="mk-t-kw">{t}</span>')
        else:
            teile.append(t)
    return "".join(teile)


def sql_block(code: str, klasse: str = "") -> str:
    return f'<pre class="mk-sql {klasse}">{sql_hervorheben(code)}</pre>'


# ---------------------------------------------------------------------------------------------------
# Kopfleisten
# ---------------------------------------------------------------------------------------------------

def kopf(chip_html: str = "", extra: str = "", palette_text: str = "Tabelle, Abfrage oder Befehl suchen …") -> str:
    return (
        f'<header class="mk-kopf">{marke()}'
        f'{chip_html}{extra}'
        f'<div class="mk-palette-feld">{ic("search")}<span class="mk-voll mk-abschneiden">{esc(palette_text)}</span>'
        f'{taste("⌘K")}</div>'
        f'<span class="mk-ikonknopf mk-ohne-rand" title="Tastenkürzel">{ic("keyboard")}</span>'
        f'<span class="mk-konto"><span class="mk-avatar">HN</span>{ic("chevron-down", "mk-ic-14")}</span>'
        f'</header>'
    )


def mkopf(links: str = "", mitte: str = "", rechts: str | None = None) -> str:
    if rechts is None:
        rechts = (f'<span class="mk-ikonknopf mk-ohne-rand">{ic("search")}</span>'
                  f'<span class="mk-avatar">HN</span>')
    return (f'<header class="mk-mkopf">{links}<div class="mk-voll mk-zeile">{mitte}</div>'
            f'<div class="mk-zeile" style="gap:4px">{rechts}</div></header>')


# ---------------------------------------------------------------------------------------------------
# Daten des Beispiels (erfunden, plausibel)
# ---------------------------------------------------------------------------------------------------

JETZT = "28.10.2026, 10:31"

SHOP_TABELLEN = [
    ("addresses", "≈ 31'540"), ("carts", "≈ 2'310"), ("categories", "86"), ("coupons", "412"),
    ("customers", "≈ 9'870"), ("order_items", "≈ 131'200"), ("orders", "≈ 48'210"), ("payments", "≈ 46'900"),
    ("product_images", "≈ 5'120"), ("product_variants", "≈ 3'480"), ("products", "1'164"), ("reviews", "≈ 7'030"),
    ("shipments", "≈ 45'800"), ("stock_movements", "≈ 218'000"),
]


def navigator(aktiv: str = "orders", unten: bool = True, suche: str = "") -> str:
    e = []
    e.append(f'<div class="mk-baum-e mk-gruppe">{ic("chevron-down", "mk-ic-14")}{ic("database")}shop'
             f'<span class="mk-zaehler">MySQL 8.4</span></div>')
    e.append(f'<div class="mk-baum-e mk-gruppe" style="padding-left:24px">{ic("chevron-down", "mk-ic-14")}Tabellen'
             f'<span class="mk-zaehler">14</span></div>')
    for name, zahl in SHOP_TABELLEN:
        klasse = " mk-aktiv" if name == aktiv else ""
        e.append(f'<div class="mk-baum-e{klasse}" style="padding-left:44px">{ic("table")}'
                 f'<span class="mk-abschneiden">{name}</span><span class="mk-zaehler">{zahl}</span></div>')
    for gruppe, zahl in (("Views", "2"), ("Routinen", "1"), ("Trigger", "1"), ("Events", "0")):
        e.append(f'<div class="mk-baum-e mk-gruppe" style="padding-left:24px">{ic("chevron-right", "mk-ic-14")}'
                 f'{gruppe}<span class="mk-zaehler">{zahl}</span></div>')
    e.append(f'<div class="mk-baum-e mk-gruppe">{ic("chevron-right", "mk-ic-14")}{ic("database")}shop_archiv</div>')
    baum = "".join(e)
    such = (f'<div class="mk-navi-suche">{ic("search", "mk-ic-14")}<span class="mk-voll">'
            f'{esc(suche) if suche else "Objekte filtern"}</span>{taste("/")}</div>')
    teil_unten = ""
    if unten:
        teil_unten = (
            '<div class="mk-navi-unten">'
            f'<div class="mk-navi-titel">{ic("bookmark", "mk-ic-14")}Gespeichert</div>'
            f'<div class="mk-baum-e">{ic("file-text")}<span class="mk-abschneiden">Offene Bestellungen &gt; 7 Tage</span></div>'
            f'<div class="mk-baum-e">{ic("file-text")}<span class="mk-abschneiden">Umsatz je Monat</span></div>'
            f'<div class="mk-navi-titel">{ic("history", "mk-ic-14")}Verlauf</div>'
            f'<div class="mk-baum-e"><span class="mk-mono mk-abschneiden mk-voll" style="font-size:12px">SELECT … FROM orders WHERE …</span><span class="mk-zaehler">10:28</span></div>'
            f'<div class="mk-baum-e"><span class="mk-mono mk-abschneiden mk-voll" style="font-size:12px">UPDATE orders SET status …</span><span class="mk-zaehler">09:58</span></div>'
            '</div>'
        )
    return (f'<nav class="mk-navi" aria-label="Navigator">{such}<div class="mk-baum">{baum}{kante_unten(True)}</div>'
            f'{teil_unten}</nav>')


# ---------------------------------------------------------------------------------------------------
# Seitenrahmen
# ---------------------------------------------------------------------------------------------------

def seite(titel: str, inhalt: str, geraet: str, dunkel: bool = False) -> str:
    thema = "mk-dunkel" if dunkel else "mk-hell"
    return (
        '<!doctype html>\n'
        f'<html lang="de-CH" class="{thema}"><head><meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width, initial-scale=1">'
        f'<title>{esc(titel)}</title><style>{css()}</style></head>\n'
        f'<body class="mk-body"><div class="mk-rahmen mk-{geraet}">{inhalt}</div></body></html>\n'
    )


# ===================================================================================================
# Bereich 01 — Anmeldung
# ===================================================================================================

def b01_anmeldung(mobil: bool) -> str:
    karte = (
        '<div class="mk-anmelde-karte">'
        f'<div class="mk-zeile">{marke()}</div>'
        '<div class="mk-stapel mk-luecke-4"><h1 class="mk-titel-1">Anmelden</h1>'
        '<p class="mk-zweit">Mit Ihrem Passkey oder mit einem Code, den wir Ihnen per Mail senden.</p></div>'
        f'{knopf("Mit Passkey anmelden", "primaer gross breit", "key")}'
        '<div class="mk-oder">oder mit Code per Mail</div>'
        + feld("E-Mail-Adresse", "hoang@nguyen-development.ch", fokus=True,
               hilfe="Ihr Browser schlägt hier auch gespeicherte Passkeys vor.")
        + knopf("Code senden", "gross breit", "mail")
        + '<p class="mk-hilfe">Neue Konten entstehen nur per Einladung durch eine Administratorin oder einen Administrator.</p>'
        '</div>'
    )
    fuss = ('<div class="mk-zeile-wrap mk-leise mk-klein" style="justify-content:center">'
            f'<span class="mk-zeile" style="gap:4px">{ic("globe", "mk-ic-14")}<b class="mk-zweit">Deutsch</b></span>'
            '<span>·</span><span>English</span></div>')
    return f'<div class="mk-anmelde-grund">{karte}{fuss}</div>'


# ===================================================================================================
# Bereich 02 — Code eingeben
# ===================================================================================================

def b02_code(mobil: bool, abgelaufen: bool = False) -> str:
    if abgelaufen:
        meldung = (
            '<div class="mk-fehlerkarte">'
            f'<div class="mk-fk-titel">{ic("clock")}Code abgelaufen</div>'
            '<p>Dieser Code galt bis 10:24 Uhr. Codes sind 10 Minuten gültig und nur einmal verwendbar. '
            'Fordern Sie einen neuen Code an — er kommt an dieselbe Adresse.</p>'
            '</div>'
        )
        code_feld = feld("Code aus der Mail", "482916", fehler="", roh=False, klasse="")
        code_feld = code_feld.replace('class="mk-eingabe', 'class="mk-eingabe mk-code-feld mk-fehler')
        knoepfe = knopf("Neuen Code senden", "primaer gross breit", "refresh")
        unten = ('<div class="mk-zeile-wrap" style="justify-content:space-between">'
                 '<a>Andere Adresse verwenden</a><span class="mk-hilfe">Neuer Code: 10 Minuten gültig</span></div>')
    else:
        meldung = hinweis('<span>Wir haben einen Code an <b class="mk-nowrap">hoang@nguyen-development.ch</b> gesendet. '
                          'Er ist 10 Minuten gültig, bis 10:41 Uhr.</span>', "info", "mail")
        code_feld = feld("Code aus der Mail", "4829", fokus=True)
        code_feld = code_feld.replace('class="mk-eingabe', 'class="mk-eingabe mk-code-feld')
        knoepfe = knopf("Anmelden", "primaer gross breit")
        unten = ('<div class="mk-zeile-wrap" style="justify-content:space-between">'
                 '<span class="mk-leise">Code erneut senden <span class="mk-zahl">(in 0:42)</span></span>'
                 '<a>Andere Adresse verwenden</a></div>')
    hilfe = '<p class="mk-hilfe">Sechs Ziffern. Sie können den Code auch aus der Mail einfügen — Ihr Gerät schlägt ihn oft schon vor.</p>'
    karte = (
        '<div class="mk-anmelde-karte">'
        f'<div class="mk-zeile">{marke()}</div>'
        '<div class="mk-stapel mk-luecke-4"><h1 class="mk-titel-1">Code eingeben</h1></div>'
        f'{meldung}{code_feld}{hilfe if not abgelaufen else ""}{knoepfe}{unten}'
        '</div>'
    )
    zurueck = (f'<span class="mk-zeile mk-zweit" style="gap:4px">{ic("arrow-left", "mk-ic-14")}'
               'Zurück zur Anmeldung</span>')
    return f'<div class="mk-anmelde-grund">{karte}{zurueck}</div>'


# ===================================================================================================
# Bereich 03 — Startseite = Verbindungsübersicht
# ===================================================================================================

VERBINDUNGEN = {
    "basalto": dict(umg="lokal", name="basalto", drv="MariaDB 10.11", ort="127.0.0.1:3306", db="basalto",
                    konto="root", zuletzt="heute 09:12", stern=True, warnung=True, gruppe="Lokal (Herd)"),
    "fristo": dict(umg="lokal", name="fristo", drv="PostgreSQL 18", ort="127.0.0.1:5432", db="fristo · Schema public",
                   konto="fristo", zuletzt="gestern 17:40", stern=False, gruppe="Lokal (Herd)"),
    "notizen": dict(umg="lokal", name="notizen.sqlite", drv="SQLite 3.46", ort="~/Datenbanken/notizen.sqlite",
                    db="2,4 MB", konto="", zuletzt="22.10.2026", stern=False, gruppe="Lokal (Herd)"),
    "test-shop": dict(umg="test", name="shop", drv="MySQL 8.4", ort="test-db.kunde-shop.ch:3306", db="shop_test",
                      konto="shop_app", zuletzt="heute 08:47", stern=False, gruppe="Kunde Shop"),
    "prod-shop": dict(umg="prod", name="shop", drv="MySQL 8.4", ort="db.kunde-shop.ch:3306", db="shop",
                      konto="shop_lesen", zuletzt="heute 10:19", stern=True, schloss=True, gruppe="Kunde Shop"),
}


def vkarte(v: dict, mobil: bool = False) -> str:
    umg = v["umg"]
    karte_klasse = {"prod": " mk-umg-prod-karte", "test": " mk-umg-test-karte"}.get(umg, "")
    stern = (f'<span class="mk-stern{" mk-an" if v.get("stern") else ""}" title="Favorit">{ic("star")}</span>')
    schloss = (f'<span class="mk-schild mk-s-gefahr">{ic("lock", "mk-ic-14")}schreibgeschützt</span>'
               if v.get("schloss") else "")
    konto = f' · Konto <code>{esc(v["konto"])}</code>' if v["konto"] else ""
    warn = ""
    if v.get("warnung"):
        warn = (f'<div class="mk-warnschild">{ic("alert")}<div class="mk-stapel mk-luecke-4">'
                '<b>Dieses Konto darf Dateien auf dem Server lesen.</b>'
                '<span class="mk-zweit">Empfehlung: ein eigenes Konto ohne <code>FILE</code>-Recht. '
                '<a>So richten Sie es ein</a></span></div></div>')
    return (
        f'<article class="mk-vkarte{karte_klasse}">'
        f'<div class="mk-zeile">{umg_marke(umg)}{schloss}<span class="mk-rechts-weg mk-zeile" style="gap:4px">{stern}'
        f'<span class="mk-ikonknopf mk-ohne-rand" style="width:28px;height:28px">{ic("more")}</span></span></div>'
        f'<div class="mk-zeile-wrap"><span class="mk-vname">{esc(v["name"])}</span>{treiber(v["drv"])}</div>'
        f'<div class="mk-zweit mk-klein" style="font-size:13px;line-height:18px">'
        f'<span class="mk-mono" style="font-size:12px">{esc(v["ort"])}</span><br>'
        f'{"Datenbank " if v["konto"] else ""}{esc(v["db"])}{konto}</div>'
        f'{warn}'
        f'<div class="mk-leise mk-klein mk-zeile" style="gap:4px">{ic("clock", "mk-ic-14")}zuletzt geöffnet {esc(v["zuletzt"])}</div>'
        f'</article>'
    )


def b03_verbindungen(mobil: bool) -> str:
    gruppen = [("Lokal (Herd)", ["basalto", "fristo", "notizen"]), ("Kunde Shop", ["prod-shop", "test-shop"])]
    filterzeile = (
        '<div class="mk-zeile-wrap">'
        + feld("", "", platzhalter="Verbindungen suchen", nach="", klasse="").replace(
            '<span class="mk-voll mk-platzhalter">', f'{ic("search")}<span class="mk-voll mk-platzhalter">')
        .replace('class="mk-feld ', 'style="width:' + ("100%" if mobil else "300px") + '" class="mk-feld ')
        + f'<span class="mk-fchip" style="background:var(--akzent-hell);border-color:var(--akzent);font-weight:600;padding:0 10px">Alle 5</span>'
        f'<span class="mk-fchip" style="padding:0 10px">{ic("star", "mk-ic-14")}Favoriten 2</span>'
        f'<span class="mk-fchip" style="padding:0 10px">{punkt("prod")}Produktion 1</span>'
        + ("" if mobil else f'<span class="mk-rechts-weg mk-zweit mk-zeile" style="gap:6px;font-size:13px">{ic("sort", "mk-ic-14")}Sortiert nach Gruppe, dann zuletzt geöffnet</span>')
        + '</div>'
    )
    bloecke = []
    for titel, schluessel in gruppen:
        karten = "".join(vkarte(VERBINDUNGEN[s], mobil) for s in schluessel)
        raster = ("display:flex;flex-direction:column;gap:12px" if mobil
                  else "display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;align-items:start")
        bloecke.append(f'<section><h2 class="mk-gruppe-titel">{ic("folder", "mk-ic-14")}{titel}'
                       f'<span class="mk-zaehler" style="margin-left:0">{len(schluessel)}</span></h2>'
                       f'<div style="{raster}">{karten}</div></section>')
    if mobil:
        kopfzeile = mkopf(marke())
        inhalt = (
            '<div class="mk-stapel mk-luecke-12">'
            '<div class="mk-stapel mk-luecke-4"><h1 class="mk-titel-1">Verbindungen</h1>'
            '<p class="mk-zweit">5 Verbindungen in 2 Gruppen</p></div>'
            f'{knopf("Verbindung anlegen", "primaer breit", "plus")}</div>'
            f'{filterzeile}{"".join(bloecke)}{kante_unten()}'
        )
        return kopfzeile + f'<main class="mk-minhalt">{inhalt}</main>'
    zuletzt = [
        ("orders", "Tabelle", chip("prod", "shop", pfeil=False, klein=True), "vor 12 Min."),
        ("customers", "Tabelle", chip("test", "shop", pfeil=False, klein=True), "08:47"),
        ("Umsatz je Monat", "Abfrage", chip("lokal", "basalto", pfeil=False, klein=True), "gestern"),
        ("members", "Tabelle", chip("lokal", "fristo", pfeil=False, klein=True), "gestern"),
        ("notes", "Tabelle", chip("lokal", "notizen.sqlite", pfeil=False, klein=True), "22.10."),
    ]
    zl = "".join(
        f'<li style="padding:10px 0" class="mk-stapel mk-luecke-4"><div class="mk-zeile"><span class="mk-mono mk-fett">{esc(n)}</span>'
        f'<span class="mk-rechts-weg mk-leise mk-klein">{z}</span></div>'
        f'<div class="mk-zeile-wrap mk-klein mk-zweit">{a} in {c}</div></li>' for n, a, c, z in zuletzt)
    seite_inhalt = (
        '<div style="display:flex;gap:24px;padding:24px 32px;height:100%;position:relative">'
        '<div class="mk-voll mk-stapel mk-luecke-24" style="position:relative;overflow:hidden">'
        '<div class="mk-zeile"><div class="mk-stapel mk-luecke-4"><h1 class="mk-titel-1">Verbindungen</h1>'
        '<p class="mk-zweit">5 Verbindungen in 2 Gruppen · Klick öffnet die Verbindung im Arbeitsbildschirm</p></div>'
        f'<span class="mk-rechts-weg">{knopf("Verbindung anlegen", "primaer", "plus")}</span></div>'
        f'{filterzeile}{"".join(bloecke)}</div>'
        '<aside style="width:300px;flex-shrink:0" class="mk-stapel mk-luecke-16">'
        f'<section class="mk-karte"><div class="mk-karte-kopf">{ic("history")}<h2>Zuletzt geöffnet</h2></div>'
        f'<ul class="mk-liste-trenn" style="padding:2px 16px">{zl}</ul></section>'
        f'<section class="mk-karte mk-karte-inhalt mk-stapel mk-luecke-8"><div class="mk-zeile mk-fett">{ic("command")}Schneller ans Ziel</div>'
        f'<p class="mk-zweit" style="font-size:13px;line-height:18px">{taste("⌘K")} öffnet jede Tabelle, Abfrage oder Verbindung direkt — auch von hier aus.</p></section>'
        '</aside></div>'
    )
    return kopf() + f'<main style="position:absolute;top:52px;left:0;right:0;bottom:0">{seite_inhalt}</main>'


# ===================================================================================================
# Bereich 04 — Verbindung anlegen
# ===================================================================================================

TREIBER = [("MariaDB", "ab 10.6"), ("MySQL", "ab 8.0"), ("PostgreSQL", "ab 14"), ("SQLite", "ab 3.35")]


def treiber_kacheln(aktiv: str, spalten: int) -> str:
    k = []
    for name, ab in TREIBER:
        a = name == aktiv
        haken = f'<span class="mk-haken">{ic("check")}</span>' if a else ""
        k.append(f'<div class="mk-kachel{" mk-aktiv" if a else ""}">{haken}<span class="mk-zeile mk-fett">'
                 f'{ic("database")}{name}</span><span class="mk-hilfe">{ab}</span></div>')
    return (f'<div style="display:grid;grid-template-columns:repeat({spalten},minmax(0,1fr));gap:8px">'
            f'{"".join(k)}</div>')


def umgebung_wahl(aktiv: str) -> str:
    """Umgebung als Segment; die gewählte Umgebung trägt ihre eigene Farbe, nicht den Akzent."""
    teile = []
    for umg in ("lokal", "test", "prod"):
        klasse, wort = UMG[umg]
        stil = ""
        if umg == aktiv:
            farbe = {"lokal": "var(--umgebung-lokal)", "test": "var(--umgebung-test)", "prod": "var(--umgebung-produktion)"}[umg]
            grund = "var(--gefahr-hell)" if umg == "prod" else "var(--flaeche)"
            stil = f' style="background:{grund};color:{farbe};box-shadow:inset 0 -2px 0 {farbe};font-weight:600"'
        teile.append(f'<span{stil}>{punkt(umg)}{wort}</span>')
    return f'<span class="mk-segment">{"".join(teile)}</span>'


def b04_verbindung_anlegen(mobil: bool) -> str:
    if mobil:
        formular = (
            '<div class="mk-stapel mk-luecke-4"><h1 class="mk-titel-1">Verbindung anlegen</h1></div>'
            f'<div class="mk-feld"><span class="mk-feld-titel">Treiber</span>{treiber_kacheln("SQLite", 2)}</div>'
            + feld("Name", "notizen.sqlite")
            + feld("Datei", "notizen.sqlite", praefix="~/Datenbanken/", mono=True,
                   hilfe="Nur Dateien in erlaubten Ordnern: <code>~/Datenbanken</code>, <code>~/Herd</code>. "
                         "Andere Pfade, <code>..</code> und Verknüpfungen nach draussen lehnt phpdbadmin ab.")
            + f'<div class="mk-feld"><span class="mk-feld-titel">Umgebung</span>{umgebung_wahl("lokal")}</div>'
            + schalter("Schreibgeschützt", False, "Nur lesende Anweisungen. Für Produktion vorgegeben.")
            + '<div class="mk-stapel mk-luecke-8">'
            + hinweis('<b class="mk-erfolg-text">Verbunden · SQLite 3.46.1</b>'
                      '<span class="mk-zweit">Datei 2,4 MB · 9 Tabellen · Fremdschlüssel eingeschaltet · getestet 10:31</span>', "erfolg")
            + '</div>'
            + f'<div class="mk-stapel mk-luecke-8">{knopf("Speichern", "primaer breit")}{knopf("Verbindung erneut testen", "breit", "refresh")}</div>'
        )
        links = f'<span class="mk-ikonknopf mk-ohne-rand">{ic("arrow-left")}</span>'
        return mkopf(links, '<span class="mk-fett">Neue Verbindung</span>', rechts="") + \
            f'<main class="mk-minhalt">{formular}{kante_unten()}</main>'

    links = (
        '<div class="mk-stapel mk-luecke-24" style="width:720px;flex-shrink:0;position:relative">'
        f'<div class="mk-feld"><span class="mk-feld-titel">Treiber</span>{treiber_kacheln("MySQL", 4)}</div>'
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">'
        + feld("Name", "shop") + auswahl("Gruppe", "Kunde Shop") + '</div>'
        + f'<div class="mk-feld"><span class="mk-feld-titel">Umgebung</span><div class="mk-zeile-wrap">{umgebung_wahl("prod")}'
          '<span class="mk-hilfe" style="max-width:300px">Produktion: roter Streifen oben, «PROD ·» im Seitentitel, Schreibschutz eingeschaltet.</span></div></div>'
        + '<div style="display:grid;grid-template-columns:1fr 120px;gap:16px">'
        + feld("Host", "db.kunde-shop.ch", mono=True, hilfe="Erlaubt in dieser Installation: <code>*.kunde-shop.ch</code>, <code>127.0.0.1</code>")
        + feld("Port", "3306", mono=True) + '</div>'
        + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">'
        + feld("Benutzer", "shop_lesen", mono=True)
        + feld("Passwort", "••••••••••••", nach=f'<span class="mk-leise">{ic("eye")}</span>', fokus=False,
               fehler="Zugang verweigert — Benutzer oder Passwort prüfen")
        + '</div>'
        + kasten("Passwort nicht speichern — in jeder Sitzung fragen", False,
                 "Gespeicherte Passwörter liegen verschlüsselt in der App-Datenbank und erreichen nie den Browser.")
        + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">'
        + feld("Datenbank", "shop", mono=True) + auswahl("SSL", "Erforderlich, Zertifikat prüfen") + '</div>'
        + schalter("<b>Schreibgeschützt</b>", True,
                   "Nur lesende Anweisungen — in mehreren Schichten durchgesetzt. Für Produktion empfohlen, zusammen mit einem eigenen Lesekonto.")
        + kante_unten()
        + '</div>'
    )
    pruef = [
        ("ok", "erfolg", "Host erlaubt", "<code>db.kunde-shop.ch</code> steht auf der Liste dieser Installation"),
        ("ok", "erfolg", "Server erreichbar", "Port 3306 offen, TLS 1.3, Zertifikat gültig bis 14.03.2027"),
        ("nok", "gefahr", "Anmeldung", "Zugang verweigert"),
        ("kreis", "leise", "Rechte des Kontos", "nicht geprüft — erst nach erfolgreicher Anmeldung"),
    ]
    schritte = "".join(
        f'<li class="mk-pruefschritt"><span class="mk-{farbe}{"-text" if farbe != "leise" else ""}">{ic(s)}</span>'
        f'<span class="mk-stapel"><b>{t}</b><span class="mk-zweit">{d}</span></span></li>'
        for s, farbe, t, d in pruef)
    rechts = (
        '<aside class="mk-stapel mk-luecke-16" style="flex:1;min-width:0">'
        '<section class="mk-karte">'
        f'<div class="mk-karte-kopf">{ic("zap")}<h2>Verbindung testen</h2><span class="mk-rechts-weg mk-hilfe">getestet 10:31 · 212 ms</span></div>'
        '<div class="mk-karte-inhalt mk-stapel mk-luecke-12">'
        '<div class="mk-fehlerkarte">'
        f'<div class="mk-fk-titel">{ic("nok")}Zugang verweigert (1045)</div>'
        '<p>Der Server kennt diesen Benutzer nicht oder das Passwort stimmt nicht. Prüfen Sie Benutzer und Passwort; '
        'das Konto muss Zugriff von der Adresse dieses Servers haben.</p>'
        '<div class="mk-servertext"><span class="mk-zeile mk-zweit mk-klein">'
        f'{ic("chevron-down", "mk-ic-14")}Meldung des Servers</span>'
        "<pre>SQLSTATE[HY000] [1045] Access denied for user 'shop_lesen'@'10.0.4.12' (using password: YES)</pre></div>"
        '</div>'
        f'<ul class="mk-liste-trenn">{schritte}</ul>'
        '</div></section>'
        + hinweis('<b>Eigenes Lesekonto empfohlen</b><span class="mk-zweit">Für Produktion: ein Konto nur mit '
                  '<code>SELECT</code>-Recht auf <code>shop</code>. Dann hilft der Schreibschutz doppelt.</span>', "info", "shield")
        + '</aside>'
    )
    kopfzeile = ('<div class="mk-stapel mk-luecke-4">'
                 f'<div class="mk-zeile mk-zweit mk-klein" style="gap:4px"><span>Verbindungen</span>{ic("chevron-right", "mk-ic-14")}<span>Neu</span></div>'
                 '<div class="mk-zeile"><h1 class="mk-titel-1">Verbindung anlegen</h1>'
                 f'<span class="mk-rechts-weg mk-zeile">{knopf("Abbrechen", "leise")}{knopf("Verbindung testen", "", "zap")}{knopf("Speichern", "primaer")}</span></div></div>')
    rumpf = (f'<div style="position:absolute;top:52px;left:0;right:0;bottom:0;padding:24px 32px;display:flex;flex-direction:column;gap:24px">'
             f'{kopfzeile}<div style="display:flex;gap:32px;flex:1;min-height:0">{links}{rechts}</div></div>')
    return kopf() + rumpf


# ===================================================================================================
# Arbeitsbildschirm — gemeinsame Teile
# ===================================================================================================

CSS_ARBEIT = """
.mk-anw-liste{border:1px solid var(--rand);border-radius:6px;background:var(--flaeche);overflow:hidden}
.mk-anw{display:flex;gap:10px;padding:8px 12px;border-bottom:1px solid var(--rand);align-items:flex-start}
.mk-anw:last-child{border-bottom:0}
.mk-anw-nr{width:18px;flex-shrink:0;color:var(--text-leise);font-family:MONO;font-size:12px;line-height:20px;text-align:right}
.mk-anw pre{flex:1;min-width:0;font-family:MONO;font-size:13px;line-height:20px;white-space:pre-wrap;overflow-wrap:anywhere}
.mk-anw-zahl{flex-shrink:0;font-size:12px;line-height:20px;color:var(--text-zweit);white-space:nowrap;text-align:right}
.mk-anw.mk-rahmenzeile{padding-top:6px;padding-bottom:6px}
.mk-anw.mk-rahmenzeile pre{color:var(--text-leise)}
.mk-anw-folge{display:flex;gap:6px;align-items:flex-start;font-size:12px;line-height:16px;color:var(--warnung);margin-top:4px;font-family:SCHRIFT;white-space:normal}
.mk-bestaetigung{border:1px solid var(--gefahr);background:var(--gefahr-hell);border-radius:10px;padding:12px 14px;display:flex;flex-direction:column;gap:10px}
.mk-bestaetigung .mk-b-titel{display:flex;gap:8px;align-items:center;color:var(--gefahr);font-weight:600}
.mk-dfelder{display:flex;flex-direction:column}
.mk-dfeld{display:grid;grid-template-columns:168px minmax(0,1fr);gap:12px;padding:10px 0;border-bottom:1px solid var(--rand)}
.mk-dlabel{display:flex;flex-direction:column;min-width:0}
.mk-dlabel .mk-mono{font-weight:600}
.mk-dwert{display:flex;flex-direction:column;gap:6px;min-width:0}
.mk-mobil .mk-dfeld{grid-template-columns:minmax(0,1fr);gap:6px}
.mk-detail-eng .mk-dfeld{padding:8px 0}
.mk-detail-eng .mk-eingabe{min-height:32px}
.mk-detail-eng .mk-sql{padding:6px 10px}
.mk-schiene{width:52px;flex-shrink:0;background:var(--flaeche);border-right:1px solid var(--rand);display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 0}
.mk-unterreiter{display:flex;gap:8px;flex-wrap:wrap;padding:10px 16px;border-bottom:1px solid var(--rand)}
.mk-unterreiter span{display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 10px;border-radius:999px;border:1px solid var(--rand);font-size:13px;color:var(--text-zweit);white-space:nowrap}
.mk-unterreiter span.mk-aktiv{border-color:var(--akzent);color:var(--akzent);font-weight:600;background:var(--akzent-hell)}
.mk-oberbegriff{display:inline-flex;font-size:12px;line-height:16px;padding:0 6px;border-radius:4px;background:var(--flaeche-stark);color:var(--text-zweit);white-space:nowrap}
.mk-editor{display:flex;font-family:MONO;font-size:13px;line-height:20px;background:var(--grund);position:relative;overflow:visible}
.mk-editor-nr{width:44px;flex-shrink:0;text-align:right;padding:10px 10px 10px 0;color:var(--text-leise);background:var(--flaeche);border-right:1px solid var(--rand);user-select:none}
.mk-editor-text{flex:1;min-width:0;padding:10px 12px;white-space:pre;color:var(--text)}
.mk-editor-zeile{display:block;margin:0 -12px;padding:0 12px}
.mk-editor-zeile.mk-aktiv{background:var(--akzent-hell)}
.mk-ergebnisreiter{display:flex;align-items:flex-end;gap:0;padding:0 8px;border-bottom:1px solid var(--rand);background:var(--flaeche);flex-wrap:wrap}
.mk-schritte{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.mk-schritt{display:inline-flex;align-items:center;gap:8px;color:var(--text-zweit);font-weight:500;white-space:nowrap}
.mk-schritt i{width:22px;height:22px;border-radius:999px;border:1.5px solid var(--rand);display:grid;place-items:center;font-style:normal;font-size:12px;font-weight:600;color:var(--text-zweit);background:var(--grund)}
.mk-schritt.mk-fertig i{background:var(--akzent);border-color:var(--akzent);color:var(--akzent-text)}
.mk-schritt.mk-aktiv{color:var(--text);font-weight:600}
.mk-schritt.mk-aktiv i{border:2px solid var(--akzent);color:var(--akzent)}
.mk-schritt-linie{width:32px;height:1px;background:var(--rand)}
.mk-fortschritt{height:6px;border-radius:999px;background:var(--flaeche-stark);overflow:hidden}
.mk-fortschritt > i{display:block;height:100%;background:var(--akzent);border-radius:999px}
.mk-pal-e{display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:6px;min-width:0}
.mk-pal-e.mk-aktiv{background:var(--akzent-hell);box-shadow:inset 3px 0 0 var(--akzent)}
.mk-pal-e mark{background:transparent;color:var(--akzent);font-weight:700}
.mk-pal-gruppe{font-size:12px;line-height:16px;font-weight:600;color:var(--text-zweit);padding:10px 12px 4px}
.mk-rechte{display:inline-flex;border:1px solid var(--rand);border-radius:6px;overflow:hidden;flex-wrap:wrap}
.mk-rechte span{padding:4px 10px;font-size:13px;border-right:1px solid var(--rand);color:var(--text-zweit);white-space:nowrap}
.mk-rechte span:last-child{border-right:0}
.mk-rechte span.mk-aktiv{background:var(--akzent-hell);color:var(--text);font-weight:600;box-shadow:inset 0 -2px 0 var(--akzent)}
.mk-person{display:flex;gap:10px;align-items:center;padding:10px 12px;border-radius:6px;min-width:0}
.mk-person.mk-aktiv{background:var(--akzent-hell);box-shadow:inset 3px 0 0 var(--akzent)}
.mk-dichte-muster{display:flex;flex-direction:column;border:1px solid var(--rand);border-radius:6px;overflow:hidden;font-size:12px;width:100%}
.mk-dichte-muster span{border-bottom:1px solid var(--rand);padding:0 8px;display:flex;align-items:center;color:var(--text-zweit)}
.mk-dichte-muster span:last-child{border-bottom:0}
.mk-wahlkarte{border:1px solid var(--rand);border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:8px;min-width:0;background:var(--grund)}
.mk-wahlkarte.mk-aktiv{border:2px solid var(--akzent);padding:11px}
"""


def offene_reiter(reiter: list[tuple[str, str, bool, str]]) -> str:
    """Offene Objekte als Reiterleiste (bricht um statt zu scrollen); jeder Reiter trägt seine Umgebung."""
    teile = []
    for text, symbol, aktiv, umg in reiter:
        vor = f'<span class="{UMG[umg][0]}" title="{UMG[umg][1]}">{punkt(umg)}</span>' if umg else ""
        teile.append(f'<span class="mk-reiter{" mk-aktiv" if aktiv else ""}">{vor}{ic(symbol, "mk-ic-14")}'
                     f'<span class="mk-mono">{esc(text)}</span><span class="mk-x">{ic("x", "mk-ic-14")}</span></span>')
    teile.append(f'<span class="mk-reiter mk-leise" style="border-right:0" title="Neuer Reiter">{ic("plus", "mk-ic-14")}</span>')
    return f'<div class="mk-offen">{"".join(teile)}</div>'


def objkopf(name: str, meta: str, aktionen: str) -> str:
    return (f'<div class="mk-objkopf"><h1>{esc(name)}</h1><span class="mk-zweit" style="font-size:13px">{meta}</span>'
            f'<span class="mk-rechts-weg mk-zeile">{aktionen}</span></div>')


def ansichten(aktiv: str, mobil: bool = False) -> str:
    eintraege = [("Daten", "table"), ("Struktur", "columns"), ("Beziehungen", "relation"), ("SQL", "terminal")]
    teile = "".join(f'<span class="mk-ansicht{" mk-aktiv" if n == aktiv else ""}">{"" if mobil else ic(s, "mk-ic-14")}{n}</span>'
                    for n, s in eintraege)
    stil = ' style="padding:0;gap:0"' if mobil else ""
    return f'<div class="mk-ansichten"{stil}>{teile}</div>'


def fchip(spalte: str, op: str, wert: str) -> str:
    return (f'<span class="mk-fchip"><span class="mk-sp">{esc(spalte)}</span><span class="mk-op">{esc(op)}</span>'
            f'<span>{esc(wert)}</span><span class="mk-leise">{ic("x", "mk-ic-14")}</span></span>')


NULL = '<span class="mk-null">NULL</span>'


def fk(schluessel: int | str, name: str) -> str:
    return f'<span class="mk-fk">→</span>{schluessel} <span class="mk-fk-name">{esc(name)}</span>'


def json_kurz(adr: tuple[str, str, str, str]) -> str:
    s, z, o, l = adr
    return esc(f'{{"street": "{s}", "zip": "{z}", "city": "{o}", "country": "{l}"}}')


def json_lang(adr: tuple[str, str, str, str]) -> str:
    s, z, o, l = adr
    return f'{{\n  "street": "{s}",\n  "zip": "{z}",\n  "city": "{o}",\n  "country": "{l}"\n}}'


# id, Kunde, Kundenname, status, total, currency, created_at, Adresse, notes, invoice_pdf
BESTELLUNGEN = [
    (48236, 5528, "Beat Keller", "offen", "89.50", "CHF", "2026-10-28 10:22:41", ("Freie Strasse 40", "4001", "Basel", "CH"), None, "48,2 KB"),
    (48235, 2087, "Carla Rossi", "offen", "1002.00", "CHF", "2026-10-28 10:05:09", ("Via Nassa 21", "6900", "Lugano", "CH"), "Rechnung an Firma", "51,7 KB"),
    (48233, 6650, "Fabian Meier", "offen", "18.50", "CHF", "2026-10-28 09:47:13", ("Multergasse 2", "9000", "St. Gallen", "CH"), "Testbestellung, bitte löschen", None),
    (48231, 7012, "Anna Muster", "offen", "149.00", "CHF", "2026-10-28 08:58:30", ("Bahnhofstrasse 12", "8001", "Zürich", "CH"), None, None),
    (48229, 3391, "Daniel Weber", "offen", "35.00", "CHF", "2026-10-28 08:15:02", ("Marktgasse 7", "3011", "Bern", "CH"), "Bitte nicht vor 17 Uhr liefern", None),
    (48227, 9104, "Eva Brunner", "offen", "212.40", "CHF", "2026-10-28 07:41:55", ("Pilatusstrasse 3", "6003", "Luzern", "CH"), None, None),
    (48224, 1203, "Gabriela Huber", "offen", "349.00", "CHF", "2026-10-27 22:13:08", ("Marktgasse 20", "8400", "Winterthur", "CH"), None, None),
    (48220, 4471, "Hans Steiner", "offen", "74.80", "EUR", "2026-10-27 20:02:44", ("Rosgartenstrasse 9", "78462", "Konstanz", "DE"), None, None),
    (48217, 7788, "Irene Baumann", "offen", "129.00", "CHF", "2026-10-27 18:36:19", ("Bahnhofstrasse 30", "5000", "Aarau", "CH"), None, None),
    (48212, 5012, "Jonas Frei", "offen", "56.20", "CHF", "2026-10-27 16:50:03", ("Poststrasse 11", "7000", "Chur", "CH"), "Geschenk – bitte mit Karte", None),
    (48208, 3140, "Katrin Schmid", "offen", "98.00", "CHF", "2026-10-27 15:11:47", ("Bälliz 18", "3600", "Thun", "CH"), None, None),
    (48203, 8833, "Lukas Gerber", "offen", "410.00", "CHF", "2026-10-27 13:27:30", ("Nidaugasse 25", "2502", "Biel/Bienne", "CH"), None, "44,9 KB"),
    (48199, 2264, "Monika Moser", "offen", "27.90", "CHF", "2026-10-27 11:58:12", ("Baarerstrasse 8", "6300", "Zug", "CH"), None, None),
    (48195, 6015, "Nicola Bianchi", "offen", "189.00", "CHF", "2026-10-27 10:40:55", ("Viale Stazione 6", "6500", "Bellinzona", "CH"), None, None),
    (48190, 9341, "Olivia Widmer", "offen", "63.50", "CHF", "2026-10-27 09:12:26", ("Vordergasse 4", "8200", "Schaffhausen", "CH"), None, None),
    (48186, 1876, "Peter Zürcher", "offen", "145.00", "CHF", "2026-10-27 08:03:39", ("Hauptgasse 50", "4500", "Solothurn", "CH"), None, None),
    (48181, 4590, "Simon Wyss", "offen", "22.00", "CHF", "2026-10-26 23:47:10", ("Rue de Romont 12", "1700", "Fribourg", "CH"), None, None),
]
GEAENDERT = {48236: {"status": "bezahlt"}, 48235: {"total": "1020.00"}}
ZU_LOESCHEN = {48233}
NEUE_ZEILE = (None, 8120, "Rahel Graf", "offen", "64.90", "CHF", None, ("Seestrasse 4", "8800", "Thalwil", "CH"), None, None)

ORDERS_SPALTEN = [
    ("id", "bigint", 84, True), ("customer_id", "bigint → customers", 214, False), ("status", "enum", 110, False),
    ("total", "decimal(10,2)", 104, True), ("currency", "char(3)", 84, False), ("created_at", "timestamp", 178, False),
    ("shipping_address", "json", 300, False), ("notes", "text", 230, False), ("invoice_pdf", "longblob", 150, False),
    ("updated_at", "timestamp", 178, False),
]


def bestellung_werte(z: tuple) -> dict[str, str]:
    bid, kid, kname, status, total, cur, created, adr, notes, pdf = z
    return {
        "id": str(bid) if bid else '<span class="mk-vorgabe">neu</span>',
        "customer_id": fk(kid, kname),
        "status": status,
        "total": total,
        "currency": cur,
        "created_at": created if created else '<span class="mk-vorgabe">Vorgabe: CURRENT_TIMESTAMP</span>',
        "shipping_address": json_kurz(adr),
        "notes": esc(notes) if notes else NULL,
        "invoice_pdf": f'PDF · {pdf}' if pdf else NULL,
        "updated_at": created if created else NULL,
    }


def datenraster(fokus: tuple[int, str] | None = (48235, "total"), zeilen: int = 17, gewaehlt: int | None = None,
                aenderungen: bool = True) -> str:
    breite = 44 + sum(b for _, _, b, _ in ORDERS_SPALTEN)
    cols = '<col style="width:44px">' + "".join(f'<col style="width:{b}px">' for _, _, b, _ in ORDERS_SPALTEN)
    kopfzellen = ['<th class="mk-nr" title="Zeilenzustand">#</th>']
    for i, (name, typ, _, rechts) in enumerate(ORDERS_SPALTEN):
        sort = f'<span class="mk-sort">{ic("arrow-down", "mk-ic-14")}</span>' if name == "created_at" else ""
        schl = f'<span class="mk-akzent-text" title="Primärschlüssel">{ic("pk", "mk-ic-14")}</span> ' if name == "id" else ""
        klasse = ' class="mk-fix-ende"' if i == 0 else (' class="mk-r"' if rechts else "")
        kopfzellen.append(f'<th{klasse}>{sort}{schl}{name}<span class="mk-typ">{esc(typ)}</span></th>')
    reihen = []
    alle = ([("neu", NEUE_ZEILE)] if aenderungen else []) + [("", z) for z in BESTELLUNGEN]
    geaendert = GEAENDERT if aenderungen else {}
    loeschen = ZU_LOESCHEN if aenderungen else set()
    for nr, (art, z) in enumerate(alle[:zeilen + 1], 0 if aenderungen else 1):
        werte = bestellung_werte(z)
        bid = z[0]
        zustand = "mk-neu" if art == "neu" else ("mk-loeschen" if bid in loeschen else "")
        if bid == gewaehlt:
            zustand += " mk-gewaehlt"
        marke_nr = "+" if art == "neu" else ("−" if bid in loeschen else str(nr))
        zellen = [f'<td class="mk-nr">{marke_nr}</td>']
        for i, (name, _, _, rechts) in enumerate(ORDERS_SPALTEN):
            wert = werte[name]
            klassen = []
            if i == 0:
                klassen.append("mk-fix-ende")
            if rechts:
                klassen.append("mk-r")
            if bid in geaendert and name in geaendert[bid]:
                wert = geaendert[bid][name]
                klassen.append("mk-geaendert")
            if fokus and bid == fokus[0] and name == fokus[1]:
                klassen.append("mk-fokus")
            k = f' class="{" ".join(klassen)}"' if klassen else ""
            zellen.append(f"<td{k}>{wert}</td>")
        reihen.append(f'<tr class="{zustand.strip()}">{"".join(zellen)}</tr>')
    return (f'<table class="mk-raster" style="width:{breite}px"><colgroup>{cols}</colgroup>'
            f'<thead><tr>{"".join(kopfzellen)}</tr></thead><tbody>{"".join(reihen)}</tbody></table>')


def filterleiste(zeilen: str = "212 Zeilen", mobil: bool = False) -> str:
    teile = [fchip("status", "=", "offen"), fchip("created_at", "≥", "21.10.2026"),
             f'<span class="mk-fchip mk-neu">{ic("plus", "mk-ic-14")}Filter</span>']
    if not mobil:
        teile.append(f'<span class="mk-umschalter"><span class="mk-schalter"></span>WHERE frei</span>')
        teile.append('<span class="mk-trenner" style="height:20px;align-self:center"></span>')
        teile.append(f'<span class="mk-eingabe" style="min-height:28px;width:220px;font-size:13px">{ic("search", "mk-ic-14")}'
                     '<span class="mk-platzhalter">In Textspalten suchen</span></span>')
    teile.append(f'<span class="{"" if mobil else "mk-rechts-weg "}mk-zweit mk-zahl mk-nowrap" style="font-size:13px"><b class="mk-fett" style="color:var(--text)">{zeilen}</b> · genau gezählt</span>')
    return f'<div class="mk-filter">{"".join(teile)}</div>'


def aenderungsleiste() -> str:
    return (
        '<div class="mk-aenderungen">'
        '<span class="mk-anzahl">4 Änderungen noch nicht angewendet</span>'
        '<span class="mk-legende"><i class="mk-lmarke mk-lm-geaendert"></i>2 Zellen geändert</span>'
        '<span class="mk-legende"><i class="mk-lmarke mk-lm-neu">+</i>1 Zeile neu</span>'
        '<span class="mk-legende"><i class="mk-lmarke mk-lm-loeschen">−</i>1 Zeile zum Löschen</span>'
        f'<span class="mk-rechts-weg mk-zeile">{knopf("SQL ansehen", "klein", "code")}{knopf("Verwerfen", "klein", "undo")}'
        f'{knopf("Anwenden …", "primaer klein")}</span></div>'
    )


def statusleiste() -> str:
    return (
        '<div class="mk-statusleiste mk-zahl">'
        '<span>Zeilen 1–50 von 212</span>'
        f'<span class="mk-zeile" style="gap:4px">50 je Seite{ic("chevron-down", "mk-ic-14")}</span>'
        f'<span class="mk-zeile" style="gap:2px">{ic("chevron-left", "mk-ic-14")}Zurück</span>'
        f'<span class="mk-zeile" style="gap:2px;color:var(--text)">Weiter{ic("chevron-right", "mk-ic-14")}</span>'
        '<span class="mk-trenner" style="height:16px;align-self:center"></span>'
        '<span>10 von 10 Spalten</span>'
        f'<span class="mk-rechts-weg mk-zeile" style="gap:6px">{ic("undo", "mk-ic-14")}Zuletzt angewendet 09:58 · 1 Zeile · <a>Rückgängig machen</a></span>'
        '</div>'
    )


def arbeit(haupt: str, chip_html: str, extra: str = "", prod: bool = False, navi: str | None = None,
           ueber: str = "") -> str:
    streifen = '<div class="mk-streifen-prod" title="Produktion"></div>' if prod else ""
    return (streifen + kopf(chip_html, extra)
            + f'<div class="mk-rumpf">{navigator() if navi is None else navi}<main class="mk-haupt">{haupt}</main>{ueber}</div>')


def entsperrt() -> str:
    return (f'<span class="mk-entsperrt" title="Schreibschutz auf Zeit aufgehoben">{ic("lock-open", "mk-ic-14")}'
            'Schreiben erlaubt bis 10:45 · <u>wieder sperren</u></span>')


def prod_chip_offen() -> str:
    return chip("prod", "shop", "MySQL 8.4", offen=True)


def prod_chip_zu() -> str:
    return chip("prod", "shop", "MySQL 8.4", schloss=True)


def reiter_shop(aktiv: str = "orders") -> str:
    return offene_reiter([("orders", "table", aktiv == "orders", "prod"), ("customers", "table", aktiv == "customers", "prod"),
                          ("Abfrage 1", "terminal", aktiv == "Abfrage 1", "test")])


def daten_haupt(fokus: tuple[int, str] | None = (48235, "total"), gewaehlt: int | None = None, aenderungen: bool = True) -> str:
    einfuegen = (knopf("Zeile einfügen", "klein", "plus") if aenderungen else
                 f'<span class="mk-knopf mk-klein mk-aus" title="Verbindung ist schreibgeschützt">{ic("lock", "mk-ic-14")}Zeile einfügen</span>')
    aktionen = einfuegen + knopf("Exportieren", "klein", "download") + knopf("", "klein", "more")
    return (
        reiter_shop()
        + objkopf("orders", "Tabelle · InnoDB · ≈ 48'210 Zeilen · utf8mb4_unicode_ci", aktionen)
        + ansichten("Daten")
        + filterleiste()
        + f'<div class="mk-raster-huelle">{datenraster(fokus, gewaehlt=gewaehlt, aenderungen=aenderungen)}{kante_rechts()}{kante_unten()}</div>'
        + (aenderungsleiste() if aenderungen else "")
        + statusleiste()
    )


# ===================================================================================================
# Bereich 05 — Arbeitsbildschirm Daten
# ===================================================================================================

def mkarte_bestellung(z: tuple, art: str = "") -> str:
    w = bestellung_werte(z)
    bid = z[0]
    zust = ""
    schild = ""
    if art == "neu":
        zust, schild = " mk-neu", f'<span class="mk-schild mk-s-erfolg">{ic("plus", "mk-ic-14")}neu</span>'
    elif bid in ZU_LOESCHEN:
        zust, schild = " mk-loeschen", f'<span class="mk-schild mk-s-gefahr">{ic("minus", "mk-ic-14")}wird gelöscht</span>'
    elif bid in GEAENDERT:
        schild = f'<span class="mk-schild mk-s-akzent">{ic("pencil", "mk-ic-14")}geändert</span>'
    def wert(name: str) -> str:
        if bid in GEAENDERT and name in GEAENDERT[bid]:
            return f'<span class="mk-wert mk-geaendert">{GEAENDERT[bid][name]}</span>'
        return f'<span class="mk-wert">{w[name]}</span>'
    titel = f'<span class="mk-mono mk-fett">{"id " + str(bid) if bid else "neue Zeile"}</span>'
    return (f'<article class="mk-mkarte{zust}"><div class="mk-zeile">{titel}{schild}'
            f'<span class="mk-rechts-weg mk-zeile mk-leise mk-klein" style="gap:2px">alle 10 Felder{ic("chevron-right", "mk-ic-14")}</span></div>'
            f'<div class="mk-mzeile"><span class="mk-lab">customer_id</span>{wert("customer_id")}</div>'
            f'<div class="mk-mzeile"><span class="mk-lab">status</span>{wert("status")}<span class="mk-lab">total</span>{wert("total")}'
            f'</div></article>')


def b05_mobil(navigator_offen: bool = False) -> str:
    links = f'<span class="mk-ikonknopf">{ic("panel")}</span>'
    kopfzeile = '<div class="mk-streifen-prod"></div>' + mkopf(links, chip("prod", "shop", offen=True, kurz=True))
    karten = (mkarte_bestellung(NEUE_ZEILE, "neu") + "".join(mkarte_bestellung(z) for z in BESTELLUNGEN[:6]))
    inhalt = (
        f'<div class="mk-zeile-wrap" style="gap:6px">{entsperrt()}</div>'
        '<div class="mk-stapel mk-luecke-4"><div class="mk-zeile-wrap"><h1 class="mk-mono" style="font-size:18px;line-height:24px">orders</h1>'
        f'{treiber("MySQL 8.4")}<span class="mk-zweit mk-klein">≈ 48\'210 Zeilen</span></div></div>'
        + ansichten("Daten", mobil=True)
        + '<div class="mk-zeile-wrap" style="gap:6px">' + fchip("status", "=", "offen") + fchip("created_at", "≥", "21.10.2026")
        + f'<span class="mk-fchip mk-neu">{ic("plus", "mk-ic-14")}Filter</span></div>'
        + '<div class="mk-zeile mk-zweit mk-klein"><b style="color:var(--text)">212 Zeilen</b> · neueste zuerst'
          f'<span class="mk-rechts-weg mk-zeile" style="gap:4px">{ic("sort", "mk-ic-14")}created_at</span></div>'
        + f'<div class="mk-stapel mk-luecke-8">{karten}</div>'
    )
    leiste = (
        '<div class="mk-unten-fest mk-aenderungen" style="flex-direction:column;align-items:stretch;gap:8px;padding:10px 12px 14px">'
        '<div class="mk-zeile-wrap" style="gap:4px 10px"><span class="mk-anzahl">4 Änderungen</span>'
        '<span class="mk-legende">2 geändert · 1 neu · 1 löschen</span></div>'
        f'<div class="mk-zeile">{knopf("SQL ansehen", "klein", "code")}{knopf("Verwerfen", "klein")}'
        f'<span class="mk-rechts-weg">{knopf("Anwenden …", "primaer klein")}</span></div></div>'
    )
    teile = kopfzeile + f'<main class="mk-minhalt" style="bottom:98px;gap:12px;padding:12px">{inhalt}{kante_unten()}</main>' + leiste
    if navigator_offen:
        baum = []
        baum.append(f'<div class="mk-baum-e mk-gruppe">{ic("chevron-down", "mk-ic-14")}{ic("database")}shop<span class="mk-zaehler">MySQL 8.4</span></div>')
        baum.append(f'<div class="mk-baum-e mk-gruppe" style="padding-left:24px">{ic("chevron-down", "mk-ic-14")}Tabellen<span class="mk-zaehler">14</span></div>')
        for name, zahl in SHOP_TABELLEN:
            k = " mk-aktiv" if name == "orders" else ""
            baum.append(f'<div class="mk-baum-e{k}" style="padding-left:44px;height:36px">{ic("table")}<span class="mk-abschneiden">{name}</span><span class="mk-zaehler">{zahl}</span></div>')
        schublade = (
            '<div class="mk-schleier" style="top:54px"></div>'
            '<nav class="mk-schublade" style="left:0;right:auto;top:54px;width:300px;border-radius:0 10px 10px 0;border-left:0;border-right:1px solid var(--rand);background:var(--flaeche)">'
            f'<div class="mk-zeile" style="padding:12px 12px 4px">{chip("prod", "shop", "MySQL")}'
            f'<span class="mk-rechts-weg mk-ikonknopf mk-ohne-rand">{ic("x")}</span></div>'
            f'<div class="mk-navi-suche">{ic("search", "mk-ic-14")}<span class="mk-voll">Objekte filtern</span></div>'
            f'<div class="mk-baum" style="padding-bottom:8px">{"".join(baum)}{kante_unten(True)}</div>'
            '<div class="mk-navi-unten">'
            f'<div class="mk-baum-e" style="height:36px">{ic("bookmark")}Gespeichert<span class="mk-zaehler">2</span></div>'
            f'<div class="mk-baum-e" style="height:36px">{ic("history")}Verlauf<span class="mk-zaehler">48</span></div>'
            '</div></nav>'
        )
        teile += schublade
    return teile


def b05_desktop() -> str:
    return arbeit(daten_haupt(), prod_chip_offen(), entsperrt(), prod=True)


# ===================================================================================================
# Bereich 06 — SQL-Vorschau vor dem Anwenden
# ===================================================================================================

SQL_VORSCHAU = [
    ("INSERT INTO `orders` (`customer_id`, `status`, `total`, `currency`, `shipping_address`)\n"
     "VALUES (8120, 'offen', 64.90, 'CHF', '{\"street\": \"Seestrasse 4\", \"zip\": \"8800\", \"city\": \"Thalwil\", \"country\": \"CH\"}');",
     "1 Zeile neu", ""),
    ("UPDATE `orders` SET `status` = 'bezahlt' WHERE `id` = 48236;", "1 Zeile", ""),
    ("UPDATE `orders` SET `total` = 1020.00 WHERE `id` = 48235;", "1 Zeile", ""),
    ("DELETE FROM `orders` WHERE `id` = 48233;", "1 Zeile",
     "löscht zusätzlich 2 Zeilen in <code>order_items</code> (Fremdschlüssel mit ON DELETE CASCADE)"),
]


def anweisungsliste(anweisungen: list[tuple[str, str, str]], rahmen: tuple[str, str] | None = ("START TRANSACTION;", "COMMIT;"),
                    nummern: bool = True) -> str:
    zeilen = []
    if rahmen:
        zeilen.append(f'<div class="mk-anw mk-rahmenzeile"><span class="mk-anw-nr"></span><pre>{esc(rahmen[0])}</pre></div>')
    for i, (sql, zahl, folge) in enumerate(anweisungen, 1):
        f = (f'<div class="mk-anw-folge">{ic("alert", "mk-ic-14")}<span>{folge}</span></div>' if folge else "")
        zeilen.append(f'<div class="mk-anw"><span class="mk-anw-nr">{i if nummern else ""}</span>'
                      f'<div class="mk-voll"><pre>{sql_hervorheben(sql)}</pre>{f}</div>'
                      f'<span class="mk-anw-zahl">{zahl}</span></div>')
    if rahmen:
        zeilen.append(f'<div class="mk-anw mk-rahmenzeile"><span class="mk-anw-nr"></span><pre>{esc(rahmen[1])}</pre></div>')
    return f'<div class="mk-anw-liste">{"".join(zeilen)}</div>'


def bestaetigung(eingabe: str, fertig: bool, was: str = "4 Änderungen an <code>orders</code>",
                 ziel: str = "shop", grund: str = "produktion") -> str:
    if grund == "produktion":
        titel = "Änderung auf Produktion"
        text = (f'{was} auf <b class="mk-nowrap">produktion · shop</b>. Geben Sie zur Bestätigung den Namen '
                f'der Verbindung ein: <b class="mk-mono">{ziel}</b>')
    else:
        titel = "Diese Änderung löscht Daten"
        text = f'{was}. Geben Sie zur Bestätigung den Namen der Tabelle ein: <b class="mk-mono">{ziel}</b>'
    hilfe = ("Name stimmt — der Knopf ist jetzt aktiv." if fertig else "Der Knopf wird aktiv, sobald der Name stimmt.")
    nach = f'<span class="mk-erfolg-text">{ic("check")}</span>' if fertig else ""
    return (f'<div class="mk-bestaetigung"><div class="mk-b-titel">{ic("alert")}{titel}</div><p>{text}</p>'
            + feld("", eingabe, fokus=not fertig, mono=True, nach=nach, hilfe=hilfe) + '</div>')


def bestaetigung_fuss(eingabe: str, text: str, knopf_text: str) -> str:
    """Mobil: Namenseingabe direkt über dem Knopf, damit beide zusammen sichtbar bleiben."""
    nach = f'<span class="mk-erfolg-text">{ic("check")}</span>'
    return (
        '<div class="mk-stapel" style="border-top:1px solid var(--gefahr);background:var(--gefahr-hell);padding:10px 16px 12px;gap:8px">'
        f'<div class="mk-b-titel mk-zeile mk-gefahr-text mk-fett" style="gap:6px;font-size:13px">{ic("alert", "mk-ic-14")}{text}</div>'
        + feld("", eingabe, mono=True, nach=nach)
        + f'<div class="mk-zeile" style="flex-wrap:nowrap">{knopf("Abbrechen", "")}<span class="mk-voll"></span>{knopf(knopf_text, "gefahr")}</div>'
        '</div>'
    )


def sql_vorschau_inhalt(fertig: bool, mobil: bool = False) -> str:
    return (
        hinweis('<b>MySQL 8.4 · InnoDB: in einer Transaktion</b>'
                '<span class="mk-zweit">Schlägt eine Anweisung fehl, wird keine geschrieben. Die Vorher-Werte werden gesichert; '
                'Sie können die Änderung danach rückgängig machen.</span>', "info", "layers")
        + anweisungsliste(SQL_VORSCHAU)
        + '<p class="mk-hilfe">Gezählt vor dem Anwenden. Werte stehen hier im Klartext, im Protokoll nur als Platzhalter.</p>'
        + ("" if mobil else bestaetigung("shop" if fertig else "sho", fertig))
    )


def b06_desktop() -> str:
    fuss = (f'{knopf("Kopieren", "klein", "copy")}{knopf("Im Editor öffnen", "klein", "terminal")}'
            f'<span class="mk-rechts-weg mk-zeile">{knopf("Abbrechen", "leise")}{knopf("Auf Produktion anwenden", "aus")}</span>')
    schublade = (
        '<div class="mk-schleier"></div>'
        '<aside class="mk-schublade" style="width:640px" aria-label="SQL-Vorschau">'
        f'<div class="mk-sch-kopf"><h2 class="mk-titel-2">SQL-Vorschau</h2>{chip("prod", "shop", pfeil=False, klein=True)}'
        f'<span class="mk-zweit mk-klein">4 Anweisungen · <code>orders</code></span>'
        f'<span class="mk-rechts-weg mk-ikonknopf mk-ohne-rand">{ic("x")}</span></div>'
        f'<div class="mk-sch-inhalt">{sql_vorschau_inhalt(False)}</div>'
        f'<div class="mk-sch-fuss">{fuss}</div></aside>'
    )
    return arbeit(daten_haupt(fokus=None), prod_chip_offen(), entsperrt(), prod=True, ueber=schublade)


def b06_mobil() -> str:
    links = f'<span class="mk-ikonknopf">{ic("panel")}</span>'
    hintergrund = ('<div class="mk-streifen-prod"></div>' + mkopf(links, chip("prod", "shop", offen=True, kurz=True))
                   + '<main class="mk-minhalt">' + "".join(mkarte_bestellung(z) for z in BESTELLUNGEN[:4]) + '</main>')
    blatt = (
        '<div class="mk-schleier" style="top:54px"></div>'
        '<aside class="mk-blatt" style="top:66px" aria-label="SQL-Vorschau"><div class="mk-griff"></div>'
        f'<div class="mk-sch-kopf" style="padding:10px 16px"><h2 class="mk-titel-2">SQL-Vorschau</h2>'
        f'<span class="mk-zweit mk-klein">4 Anweisungen</span><span class="mk-rechts-weg mk-ikonknopf mk-ohne-rand">{ic("x")}</span></div>'
        f'<div class="mk-sch-inhalt" style="gap:12px">{sql_vorschau_inhalt(True, mobil=True)}{kante_unten()}</div>'
        + bestaetigung_fuss("shop", "Produktion: Namen der Verbindung «shop» eingeben", "Auf Produktion anwenden")
        + '</aside>'
    )
    return hintergrund + blatt


# ===================================================================================================
# Bereich 07 — Zeilen-Detail-Schublade
# ===================================================================================================

def dfeld(name: str, typ: str, wert: str, extra: str = "") -> str:
    return (f'<div class="mk-dfeld"><div class="mk-dlabel"><span class="mk-mono">{name}</span>'
            f'<span class="mk-leise mk-klein mk-mono">{esc(typ)}</span>{extra}</div><div class="mk-dwert">{wert}</div></div>')


def detail_felder(mobil: bool) -> str:
    adr = ("Freie Strasse 40", "4001", "Basel", "CH")
    felder = [
        dfeld("id", "bigint unsigned", feld("", "48236", gesperrt=True, nach=f'<span class="mk-leise" title="Primärschlüssel">{ic("pk")}</span>')),
        dfeld("customer_id", "bigint unsigned", feld("", "5528", mono=True)
              + f'<a class="mk-zeile" style="gap:6px">{ic("arrow-right", "mk-ic-14")}customers · Beat Keller · beat.keller@example.ch</a>'),
        dfeld("status", "enum", feld("", "bezahlt", nach=ic("chevron-down")).replace('class="mk-eingabe', 'style="background:var(--zelle-geaendert)" class="mk-eingabe')
              + f'<span class="mk-hilfe mk-zeile" style="gap:6px">{ic("pencil", "mk-ic-14")}geändert, vorher «offen» · <a>zurücksetzen</a></span>',
              '<span class="mk-schild mk-s-akzent" style="align-self:flex-start;margin-top:4px">geändert</span>'),
        dfeld("total", "decimal(10,2)", feld("", "89.50", mono=True)),
        dfeld("currency", "char(3)", feld("", "CHF", mono=True)),
        dfeld("shipping_address", "json",
              f'<pre class="mk-sql">{esc(json_lang(adr))}</pre>'
              f'<span class="mk-zeile-wrap mk-klein"><span class="mk-erfolg-text mk-zeile" style="gap:4px">{ic("ok", "mk-ic-14")}gültiges JSON</span>'
              f'<span class="mk-leise">·</span><a>formatieren</a><span class="mk-leise">·</span><a>als Text bearbeiten</a></span>'),
        dfeld("notes", "text · NULL erlaubt",
              feld("", NULL, roh=True, nach=f'<span class="mk-check mk-klein" style="gap:6px">{"<span class=\"mk-kasten mk-an\">" + ic("check") + "</span>"}NULL</span>')
              + '<span class="mk-hilfe">NULL ist etwas anderes als ein leerer Text. Häkchen weg, um Text einzugeben.</span>'),
        dfeld("invoice_pdf", "longblob · NULL erlaubt",
              f'<div class="mk-eingabe" style="min-height:44px">{ic("file")}<span class="mk-voll mk-stapel"><span>PDF-Dokument · 48,2 KB</span>'
              '<span class="mk-hilfe">49\'371 Bytes · beginnt mit %PDF-1.7</span></span></div>'
              f'<span class="mk-zeile-wrap">{knopf("Herunterladen", "klein", "download")}{knopf("Ersetzen …", "klein", "upload")}</span>'),
        dfeld("created_at", "timestamp", feld("", "2026-10-28 10:22:41", mono=True)
              + '<span class="mk-hilfe">Angezeigt in Europe/Zurich (UTC+01:00) · gespeichert als 09:22:41 UTC</span>'),
        dfeld("updated_at", "timestamp · NULL erlaubt", feld("", "2026-10-28 10:22:41", mono=True)),
    ]
    verweise = (
        '<section class="mk-stapel mk-luecke-8"><h3 class="mk-titel-3">Verweise</h3>'
        '<div class="mk-karte mk-liste-trenn">'
        f'<div class="mk-zeile" style="padding:10px 12px">{ic("arrow-right")}<span class="mk-voll"><span class="mk-leise mk-klein">verweist auf</span><br>'
        '<span class="mk-mono">customers</span> · 5528 Beat Keller</span><a>öffnen</a></div>'
        f'<div class="mk-zeile" style="padding:10px 12px">{ic("arrow-left")}<span class="mk-voll"><span class="mk-leise mk-klein">wird verwiesen von</span><br>'
        '<span class="mk-mono">order_items</span>.order_id · 3 Zeilen</span><a>zeigen</a></div>'
        f'<div class="mk-zeile" style="padding:10px 12px">{ic("arrow-left")}<span class="mk-voll"><span class="mk-leise mk-klein">wird verwiesen von</span><br>'
        '<span class="mk-mono">payments</span>.order_id · 1 Zeile</span><a>zeigen</a></div>'
        f'<div class="mk-zeile mk-leise" style="padding:10px 12px">{ic("arrow-left")}<span class="mk-voll"><span class="mk-klein">wird verwiesen von</span><br>'
        '<span class="mk-mono">shipments</span>.order_id · keine Zeilen</span></div>'
        '</div></section>'
    )
    if mobil:
        return f'<div class="mk-dfelder">{"".join(felder)}</div>{verweise}'
    kopieren = ('<section class="mk-stapel mk-luecke-8"><h3 class="mk-titel-3">Zeile kopieren als</h3>'
                f'<div class="mk-zeile-wrap">{knopf("JSON", "klein", "copy")}{knopf("INSERT", "klein", "copy")}{knopf("TSV", "klein", "copy")}</div></section>')
    return (f'<div style="display:flex;gap:20px;min-height:0;flex:1">'
            f'<div class="mk-dfelder mk-voll" style="position:relative;overflow:hidden">{"".join(felder)}{kante_unten()}</div>'
            f'<div class="mk-stapel mk-luecke-24" style="width:290px;flex-shrink:0">{verweise}{kopieren}</div></div>')


def b07_desktop() -> str:
    schublade = (
        '<div class="mk-schleier" style="left:256px;background:transparent"></div>'
        '<aside class="mk-schublade" style="width:900px" aria-label="Zeile 48236">'
        f'<div class="mk-sch-kopf"><h2 class="mk-titel-2">Zeile</h2><span class="mk-mono mk-zweit">orders · id 48236</span>'
        f'<span class="mk-schild mk-s-akzent">1 Änderung</span>'
        f'<span class="mk-rechts-weg mk-zeile" style="gap:4px"><span class="mk-ikonknopf" title="Vorherige Zeile">{ic("chevron-up")}</span>'
        f'<span class="mk-ikonknopf" title="Nächste Zeile">{ic("chevron-down")}</span>'
        f'<span class="mk-ikonknopf mk-ohne-rand">{ic("x")}</span></span></div>'
        f'<div class="mk-sch-inhalt mk-detail-eng" style="gap:16px;padding-top:4px">{detail_felder(False)}</div>'
        f'<div class="mk-sch-fuss">{knopf("Duplizieren", "klein", "copy")}{knopf("Zum Löschen markieren", "klein gefahr-rand", "trash")}'
        f'<span class="mk-rechts-weg mk-hilfe">Änderungen landen in der Änderungsleiste und werden erst mit «Anwenden» geschrieben.</span></div></aside>'
    )
    return arbeit(daten_haupt(fokus=(48236, "status"), gewaehlt=48236), prod_chip_offen(), entsperrt(), prod=True, ueber=schublade)


def b07_mobil() -> str:
    kopfzeile = ('<div class="mk-streifen-prod"></div>'
                 + mkopf(f'<span class="mk-ikonknopf mk-ohne-rand">{ic("arrow-left")}</span>',
                         '<span class="mk-stapel"><span class="mk-fett">Zeile 48236</span><span class="mk-leise mk-klein mk-mono">orders · produktion · shop</span></span>',
                         rechts=f'<span class="mk-ikonknopf">{ic("chevron-up")}</span><span class="mk-ikonknopf">{ic("chevron-down")}</span>'))
    inhalt = (f'<div class="mk-zeile-wrap">{entsperrt()}<span class="mk-schild mk-s-akzent">1 Änderung</span></div>'
              f'{detail_felder(True)}{kante_unten()}')
    return kopfzeile + f'<main class="mk-minhalt" style="gap:12px">{inhalt}</main>'


# ===================================================================================================
# Bereich 08 — Struktur einer Tabelle
# ===================================================================================================

STRUKTUR_SPALTEN = [
    ("id", "bigint unsigned", "Zahl", False, "auto_increment", "", "", "pk"),
    ("customer_id", "bigint unsigned", "Zahl", False, "", "", "", "fk"),
    ("status", "enum('offen','bezahlt','versendet','storniert')", "Auswahl", False, "'offen'", "utf8mb4_unicode_ci", "", ""),
    ("total", "decimal(10,2)", "Zahl", False, "", "", "Betrag inkl. MWST", ""),
    ("currency", "char(3)", "Text", False, "'CHF'", "utf8mb4_unicode_ci", "", ""),
    ("shipping_address", "json", "JSON", True, "NULL", "", "", ""),
    ("notes", "text", "Text", True, "NULL", "utf8mb4_unicode_ci", "Interne Notiz", ""),
    ("invoice_pdf", "longblob", "Binär", True, "NULL", "", "", ""),
    ("created_at", "timestamp", "Datum und Zeit", False, "CURRENT_TIMESTAMP", "", "", ""),
    ("updated_at", "timestamp", "Datum und Zeit", True, "NULL", "", "", ""),
]

DDL_ORDERS = """CREATE TABLE `orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `status` enum('offen','bezahlt','versendet','storniert') NOT NULL DEFAULT 'offen',
  `total` decimal(10,2) NOT NULL COMMENT 'Betrag inkl. MWST',
  `currency` char(3) NOT NULL DEFAULT 'CHF',
  `shipping_address` json DEFAULT NULL,
  `notes` text COMMENT 'Interne Notiz',
  `invoice_pdf` longblob,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `orders_customer_id_foreign` (`customer_id`),
  KEY `orders_status_created_at_index` (`status`,`created_at`),
  CONSTRAINT `orders_customer_id_foreign` FOREIGN KEY (`customer_id`)
    REFERENCES `customers` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `orders_total_check` CHECK ((`total` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;"""


def spalten_tabelle() -> str:
    zeilen = []
    for i, (name, typ, ober, null, vorgabe, koll, kommentar, merk) in enumerate(STRUKTUR_SPALTEN, 1):
        merkmal = ""
        if merk == "pk":
            merkmal = f'<span class="mk-akzent-text" title="Primärschlüssel">{ic("pk", "mk-ic-14")}</span>'
        elif merk == "fk":
            merkmal = '<span class="mk-fk mk-nowrap" title="Fremdschlüssel">→ customers.id</span>'
        v = f'<span class="mk-null">NULL</span>' if vorgabe == "NULL" else (f'<code>{esc(vorgabe)}</code>' if vorgabe else '<span class="mk-leise">—</span>')
        zeilen.append(
            f'<tr><td class="mk-leise mk-zahl">{i}</td><td><span class="mk-zeile" style="gap:6px"><code class="mk-fett">{name}</code>{merkmal}</span></td>'
            f'<td style="white-space:normal"><code style="font-size:12px">{esc(typ).replace(",", ",<wbr>")}</code></td><td><span class="mk-oberbegriff">{ober}</span></td>'
            f'<td>{"ja" if null else "nein"}</td><td>{v}</td><td class="mk-zweit" style="font-size:12px">{koll or "—"}</td>'
            f'<td class="mk-zweit">{esc(kommentar) or "—"}</td></tr>')
    return ('<table class="mk-tabelle mk-eng mk-einzeilig"><thead><tr><th style="width:36px">#</th><th>Name</th><th>Typ (MySQL)</th><th>Art</th><th>NULL</th>'
            f'<th>Vorgabe</th><th>Kollation</th><th>Kommentar</th></tr></thead><tbody>{"".join(zeilen)}</tbody></table>')


def struktur_karte(titel: str, zahl: str, inhalt: str, symbol: str, stil: str = "") -> str:
    return (f'<section class="mk-karte" style="overflow:hidden;position:relative;{stil}"><div class="mk-karte-kopf" style="padding:10px 14px">{ic(symbol)}<h3>{titel}</h3>'
            f'<span class="mk-zaehler" style="margin-left:0">{zahl}</span></div>{inhalt}</section>')


def eintrag(titel: str, unten: str, rechts: str = "") -> str:
    return (f'<div class="mk-stapel" style="padding:8px 14px;gap:2px"><div class="mk-zeile"><code class="mk-fett mk-abschneiden">{titel}</code>'
            f'<span class="mk-rechts-weg">{rechts}</span></div><div class="mk-zweit" style="font-size:13px;line-height:18px">{unten}</div></div>')


def b08_desktop() -> str:
    indizes = ('<div class="mk-liste-trenn">'
               + eintrag("PRIMARY", "<code>id</code>", '<span class="mk-schild">Primärschlüssel</span>')
               + eintrag("orders_customer_id_foreign", "<code>customer_id</code>", '<span class="mk-schild">Index</span>')
               + eintrag("orders_status_created_at_index", "<code>status, created_at</code>", '<span class="mk-schild">Index</span>')
               + '</div>')
    fks = ('<div class="mk-liste-trenn">'
           + eintrag("orders_customer_id_foreign",
                     '<code>customer_id</code> <span class="mk-fk">→</span><a><code>customers.id</code></a><br>'
                     'beim Ändern <code>CASCADE</code> · beim Löschen <code>RESTRICT</code>')
           + '<div class="mk-zweit" style="padding:8px 14px;font-size:13px;line-height:18px;background:var(--flaeche)">'
             '<b style="color:var(--text)">Verwiesen von</b> <a><code>order_items</code></a> (CASCADE) · '
             '<a><code>payments</code></a> · <a><code>shipments</code></a></div>'
           + '</div>')
    checks = ('<div class="mk-liste-trenn">'
              + eintrag("orders_total_check", "<code>CHECK ((`total` &gt;= 0))</code>", '<span class="mk-schild">Check</span>')
              + eintrag("orders_before_update", "<code>SET NEW.updated_at = NOW();</code>", '<span class="mk-schild">BEFORE UPDATE</span>')
              + '</div>')
    ddl = (f'<div style="padding:0 14px 10px;position:relative">{sql_block(DDL_ORDERS, "mk-ddl")}</div>'
           f'<div class="mk-kante-unten"><span class="mk-kante-zeichen">{ic("chevron-down", "mk-ic-14")}</span></div>')
    ddl_kopf_knoepfe = f'<span class="mk-zeile" style="padding:0 14px 8px">{knopf("Kopieren", "klein", "copy")}{knopf("Im Editor öffnen", "klein", "terminal")}</span>'
    unternavi = ('<div class="mk-unterreiter"><span class="mk-aktiv">Spalten 10</span><span>Indizes 3</span><span>Fremdschlüssel 1</span>'
                 '<span>Check-Constraints 1</span><span>Trigger 1</span><span>DDL</span>'
                 f'<span style="margin-left:auto;border:0;padding:0" class="mk-zweit">{ic("info", "mk-ic-14")}Typ im Original, daneben die Art — gleich für alle vier Datenbanken</span></div>')
    aktionen = (f'<span class="mk-knopf mk-klein mk-aus" title="Verbindung ist schreibgeschützt">{ic("lock", "mk-ic-14")}Tabelle ändern</span>'
                + knopf("Exportieren", "klein", "download") + knopf("", "klein", "more"))
    reihe = (f'<div style="display:grid;grid-template-columns:1fr 1.15fr 1fr 1.3fr;gap:16px;flex:1;min-height:0">'
             f'{struktur_karte("Indizes", "3", indizes, "list")}'
             f'{struktur_karte("Fremdschlüssel", "1", fks, "link")}'
             f'{struktur_karte("Checks und Trigger", "2", checks, "shield")}'
             f'{struktur_karte("DDL", "", ddl_kopf_knoepfe + ddl, "code")}</div>')
    haupt = (reiter_shop()
             + objkopf("orders", "Tabelle · InnoDB · ≈ 48'210 Zeilen · 18,4 MB Daten · 6,1 MB Indizes · utf8mb4_unicode_ci", aktionen)
             + ansichten("Struktur") + unternavi
             + f'<div class="mk-stapel mk-luecke-16" style="padding:16px;flex:1;min-height:0">'
               f'{struktur_karte("Spalten", "10", spalten_tabelle(), "columns", "flex-shrink:0")}{reihe}</div>')
    return arbeit(haupt, prod_chip_zu(), prod=True)


def b08_mobil() -> str:
    links = f'<span class="mk-ikonknopf">{ic("panel")}</span>'
    kopfzeile = '<div class="mk-streifen-prod"></div>' + mkopf(links, chip("prod", "shop", schloss=True, kurz=True))
    zeilen = []
    for name, typ, ober, null, vorgabe, _, kommentar, merk in STRUKTUR_SPALTEN:
        m = f' <span class="mk-akzent-text">{ic("pk", "mk-ic-14")}</span>' if merk == "pk" else (' <span class="mk-fk">→ customers.id</span>' if merk == "fk" else "")
        zusatz = []
        zusatz.append("NULL erlaubt" if null else "nicht NULL")
        if vorgabe and vorgabe != "NULL":
            zusatz.append(f"Vorgabe <code>{esc(vorgabe)}</code>")
        if kommentar:
            zusatz.append(f"«{esc(kommentar)}»")
        zeilen.append(f'<div class="mk-stapel" style="padding:10px 12px;gap:2px"><div class="mk-zeile-wrap" style="gap:6px"><code class="mk-fett">{name}</code>{m}'
                      f'<span class="mk-rechts-weg mk-oberbegriff">{ober}</span></div><code class="mk-zweit" style="font-size:12px">{esc(typ)}</code>'
                      f'<span class="mk-hilfe">{" · ".join(zusatz)}</span></div>')
    inhalt = (
        '<div class="mk-zeile-wrap"><h1 class="mk-mono" style="font-size:18px;line-height:24px">orders</h1>'
        f'{treiber("MySQL 8.4")}<span class="mk-schild mk-s-gefahr">{ic("lock", "mk-ic-14")}schreibgeschützt</span></div>'
        + ansichten("Struktur", mobil=True)
        + '<div class="mk-zeile-wrap" style="gap:6px"><span class="mk-fchip" style="background:var(--akzent-hell);border-color:var(--akzent);font-weight:600;padding:0 10px">Spalten 10</span>'
          '<span class="mk-fchip" style="padding:0 10px">Indizes 3</span><span class="mk-fchip" style="padding:0 10px">Fremdschlüssel 1</span>'
          '<span class="mk-fchip" style="padding:0 10px">Checks 1</span><span class="mk-fchip" style="padding:0 10px">Trigger 1</span>'
          '<span class="mk-fchip" style="padding:0 10px">DDL</span></div>'
        + f'<div class="mk-karte mk-liste-trenn">{"".join(zeilen)}</div>'
    )
    return kopfzeile + f'<main class="mk-minhalt" style="gap:12px">{inhalt}{kante_unten()}</main>'


# ===================================================================================================
# Bereich 09 — Tabelle ändern mit Vorschau
# ===================================================================================================

AENDERN_MYSQL = [
    ("ALTER TABLE `orders` ADD `tracking_number` varchar(40) NULL AFTER `currency`;", "Spalte neu", ""),
    ("ALTER TABLE `orders` RENAME COLUMN `notes` TO `internal_notes`;", "umbenannt", ""),
    ("ALTER TABLE `orders` DROP COLUMN `invoice_pdf`;", "Spalte weg",
     "löscht die Werte in 12'406 Zeilen, davon 8'115 nicht NULL"),
    ("ALTER TABLE `orders` ADD INDEX `orders_tracking_number_index` (`tracking_number`);", "Index neu", ""),
]
AENDERN_PG = [
    ('ALTER TABLE "orders" ADD COLUMN "tracking_number" varchar(40) NULL;', "Spalte neu", ""),
    ('ALTER TABLE "orders" RENAME COLUMN "notes" TO "internal_notes";', "umbenannt", ""),
    ('ALTER TABLE "orders" DROP COLUMN "invoice_pdf";', "Spalte weg", "löscht die Werte in 12'406 Zeilen, davon 8'115 nicht NULL"),
    ('CREATE INDEX "orders_tracking_number_index" ON "orders" ("tracking_number");', "Index neu", ""),
]


def treiberhinweis_ddl(drv: str) -> str:
    if drv == "mysql":
        return hinweis('<b class="mk-warnung-text">Nicht rückrollbar — Anweisung für Anweisung</b>'
                       '<span>MySQL schliesst bei jeder Strukturänderung die Transaktion ab. Schlägt Anweisung 3 fehl, bleiben 1 und 2 '
                       'bestehen; phpdbadmin hält dann an und zeigt, was angewendet ist.</span>', "warnung")
    return hinweis('<b>PostgreSQL 18: in einer Transaktion</b>'
                   '<span class="mk-zweit">Schlägt eine Anweisung fehl, wird keine angewendet — die Tabelle bleibt, wie sie war.</span>',
                   "info", "layers")


def aendern_formular(drv: str = "mysql") -> str:
    def zeile(zust: str, name: str, typ: str, null: bool, vorgabe: str, kommentar: str, schild: str) -> str:
        k = {"neu": ' class="mk-neu"', "geaendert": ' class="mk-geaendert"', "loeschen": ' class="mk-loeschen"'}.get(zust, "")
        durch = ' class="mk-durch"' if zust == "loeschen" else ""
        n_kasten = f'<span class="mk-kasten{" mk-an" if null else ""}">{ic("check") if null else ""}</span>'
        komm = f'<span class="mk-hilfe mk-abschneiden" style="display:block">Kommentar: {kommentar}</span>' if kommentar else ""
        return (f'<tr{k}><td class="mk-leise">{ic("grip", "mk-ic-14")}</td>'
                f'<td{durch}><code class="mk-fett mk-abschneiden" style="display:block">{name}</code>{komm}</td>'
                f'<td{durch}><span class="mk-eingabe" style="min-height:28px;font-size:12px"><code class="mk-voll mk-abschneiden" style="font-size:12px">{esc(typ)}</code>{ic("chevron-down", "mk-ic-14")}</span></td>'
                f'<td>{n_kasten}</td><td{durch}>{vorgabe or "<span class=mk-leise>—</span>"}</td><td style="text-align:right">{schild}</td></tr>')
    s_neu = f'<span class="mk-schild mk-s-erfolg">{ic("plus", "mk-ic-14")}neu</span>'
    s_um = f'<span class="mk-schild mk-s-akzent">{ic("pencil", "mk-ic-14")}umbenannt</span>'
    s_weg = f'<span class="mk-schild mk-s-gefahr">{ic("trash", "mk-ic-14")}wird gelöscht</span>'
    leer = f'<span class="mk-leise">{ic("more", "mk-ic-14")}</span>'
    null = '<span class="mk-null">NULL</span>'
    pg = drv == "pg"
    typen = {
        "id": "bigint · identity" if pg else "bigint unsigned auto_increment",
        "customer_id": "bigint" if pg else "bigint unsigned",
        "status": "order_status (Enum-Typ)" if pg else "enum('offen','bezahlt','versendet','storniert')",
        "total": "numeric(10,2)" if pg else "decimal(10,2)",
        "shipping_address": "jsonb" if pg else "json",
        "invoice_pdf": "bytea" if pg else "longblob",
        "created_at": "timestamptz" if pg else "timestamp",
    }
    jetzt = "<code>now()</code>" if pg else "<code>CURRENT_TIMESTAMP</code>"
    zeilen = [
        zeile("", "id", typen["id"], False, "", "", leer),
        zeile("", "customer_id", typen["customer_id"], False, "", "", leer),
        zeile("", "status", typen["status"], False, "<code>'offen'</code>", "", leer),
        zeile("", "total", typen["total"], False, "", "Betrag inkl. MWST", leer),
        zeile("", "currency", "char(3)", False, "<code>'CHF'</code>", "", leer),
        zeile("neu", "tracking_number", "varchar(40)", True, null, "Sendungsnummer der Post", s_neu),
        zeile("", "shipping_address", typen["shipping_address"], True, null, "", leer),
        zeile("geaendert", "internal_notes <span class='mk-leise' style='font-weight:400'>war notes</span>", "text", True, null, "Interne Notiz", s_um),
        zeile("loeschen", "invoice_pdf", typen["invoice_pdf"], True, null, "", s_weg),
        zeile("", "created_at", typen["created_at"], False, jetzt, "", leer),
        zeile("", "updated_at", typen["created_at"], True, null, "", leer),
    ]
    spalten = ('<colgroup><col style="width:30px"><col style="width:190px"><col style="width:250px"><col style="width:52px">'
               '<col style="width:136px"><col></colgroup>')
    return ('<table class="mk-tabelle mk-eng" style="table-layout:fixed">' + spalten
            + '<thead><tr><th></th><th>Name</th><th>Typ</th><th>NULL</th><th>Vorgabe</th>'
            f'<th></th></tr></thead><tbody>{"".join(zeilen)}</tbody></table>')


def navigator_schiene() -> str:
    knoepfe = "".join(f'<span class="mk-ikonknopf mk-ohne-rand" title="{t}">{ic(s)}</span>'
                      for s, t in (("panel", "Navigator ausklappen"), ("search", "Objekte filtern"), ("database", "shop_test"),
                                   ("bookmark", "Gespeichert"), ("history", "Verlauf")))
    return f'<nav class="mk-schiene" aria-label="Navigator (eingeklappt)">{knoepfe}</nav>'


def b09_desktop(drv: str = "mysql") -> str:
    anweisungen = AENDERN_MYSQL if drv == "mysql" else AENDERN_PG
    rahmen = None if drv == "mysql" else ("BEGIN;", "COMMIT;")
    formular = (f'<section class="mk-karte" style="overflow:hidden">'
                f'<div class="mk-karte-kopf" style="padding:10px 14px">{ic("columns")}<h3>Spalten</h3><span class="mk-zaehler" style="margin-left:0">11</span>'
                f'<span class="mk-rechts-weg mk-zeile">{knopf("Spalte", "klein", "plus")}{knopf("Index", "klein", "plus")}'
                f'{knopf("Fremdschlüssel", "klein", "plus")}{knopf("Check", "klein", "plus")}</span></div>{aendern_formular(drv)}'
                f'<div class="mk-zeile-wrap" style="padding:10px 14px;border-top:1px solid var(--rand);background:var(--zelle-neu)">'
                f'<span class="mk-schild mk-s-erfolg">{ic("plus", "mk-ic-14")}neu</span><span><b>Index</b> '
                '<code>orders_tracking_number_index</code> auf <code>tracking_number</code></span></div></section>')
    vorschau = (
        f'<section class="mk-karte mk-stapel" style="overflow:hidden">'
        f'<div class="mk-karte-kopf" style="padding:10px 14px">{ic("code")}<h3>Erzeugtes SQL</h3>'
        f'<span class="mk-hilfe">wird genau so ausgeführt</span>'
        f'<span class="mk-rechts-weg mk-zeile" style="gap:4px"><span class="mk-ikonknopf" title="Kopieren">{ic("copy")}</span>'
        f'<span class="mk-ikonknopf" title="Im Editor öffnen">{ic("terminal")}</span></span></div>'
        f'<div class="mk-karte-inhalt mk-stapel mk-luecke-12">{treiberhinweis_ddl(drv)}{anweisungsliste(anweisungen, rahmen)}</div></section>'
    )
    best = bestaetigung("ord", False, "Die Spalte <code>invoice_pdf</code> wird mit allen Werten gelöscht", "orders", "loeschen")
    seitenleiste = (
        f'<div class="mk-stapel mk-luecke-12" style="width:540px;flex-shrink:0">{vorschau}{best}'
        f'<div class="mk-zeile">{knopf("Verwerfen", "leise")}<span class="mk-rechts-weg">{knopf("4 Anweisungen anwenden", "aus")}</span></div></div>'
    )
    if drv == "mysql":
        chip_html = chip("test", "shop", "MySQL 8.4")
        reiter = offene_reiter([("orders", "table", True, "test"), ("customers", "table", False, "test"), ("Abfrage 1", "terminal", False, "test")])
        meta = "Tabelle · InnoDB · ≈ 12'406 Zeilen"
    else:
        chip_html = chip("lokal", "shop", "PostgreSQL 18")
        reiter = offene_reiter([("orders", "table", True, "lokal"), ("customers", "table", False, "lokal")])
        meta = "Tabelle · Schema public · ≈ 12'406 Zeilen"
    aktionen = knopf("Bearbeiten beenden", "klein", "x")
    haupt = (reiter + objkopf("orders", meta + " · <b>Struktur wird bearbeitet</b>", aktionen) + ansichten("Struktur")
             + f'<div style="display:flex;gap:16px;padding:16px;flex:1;min-height:0;overflow:hidden">'
               f'<div class="mk-voll" style="position:relative;overflow:hidden">{formular}</div>{seitenleiste}</div>')
    return arbeit(haupt, chip_html, navi=navigator_schiene())


def b09_mobil() -> str:
    links = f'<span class="mk-ikonknopf">{ic("panel")}</span>'
    kopfzeile = mkopf(links, chip("test", "shop", kurz=True))
    aenderungen = (
        '<div class="mk-karte mk-liste-trenn">'
        f'<div class="mk-zeile" style="padding:10px 12px;background:var(--zelle-neu)"><span class="mk-schild mk-s-erfolg">neu</span><span class="mk-voll"><code class="mk-fett">tracking_number</code><br><code class="mk-zweit" style="font-size:12px">varchar(40) NULL</code></span></div>'
        f'<div class="mk-zeile" style="padding:10px 12px;background:var(--zelle-geaendert)"><span class="mk-schild mk-s-akzent">umbenannt</span><span class="mk-voll"><code>notes</code> → <code class="mk-fett">internal_notes</code></span></div>'
        f'<div class="mk-zeile" style="padding:10px 12px;background:var(--zelle-geloescht)"><span class="mk-schild mk-s-gefahr">gelöscht</span><span class="mk-voll"><code class="mk-fett" style="text-decoration:line-through;text-decoration-color:var(--gefahr)">invoice_pdf</code><br><span class="mk-hilfe">Werte in 12\'406 Zeilen gehen verloren</span></span></div>'
        f'<div class="mk-zeile" style="padding:10px 12px;background:var(--zelle-neu)"><span class="mk-schild mk-s-erfolg">neu</span><span class="mk-voll">Index auf <code>tracking_number</code></span></div>'
        '</div>'
    )
    inhalt = (
        '<div class="mk-stapel mk-luecke-4"><h1 class="mk-titel-2">Tabelle ändern · <code>orders</code></h1>'
        '<p class="mk-hilfe">Strukturänderungen sind für den Desktop ausgelegt; hier sehen und prüfen Sie sie.</p></div>'
        + aenderungen + treiberhinweis_ddl("mysql")
        + anweisungsliste(AENDERN_MYSQL, None)
    )
    leiste = ('<div class="mk-unten-fest">'
              + bestaetigung_fuss("orders", "Löscht Daten: Tabellennamen «orders» eingeben", "4 Anweisungen anwenden")
              + '</div>')
    return kopfzeile + f'<main class="mk-minhalt" style="gap:12px;bottom:142px">{inhalt}{kante_unten()}</main>' + leiste


# ===================================================================================================
# Bereich 10 — SQL-Editor
# ===================================================================================================

ABFRAGE = """-- Umsatz je Kunde im Oktober
SELECT c.id, c.last_name, c.first_name,
       COUNT(o.id) AS bestellungen,
       SUM(o.total) AS umsatz
FROM customers c
JOIN orders o ON o.customer_id = c.id
WHERE o.created_at >= '2026-10-01'
  AND o.status <> 'storniert'
GROUP BY c.id, c.last_name, c.first_name
ORDER BY umsatz DESC
LIMIT 25;

-- Artikel ohne Lagerbewegung seit einem Jahr
SELECT p.sku, p.name, MAX(m.created_at) AS letzte_bewegung
FROM products p
LEFT JOIN stock_movements m ON m.product_id = p.id
GROUP BY p.sku, p.name
HAVING letzte_bewegung < NOW() - INTERVAL 365 DAY;

SELECT * FROM ord"""

UMSATZ = [
    (2087, "Rossi", "Carla", 4, "3412.50"), (8833, "Gerber", "Lukas", 3, "2190.00"), (6015, "Bianchi", "Nicola", 5, "1874.40"),
    (7012, "Muster", "Anna", 6, "1512.80"), (1203, "Huber", "Gabriela", 2, "1298.00"), (5528, "Keller", "Beat", 3, "1105.50"),
    (4471, "Steiner", "Hans", 2, "986.30"), (9104, "Brunner", "Eva", 4, "912.60"), (3391, "Weber", "Daniel", 5, "874.20"),
    (7788, "Baumann", "Irene", 3, "802.00"), (5012, "Frei", "Jonas", 2, "768.40"),
]


def editor(zeilen_von: int = 1, zeilen_bis: int = 20, aktiv: int = 20, vervollstaendigen: bool = True,
           nummern: bool = True) -> str:
    alle = ABFRAGE.split("\n")
    teile = []
    for nr in range(zeilen_von, zeilen_bis + 1):
        text = alle[nr - 1] if nr - 1 < len(alle) else ""
        inhalt = sql_hervorheben(text) if text else " "
        if nr == aktiv and vervollstaendigen:
            inhalt += '<i class="mk-cursor"></i>'
        teile.append(f'<span class="mk-editor-zeile{" mk-aktiv" if nr == aktiv else ""}">{inhalt}</span>')
    nr_spalte = "<br>".join(str(n) for n in range(zeilen_von, zeilen_bis + 1))
    nr_html = f'<div class="mk-editor-nr">{nr_spalte}</div>' if nummern else ""
    popup = ""
    if vervollstaendigen:
        eintraege = [("table", "<mark>ord</mark>ers", "Tabelle · ≈ 12'406", True), ("table", "<mark>ord</mark>er_items", "Tabelle · ≈ 33'950", False),
                     ("view", "v_open_<mark>ord</mark>ers", "View", False), ("terminal", "<mark>ORD</mark>ER BY", "Schlüsselwort", False)]
        zeilen = "".join(f'<div class="mk-pal-e{" mk-aktiv" if a else ""}" style="padding:5px 10px">{ic(s, "mk-ic-14")}'
                         f'<span class="mk-mono mk-voll">{n}</span><span class="mk-hilfe">{h}</span></div>' for s, n, h, a in eintraege)
        oben = 10 + (aktiv - zeilen_von + 1) * 20 + 2
        popup = (f'<div class="mk-menue" style="left:{(44 if nummern else 0) + 12 + 17 * 7.8:.0f}px;top:{oben}px;width:330px;padding:4px;font-family:{SCHRIFT}">{zeilen}'
                 f'<div class="mk-hilfe" style="padding:6px 10px 2px;border-top:1px solid var(--rand);margin-top:4px">aus dem Schema von shop_test · ↵ übernehmen</div></div>')
    return f'<div class="mk-editor">{nr_html}<div class="mk-editor-text">{"".join(teile)}</div>{popup}</div>'


def ergebnis_raster() -> str:
    kopf_z = ('<tr><th class="mk-nr">#</th><th>id<span class="mk-typ">bigint</span></th><th>last_name<span class="mk-typ">varchar</span></th>'
              '<th>first_name<span class="mk-typ">varchar</span></th><th class="mk-r">bestellungen<span class="mk-typ">bigint</span></th>'
              '<th class="mk-r">umsatz<span class="mk-typ">decimal</span></th></tr>')
    zeilen = "".join(f'<tr><td class="mk-nr">{i}</td><td>{a}</td><td>{b}</td><td>{c}</td><td class="mk-r">{d}</td><td class="mk-r">{e}</td></tr>'
                     for i, (a, b, c, d, e) in enumerate(UMSATZ, 1))
    cols = '<col style="width:44px"><col style="width:90px"><col style="width:180px"><col style="width:180px"><col style="width:130px"><col style="width:130px">'
    return (f'<table class="mk-raster" style="width:754px"><colgroup>{cols}</colgroup><thead>{kopf_z}</thead><tbody>{zeilen}</tbody></table>')


def abgebrochen_karte(mobil: bool = False) -> str:
    return (
        '<div class="mk-hinweis mk-h-warnung" style="padding:14px 16px;gap:12px">'
        f'{ic("stop")}<div class="mk-stapel mk-luecke-8">'
        '<b class="mk-warnung-text" style="font-size:15px">Abgebrochen nach 30 s</b>'
        '<p>Die Abfrage hat die Zeitgrenze dieser Verbindung erreicht; phpdbadmin hat sie auf dem Server beendet. '
        'MySQL meldet einen solchen Abbruch nicht selbst — phpdbadmin hat die Zeit gemessen und zeigt es deshalb hier an.</p>'
        '<div class="mk-stapel mk-luecke-4"><b>So kommen Sie weiter</b>'
        '<span>· Eingrenzen, zum Beispiel <code>WHERE m.created_at &gt;= …</code> oder weniger Produkte</span>'
        '<span>· Index auf <code>stock_movements.product_id</code> prüfen</span>'
        '<span>· Statistik aktualisieren, wenn die Tabelle frisch gefüllt wurde</span></div>'
        f'<div class="mk-zeile-wrap">{knopf("Erneut ausführen", "klein", "play")}{knopf("Struktur von stock_movements", "klein", "columns")}'
        f'{knopf("Statistik aktualisieren", "klein", "refresh")}</div>'
        '<span class="mk-hilfe">Gestartet 10:31:02 · beendet 10:31:32 · KILL QUERY nach 30,0 s · 0 Zeilen</span>'
        '</div></div>'
    )


def verlauf_panel() -> str:
    gespeichert = [("Offene Bestellungen &gt; 7 Tage", "shop · gestern"), ("Umsatz je Monat", "shop · 12.10.2026"), ("Kunden ohne Bestellung", "shop · 02.10.2026")]
    verlauf = [
        ("10:31", "SELECT p.sku, p.name, MAX(…", "stop", "warnung", "abgebrochen nach 30 s"),
        ("10:30", "SELECT c.id, c.last_name, …", "ok", "erfolg", "25 Zeilen · 18 ms"),
        ("10:12", "ALTER TABLE `orders` ADD …", "ok", "erfolg", "4 Anweisungen · 2,4 s"),
        ("09:58", "UPDATE `customers` SET …", "nok", "gefahr", "Fehler 1406: Wert zu lang"),
        ("09:41", "SELECT COUNT(*) FROM customers", "ok", "erfolg", "1 Zeile · 4 ms"),
        ("gestern", "EXPLAIN SELECT * FROM orders …", "ok", "erfolg", "3 Zeilen · 2 ms"),
    ]
    g = "".join(f'<div class="mk-stapel" style="padding:8px 12px;gap:2px"><span class="mk-zeile" style="gap:6px">{ic("file-text", "mk-ic-14")}'
                f'<span class="mk-fett mk-abschneiden">{n}</span></span><span class="mk-hilfe">{h}</span></div>' for n, h in gespeichert)
    v = "".join(f'<div class="mk-stapel" style="padding:8px 12px;gap:2px"><span class="mk-zeile"><code class="mk-abschneiden mk-voll" style="font-size:12px">{esc(q)}</code>'
                f'<span class="mk-hilfe">{z}</span></span><span class="mk-zeile mk-klein mk-{f}-text" style="gap:4px">{ic(s, "mk-ic-14")}{t}</span></div>'
                for z, q, s, f, t in verlauf)
    return (
        '<aside style="width:300px;flex-shrink:0;border-left:1px solid var(--rand);background:var(--flaeche);display:flex;flex-direction:column;position:relative;overflow:hidden">'
        f'<div class="mk-navi-titel" style="padding:12px 12px 6px">{ic("bookmark", "mk-ic-14")}Gespeichert<span class="mk-zaehler">3</span></div>'
        f'<div class="mk-liste-trenn">{g}</div>'
        f'<div class="mk-navi-titel" style="padding:14px 12px 6px;border-top:1px solid var(--rand)">{ic("history", "mk-ic-14")}Verlauf'
        '<span class="mk-zaehler">dieses Konto · test · shop</span></div>'
        f'<div class="mk-navi-suche" style="margin:4px 12px 6px">{ic("search", "mk-ic-14")}<span>Verlauf durchsuchen</span></div>'
        f'<div class="mk-liste-trenn" style="position:relative;flex:1;min-height:0;overflow:hidden">{v}{kante_unten(True)}</div></aside>'
    )


def b10_desktop(abgebrochen_aktiv: bool = False) -> str:
    reiter = offene_reiter([("orders", "table", False, "test"), ("customers", "table", False, "test"), ("Abfrage 1", "terminal", True, "test")])
    leiste = (
        '<div class="mk-zeile-wrap" style="padding:10px 16px;border-bottom:1px solid var(--rand)">'
        f'{knopf("Ausführen", "primaer klein", "play")}{taste("⌘↵")}'
        f'{segment(["Aktuelle Anweisung", "Auswahl", "Alles"], 0)}'
        f'{knopf("Formatieren", "klein", "wand")}{knopf("Speichern …", "klein", "save")}'
        f'<span class="mk-knopf mk-klein mk-aus">{ic("stop", "mk-ic-14")}Abbrechen</span>'
        '<span class="mk-rechts-weg mk-hilfe">Zeitgrenze 30 s · höchstens 1\'000 Zeilen je Ergebnis</span></div>'
    )
    r1_aktiv = not abgebrochen_aktiv
    ergebnisreiter = (
        '<div class="mk-ergebnisreiter">'
        f'<span class="mk-reiter{" mk-aktiv" if r1_aktiv else ""}" style="border-left:1px solid var(--rand)">{ic("table", "mk-ic-14")}Ergebnis 1'
        '<span class="mk-zaehler" style="margin-left:4px">25 Zeilen · 18 ms</span></span>'
        f'<span class="mk-reiter{" mk-aktiv" if not r1_aktiv else ""}"><span class="mk-warnung-text mk-zeile" style="gap:6px">{ic("stop", "mk-ic-14")}Ergebnis 2</span>'
        '<span class="mk-zaehler mk-warnung-text" style="margin-left:4px">abgebrochen nach 30 s</span></span>'
        f'<span class="mk-rechts-weg mk-zeile mk-zweit mk-klein" style="padding:0 8px;height:39px">{ic("download", "mk-ic-14")}Ergebnis exportieren · {ic("copy", "mk-ic-14")}kopieren</span>'
        '</div>'
    )
    if r1_aktiv:
        unten = f'<div class="mk-raster-huelle">{ergebnis_raster()}{kante_unten()}</div>'
    else:
        unten = f'<div style="padding:16px;flex:1;min-height:0;overflow:hidden">{abgebrochen_karte()}</div>'
    mitte = (f'<div class="mk-voll mk-stapel">{leiste}<div style="height:420px;flex-shrink:0;border-bottom:1px solid var(--rand);position:relative;z-index:5">'
             f'{editor()}</div>{ergebnisreiter}{unten}</div>')
    haupt = reiter + f'<div style="display:flex;flex:1;min-height:0">{mitte}{verlauf_panel()}</div>'
    navi = navigator("", unten=False).replace('>shop<span class="mk-zaehler">MySQL 8.4', '>shop_test<span class="mk-zaehler">MySQL 8.4')
    return arbeit(haupt, chip("test", "shop", "MySQL 8.4"), navi=navi)


def b10_mobil() -> str:
    links = f'<span class="mk-ikonknopf">{ic("panel")}</span>'
    kopfzeile = mkopf(links, chip("test", "shop", kurz=True))
    inhalt = (
        '<div class="mk-zeile-wrap"><h1 class="mk-titel-2">Abfrage 1</h1><span class="mk-schild">nicht gespeichert</span>'
        f'<span class="mk-rechts-weg mk-zeile" style="gap:4px"><span class="mk-ikonknopf">{ic("history")}</span><span class="mk-ikonknopf">{ic("save")}</span></span></div>'
        f'<div class="mk-karte" style="overflow:hidden">{editor(13, 18, 18, vervollstaendigen=False, nummern=False).replace("mk-editor-text", "mk-editor-text\" style=\"white-space:pre-wrap;font-size:12px;line-height:18px")}</div>'
        f'<div class="mk-zeile">{knopf("Ausführen", "primaer klein", "play")}<span class="mk-knopf mk-klein">Aktuelle Anweisung{ic("chevron-down", "mk-ic-14")}</span></div>'
        '<div class="mk-zeile-wrap" style="gap:6px">'
        f'<span class="mk-fchip" style="padding:0 10px">{ic("table", "mk-ic-14")}Ergebnis 1 · 25 Zeilen</span>'
        f'<span class="mk-fchip mk-warnung-text" style="padding:0 10px;border-color:var(--warnung);font-weight:600;background:var(--grund)">{ic("stop", "mk-ic-14")}Ergebnis 2 · abgebrochen</span></div>'
        + abgebrochen_karte(True)
    )
    return kopfzeile + f'<main class="mk-minhalt" style="gap:12px">{inhalt}{kante_unten()}</main>'


# ===================================================================================================
# Bereich 11 — Export und Import
# ===================================================================================================

def hintergrund_auftraege(breite: int = 360) -> str:
    return (
        f'<div class="mk-karte" style="width:{breite}px;max-width:100%;box-shadow:var(--schatten);overflow:hidden">'
        f'<div class="mk-karte-kopf" style="padding:10px 14px">{ic("layers")}<h3>Im Hintergrund</h3><span class="mk-zaehler" style="margin-left:0">2</span>'
        f'<span class="mk-rechts-weg mk-leise">{ic("chevron-down")}</span></div>'
        '<div class="mk-liste-trenn">'
        '<div class="mk-stapel mk-luecke-8" style="padding:10px 14px">'
        f'<div class="mk-zeile"><span class="mk-mono mk-fett mk-abschneiden">orders.csv</span><span class="mk-rechts-weg mk-zweit mk-klein mk-zahl">62 %</span></div>'
        '<div class="mk-fortschritt"><i style="width:62%"></i></div>'
        f'<div class="mk-zeile mk-hilfe"><span class="mk-zahl">29\'900 von ≈ 48\'210 Zeilen · noch etwa 20 s</span><a class="mk-rechts-weg mk-nowrap">Abbrechen</a></div></div>'
        '<div class="mk-stapel mk-luecke-4" style="padding:10px 14px">'
        f'<div class="mk-zeile"><span class="mk-erfolg-text">{ic("ok")}</span><span class="mk-mono mk-fett mk-abschneiden">customers.json</span>'
        f'<span class="mk-rechts-weg">{knopf("Herunterladen", "klein", "download")}</span></div>'
        '<span class="mk-hilfe">fertig 10:24 · 3,1 MB · 9\'870 Zeilen · bereit bis morgen 10:24</span></div>'
        '</div></div>'
    )


def export_inhalt(mobil: bool = False) -> str:
    formate = [("CSV", "für Tabellenkalkulation", True), ("JSON", "eine Zeile je Objekt", False), ("SQL", "INSERT, Struktur oder beides", False)]
    kacheln = "".join(f'<div class="mk-kachel{" mk-aktiv" if a else ""}">{"<span class=mk-haken>" + ic("check") + "</span>" if a else ""}'
                      f'<span class="mk-fett">{n}</span><span class="mk-hilfe">{h}</span></div>' for n, h, a in formate)
    spalten = 3 if not mobil else 1
    return (
        f'<div class="mk-feld"><span class="mk-feld-titel">Format</span><div style="display:grid;grid-template-columns:repeat({spalten},minmax(0,1fr));gap:8px">{kacheln}</div></div>'
        '<div class="mk-feld"><span class="mk-feld-titel">Umfang</span><div class="mk-stapel mk-luecke-8">'
        + radio("Gefilterte Zeilen · <b>212</b>", True, "status = offen · created_at ≥ 21.10.2026 · sortiert nach created_at")
        + radio("Ganze Tabelle · <b>≈ 48'210</b>", False, "läuft im Hintergrund, in Paketen über den Primärschlüssel")
        + '</div></div>'
        + ('<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' if not mobil else '<div class="mk-stapel mk-luecke-12">')
        + auswahl("Trennzeichen", "Strichpunkt ;") + auswahl("Zeichensatz", "UTF-8 mit BOM (für Excel)") + '</div>'
        + kasten("Erste Zeile mit Spaltennamen", True)
        + auswahl("Binärspalte invoice_pdf", "weglassen", "CSV kann keine Binärdaten tragen; als Base64 möglich.")
        + hinweis('<span>Grosse Exporte laufen im Hintergrund weiter — Sie können arbeiten und laden die Datei später herunter. '
                  'Lesen ist auf schreibgeschützten Verbindungen erlaubt.</span>', "info")
    )


def b11_export_desktop() -> str:
    dialog = (
        '<div class="mk-schleier" style="top:-52px"></div>'
        '<div class="mk-dialog" style="left:50%;top:40px;transform:translateX(-50%);width:600px" role="dialog" aria-label="Exportieren">'
        f'<div class="mk-sch-kopf"><h2 class="mk-titel-2">Exportieren</h2><span class="mk-mono mk-zweit">orders</span>{chip("prod", "shop", pfeil=False, klein=True, schloss=True)}'
        f'<span class="mk-rechts-weg mk-ikonknopf mk-ohne-rand">{ic("x")}</span></div>'
        f'<div class="mk-sch-inhalt" style="gap:16px">{export_inhalt()}</div>'
        f'<div class="mk-sch-fuss"><span class="mk-hilfe">Datei: orders-2026-10-28.csv</span><span class="mk-rechts-weg mk-zeile">{knopf("Abbrechen", "leise")}{knopf("Exportieren", "primaer", "download")}</span></div>'
        '</div>'
        f'<div style="position:absolute;right:16px;bottom:16px;z-index:22">{hintergrund_auftraege()}</div>'
    )
    return arbeit(daten_haupt(fokus=None, aenderungen=False), prod_chip_zu(), prod=True, ueber=dialog)


ZUORDNUNG = [
    ("Vorname", "Lea · Marco", "first_name", "varchar(80)", "", "ok"),
    ("Nachname", "Ammann · Bernasconi", "last_name", "varchar(80) · nicht NULL", "", "ok"),
    ("E-Mail", "lea.ammann@example.ch", "email", "varchar(190) · eindeutig", "", "ok"),
    ("PLZ", "3012 · 6900", "postal_code", "varchar(10)", "", "ok"),
    ("Ort", "Bern · Lugano", "city", "varchar(80)", "", "ok"),
    ("Kunde seit", "14.03.2024", "created_at", "timestamp", "Datumsformat TT.MM.JJJJ", "ok"),
    ("Newsletter", "ja · nein", "newsletter", "tinyint(1)", "ja → 1, nein → 0", "ok"),
    ("Bemerkung", "Messe Bern", "— nicht importieren —", "", "", "aus"),
]


def zuordnung_tabelle() -> str:
    zeilen = []
    for csv, bsp, ziel, typ, hilfe, art in ZUORDNUNG:
        leise = art == "aus"
        ziel_html = (f'<span class="mk-eingabe" style="min-height:30px"><code class="mk-voll{" mk-leise" if leise else " mk-fett"}" style="font-size:12px">{ziel}</code>'
                     f'{ic("chevron-down", "mk-ic-14")}</span>')
        unter = f'<span class="mk-hilfe">{typ}{" · " + hilfe if hilfe else ""}</span>' if typ else ""
        zeilen.append(f'<tr><td><b>{csv}</b></td><td class="mk-zweit" style="font-size:13px">{bsp}</td><td class="mk-leise">{ic("arrow-right", "mk-ic-14")}</td>'
                      f'<td><div class="mk-stapel mk-luecke-4">{ziel_html}{unter}</div></td></tr>')
    return ('<table class="mk-tabelle mk-eng"><thead><tr><th>Spalte in der Datei</th><th>Beispiel</th><th></th><th style="width:300px">Spalte in customers</th></tr></thead>'
            f'<tbody>{"".join(zeilen)}</tbody></table>')


def probelauf_karte() -> str:
    fehler = [
        ("47", "Nachname ist leer — <code>last_name</code> darf nicht NULL sein"),
        ("128", "E-Mail <code>anna.muster@example.ch</code> gibt es schon (eindeutiger Index <code>customers_email_unique</code>)"),
        ("291", "«31.09.2025» ist kein gültiges Datum"),
    ]
    f = "".join(f'<li class="mk-zeile" style="align-items:flex-start;padding:6px 0;gap:10px"><span class="mk-mono mk-klein mk-zweit mk-nowrap" style="width:72px;flex-shrink:0">Zeile {z}</span>'
                f'<span style="font-size:13px;line-height:18px">{t}</span></li>' for z, t in fehler)
    return (
        '<section class="mk-karte">'
        f'<div class="mk-karte-kopf">{ic("zap")}<h2>Probelauf</h2><span class="mk-rechts-weg mk-hilfe">10:29 · 0,8 s</span></div>'
        '<div class="mk-karte-inhalt mk-stapel mk-luecke-12">'
        + hinweis('<b>Nichts wurde geschrieben.</b><span class="mk-zweit">Der Probelauf hat alle 312 Zeilen in einer Transaktion eingefügt und sie danach zurückgerollt.</span>', "info", "shield")
        + f'<div class="mk-zeile mk-erfolg-text mk-fett">{ic("ok")}309 Zeilen bereit</div>'
        + f'<div class="mk-stapel"><div class="mk-zeile mk-gefahr-text mk-fett">{ic("nok")}3 Zeilen mit Fehlern</div><ul class="mk-liste-trenn" style="padding-left:26px">{f}</ul></div>'
        + '<div class="mk-stapel mk-luecke-8" style="border-top:1px solid var(--rand);padding-top:12px">'
        + radio("Fehlerzeilen überspringen", True, "Sie erhalten die 3 Zeilen als CSV zum Korrigieren.")
        + radio("Bei einem Fehler nichts importieren", False)
        + kasten("Danach Statistik aktualisieren (<code>ANALYZE TABLE customers</code>)", True,
                 "Ohne aktuelle Statistik sortiert MySQL nach grossen Importen deutlich langsamer.")
        + '</div></div></section>'
    )


def b11_import_desktop() -> str:
    schritte = ('<div class="mk-schritte">'
                '<span class="mk-schritt mk-fertig"><i>✓</i>Datei</span><span class="mk-schritt-linie"></span>'
                '<span class="mk-schritt mk-fertig"><i>✓</i>Zuordnung</span><span class="mk-schritt-linie"></span>'
                '<span class="mk-schritt mk-aktiv"><i>3</i>Probelauf</span><span class="mk-schritt-linie"></span>'
                '<span class="mk-schritt"><i>4</i>Importieren</span></div>')
    datei = (f'<div class="mk-zeile-wrap mk-karte" style="padding:10px 14px">{ic("file-text")}<span class="mk-mono mk-fett">neukunden-oktober.csv</span>'
             '<span class="mk-zweit mk-klein">312 Zeilen · 41 KB · UTF-8 · Trennzeichen ; · erste Zeile = Spaltennamen</span>'
             '<a class="mk-rechts-weg">andere Datei</a></div>')
    links = (f'<div class="mk-stapel mk-luecke-16 mk-voll" style="position:relative;overflow:hidden">{datei}'
             f'<section class="mk-karte" style="overflow:hidden"><div class="mk-karte-kopf" style="padding:10px 14px">{ic("columns")}<h3>Zuordnung</h3>'
             '<span class="mk-hilfe">automatisch nach Namen vorgeschlagen · id und updated_at füllt die Datenbank</span></div>'
             f'{zuordnung_tabelle()}</section></div>')
    rechts = (f'<div class="mk-stapel mk-luecke-12" style="width:480px;flex-shrink:0">{probelauf_karte()}'
              f'<div class="mk-zeile">{knopf("Zurück", "leise", "arrow-left")}<span class="mk-rechts-weg mk-zeile">{knopf("Erneut prüfen", "", "refresh")}'
              f'{knopf("309 Zeilen importieren", "primaer", "upload")}</span></div></div>')
    reiter = offene_reiter([("orders", "table", False, "test"), ("customers", "table", True, "test"), ("Abfrage 1", "terminal", False, "test")])
    haupt = (reiter
             + '<div class="mk-objkopf" style="padding-bottom:12px;border-bottom:1px solid var(--rand)"><h1>customers</h1>'
               '<span class="mk-zweit" style="font-size:13px">Daten importieren · CSV</span>'
               f'<span class="mk-rechts-weg">{schritte}</span></div>'
             + f'<div style="display:flex;gap:16px;padding:16px;flex:1;min-height:0">{links}{rechts}</div>')
    navi = navigator("customers", unten=False).replace('>shop<span class="mk-zaehler">MySQL 8.4', '>shop_test<span class="mk-zaehler">MySQL 8.4')
    return arbeit(haupt, chip("test", "shop", "MySQL 8.4"), navi=navi)


def b11_mobil() -> str:
    links = f'<span class="mk-ikonknopf">{ic("panel")}</span>'
    hintergrund = ('<div class="mk-streifen-prod"></div>' + mkopf(links, chip("prod", "shop", schloss=True, kurz=True))
                   + '<main class="mk-minhalt">' + "".join(mkarte_bestellung(z) for z in BESTELLUNGEN[3:8]) + '</main>')
    blatt = (
        '<div class="mk-schleier" style="top:54px"></div>'
        '<aside class="mk-blatt" style="top:66px" aria-label="Exportieren"><div class="mk-griff"></div>'
        f'<div class="mk-sch-kopf" style="padding:10px 16px"><h2 class="mk-titel-2">Exportieren</h2><span class="mk-mono mk-zweit">orders</span>'
        f'<span class="mk-rechts-weg mk-ikonknopf mk-ohne-rand">{ic("x")}</span></div>'
        f'<div class="mk-sch-inhalt" style="gap:14px">{hintergrund_auftraege(343)}'
        f'{export_inhalt(True)}{kante_unten()}</div>'
        f'<div class="mk-sch-fuss" style="flex-wrap:nowrap">{knopf("Abbrechen", "")}<span class="mk-voll"></span>{knopf("Exportieren", "primaer", "download")}</div>'
        '</aside>'
    )
    return hintergrund + blatt


# ===================================================================================================
# Bereich 12 — Befehlspalette ⌘K
# ===================================================================================================

def palette_liste(mobil: bool = False) -> str:
    def e(symbol: str, name: str, info: str, rechts: str = "", aktiv: bool = False, mono: bool = True) -> str:
        r = f'<span class="mk-rechts-weg mk-zeile mk-hilfe" style="gap:6px">{rechts}</span>' if rechts and not mobil else ""
        return (f'<div class="mk-pal-e{" mk-aktiv" if aktiv else ""}">{ic(symbol)}<span class="mk-stapel mk-voll">'
                f'<span class="{"mk-mono " if mono else ""}mk-abschneiden">{name}</span><span class="mk-hilfe">{info}</span></span>{r}</div>')
    return (
        f'<div class="mk-pal-gruppe mk-zeile" style="gap:6px">Objekte in <span class="mk-umg mk-umg-prod">{punkt("prod")}produktion · shop</span></div>'
        + e("table", "<mark>ord</mark>ers", "Tabelle · ≈ 48'210 Zeilen · zuletzt vor 12 Min.", f'{taste("↵")}öffnen', True)
        + e("table", "<mark>ord</mark>er_items", "Tabelle · ≈ 131'200 Zeilen")
        + e("view", "v_open_<mark>ord</mark>ers", "View · offene Bestellungen")
        + '<div class="mk-pal-gruppe">Andere Verbindungen</div>'
        + e("table", "<mark>ord</mark>ers", f'Tabelle in <span class="mk-umg mk-umg-test" style="font-weight:500">{punkt("test")}test · shop</span>',
            f'{taste("⌘↵")}neuer Reiter')
        + e("table", "<mark>ord</mark>er_items", f'Tabelle in <span class="mk-umg mk-umg-test" style="font-weight:500">{punkt("test")}test · shop</span>')
        + '<div class="mk-pal-gruppe">Aktionen</div>'
        + e("terminal", "Neue Abfrage auf <code><mark>ord</mark>ers</code>", "SELECT … FROM orders im SQL-Editor", mono=False)
        + e("download", "<code><mark>ord</mark>ers</code> exportieren …", "CSV, JSON oder SQL", mono=False)
        + e("columns", "Struktur von <code><mark>ord</mark>ers</code>", "Spalten, Indizes, Fremdschlüssel, DDL", mono=False)
    )


def b12_desktop() -> str:
    palette = (
        '<div class="mk-schleier" style="top:-52px"></div>'
        '<div class="mk-dialog" style="left:50%;top:36px;transform:translateX(-50%);width:680px" role="dialog" aria-label="Befehlspalette">'
        f'<div class="mk-zeile" style="padding:14px 16px;border-bottom:1px solid var(--rand);font-size:16px">{ic("search", "mk-ic-20")}'
        f'<span class="mk-voll">ord<i class="mk-cursor"></i></span>{taste("esc")}</div>'
        f'<div style="padding:4px 8px 8px">{palette_liste()}</div>'
        '<div class="mk-zeile-wrap mk-hilfe" style="padding:10px 16px;border-top:1px solid var(--rand);background:var(--flaeche);gap:14px">'
        f'<span class="mk-zeile" style="gap:4px">{taste("↑")}{taste("↓")}wählen</span><span class="mk-zeile" style="gap:4px">{taste("↵")}öffnen</span>'
        f'<span class="mk-zeile" style="gap:4px">{taste("⌘↵")}in neuem Reiter</span><span class="mk-zeile" style="gap:4px">{taste("esc")}schliessen</span>'
        f'<span class="mk-rechts-weg">«&gt;» am Anfang zeigt nur Aktionen</span></div>'
        '</div>'
    )
    return arbeit(daten_haupt(fokus=None, aenderungen=False), prod_chip_zu(), prod=True, ueber=palette)


def b12_mobil() -> str:
    return (
        '<div class="mk-streifen-prod"></div>'
        '<div class="mk-stapel" style="position:absolute;inset:0;background:var(--grund)">'
        f'<div class="mk-zeile" style="padding:12px;border-bottom:1px solid var(--rand);height:56px">{ic("search", "mk-ic-20")}'
        f'<span class="mk-voll" style="font-size:16px">ord<i class="mk-cursor"></i></span>{knopf("Fertig", "leise klein")}</div>'
        f'<div style="padding:4px 8px;position:relative;flex:1;overflow:hidden">{palette_liste(True)}'
        '<div class="mk-pal-gruppe">Gespeicherte Abfragen</div>'
        f'<div class="mk-pal-e">{ic("file-text")}<span class="mk-stapel mk-voll"><span class="mk-abschneiden">Offene Bestellungen &gt; 7 Tage</span>'
        '<span class="mk-hilfe">enthält <code>orders</code> · gestern</span></span></div>'
        '</div></div>'
    )


# ===================================================================================================
# Bereich 13 — Konto: Sicherheit und Darstellung
# ===================================================================================================

PASSKEYS = [
    ("MacBook Pro — Touch ID", "hinzugefügt 21.10.2026", "zuletzt heute 09:02", "dieses Gerät"),
    ("iPhone — iCloud-Schlüsselbund", "hinzugefügt 21.10.2026", "zuletzt heute 07:45", ""),
    ("YubiKey 5C NFC", "hinzugefügt 22.10.2026", "noch nie benutzt", ""),
]
SITZUNGEN = [
    ("monitor", "Safari auf macOS", "Netz 192.168.1.x", "aktiv jetzt", True),
    ("smartphone", "Safari auf iOS", "Netz 192.168.1.x", "zuletzt heute 07:45", False),
    ("monitor", "Firefox auf Ubuntu", "Netz 10.0.4.x", "zuletzt 27.10.2026, 18:20", False),
]


def passkey_liste(mobil: bool) -> str:
    z = []
    for name, dazu, zuletzt, geraet in PASSKEYS:
        schild = f'<span class="mk-schild mk-s-akzent">{geraet}</span>' if geraet else ""
        aktionen = (f'<span class="mk-zeile" style="gap:4px">{knopf("Umbenennen", "klein leise", "pencil")}{knopf("Entfernen", "klein leise", "trash")}</span>'
                    if not mobil else f'<span class="mk-ikonknopf mk-ohne-rand">{ic("more")}</span>')
        z.append(f'<div class="mk-zeile" style="padding:12px 16px;gap:12px;align-items:flex-start"><span class="mk-akzent-text" style="margin-top:2px">{ic("key")}</span>'
                 f'<span class="mk-stapel mk-voll mk-luecke-4"><span class="mk-zeile-wrap" style="gap:6px"><b>{name}</b>{schild}</span>'
                 f'<span class="mk-hilfe">{dazu} · {zuletzt}</span></span>{aktionen}</div>')
    return f'<div class="mk-liste-trenn">{"".join(z)}</div>'


def sitzung_liste(mobil: bool) -> str:
    z = []
    for symbol, geraet, netz, zeit, dieses in SITZUNGEN:
        rechts = (f'<span class="mk-schild mk-s-erfolg">dieses Gerät</span>' if dieses else knopf("Beenden", "klein", "logout"))
        z.append(f'<div class="mk-zeile" style="padding:12px 16px;gap:12px;align-items:flex-start"><span class="mk-zweit" style="margin-top:2px">{ic(symbol)}</span>'
                 f'<span class="mk-stapel mk-voll mk-luecke-4"><b>{geraet}</b><span class="mk-hilfe">{netz} · {zeit}</span></span>{rechts}</div>')
    return f'<div class="mk-liste-trenn">{"".join(z)}</div>'


def darstellung(mobil: bool) -> str:
    def schema(name: str, symbol: str, aktiv: bool, hell: bool | None) -> str:
        if hell is None:
            vorschau = ('<span style="display:flex;height:44px;border-radius:6px;overflow:hidden;border:1px solid var(--rand)">'
                        '<span style="flex:1;background:#ffffff"></span><span style="flex:1;background:#0b1120"></span></span>')
        else:
            g, f, t = ("#ffffff", "#eceff3", "#1c2534") if hell else ("#0b1120", "#1a2438", "#e5e9f0")
            vorschau = (f'<span style="display:flex;flex-direction:column;gap:4px;justify-content:center;padding:0 8px;height:44px;border-radius:6px;background:{g};border:1px solid var(--rand)">'
                        f'<i style="height:6px;width:60%;background:{t};border-radius:3px;opacity:.8"></i><i style="height:6px;width:80%;background:{f};border-radius:3px"></i></span>')
        return (f'<div class="mk-wahlkarte{" mk-aktiv" if aktiv else ""}">{vorschau}<span class="mk-zeile" style="gap:6px">'
                f'<span class="mk-radio{" mk-an" if aktiv else ""}" style="margin:0"></span>{ic(symbol, "mk-ic-14")}{name}</span></div>')

    def dichte(name: str, px: int, aktiv: bool) -> str:
        zeilen = "".join(f'<span style="height:{px // 2}px">Zeile {i}</span>' for i in range(1, 4))
        return (f'<div class="mk-wahlkarte{" mk-aktiv" if aktiv else ""}"><span class="mk-dichte-muster">{zeilen}</span>'
                f'<span class="mk-zeile" style="gap:6px"><span class="mk-radio{" mk-an" if aktiv else ""}" style="margin:0"></span>{name}</span></div>')
    return (
        '<div class="mk-feld"><span class="mk-feld-titel">Farbschema</span>'
        '<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px">'
        + schema("Hell", "sun", False, True) + schema("Dunkel", "moon", False, False) + schema("System", "monitor", True, None)
        + '</div><span class="mk-hilfe">Folgt jetzt dem System: hell.</span></div>'
        '<div class="mk-feld"><span class="mk-feld-titel">Dichte im Raster</span>'
        '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px">'
        + dichte("Standard · 32 px", 32, True) + dichte("Kompakt · 28 px", 28, False)
        + '</div></div>'
        f'<div class="mk-feld"><span class="mk-feld-titel">Sprache</span>{segment(["Deutsch", "English"], 0)}</div>'
    )


def konto_karte() -> str:
    return (
        '<section class="mk-karte mk-karte-inhalt mk-stapel mk-luecke-12" style="padding:16px">'
        '<div class="mk-zeile" style="gap:12px"><span class="mk-avatar" style="width:44px;height:44px;font-size:15px">HN</span>'
        '<span class="mk-stapel"><b style="font-size:16px">Hoang Nguyen</b><span class="mk-zweit">hoang@nguyen-development.ch</span></span></div>'
        '<div class="mk-zeile-wrap"><span class="mk-schild mk-s-akzent">Administrator</span><span class="mk-hilfe">Konto seit 21.10.2026</span></div>'
        + hinweis('<span>Name und E-Mail-Adresse ändert eine Administratorin oder ein Administrator unter <a>Verwaltung</a>. '
                  'Es gibt keine eigene Profilseite.</span>', "info")
        + '</section>'
    )


def b13_desktop(menue_offen: bool = False) -> str:
    links = (
        '<div class="mk-stapel mk-luecke-16 mk-voll">'
        f'<section class="mk-karte"><div class="mk-karte-kopf">{ic("key")}<h2>Passkeys</h2><span class="mk-zaehler" style="margin-left:0">3</span>'
        f'<span class="mk-rechts-weg">{knopf("Passkey hinzufügen", "primaer klein", "plus")}</span></div>'
        f'{passkey_liste(False)}<div class="mk-hilfe" style="padding:10px 16px;border-top:1px solid var(--rand);background:var(--flaeche);border-radius:0 0 10px 10px">'
        'Ohne Passkey melden Sie sich mit einem Code an hoang@nguyen-development.ch an. Einen Passwort-Weg gibt es nicht.</div></section>'
        f'<section class="mk-karte"><div class="mk-karte-kopf">{ic("monitor")}<h2>Sitzungen</h2><span class="mk-zaehler" style="margin-left:0">3</span>'
        f'<span class="mk-rechts-weg">{knopf("Alle anderen beenden", "klein", "logout")}</span></div>{sitzung_liste(False)}</section>'
        '</div>'
    )
    rechts = (
        '<div class="mk-stapel mk-luecke-16" style="width:420px;flex-shrink:0">'
        f'{konto_karte()}'
        f'<section class="mk-karte"><div class="mk-karte-kopf">{ic("sun")}<h2>Darstellung</h2></div>'
        f'<div class="mk-karte-inhalt mk-stapel mk-luecke-16" style="padding:16px">{darstellung(False)}</div></section>'
        '</div>'
    )
    seite_inhalt = (
        '<div style="position:absolute;top:52px;left:0;right:0;bottom:0;padding:24px 32px;display:flex;flex-direction:column;gap:20px;overflow:hidden">'
        '<div class="mk-zeile"><div class="mk-stapel mk-luecke-4"><h1 class="mk-titel-1">Konto</h1>'
        '<p class="mk-zweit">Sicherheit und Darstellung für Ihr Konto</p></div>'
        f'<span class="mk-rechts-weg">{knopf("Abmelden", "", "logout")}</span></div>'
        f'<div style="display:flex;gap:24px;align-items:flex-start">{links}{rechts}</div></div>'
    )
    menue = (
        '<div class="mk-menue" style="right:16px;top:48px;width:260px">'
        '<div class="mk-stapel" style="padding:8px 10px 10px;border-bottom:1px solid var(--rand);margin-bottom:4px"><b>Hoang Nguyen</b><span class="mk-hilfe">hoang@nguyen-development.ch</span></div>'
        f'<div class="mk-menue-e mk-aktiv">{ic("shield")}Sicherheit</div><div class="mk-menue-e">{ic("sun")}Darstellung</div>'
        f'<div class="mk-menue-e">{ic("users")}Verwaltung</div>'
        f'<div class="mk-menue-e" style="border-top:1px solid var(--rand);margin-top:4px;border-radius:0">{ic("logout")}Abmelden</div></div>'
    )
    if menue_offen:
        return b03_verbindungen(False) + menue
    return kopf() + seite_inhalt


def b13_mobil() -> str:
    inhalt = (
        '<div class="mk-stapel mk-luecke-4"><h1 class="mk-titel-1">Konto</h1></div>'
        + konto_karte()
        + f'<div>{segment(["Sicherheit", "Darstellung"], 0)}</div>'
        + f'<section class="mk-karte"><div class="mk-karte-kopf">{ic("key")}<h2>Passkeys</h2><span class="mk-zaehler" style="margin-left:0">3</span></div>'
          f'{passkey_liste(True)}<div style="padding:12px 16px;border-top:1px solid var(--rand)">{knopf("Passkey hinzufügen", "primaer breit", "plus")}</div></section>'
        + f'<section class="mk-karte"><div class="mk-karte-kopf">{ic("monitor")}<h2>Sitzungen</h2></div>{sitzung_liste(True)}</section>'
    )
    return mkopf(marke()) + f'<main class="mk-minhalt" style="gap:12px">{inhalt}{kante_unten()}</main>'


# ===================================================================================================
# Bereich 14 — Verwaltung: Konten, Einladungen, Rechte, Protokoll
# ===================================================================================================

KONTEN = [
    ("HN", "Hoang Nguyen", "hoang@nguyen-development.ch", "Administrator", "aktiv jetzt", False),
    ("NB", "Nina Brügger", "nina.bruegger@kunde-shop.ch", "Mitglied", "zuletzt gestern 16:40", True),
    ("RI", "Reto Imhof", "reto.imhof@kunde-shop.ch", "Mitglied", "zuletzt 20.10.2026", False),
    ("SK", "Sandra Kälin", "sandra.kaelin@kunde-shop.ch", "eingeladen", "läuft ab 04.11.2026", False),
]


def konten_liste() -> str:
    z = []
    for kurz, name, mail, rolle, zeit, aktiv in KONTEN:
        schild_art = {"Administrator": "mk-s-akzent", "eingeladen": "mk-s-warnung"}.get(rolle, "")
        z.append(f'<div class="mk-person{" mk-aktiv" if aktiv else ""}"><span class="mk-avatar">{kurz}</span>'
                 f'<span class="mk-stapel mk-voll"><span class="mk-zeile" style="gap:6px"><b class="mk-abschneiden">{name}</b>'
                 f'<span class="mk-schild {schild_art}">{rolle}</span></span><span class="mk-hilfe mk-abschneiden">{mail}</span>'
                 f'<span class="mk-hilfe">{zeit}</span></span></div>')
    return "".join(z)


def rechte_zeile(chip_html: str, drv: str, recht: int | None, hilfe: str = "") -> str:
    if recht is None:
        r = '<span class="mk-hilfe">persönlich — gehört Hoang Nguyen, nicht geteilt</span>'
    else:
        teile = ["kein Zugriff", "lesen", "schreiben", "verwalten"]
        r = '<span class="mk-rechte">' + "".join(f'<span class="{"mk-aktiv" if i == recht else ""}">{t}</span>' for i, t in enumerate(teile)) + '</span>'
    h = f'<br><span class="mk-hilfe">{hilfe}</span>' if hilfe else ""
    return f'<tr><td>{chip_html}{h}</td><td>{treiber(drv)}</td><td>{r}</td></tr>'


def b14_desktop() -> str:
    rechte = (
        '<table class="mk-tabelle"><thead><tr><th>Verbindung</th><th>Treiber</th><th>Recht von Nina Brügger</th></tr></thead><tbody>'
        + rechte_zeile(chip("prod", "shop", pfeil=False, klein=True, schloss=True), "MySQL 8.4", 1,
                       "Schreibgeschützt: auch mit «schreiben» erst nach Aufheben auf Zeit")
        + rechte_zeile(chip("test", "shop", pfeil=False, klein=True), "MySQL 8.4", 2)
        + rechte_zeile(chip("lokal", "basalto", pfeil=False, klein=True), "MariaDB 10.11", None)
        + rechte_zeile(chip("lokal", "fristo", pfeil=False, klein=True), "PostgreSQL 18", None)
        + rechte_zeile(chip("lokal", "notizen.sqlite", pfeil=False, klein=True), "SQLite 3.46", None)
        + '</tbody></table>'
    )
    erklaerung = (
        '<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:12px 16px;border-top:1px solid var(--rand);background:var(--flaeche);border-radius:0 0 10px 10px">'
        '<span class="mk-stapel"><b>lesen</b><span class="mk-hilfe">Daten und Struktur ansehen, SQL nur lesend, exportieren</span></span>'
        '<span class="mk-stapel"><b>schreiben</b><span class="mk-hilfe">zusätzlich Daten und Struktur ändern, importieren</span></span>'
        '<span class="mk-stapel"><b>verwalten</b><span class="mk-hilfe">zusätzlich Verbindung bearbeiten und mit anderen teilen</span></span></div>'
    )
    detail = (
        '<div class="mk-stapel mk-luecke-16 mk-voll">'
        '<section class="mk-karte mk-karte-inhalt" style="padding:16px">'
        '<div class="mk-zeile" style="gap:12px"><span class="mk-avatar" style="width:44px;height:44px;font-size:15px">NB</span>'
        '<span class="mk-stapel mk-voll"><b style="font-size:16px">Nina Brügger</b><span class="mk-zweit">nina.bruegger@kunde-shop.ch · zuletzt angemeldet gestern 16:40</span></span>'
        f'<span class="mk-zeile">{knopf("Name und E-Mail ändern", "klein", "pencil")}<span class="mk-knopf mk-klein">Rolle: Mitglied{ic("chevron-down", "mk-ic-14")}</span>'
        f'{knopf("Sperren", "klein", "lock")}{knopf("Entfernen", "klein gefahr-rand", "trash")}</span></div></section>'
        f'<section class="mk-karte"><div class="mk-karte-kopf">{ic("shield")}<h2>Rechte je Verbindung</h2>'
        '<span class="mk-hilfe">Administratoren haben überall alle Rechte</span>'
        f'<span class="mk-rechts-weg">{knopf("Speichern", "primaer klein")}</span></div>{rechte}{erklaerung}</section>'
        '</div>'
    )
    links = (
        '<div class="mk-stapel mk-luecke-8" style="width:340px;flex-shrink:0">'
        f'<div class="mk-eingabe">{ic("search", "mk-ic-14")}<span class="mk-platzhalter">Konten suchen</span></div>'
        f'<div class="mk-karte" style="padding:4px">{konten_liste()}</div>'
        '<p class="mk-hilfe" style="padding:0 4px">Das erste Konto entsteht mit <code>php artisan phpdbadmin:admin</code>; alle weiteren per Einladung.</p>'
        '</div>'
    )
    return kopf() + verwaltung_rahmen("Konten", f'<div style="display:flex;gap:24px;align-items:flex-start">{links}{detail}</div>')


def verwaltung_rahmen(aktiv: str, inhalt: str) -> str:
    reiter = "".join(f'<span class="mk-ansicht{" mk-aktiv" if n == aktiv else ""}">{ic(s, "mk-ic-14")}{n}<span class="mk-zaehler" style="margin-left:2px">{z}</span></span>'
                     for n, s, z in (("Konten", "users", "4"), ("Einladungen", "send", "1"), ("Protokoll", "file-text", "")))
    return (
        '<div style="position:absolute;top:52px;left:0;right:0;bottom:0;padding:24px 32px 0;display:flex;flex-direction:column;gap:16px;overflow:hidden">'
        '<div class="mk-zeile"><div class="mk-stapel mk-luecke-4"><h1 class="mk-titel-1">Verwaltung</h1>'
        '<p class="mk-zweit">Konten, Einladungen und Rechte dieser Installation · nur für Administratoren</p></div>'
        f'<span class="mk-rechts-weg">{knopf("Person einladen", "primaer", "send")}</span></div>'
        f'<div class="mk-ansichten" style="padding:0">{reiter}</div>{inhalt}</div>'
    )


PROTOKOLL = [
    ("28.10. 10:33:12", "Hoang Nguyen", ("prod", "shop"), "Daten geändert", "UPDATE `orders` SET `status` = ? WHERE `id` = ?", "1", "ok"),
    ("28.10. 10:33:12", "Hoang Nguyen", ("prod", "shop"), "Daten geändert", "DELETE FROM `orders` WHERE `id` = ?", "1 + 2", "ok"),
    ("28.10. 10:31:40", "Hoang Nguyen", ("prod", "shop"), "Schreibschutz aufgehoben", "bis 10:45, Grund: «Testbestellung löschen»", "—", "ok"),
    ("28.10. 10:12:05", "Nina Brügger", ("test", "shop"), "Struktur geändert", "ALTER TABLE `orders` ADD `tracking_number` …", "—", "ok"),
    ("28.10. 09:58:21", "Hoang Nguyen", ("prod", "shop"), "Rückgängig", "UPDATE `orders` SET `status` = ? WHERE `id` = ?", "1", "ok"),
    ("28.10. 09:40:03", "Reto Imhof", ("test", "shop"), "Import", "INSERT INTO `customers` (…) VALUES (?, …) · 309 Zeilen", "309", "ok"),
    ("28.10. 09:02:44", "Hoang Nguyen", None, "Anmeldung", "Passkey «MacBook Pro — Touch ID»", "—", "ok"),
    ("28.10. 08:51:19", "unbekannt · a3f9…c2", None, "Anmeldung", "Code falsch (2. Versuch)", "—", "nok"),
    ("28.10. 08:47:30", "Nina Brügger", ("prod", "shop"), "Schreibversuch", "UPDATE `customers` SET `city` = ? WHERE `id` = ?", "0", "gesperrt"),
    ("27.10. 17:12:09", "Reto Imhof", ("test", "shop"), "Export", "customers.json · 9'870 Zeilen", "9'870", "ok"),
]


def b14_protokoll() -> str:
    filter_z = (
        '<div class="mk-zeile-wrap">'
        f'<span class="mk-knopf mk-klein">{ic("calendar", "mk-ic-14")}Letzte 7 Tage{ic("chevron-down", "mk-ic-14")}</span>'
        f'<span class="mk-knopf mk-klein">Konto: alle{ic("chevron-down", "mk-ic-14")}</span>'
        f'<span class="mk-knopf mk-klein">Verbindung: alle{ic("chevron-down", "mk-ic-14")}</span>'
        f'<span class="mk-knopf mk-klein">Art: alle{ic("chevron-down", "mk-ic-14")}</span>'
        f'<span class="mk-eingabe" style="min-height:28px;width:300px;font-size:13px">{ic("search", "mk-ic-14")}<span class="mk-platzhalter mk-nowrap">Anweisung oder Tabelle suchen</span></span>'
        f'<span class="mk-rechts-weg mk-zeile">{knopf("Als CSV exportieren", "klein", "download")}</span></div>'
    )
    zeilen = []
    for zeit, konto, verb, art, sql, n, erg in PROTOKOLL:
        v = chip(verb[0], verb[1], pfeil=False, klein=True) if verb else '<span class="mk-leise">—</span>'
        e = {"ok": f'<span class="mk-erfolg-text mk-zeile" style="gap:4px">{ic("ok", "mk-ic-14")}ausgeführt</span>',
             "nok": f'<span class="mk-gefahr-text mk-zeile" style="gap:4px">{ic("nok", "mk-ic-14")}abgelehnt</span>',
             "gesperrt": f'<span class="mk-gefahr-text mk-zeile" style="gap:4px">{ic("lock", "mk-ic-14")}schreibgeschützt</span>'}[erg]
        sql_html = sql_hervorheben(sql) if any(w in sql for w in ("UPDATE", "DELETE", "ALTER", "INSERT")) else esc(sql)
        zeilen.append(f'<tr><td class="mk-zahl mk-nowrap">{zeit}</td><td class="mk-nowrap">{esc(konto)}</td><td>{v}</td><td>{art}</td>'
                      f'<td><code style="font-size:12px">{sql_html}</code></td><td class="mk-r mk-zahl">{n}</td><td class="mk-nowrap">{e}</td></tr>')
    tabelle = ('<section class="mk-karte" style="overflow:hidden"><table class="mk-tabelle mk-eng"><thead><tr><th>Zeit</th><th>Konto</th><th>Verbindung</th><th>Art</th>'
               f'<th>Anweisung (Werte als ?)</th><th class="mk-r">Zeilen</th><th>Ergebnis</th></tr></thead><tbody>{"".join(zeilen)}</tbody></table></section>')
    hinweis_text = hinweis('<span>Jede schreibende Aktion steht hier genau einmal — mit wer, wann, Verbindung, SQL und betroffenen Zeilen. '
                           'Werte erscheinen nie, nur Platzhalter. Einträge werden nach <b>365 Tagen</b> gelöscht (Einstellung der Installation). '
                           'Fehlversuche bei der Anmeldung stehen mit gehashter Adresse.</span>', "info", "shield")
    mehr = (f'<div class="mk-zeile mk-hilfe" style="justify-content:center">10 von 214 Einträgen der letzten 7 Tage · '
            f'<a>ältere laden</a></div>')
    inhalt = f'<div class="mk-stapel mk-luecke-12" style="position:relative;flex:1;min-height:0;overflow:hidden">{filter_z}{hinweis_text}{tabelle}{mehr}</div>'
    return kopf() + verwaltung_rahmen("Protokoll", inhalt)


def b14_mobil() -> str:
    karten = []
    for kurz, name, mail, rolle, zeit, _ in KONTEN:
        schild_art = {"Administrator": "mk-s-akzent", "eingeladen": "mk-s-warnung"}.get(rolle, "")
        unten = ""
        if rolle == "Mitglied" and kurz == "NB":
            unten = (f'<div class="mk-zeile-wrap" style="gap:6px 12px"><span class="mk-zeile mk-nowrap" style="gap:6px">{chip("prod", "shop", pfeil=False, klein=True)}<span class="mk-hilfe">lesen</span></span>'
                     f'<span class="mk-zeile mk-nowrap" style="gap:6px">{chip("test", "shop", pfeil=False, klein=True)}<span class="mk-hilfe">schreiben</span></span></div>')
        if rolle == "eingeladen":
            unten = f'<div class="mk-zeile">{knopf("Erneut senden", "klein", "send")}{knopf("Zurückziehen", "klein leise")}</div>'
        karten.append(f'<article class="mk-mkarte"><div class="mk-zeile" style="gap:10px"><span class="mk-avatar">{kurz}</span>'
                      f'<span class="mk-stapel mk-voll"><span class="mk-zeile-wrap" style="gap:6px"><b>{name}</b><span class="mk-schild {schild_art}">{rolle}</span></span>'
                      f'<span class="mk-hilfe">{mail}</span><span class="mk-hilfe">{zeit}</span></span>{ic("chevron-right", "mk-leise")}</div>{unten}</article>')
    inhalt = (
        '<div class="mk-stapel mk-luecke-4"><h1 class="mk-titel-1">Verwaltung</h1><p class="mk-zweit">nur für Administratoren</p></div>'
        f'{knopf("Person einladen", "primaer breit", "send")}'
        f'<div>{segment(["Konten 4", "Einladungen 1", "Protokoll"], 0)}</div>'
        + "".join(karten)
    )
    return mkopf(marke()) + f'<main class="mk-minhalt" style="gap:12px">{inhalt}</main>'


# @@ weitere Bereiche @@


# ===================================================================================================
# Verzeichnis der Bereiche
# ===================================================================================================

@dataclass
class Variante:
    geraet: str  # "desktop" | "mobil"
    bauen: Callable[[], str]
    zusatz: str = ""  # z. B. "dunkel", "abgelaufen"
    dunkel: bool = False
    beschreibung: str = ""


@dataclass
class Bereich:
    nr: int
    kurz: str
    titel: str
    inhalt: str
    anforderungen: str
    varianten: list[Variante] = field(default_factory=list)
    seitentitel: str = ""


def bereiche() -> list[Bereich]:
    return [
        Bereich(1, "anmeldung", "Anmeldung",
                "E-Mail-Adresse und «Mit Passkey anmelden»; Passkey ist der Hauptweg, der Code der Weg für neue Geräte. "
                "Kein Passwort, keine Registrierung.",
                "A-01, A-02, A-03, A-06",
                [Variante("desktop", lambda: b01_anmeldung(False)), Variante("mobil", lambda: b01_anmeldung(True))],
                "Anmelden — phpdbadmin"),
        Bereich(2, "code", "Code eingeben",
                "Ein Feld für sechs Ziffern (Einfügen aus der Mail), «Code erneut senden» mit Wartezeit, Fehlerzustand «Code abgelaufen».",
                "A-02",
                [Variante("desktop", lambda: b02_code(False)),
                 Variante("mobil", lambda: b02_code(True, abgelaufen=True), beschreibung="Fehlerzustand «Code abgelaufen»"),
                 Variante("desktop", lambda: b02_code(False, abgelaufen=True), "abgelaufen", beschreibung="Fehlerzustand «Code abgelaufen»")],
                "Code eingeben — phpdbadmin"),
        Bereich(3, "verbindungen", "Startseite: Verbindungen",
                "Statt Dashboard die Verbindungsübersicht: Gruppen, Umgebung mit Symbol und Wort, Treiber-Schild, Schloss bei Schreibschutz, "
                "Favoriten, Kontowarnung an «lokal · basalto», «Verbindung anlegen», zuletzt Geöffnetes.",
                "A-04, V-04, V-06, V-10",
                [Variante("desktop", lambda: b03_verbindungen(False)), Variante("mobil", lambda: b03_verbindungen(True))],
                "Verbindungen — phpdbadmin"),
        Bereich(4, "verbindung-anlegen", "Verbindung anlegen",
                "Treiber als vier Kacheln, Felder je Treiber, Umgebung, Schreibschutz, «Passwort nicht speichern», Test in Schritten. "
                "Desktop: MySQL auf Produktion mit Fehler «Zugang verweigert (1045)»; Mobil: SQLite-Datei im erlaubten Ordner, Test erfolgreich.",
                "V-01, V-02, V-03, V-04, V-05",
                [Variante("desktop", lambda: b04_verbindung_anlegen(False)), Variante("mobil", lambda: b04_verbindung_anlegen(True))],
                "Verbindung anlegen — phpdbadmin"),
        Bereich(5, "arbeitsbildschirm", "Arbeitsbildschirm Daten",
                "Kopfleiste mit Produktions-Chip und Schreibfreigabe auf Zeit, Befehlspalette-Feld, Navigator, offene Reiter mit Umgebung, "
                "Ansichten Daten · Struktur · Beziehungen · SQL, Filter als Chips, Raster mit geänderten Zellen, neuer Zeile, "
                "Zeile zum Löschen, NULL und Fremdschlüssel-Pfeil, Änderungsleiste und «Rückgängig» der letzten Anwendung.",
                "O-01, O-02, O-03, O-04, D-01, D-02, D-03, D-04, D-06, D-09, V-04",
                [Variante("desktop", b05_desktop),
                 Variante("mobil", lambda: b05_mobil(), beschreibung="Raster als Kartenliste, Änderungsleiste unten"),
                 Variante("desktop", b05_desktop, "dunkel", dunkel=True, beschreibung="Dunkelmodus"),
                 Variante("mobil", lambda: b05_mobil(), "dunkel", dunkel=True, beschreibung="Dunkelmodus"),
                 Variante("mobil", lambda: b05_mobil(navigator_offen=True), "navigator", beschreibung="Navigator als Schublade")],
                "PROD · orders · shop — phpdbadmin"),
        Bereich(6, "sql-vorschau", "SQL-Vorschau vor dem Anwenden",
                "Schublade mit jeder Anweisung, gezählten Zeilen und Folgen (ON DELETE CASCADE), Hinweis je Treiber "
                "(MySQL/InnoDB: in einer Transaktion) und Bestätigung mit Namenseingabe auf Produktion. "
                "Desktop: Name halb getippt, Knopf noch aus; Mobil: Name stimmt, Knopf aktiv.",
                "D-04, D-09, Q-07, N-03, P-02, P-08",
                [Variante("desktop", b06_desktop), Variante("mobil", b06_mobil)],
                "PROD · orders · shop — phpdbadmin"),
        Bereich(7, "zeilen-detail", "Zeilen-Detail-Schublade",
                "Alle Felder einer Zeile mit Typ, geänderter Wert mit Vorher-Wert, JSON formatiert und geprüft, NULL gegen leer, "
                "Binärwert mit Grösse und Herunterladen, Zeitzone, Verweise vor (customers) und zurück (order_items, payments, shipments).",
                "D-03, D-05",
                [Variante("desktop", b07_desktop), Variante("mobil", b07_mobil)],
                "PROD · orders · shop — phpdbadmin"),
        Bereich(8, "struktur", "Struktur einer Tabelle",
                "Spalten mit Originaltyp und gemeinsamer Art, Indizes, Fremdschlüssel samt eingehender Verweise, Check-Constraints, "
                "Trigger und DDL zum Kopieren. Auf der schreibgeschützten Produktion ist «Tabelle ändern» gesperrt.",
                "S-01, O-04, V-04",
                [Variante("desktop", b08_desktop), Variante("mobil", b08_mobil)],
                "PROD · orders · shop — phpdbadmin"),
        Bereich(9, "tabelle-aendern", "Tabelle ändern mit Vorschau",
                "Formular mit neuer, umbenannter und gelöschter Spalte und neuem Index; daneben das erzeugte SQL. "
                "MySQL: «nicht rückrollbar — Anweisung für Anweisung»; Spalte löschen verlangt den Tabellennamen. "
                "Zusatzbild PostgreSQL: gleiche Oberfläche, Hinweis «in einer Transaktion».",
                "S-03, S-04, N-03, O-04",
                [Variante("desktop", lambda: b09_desktop("mysql")), Variante("mobil", b09_mobil),
                 Variante("desktop", lambda: b09_desktop("pg"), "postgresql", beschreibung="gleiche Änderung auf PostgreSQL")],
                "TEST · orders · shop — phpdbadmin"),
        Bereich(10, "sql-editor", "SQL-Editor",
                "Editor mit Hervorhebung und Vervollständigung aus dem Schema, Ausführen (aktuelle Anweisung, Auswahl, alles), "
                "Ergebnisse als Reiter mit Zeilen und Dauer, Zustand «abgebrochen nach 30 s» (MySQL meldet es nicht selbst), "
                "Gespeichertes und Verlauf rechts.",
                "Q-01, Q-02, Q-03, Q-04, Q-09, O-05",
                [Variante("desktop", lambda: b10_desktop(False)),
                 Variante("mobil", b10_mobil, beschreibung="Zustand «abgebrochen nach 30 s»"),
                 Variante("desktop", lambda: b10_desktop(True), "dunkel", dunkel=True, beschreibung="Dunkelmodus, Zustand «abgebrochen»")],
                "TEST · Abfrage 1 · shop — phpdbadmin"),
        Bereich(11, "export-import", "Export und Import",
                "CSV-Import in customers mit Spaltenzuordnung und Probelauf (nichts geschrieben, 3 Fehlerzeilen in Klartext, "
                "Statistik danach aktualisieren). Zusatzbild: Export-Dialog CSV/JSON/SQL mit Aufträgen im Hintergrund und Fortschritt.",
                "E-01, E-02, E-06, B-07",
                [Variante("desktop", b11_import_desktop),
                 Variante("mobil", b11_mobil, beschreibung="Export als Blatt, Aufträge im Hintergrund"),
                 Variante("desktop", b11_export_desktop, "export", beschreibung="Export-Dialog mit Fortschritt im Hintergrund")],
                "TEST · Import · customers · shop — phpdbadmin"),
        Bereich(12, "befehlspalette", "Befehlspalette ⌘K",
                "Über dem Arbeitsbildschirm: Treffer gruppiert nach Objekten der aktuellen Verbindung, anderen Verbindungen (mit Umgebung) "
                "und Aktionen; Tastatur-Hinweise unten. Mobil als ganze Seite.",
                "O-02, O-05",
                [Variante("desktop", b12_desktop), Variante("mobil", b12_mobil)],
                "PROD · orders · shop — phpdbadmin"),
        Bereich(13, "konto", "Konto: Sicherheit und Darstellung",
                "Passkeys mit Name, Datum und letzter Nutzung, Sitzungen mit «Beenden», Farbschema, Dichte und Sprache; "
                "Name und E-Mail nur lesend (keine Profilseite). Zusatzbild: Kontomenü.",
                "A-03, A-05, A-08, O-06",
                [Variante("desktop", b13_desktop), Variante("mobil", b13_mobil),
                 Variante("desktop", lambda: b13_desktop(True), "menue", beschreibung="Kontomenü über der Startseite: Sicherheit, Darstellung, Verwaltung, Abmelden")],
                "Konto — phpdbadmin"),
        Bereich(14, "verwaltung", "Verwaltung: Konten, Rechte, Protokoll",
                "Konten und Einladungen, Rechte je Verbindung (kein Zugriff, lesen, schreiben, verwalten), persönliche Verbindungen "
                "nicht teilbar. Zusatzbild: Protokoll aller schreibenden Aktionen mit maskierten Werten und Aufbewahrungsfrist.",
                "A-06, A-07, A-09, V-09, B-04",
                [Variante("desktop", b14_desktop), Variante("mobil", b14_mobil),
                 Variante("desktop", b14_protokoll, "protokoll", beschreibung="Protokoll")],
                "Verwaltung — phpdbadmin"),
    ]


# ===================================================================================================
# Bauen, Aufnehmen, Übersicht
# ===================================================================================================

def dateiname(b: Bereich, v: Variante) -> str:
    teile = [f"{b.nr:02d}", b.kurz, v.geraet]
    if v.zusatz:
        teile.append(v.zusatz)
    return "-".join(teile)


def seiten_schreiben(liste: list[Bereich]) -> list[tuple[Path, Path, str]]:
    SEITEN.mkdir(exist_ok=True)
    auftraege = []
    for b in liste:
        for v in b.varianten:
            name = dateiname(b, v)
            ziel = SEITEN / f"{name}.html"
            titel = f"{b.nr:02d} {b.titel} · {v.geraet}{' · ' + v.zusatz if v.zusatz else ''} — {b.seitentitel or 'phpdbadmin'}"
            ziel.write_text(seite(titel, v.bauen(), v.geraet, v.dunkel), encoding="utf-8")
            auftraege.append((ziel, BILDER / f"{name}.png", v.geraet))
    return auftraege


def aufnehmen(auftrag: tuple[Path, Path, str]) -> str:
    quelle, ziel, geraet = auftrag
    breite, hoehe = DESKTOP if geraet == "desktop" else (MOBIL_FENSTER, MOBIL[1])
    roh = ziel if geraet == "desktop" else ziel.with_suffix(".roh.png")
    befehl = [CHROME, "--headless=new", "--disable-gpu", "--hide-scrollbars", "--no-first-run",
              "--no-default-browser-check", "--force-device-scale-factor=1", f"--screenshot={roh}",
              f"--window-size={breite},{hoehe}", quelle.as_uri()]
    subprocess.run(befehl, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=90)
    if geraet == "mobil":
        from PIL import Image  # nur hier gebraucht
        with Image.open(roh) as bild:
            bild.crop((0, 0, MOBIL[0], MOBIL[1])).save(ziel, optimize=True)
        roh.unlink()
    return ziel.name


INDEX_CSS = """
body{margin:0;font-family:Inter,system-ui,-apple-system,Helvetica,Arial,sans-serif;font-size:14px;line-height:20px;color:#1c2534;background:#f6f7f9}
.ix-kopf{padding:32px 40px 8px;max-width:1500px;margin:0 auto}
.ix-kopf h1{font-size:24px;line-height:32px;margin:0 0 4px}
.ix-kopf p{margin:0 0 4px;color:#4a5568;max-width:900px}
.ix-marke{font-family:"JetBrains Mono",Menlo,monospace}.ix-marke b{color:#0b6e82}
.ix-liste{max-width:1500px;margin:0 auto;padding:16px 40px 48px;display:flex;flex-direction:column;gap:24px}
.ix-bereich{background:#fff;border:1px solid #dde2e8;border-radius:10px;padding:20px 24px}
.ix-bereich h2{font-size:18px;line-height:24px;margin:0;display:flex;gap:12px;align-items:baseline;flex-wrap:wrap}
.ix-nr{font-family:"JetBrains Mono",Menlo,monospace;color:#0b6e82}
.ix-anf{font-size:12px;color:#4a5568;border:1px solid #dde2e8;border-radius:999px;padding:1px 8px;font-weight:500}
.ix-text{color:#4a5568;margin:6px 0 14px;max-width:1100px}
.ix-bilder{display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap}
.ix-bild{margin:0;display:flex;flex-direction:column;gap:6px}
.ix-bild img{display:block;border:1px solid #dde2e8;border-radius:6px;background:#fff}
.ix-bild figcaption{font-size:12px;color:#4a5568}
.ix-bild a{color:#0b6e82}
.ix-d img{width:880px;max-width:100%}
.ix-m img{width:240px}
.ix-zusatz img{width:430px}
"""


def index_schreiben(liste: list[Bereich]) -> None:
    abschnitte = []
    for b in liste:
        bilder = []
        for v in b.varianten:
            name = dateiname(b, v)
            klasse = "ix-d" if v.geraet == "desktop" and not v.zusatz else ("ix-m" if v.geraet == "mobil" else "ix-zusatz")
            if v.geraet == "mobil" and v.zusatz:
                klasse = "ix-m"
            text = f"{'Desktop' if v.geraet == 'desktop' else 'Mobil'}{' · ' + v.zusatz if v.zusatz else ''}"
            if v.beschreibung:
                text += f" — {esc(v.beschreibung)}"
            bilder.append(
                f'<figure class="ix-bild {klasse}"><a href="seiten/{name}.html"><img src="bilder/{name}.png" '
                f'alt="{esc(b.titel)}, {text}" loading="lazy"></a><figcaption>{text} · '
                f'<a href="bilder/{name}.png">PNG</a> · <a href="seiten/{name}.html">HTML</a></figcaption></figure>')
        abschnitte.append(
            f'<section class="ix-bereich" id="b{b.nr:02d}"><h2><span class="ix-nr">{b.nr:02d}</span>{esc(b.titel)}'
            f'<span class="ix-anf">{esc(b.anforderungen)}</span></h2><p class="ix-text">{esc(b.inhalt)}</p>'
            f'<div class="ix-bilder">{"".join(bilder)}</div></section>')
    inhalt = (
        '<!doctype html>\n<html lang="de-CH"><head><meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width, initial-scale=1">'
        f'<title>Wireframes — phpdbadmin</title><style>{INDEX_CSS}</style></head><body>'
        '<header class="ix-kopf"><h1>Wireframes — <span class="ix-marke">php<b>db</b>admin</span></h1>'
        f'<p>Stand 28.10.2026 · {len(liste)} Bereiche · Desktop 1440 × 900, Mobil 375 × 812 · Gestaltung nach '
        '<code>03-foundation/gestaltung.md</code>, Farben aus <code>werkzeuge/kontraste.py</code>. '
        'Daten erfunden. Erzeugt mit <code>python3 mockup-bauen.py</code>; Entscheide und offene Fragen im README.</p></header>'
        f'<main class="ix-liste">{"".join(abschnitte)}</main></body></html>\n'
    )
    (HIER / "index.html").write_text(inhalt, encoding="utf-8")


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description="Wireframes von phpdbadmin bauen")
    parser.add_argument("--ohne-bilder", action="store_true", help="nur HTML, keine Aufnahmen")
    parser.add_argument("--nur", help="nur diese Bereichsnummern, z. B. 05 oder 05,06")
    args = parser.parse_args(argv[1:])

    alle = bereiche()
    auswahl_liste = alle
    if args.nur:
        nummern = {int(n) for n in args.nur.split(",")}
        auswahl_liste = [b for b in alle if b.nr in nummern]
    auftraege = seiten_schreiben(auswahl_liste)
    index_schreiben(alle)
    print(f"{len(auftraege)} Seiten geschrieben, index.html mit {len(alle)} Bereichen")
    if args.ohne_bilder:
        return 0
    if not Path(CHROME).exists():
        print(f"Chrome fehlt: {CHROME}", file=sys.stderr)
        return 1
    BILDER.mkdir(exist_ok=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        for name in pool.map(aufnehmen, auftraege):
            print("  aufgenommen", name)
    if not args.nur:
        erwartet = {f"{dateiname(b, v)}.png" for b in alle for v in b.varianten}
        for alt in BILDER.glob("*.png"):
            if alt.name not in erwartet:
                alt.unlink()
                print("  entfernt (veraltet)", alt.name)
    print(f"{len(auftraege)} Bilder in {BILDER.relative_to(HIER)}/")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
