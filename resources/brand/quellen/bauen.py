#!/usr/bin/env python3
"""
Baut das Logo-Kit von phpdbadmin aus den Glyphen in glyphen.json (G-06).

Ergebnis: SVG-Dateien in resources/brand/, PNG-Exporte in resources/brand/png/, App-Symbole in public/
(favicon.svg, favicon.ico, apple-touch-icon.png), das Mail-Logo in resources/brand/mail/ und die Pfade der Bildmarke
für die Oberfläche in resources/js/lib/brand.ts.

Aufruf aus dem Projektordner:  python3 resources/brand/quellen/bauen.py
Braucht rsvg-convert (Homebrew: librsvg) und Pillow. Die Glyphen holt glyphen.swift aus JetBrains Mono — nur nötig,
wenn sich die Schrift ändert; glyphen.json liegt fertig bei.
"""

import io
import json
import re
import shutil
import subprocess
from pathlib import Path

from PIL import Image

QUELLEN = Path(__file__).resolve().parent
KIT = QUELLEN.parent
PROJEKT = KIT.parent.parent
PNG = KIT / 'png'
MAIL = KIT / 'mail'

# Farben aus gestaltung.md § 2 (hell / dunkel).
AKZENT = '#0b6e82'
AKZENT_DUNKEL = '#4fc3d9'
AKZENT_TEXT_DUNKEL = '#062a33'
TEXT = '#1c2534'
TEXT_DUNKEL = '#e5e9f0'
GRUND_DUNKEL = '#0b1120'
GEFAHR = '#b3261e'  # Umgebung Produktion (§ 3)
WEISS = '#ffffff'
SCHWARZ = '#000000'

GLYPHEN = json.loads((QUELLEN / 'glyphen.json').read_text())

ZAHL = re.compile(r'-?\d+(?:\.\d+)?')


def verschieben(pfad: str, dx: float, dy: float = 0.0) -> str:
    """Alle Koordinatenpaare eines Pfads (nur M, L, Q, C, Z) verschieben."""
    teile = re.findall(r'[MLQCZ]|-?\d+(?:\.\d+)?', pfad)
    aus, achse = [], 0
    for teil in teile:
        if teil in 'MLQCZ':
            aus.append(teil)
            achse = 0
        else:
            wert = float(teil) + (dx if achse % 2 == 0 else dy)
            aus.append(f'{wert:.2f}')
            achse += 1
    return re.sub(r' (?=[MLQCZ])', '', ' '.join(aus)).replace(' Z', 'Z')


def rahmen(*pfade: str) -> tuple[float, float, float, float]:
    """Grenzen über alle Stütz- und Kontrollpunkte — für Glyphen genau genug."""
    zahlen = [float(z) for pfad in pfade for z in ZAHL.findall(pfad)]
    xs, ys = zahlen[0::2], zahlen[1::2]
    return min(xs), min(ys), max(xs), max(ys)


# --- Bildmarke: «db» als Ligatur mit gemeinsamem Stamm (JetBrains Mono Bold) -----------------------------------

LIGATUR = GLYPHEN['ligatur']
LIGATUR_D = LIGATUR['d']
LIGATUR_B = verschieben(LIGATUR['b'], LIGATUR['stamm_d'][0] - LIGATUR['stamm_b'][0])
LX0, LY0, LX1, LY1 = rahmen(LIGATUR_D, LIGATUR_B)
MARKE = 64  # Kantenlänge der Bildmarke
RADIUS = 14
GLYPHE = 41  # grösste Ausdehnung der Ligatur in der Marke
GLYPHE_FAVICON = 46  # in 16–48 px braucht die Ligatur mehr Fläche


def ligatur_transform(groesse: float = MARKE, anteil: float = GLYPHE / MARKE) -> str:
    massstab = groesse * anteil / max(LX1 - LX0, LY1 - LY0)
    tx = groesse / 2 - (LX0 + (LX1 - LX0) / 2) * massstab
    ty = groesse / 2 - (LY0 + (LY1 - LY0) / 2) * massstab
    return f'translate({tx:.3f} {ty:.3f}) scale({massstab:.5f})'


def ligatur(farbe: str, groesse: float = MARKE, anteil: float = GLYPHE / MARKE) -> str:
    return (f'<g transform="{ligatur_transform(groesse, anteil)}" fill="{farbe}">'
            f'<path d="{LIGATUR_D}"/><path d="{LIGATUR_B}"/></g>')


