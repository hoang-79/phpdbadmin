#!/usr/bin/env python3
"""
Website von phpdbadmin bauen: statische Seiten auf Deutsch und Englisch aus vorlagen/ und seiten/ nach dist/.

    python3 website/bauen.py [--basis https://example.org/pfad/]

--basis ist die öffentliche Adresse der Website (für canonical, hreflang, Sitemap und Link-Vorschauen); ohne Angabe
gilt PHPDBADMIN_WEBSITE_URL oder https://phpdbadmin.net/. Alle übrigen Links sind relativ — dist/ lässt sich also auch
aus einem Unterordner oder mit `php -S` ansehen. Dazu entsteht dist/.htaccess (404, Caching, Sicherheits-Header) aus
vorlagen/htaccess; hochladen mit hochladen.sh.

Seiten: seiten/<sprache>/<name>.html mit Kopf zwischen zwei Zeilen «---» (titel, beschreibung, pfad, gegenstueck,
optional robots, jsonld). Dateien mit «_» vorne sind Bausteine derselben Sprache (_kopf, _fuss). Platzhalter:

    {{ name }}                       Wert der Seite oder des Bauvorgangs (version, root, jahr …)
    {{ link:/pfad/ }}                relativer Link auf eine Seite der Website
    {{ symbol:name }}                Symbol von Lucide (ISC) als Inline-SVG, aus node_modules/@lucide/vue
    {{ foto:name | Alt-Text }}       Bildschirmfoto aus assets/img (name-klein/-gross.webp)
    {{ foto-schema:name | Alt }}     Foto in Hell und Dunkel (name-light/-dark), passend zum Farbschema
    {{ handy:name | Alt-Text }}      hochformatiges Foto vom Handy

Keine Abhängigkeiten ausser Python 3 (Pillow nur in werkzeuge/bilder.py).
"""

import argparse
import base64
import hashlib
import html
import json
import os
import re
import shutil
from datetime import date
from pathlib import Path
from urllib.parse import urlparse

WEBSITE = Path(__file__).resolve().parent
REPO = WEBSITE.parent
DIST = WEBSITE / 'dist'
ASSETS = WEBSITE / 'assets'
LUCIDE = REPO / 'node_modules' / '@lucide' / 'vue' / 'dist' / 'esm' / 'icons'
BASIS = 'https://phpdbadmin.net/'
REPOSITORY = 'https://github.com/hoang-79/phpdbadmin'



def version() -> str:
    quelle = (REPO / 'config' / 'phpdbadmin.php').read_text(encoding='utf-8')
    treffer = re.search(r"'version'\s*=>\s*'([^']+)'", quelle)
    if treffer is None:
        raise SystemExit('Version nicht gefunden in config/phpdbadmin.php')
    return treffer.group(1)


# Lucide führt keine Markenzeichen; das von GitHub kommt aus Simple Icons (CC0) — nur als Link zu GitHub verwendet.
GITHUB = (
    'M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04'
    '-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 '
    '1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 '
    '0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 '
    '2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 '
    '5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 '
    '12.297c0-6.627-5.373-12-12-12'
)


def marke() -> str:
    """Bildmarke aus dem Logo-Kit; Flächen über Klassen, damit sie hell und dunkel den Farben der Seite folgen."""
    svg = (REPO / 'resources' / 'brand' / 'zeichen.svg').read_text(encoding='utf-8')
    inneres = svg[svg.index('<rect'):svg.rindex('</svg>')]
    inneres = inneres.replace('fill="#0b6e82"', 'class="marke-flaeche"').replace('fill="#ffffff"', 'class="marke-zeichen"')
    return f'<svg class="marke" viewBox="0 0 64 64" aria-hidden="true">{inneres}</svg>'


