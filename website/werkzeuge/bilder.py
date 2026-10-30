#!/usr/bin/env python3
"""
Bildschirmfotos (PNG in doppelter Auflösung aus werkzeuge/fotos.mjs) für die Website umwandeln: je Foto eine grosse
und eine kleine Fassung als WebP, dazu assets/img/masse.json mit den Massen für width/height und srcset.

Manche Fotos werden zugeschnitten, damit der Inhalt neben dem Text lesbar bleibt (ohne Navigator und Seitenleisten).
Aufruf: python3 website/werkzeuge/bilder.py <ordner-mit-pngs>
"""

import json
import sys
from pathlib import Path

from PIL import Image

ZIEL = Path(__file__).resolve().parent.parent / 'assets' / 'img'
BREITEN = {'gross': 2400, 'klein': 1200}
HANDY = {'gross': 780, 'klein': 390}

# Ausschnitte in CSS-Pixeln des 1440 × 900 grossen Fensters (links, oben, rechts, unten): unter der Kopfleiste
# (51 px), rechts vom Navigator (255 px), im SQL-Editor ohne die Spalte «Gespeichert / Verlauf» (ab 1152 px); die
# Befehlspalette mit etwas Rand (Kasten 384–1055 × 108–731 px).
AUSSCHNITTE = {
    'sql': (256, 52, 1152, 900),
    'explain': (256, 52, 1152, 900),
    'aendern': (256, 52, 1440, 900),
    'palette': (352, 76, 1088, 763),
}


def main(quelle: Path) -> None:
    ZIEL.mkdir(parents=True, exist_ok=True)
    masse_datei = ZIEL / 'masse.json'
    masse = {}
    for png in sorted(quelle.glob('*.png')):
        bild = Image.open(png).convert('RGB')
        if png.stem in AUSSCHNITTE:
            bild = bild.crop(tuple(wert * 2 for wert in AUSSCHNITTE[png.stem]))
        breiten = HANDY if bild.width < 1000 else BREITEN
        masse[png.stem] = {}
        for fassung, breite in breiten.items():
            kopie = bild.copy()
            if kopie.width > breite:
                kopie = kopie.resize((breite, round(kopie.height * breite / kopie.width)), Image.LANCZOS)
            ziel = ZIEL / f'{png.stem}-{fassung}.webp'
            kopie.save(ziel, 'WEBP', quality=82, method=6)
            masse[png.stem][fassung] = [kopie.width, kopie.height]
            print(f'{ziel.name}: {kopie.width}×{kopie.height}, {ziel.stat().st_size // 1024} KB')
    zeilen = [f'  {json.dumps(name)}: {json.dumps(werte)}' for name, werte in sorted(masse.items())]
    masse_datei.write_text('{\n' + ',\n'.join(zeilen) + '\n}\n', encoding='utf-8')


if __name__ == '__main__':
    main(Path(sys.argv[1]))