def marke(flaeche: str, glyphe: str, kennung: str = 'm', randlos: bool = False, groesse: float = MARKE,
          anteil: float = GLYPHE / MARKE) -> str:
    """Bildmarke als Inhalt (ohne <svg>). glyphe='aus' stanzt die Ligatur aus der Fläche (einfarbig)."""
    rx = 0 if randlos else RADIUS * groesse / MARKE
    if glyphe == 'aus':
        return (f'<mask id="{kennung}"><rect width="{groesse}" height="{groesse}" rx="{rx}" fill="#fff"/>'
                f'{ligatur("#000", groesse, anteil)}</mask>'
                f'<rect width="{groesse}" height="{groesse}" rx="{rx}" fill="{flaeche}" mask="url(#{kennung})"/>')
    return (f'<rect width="{groesse}" height="{groesse}" rx="{rx}" fill="{flaeche}"/>'
            f'{ligatur(glyphe, groesse, anteil)}')


# --- Schriftzug: «phpdbadmin» in JetBrains Mono SemiBold, «db» im Akzent ---------------------------------------

SCHRIFT = GLYPHEN['schriftzug']
ZEICHEN = SCHRIFT['zeichen']
SX0, SY0, SX1, SY1 = rahmen(*(z['d'] for z in ZEICHEN if z['d']))


def schriftzug(text: str, akzent: str, dx: float = 0.0, dy: float = 0.0, massstab: float = 1.0) -> str:
    teile = []
    for index, zeichen in enumerate(ZEICHEN):
        farbe = akzent if index in (3, 4) else text
        teile.append(f'<path d="{zeichen["d"]}" fill="{farbe}"/>')
    return f'<g transform="translate({dx:.3f} {dy:.3f}) scale({massstab:.5f})">{"".join(teile)}</g>'


def svg(breite: float, hoehe: float, inhalt: str, titel: str) -> str:
    return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{breite:g}" height="{hoehe:g}" '
            f'viewBox="0 0 {breite:g} {hoehe:g}" role="img" aria-label="{titel}">'
            f'<title>{titel}</title>{inhalt}</svg>\n')


# Schriftzug allein: Rand 0, Grundlinie aus den Glyphen.
SCHRIFT_BREITE = SX1 - SX0
SCHRIFT_HOEHE = SY1 - SY0

# Logo quer: Marke 64, Abstand 16, Schriftzug mit x-Höhe = 0.36 × Marke; Mitte zwischen Oberlänge und Grundlinie
# auf der Mitte der Marke.
QUER_MASSSTAB = 0.36 * MARKE / SCHRIFT['x_hoehe']
QUER_ABSTAND = 16
QUER_GRUNDLINIE = MARKE / 2 - (SY0 * QUER_MASSSTAB) / 2
QUER_BREITE = MARKE + QUER_ABSTAND + SCHRIFT_BREITE * QUER_MASSSTAB

# Logo gestapelt: Marke oben, Schriftzug darunter, beide mittig.
STAPEL_MASSSTAB = 0.30 * MARKE / SCHRIFT['x_hoehe']
STAPEL_BREITE = max(MARKE, SCHRIFT_BREITE * STAPEL_MASSSTAB)
STAPEL_GRUNDLINIE = MARKE + 14 - SY0 * STAPEL_MASSSTAB
STAPEL_HOEHE = STAPEL_GRUNDLINIE + SY1 * STAPEL_MASSSTAB


def logo_quer(flaeche: str, glyphe: str, text: str, akzent: str, kennung: str) -> str:
    return svg(QUER_BREITE, MARKE,
               marke(flaeche, glyphe, kennung)
               + schriftzug(text, akzent, MARKE + QUER_ABSTAND - SX0 * QUER_MASSSTAB, QUER_GRUNDLINIE,
                            QUER_MASSSTAB),
               'phpdbadmin')


def logo_gestapelt(flaeche: str, glyphe: str, text: str, akzent: str, kennung: str) -> str:
    links = (STAPEL_BREITE - MARKE) / 2
    return svg(STAPEL_BREITE, STAPEL_HOEHE,
               f'<g transform="translate({links:.3f} 0)">{marke(flaeche, glyphe, kennung)}</g>'
               + schriftzug(text, akzent, (STAPEL_BREITE - SCHRIFT_BREITE * STAPEL_MASSSTAB) / 2
                            - SX0 * STAPEL_MASSSTAB, STAPEL_GRUNDLINIE, STAPEL_MASSSTAB),
               'phpdbadmin')


def nur_schriftzug(text: str, akzent: str) -> str:
    return svg(SCHRIFT_BREITE, SCHRIFT_HOEHE, schriftzug(text, akzent, -SX0, -SY0), 'phpdbadmin')


def nur_marke(flaeche: str, glyphe: str, kennung: str) -> str:
    return svg(MARKE, MARKE, marke(flaeche, glyphe, kennung), 'phpdbadmin')