def symbol(name: str) -> str:
    """Lucide-Symbol als SVG: die Datei beschreibt es als Liste von [Element, {Attribute}]."""
    if name == 'github':
        return f'<svg class="symbol" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="{GITHUB}"/></svg>'
    datei = LUCIDE / f'{name}.mjs'
    if not datei.exists():
        raise SystemExit(f'Symbol {name} gibt es nicht in {LUCIDE}')
    quelle = datei.read_text(encoding='utf-8')
    knoten = quelle[quelle.index('node:'):quelle.index('};')]
    teile = []
    for element, attribute in re.findall(r'"(\w+)",\s*\{([^}]*)\}', knoten):
        werte = ' '.join(
            f'{schluessel}="{html.escape(wert)}"'
            for schluessel, wert in re.findall(r'(\w+):\s*"([^"]*)"', attribute)
            if schluessel != 'key'
        )
        teile.append(f'<{element} {werte}/>')
    return (
        '<svg class="symbol" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ''.join(teile) + '</svg>'
    )


def masse() -> dict[str, dict[str, list[int]]]:
    """Breite und Höhe jeder Fassung, geschrieben von werkzeuge/bilder.py — für srcset und gegen Layoutsprünge."""
    return json.loads((ASSETS / 'img' / 'masse.json').read_text(encoding='utf-8'))


def quellen(root: str, name: str) -> tuple[str, str, list[int]]:
    """src, srcset und Masse der kleinen Fassung eines Fotos."""
    fassungen = masse()[name]
    url = {
        fassung: f'{root}assets/img/{name}-{fassung}.webp?v={kennung(ASSETS / "img" / f"{name}-{fassung}.webp")}'
        for fassung in ('klein', 'gross')
    }
    srcset = ', '.join(f'{url[fassung]} {fassungen[fassung][0]}w' for fassung in ('klein', 'gross'))
    return url['klein'], srcset, fassungen['klein']


def foto(root: str, name: str, alt: str, sizes: str = '(min-width: 1100px) 640px, 92vw') -> str:
    src, srcset, (breite, hoehe) = quellen(root, name)
    return (
        f'<img src="{src}" srcset="{srcset}" sizes="{sizes}" width="{breite}" height="{hoehe}" '
        f'alt="{html.escape(alt)}" loading="lazy" decoding="async">'
    )


def foto_schema(root: str, name: str, alt: str) -> str:
    """Hell oder dunkel nach prefers-color-scheme — wie die Website selbst. Das erste Bild der Seite: sofort laden."""
    sizes = '(min-width: 1240px) 1160px, 94vw'
    _, dunkel, _ = quellen(root, f'{name}-dark')
    src, hell, (breite, hoehe) = quellen(root, f'{name}-light')
    return (
        '<picture>'
        f'<source media="(prefers-color-scheme: dark)" srcset="{dunkel}" sizes="{sizes}">'
        f'<img src="{src}" srcset="{hell}" sizes="{sizes}" width="{breite}" height="{hoehe}" '
        f'alt="{html.escape(alt)}" fetchpriority="high" decoding="async">'
        '</picture>'
    )


def lesen(datei: Path) -> tuple[dict[str, str], str]:
    text = datei.read_text(encoding='utf-8')
    if not text.startswith('---\n'):
        return {}, text
    kopf, inhalt = text[4:].split('\n---\n', 1)
    werte = {}
    for zeile in kopf.splitlines():
        if zeile.strip():
            schluessel, wert = zeile.split(':', 1)
            werte[schluessel.strip()] = wert.strip()
    return werte, inhalt


def ziel(pfad: str) -> Path:
    return DIST / (pfad.strip('/') or '.') / 'index.html' if pfad.endswith('/') else DIST / pfad.lstrip('/')


def wurzel(pfad: str, basis_pfad: str) -> str:
    """Relativer Weg zur Wurzel der Website; die 404-Seite gilt an jeder Tiefe und nimmt deshalb den absoluten Pfad."""
    if not pfad.endswith('/'):
        return basis_pfad if pfad == '/404.html' else ''
    return '../' * len([teil for teil in pfad.split('/') if teil])


def ersetzen(text: str, werte: dict[str, str]) -> str:
    root = werte['root']

    def platzhalter(treffer: re.Match[str]) -> str:
        ausdruck = treffer.group(1).strip()
        if ausdruck.startswith('link:'):
            return (root + ausdruck[5:].strip().lstrip('/')) or './'
        if ausdruck.startswith('symbol:'):
            return symbol(ausdruck[7:].strip())
        if ':' in ausdruck and ausdruck.split(':', 1)[0] in ('foto', 'foto-schema', 'handy'):
            art, rest = ausdruck.split(':', 1)
            name, alt = (teil.strip() for teil in rest.split('|', 1))
            if art == 'foto-schema':
                return foto_schema(root, name, alt)
            if art == 'handy':
                return foto(root, name, alt, '(min-width: 900px) 240px, 44vw')
            return foto(root, name, alt)
        if ausdruck not in werte:
            raise SystemExit(f'Unbekannter Platzhalter {{{{ {ausdruck} }}}}')
        return werte[ausdruck]

    return re.sub(r'\{\{(.+?)\}\}', platzhalter, text)


def kennung(datei: Path) -> str:
    return hashlib.sha256(datei.read_bytes()).hexdigest()[:10]


def skript_hash(vorlage: str) -> str:
    """Prüfsumme des einzigen Inline-Skripts der Vorlage für die Content-Security-Policy."""
    skripte = re.findall(r'<script>(.*?)</script>', vorlage, re.S)
    if len(skripte) != 1:
        raise SystemExit(f'Die Vorlage soll genau ein Inline-Skript haben, gefunden: {len(skripte)}')
    return 'sha256-' + base64.b64encode(hashlib.sha256(skripte[0].encode('utf-8')).digest()).decode('ascii')


def jsonld(werte: dict[str, str]) -> str:
    daten = {
        '@context': 'https://schema.org',
        '@type': 'SoftwareApplication',
        'name': 'phpdbadmin',
        'description': werte['beschreibung'],
        'url': werte['url'],
        'applicationCategory': 'DeveloperApplication',
        'operatingSystem': 'Linux, macOS, Windows (PHP 8.5)',
        'softwareVersion': werte['version'],
        'license': 'https://opensource.org/licenses/MIT',
        'downloadUrl': f'{REPOSITORY}/releases/latest',
        'codeRepository': REPOSITORY,
        'image': werte['basis'] + 'assets/og-bild.png',
        'inLanguage': ['de', 'en'],
        'offers': {'@type': 'Offer', 'price': '0', 'priceCurrency': 'CHF'},
        'author': {'@type': 'Organization', 'name': 'Nguyen Development'},
    }
    return '<script type="application/ld+json">' + json.dumps(daten, ensure_ascii=False) + '</script>'