VARIANTEN = {
    # Name: (Fläche der Marke, Glyphe, Schrift, Akzent im Schriftzug)
    '': (AKZENT, WEISS, TEXT, AKZENT),
    '-dunkel': (AKZENT_DUNKEL, AKZENT_TEXT_DUNKEL, TEXT_DUNKEL, AKZENT_DUNKEL),
    '-schwarz': (SCHWARZ, 'aus', SCHWARZ, SCHWARZ),
    '-weiss': (WEISS, 'aus', WEISS, WEISS),
}


def rastern(quelle: Path, ziel: Path, *, breite: int | None = None, hoehe: int | None = None) -> None:
    ziel.parent.mkdir(parents=True, exist_ok=True)
    befehl = ['rsvg-convert', str(quelle), '-o', str(ziel)]
    if breite:
        befehl += ['-w', str(breite)]
    if hoehe:
        befehl += ['-h', str(hoehe)]
    subprocess.run(befehl, check=True)


def schreiben(name: str, inhalt: str) -> Path:
    pfad = KIT / name
    pfad.write_text(inhalt)
    return pfad


def main() -> None:
    for alt in (PNG, MAIL):
        if alt.exists():
            shutil.rmtree(alt)
    for alt in KIT.glob('*.svg'):
        alt.unlink()

    for endung, (flaeche, glyphe, text, akzent) in VARIANTEN.items():
        kennung = 'aus' + endung.replace('-', '')
        dateien = {
            f'zeichen{endung}': schreiben(f'zeichen{endung}.svg', nur_marke(flaeche, glyphe, kennung)),
            f'schriftzug{endung}': schreiben(f'schriftzug{endung}.svg', nur_schriftzug(text, akzent)),
            f'logo{endung}': schreiben(f'logo{endung}.svg', logo_quer(flaeche, glyphe, text, akzent, kennung)),
            f'logo-gestapelt{endung}': schreiben(f'logo-gestapelt{endung}.svg',
                                                 logo_gestapelt(flaeche, glyphe, text, akzent, kennung)),
        }
        for name, quelle in dateien.items():
            if name.startswith('zeichen'):
                for groesse in (64, 256, 1024):
                    rastern(quelle, PNG / f'{name}-{groesse}.png', breite=groesse)
            else:
                for hoehe in (96, 384):
                    rastern(quelle, PNG / f'{name}-{hoehe}.png', hoehe=hoehe)

    # Ligatur allein, in der Schriftfarbe der Umgebung (für Inline-Gebrauch).
    schreiben('glyphe.svg', svg(MARKE, MARKE, ligatur('currentColor'), 'phpdbadmin'))

    # App-Symbole: Favicon als SVG und ICO, Apple-Symbol randlos (iOS rundet selbst), 192/512 für Web-Apps.
    favicon = schreiben('favicon.svg', svg(MARKE, MARKE, marke(AKZENT, WEISS, anteil=GLYPHE_FAVICON / MARKE),
                                           'phpdbadmin'))
    apple = KIT / 'quellen' / 'apple.svg'
    apple.write_text(svg(180, 180, marke(AKZENT, WEISS, randlos=True, groesse=180), 'phpdbadmin'))
    rastern(apple, PNG / 'apple-touch-icon.png', breite=180)
    apple.unlink()
    for groesse in (16, 32, 48, 192, 512):
        rastern(favicon, PNG / f'favicon-{groesse}.png', breite=groesse)
    ico = PNG / 'favicon.ico'
    bilder = [Image.open(PNG / f'favicon-{g}.png').convert('RGBA') for g in (16, 32, 48)]
    bilder[2].save(ico, format='ICO', sizes=[(16, 16), (32, 32), (48, 48)], append_images=bilder[:2])

    # Favicon der Umgebung Produktion (gestaltung.md § 3): dieselbe Marke auf Rot — der Tab warnt, solange eine
    # Verbindung dieser Umgebung offen ist (die App tauscht es, resources/js/composables/useEnvironmentIcon.ts).
    produktion = schreiben('favicon-produktion.svg', svg(MARKE, MARKE, marke(GEFAHR, WEISS,
                                                                            anteil=GLYPHE_FAVICON / MARKE),
                                                         'phpdbadmin · Produktion'))
    for groesse in (16, 32, 48):
        rastern(produktion, PNG / f'favicon-produktion-{groesse}.png', breite=groesse)
    ico_produktion = PNG / 'favicon-produktion.ico'
    bilder = [Image.open(PNG / f'favicon-produktion-{g}.png').convert('RGBA') for g in (16, 32, 48)]
    bilder[2].save(ico_produktion, format='ICO', sizes=[(16, 16), (32, 32), (48, 48)], append_images=bilder[:2])

    # Vorschaubild für Links (1200 × 630): dunkler Grund, Logo mittig.
    og = KIT / 'quellen' / 'og.svg'
    flaeche, glyphe, text, akzent = VARIANTEN['-dunkel']
    breite = 720
    massstab = breite / QUER_BREITE
    og.write_text(svg(1200, 630, f'<rect width="1200" height="630" fill="{GRUND_DUNKEL}"/>'
                      f'<g transform="translate({(1200 - breite) / 2:.2f} {(630 - MARKE * massstab) / 2:.2f}) '
                      f'scale({massstab:.5f})">'
                      + marke(flaeche, glyphe, 'og')
                      + schriftzug(text, akzent, MARKE + QUER_ABSTAND - SX0 * QUER_MASSSTAB, QUER_GRUNDLINIE,
                                   QUER_MASSSTAB)
                      + '</g>', 'phpdbadmin'))
    rastern(og, PNG / 'og-bild.png', breite=1200)
    og.unlink()

    # Mail: Logo quer auf hellem Grund, doppelte Auflösung (angezeigt mit 32 px Höhe).
    rastern(KIT / 'logo.svg', MAIL / 'logo@2x.png', hoehe=64)

    # In die App: Favicons und Apple-Symbol.
    public = PROJEKT / 'public'
    shutil.copy(favicon, public / 'favicon.svg')
    shutil.copy(ico, public / 'favicon.ico')
    shutil.copy(produktion, public / 'favicon-produktion.svg')
    shutil.copy(ico_produktion, public / 'favicon-produktion.ico')
    shutil.copy(PNG / 'apple-touch-icon.png', public / 'apple-touch-icon.png')

    # Für die Oberfläche: Pfade der Ligatur und ihre Lage in der 64er-Marke.
    (PROJEKT / 'resources/js/lib/brand.ts').write_text(
        '// Erzeugt von resources/brand/quellen/bauen.py — nicht von Hand ändern.\n\n'
        '/** Bildmarke (G-06): «db»-Ligatur aus JetBrains Mono Bold in einer 64er-Fläche mit Radius 14. */\n'
        'export const BRAND_MARK = {\n'
        f"    size: {MARKE},\n"
        f"    radius: {RADIUS},\n"
        f"    transform: '{ligatur_transform()}',\n"
        f"    paths: [\n        '{LIGATUR_D}',\n        '{LIGATUR_B}',\n    ],\n"
        '} as const;\n'
    )

    # Übersicht aller Varianten auf hellem und dunklem Grund.
    zeilen = []
    y = 24
    for endung, grund in (('', '#ffffff'), ('-dunkel', GRUND_DUNKEL), ('-schwarz', '#f6f7f9'), ('-weiss', '#1c2534')):
        flaeche, glyphe, text, akzent = VARIANTEN[endung]
        kennung = 'u' + endung.replace('-', '')
        zeilen.append(f'<rect x="0" y="{y - 24}" width="1200" height="200" fill="{grund}"/>')
        zeilen.append(f'<g transform="translate(40 {y + 44})">{marke(flaeche, glyphe, kennung + "a")}</g>')
        zeilen.append(f'<g transform="translate(140 {y + 44})">'
                      + marke(flaeche, glyphe, kennung + 'b')
                      + schriftzug(text, akzent, MARKE + QUER_ABSTAND - SX0 * QUER_MASSSTAB, QUER_GRUNDLINIE,
                                   QUER_MASSSTAB) + '</g>')
        zeilen.append(schriftzug(text, akzent, 560 - SX0 * 0.45, y + 92, 0.45))
        links = 1040 - STAPEL_BREITE / 2
        zeilen.append(f'<g transform="translate({links:.2f} {y + 8})">'
                      f'<g transform="translate({(STAPEL_BREITE - MARKE) / 2:.2f} 0)">'
                      + marke(flaeche, glyphe, kennung + 'c') + '</g>'
                      + schriftzug(text, akzent, (STAPEL_BREITE - SCHRIFT_BREITE * STAPEL_MASSSTAB) / 2
                                   - SX0 * STAPEL_MASSSTAB, STAPEL_GRUNDLINIE, STAPEL_MASSSTAB) + '</g>')
        y += 200
    uebersicht = schreiben('quellen/uebersicht.svg', svg(1200, 800, ''.join(zeilen), 'phpdbadmin — Logo-Kit'))
    rastern(uebersicht, KIT / 'uebersicht.png', breite=1200)
    uebersicht.unlink()

    print('Logo-Kit gebaut:', len(list(KIT.glob('*.svg'))), 'SVG,', len(list(PNG.glob('*.png'))), 'PNG')


if __name__ == '__main__':
    main()