def bauen(basis: str) -> None:
    basis = basis.rstrip('/') + '/'
    basis_pfad = urlparse(basis).path or '/'
    if DIST.exists():
        shutil.rmtree(DIST)
    shutil.copytree(ASSETS, DIST / 'assets')
    shutil.copy(REPO / 'resources' / 'brand' / 'png' / 'og-bild.png', DIST / 'assets' / 'og-bild.png')
    for datei in ('favicon.svg', 'favicon.ico', 'apple-touch-icon.png'):
        shutil.copy(REPO / 'public' / datei, DIST / datei)
    # Merkmal für hochladen.sh: Dieser Ordner gehört der Website und darf beim nächsten Hochladen ersetzt werden.
    (DIST / '.phpdbadmin-website').write_text('Website von phpdbadmin — wird bei jedem Hochladen ersetzt.\n', encoding='utf-8')

    vorlage = (WEBSITE / 'vorlagen' / 'seite.html').read_text(encoding='utf-8')
    htaccess = (WEBSITE / 'vorlagen' / 'htaccess').read_text(encoding='utf-8')
    (DIST / '.htaccess').write_text(ersetzen(htaccess, {
        'root': '',
        'basis_pfad': basis_pfad,
        'skript_hash': skript_hash(vorlage),
    }), encoding='utf-8')
    gemeinsam = {
        'version': version(),
        'jahr': str(date.today().year),
        'basis': basis,
        'repository': REPOSITORY,
        'installer': f'{REPOSITORY}/releases/latest/download/phpdbadmin-installer.php',
        'marke': marke(),
        'css': kennung(ASSETS / 'site.css'),
        'js': kennung(ASSETS / 'site.js'),
        # Prüfsumme auch an den Favicons: Browser halten sie lange fest und holen sonst kein neues Symbol
        'favicon_ico': kennung(REPO / 'public' / 'favicon.ico'),
        'favicon_svg': kennung(REPO / 'public' / 'favicon.svg'),
        'apple_touch_icon': kennung(REPO / 'public' / 'apple-touch-icon.png'),
    }
    seiten = []
    for sprache_ordner in sorted((WEBSITE / 'seiten').iterdir()):
        sprache = sprache_ordner.name
        bausteine = {datei.stem[1:]: datei.read_text(encoding='utf-8') for datei in sprache_ordner.glob('_*.html')}
        for datei in sorted(sprache_ordner.glob('[!_]*.html')):
            kopf, inhalt = lesen(datei)
            pfad = kopf['pfad']
            werte = {
                **gemeinsam,
                **kopf,
                'sprache': sprache,
                'root': wurzel(pfad, basis_pfad),
                'url': basis + pfad.lstrip('/'),
                'gegenstueck_url': basis + kopf['gegenstueck'].lstrip('/'),
                'robots': kopf.get('robots', 'index, follow'),
                'og_locale': 'de_CH' if sprache == 'de' else 'en_US',
            }
            werte['gegenstueck_link'] = (werte['root'] + kopf['gegenstueck'].lstrip('/')) or './'
            werte['de_url'] = werte['url'] if sprache == 'de' else werte['gegenstueck_url']
            werte['en_url'] = werte['url'] if sprache == 'en' else werte['gegenstueck_url']
            werte['jsonld'] = jsonld(werte) if kopf.get('jsonld') == 'ja' else ''
            werte['kopf'] = ersetzen(bausteine['kopf'], werte)
            werte['fuss'] = ersetzen(bausteine['fuss'], werte)
            werte['inhalt'] = ersetzen(inhalt, werte)
            seite = ersetzen(vorlage, werte)
            ausgabe = ziel(pfad)
            ausgabe.parent.mkdir(parents=True, exist_ok=True)
            ausgabe.write_text(seite, encoding='utf-8')
            seiten.append(werte)
            print(f'{sprache}  {pfad:<24} → {ausgabe.relative_to(DIST)}')

    karte = ['<?xml version="1.0" encoding="UTF-8"?>',
             '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">']
    for werte in seiten:
        if werte['robots'].startswith('noindex'):
            continue
        karte.append(f'  <url><loc>{werte["url"]}</loc>'
                     f'<xhtml:link rel="alternate" hreflang="de" href="{werte["de_url"]}"/>'
                     f'<xhtml:link rel="alternate" hreflang="en" href="{werte["en_url"]}"/></url>')
    karte.append('</urlset>')
    (DIST / 'sitemap.xml').write_text('\n'.join(karte) + '\n', encoding='utf-8')
    (DIST / 'robots.txt').write_text(f'User-agent: *\nAllow: /\n\nSitemap: {basis}sitemap.xml\n', encoding='utf-8')
    print(f'{len(seiten)} Seiten, Basis {basis}')


if __name__ == '__main__':
    argumente = argparse.ArgumentParser(description='Website von phpdbadmin bauen')
    argumente.add_argument('--basis', default=os.environ.get('PHPDBADMIN_WEBSITE_URL', BASIS))
    bauen(argumente.parse_args().basis)
