#!/usr/bin/env python3
"""
Baut die PDF-Dossiers der Dokumentation — eines je Phase, nach demselben Prinzip:
Leitdokument zuerst, dann die Dokumente der Phase als Anhänge; Titelseite mit Datum und
Commit, Inhaltsverzeichnis, Kopf- und Fusszeilen mit Seitenzahlen, Diagramme je auf
einer Querformat-Seite, gesetzt in der Systemschrift (die Marke folgt in der Foundation).

Weg: Markdown → (pandoc) HTML → (Google Chrome, headless) PDF. Dazwischen richtet das Skript die
Tabellenspalten am Inhalt aus (kurze Spalten ohne Umbruch, überlange Zeichenketten mit Trennstellen).

Aufruf (aus dem Projektverzeichnis):
    python3 dokumentationen/werkzeuge/pdf-bauen.py             alle Dossiers bauen
    python3 dokumentationen/werkzeuge/pdf-bauen.py --nur 01    nur ein Dossier (cr, 01, 02, 03, 04, 05, 06)
    python3 dokumentationen/werkzeuge/pdf-bauen.py --pruefen   nichts bauen, nur melden, welche PDFs älter sind als ihre Quellen
    python3 dokumentationen/werkzeuge/pdf-bauen.py --liste     Dossiers und Zieldateien ausgeben

Regel: Nach jeder Änderung an einem Dokument oder Diagramm wird das betroffene PDF neu gebaut
und im selben Commit abgelegt — jedes PDF ist immer der Stand des Repos (`--pruefen` im Hook).
"""
from __future__ import annotations

import datetime as dt
import re
import shutil
from html import escape, unescape
import subprocess
import sys
import tempfile
from dataclasses import dataclass, field
from pathlib import Path

WURZEL = Path(__file__).resolve().parent.parent            # dokumentationen/
PROJEKT = WURZEL.parent
CHROME = Path("/Applications/Google Chrome.app/Contents/MacOS/Google Chrome")
CSS = WURZEL / "werkzeuge" / "pdf.css"


@dataclass
class Dossier:
    schluessel: str
    titel: str
    untertitel: str
    kopfzeile: str
    ziel: str                                   # relativ zu dokumentationen/
    teile: list[tuple[str, str | None]]         # (Datei relativ, Präfix oder None für das Leitdokument)
    felder: list[tuple[str, str]] = field(default_factory=list)


def phase_teile(ordner: str, leit: str = "README.md", feste: list[str] | None = None) -> list[tuple[str, str | None]]:
    """Leitdokument plus alle Markdown-Dateien der Phase (und ihrer Unterordner) in fester, dann alphabetischer Folge."""
    basis = WURZEL / ordner
    teile: list[tuple[str, str | None]] = [(f"{ordner}/{leit}", None)]
    gesehen = {leit}
    reihen: list[str] = list(feste or [])
    for p in sorted(basis.rglob("*.md")):
        rel = str(p.relative_to(basis))
        if rel in gesehen or rel in reihen or "/00_sources/" in f"/{rel}" or rel.startswith("mockups/") or p.name == "markenhandbuch.md":
            continue  # Markenhandbücher haben eigene PDFs
        reihen.append(rel)
    for i, rel in enumerate(reihen):
        if (basis / rel).exists():
            teile.append((f"{ordner}/{rel}", f"Anhang {chr(65 + i)}"))
    return teile


PRODUKT = "phpdbadmin"   # Name entschieden 28.10.2026 (R-04)

GEMEINSAM = [("Träger", "Nguyen Development (Einzelfirma), Hoang Nguyen · Lizenz MIT"),
             ("Vorgehen", "DSDM/AgilePM — Dokumentation in dokumentationen/, ein PDF je Phase")]

DOSSIERS: dict[str, Dossier] = {
    "cr": Dossier("cr", f"CR-001 — {PRODUKT}",
                  "Change Request mit Ideensammlung, Rückfragen, Marktbild, Funktionsraster und Ideen-Backlog",
                  f"{PRODUKT} · CR-001 Dossier", "00-change_request/CR-001-phpdbadmin.pdf",
                  [("00-change_request/CR-001-phpdbadmin.md", None),
                   ("00-change_request/ideensammlung.md", "Anhang A"),
                   ("01-pre-project/ausgangslage-und-rueckfragen.md", "Anhang B"),
                   ("00-change_request/marktbild.md", "Anhang C"),
                   ("00-change_request/funktionsraster.md", "Anhang D"),
                   ("01-pre-project/ideen-backlog.md", "Anhang E")],
                  [("Produkt", f"{PRODUKT} — Datenbank-Verwaltung im Browser für MariaDB, MySQL, PostgreSQL und SQLite")] + GEMEINSAM),
    "01": Dossier("01", f"Terms of Reference — {PRODUKT}",
                  "Pre-Project: Terms of Reference mit Ausgangslage und Rückfragen und Ideen-Backlog",
                  f"{PRODUKT} · Terms of Reference", "01-pre-project/terms-of-reference.pdf",
                  [("01-pre-project/terms-of-reference.md", None),
                   ("01-pre-project/README.md", "Anhang A"),
                   ("01-pre-project/ausgangslage-und-rueckfragen.md", "Anhang B"),
                   ("01-pre-project/ideen-backlog.md", "Anhang C")],
                  [("Phase", "01 Pre-Project"), ("Eingang", f"CR-001 {PRODUKT} (Fassung 3), Feasibility v1.0")] + GEMEINSAM),
    "02": Dossier("02", f"Feasibility — {PRODUKT}",
                  "Machbarkeit: zehn Prüfpunkte auf vier Datenbanken, Sicherheit und Bedrohungen, Name und Marke",
                  f"{PRODUKT} · Feasibility", "02-feasibility/feasibility.pdf",
                  phase_teile("02-feasibility", feste=["feasibility.md", "sicherheit-und-bedrohungen.md", "name-und-marke.md"]),
                  [("Phase", "02 Feasibility")] + GEMEINSAM),
    "03": Dossier("03", f"Foundation — {PRODUKT}",
                  "Gestaltung, PRL, Solution-Architektur, Datenmodell, Timebox-Plan",
                  f"{PRODUKT} · Foundation", "03-foundation/foundation.pdf",
                  phase_teile("03-foundation"),
                  [("Phase", "03 Foundation")] + GEMEINSAM),
    "04": Dossier("04", f"Evolutionary Development — {PRODUKT}",
                  "Timeboxes und Architekturentscheide",
                  f"{PRODUKT} · Evolutionary Development", "04-evolutionary-development/evolutionary-development.pdf",
                  phase_teile("04-evolutionary-development"),
                  [("Phase", "04 Evolutionary Development")] + GEMEINSAM),
    "05": Dossier("05", f"Deployment — {PRODUKT}",
                  "Umgebungen, Pipeline, Betrieb, Testmanagement, Release-Notes",
                  f"{PRODUKT} · Deployment", "05-deployment/deployment.pdf",
                  phase_teile("05-deployment"),
                  [("Phase", "05 Deployment")] + GEMEINSAM),
    "06": Dossier("06", f"Post-Project — {PRODUKT}",
                  "Benefits Review, Lessons Learned, Folge-CRs",
                  f"{PRODUKT} · Post-Project", "06-post-project/post-project.pdf",
                  phase_teile("06-post-project"),
                  [("Phase", "06 Post-Project")] + GEMEINSAM),
}

LINK = re.compile(r"(!?)\[([^\]]*)\]\(([^)\s]+)(?:\s+\"[^\"]*\")?\)")
BILDZEILE = re.compile(r"^(<figure>.*?</figure>|<p><img[^>]*/></p>)$", re.S)

# Tabellen: Spaltenbreiten am Inhalt ausrichten (siehe tabellen_spalten)
SEITENBREITE_PT = 493          # A4 (210 mm) abzüglich 2 × 18 mm Rand, in Punkt
TABELLENSCHRIFT_PT = 8.8       # Schriftgrad in Tabellen (pdf.css)
ZELLENRAND_PT = 10             # Innenabstand 2 × 6 px plus Rahmen, je Spalte
KURZ = 16                      # Zellen bis zu dieser Länge: Spalte ohne Umbruch (Nr., Kürzel, Daten, Status)
STUECK_MAX, STUECK_MIN = 28, 10  # längstes unteilbares Stück je Spalte (Zeichen); wird je Tabelle gesenkt, bis die Mindestbreiten auf die Seite passen
# Werte aus Basalto übernommen; Helvetica Neue läuft etwas breiter als IBM Plex — die Reserve deckt es, nachmessen mit der Marke.
# Vorschub je Zeichenklasse in em, in Chrome gemessen (IBM Plex Sans 8.8 pt: Kleinbuchstaben 0.51, Grossbuchstaben 0.64,
# Ziffern 0.60, Satzzeichen 0.38; IBM Plex Mono 0.60), plus 4 % Reserve — eine zu knappe Schätzung liesse Chrome die
# ganze Seite verkleinern (Shrink-to-fit), eine zu grosszügige zerlegt nur unnötig lange Wörter.
EM_KLEIN, EM_GROSS, EM_ZIFFER, EM_SONST, EM_MONO, EM_LEER = 0.51, 0.64, 0.60, 0.40, 0.60, 0.25
RESERVE, FETT = 1.04, 1.08
TABELLE = re.compile(r"<table>.*?</table>", re.S)
ZEILE = re.compile(r"<tr[^>]*>.*?</tr>", re.S)
ZELLE = re.compile(r"<(t[dh])([^>]*)>(.*?)</\1>", re.S)
TAG = re.compile(r"<[^>]+>")
TAG_ODER_TEXT = re.compile(r"(<[^>]+>)")
CODE_GANZ = re.compile(r"<code>([^<\s]*)</code>")
TRENNER = re.compile(r"(?<=[/\\\-_:.@])")   # Trennstellen in langen Zeichenketten: nach dem Zeichen
WORTGRENZE = re.compile(r"\s+|(?<=-)(?=[^\W\d])")  # Chrome bricht an Leerraum und nach Bindestrich vor Buchstaben («MK-01» bleibt ganz)


def slug(pfad: str) -> str:
    return "dok-" + Path(pfad).stem.lower()


def git(*args: str) -> str:
    try:
        return subprocess.run(["git", *args], cwd=PROJEKT, capture_output=True, text=True, check=True).stdout.strip()
    except Exception:
        return ""


def stand() -> tuple[str, str]:
    commit = git("rev-parse", "--short", "HEAD") or "ohne Git"
    schmutzig = bool(git("status", "--porcelain", "--", "dokumentationen"))
    return dt.datetime.now().strftime("%d.%m.%Y, %H:%M"), commit + (" + Änderungen dieses Standes" if schmutzig else "")


def bereite_dokument(rel: str, praefix: str | None, im_dossier: set[str], quellen: set[Path] | None = None) -> tuple[str, str]:
    """Liest ein Markdown-Dokument, setzt Anker und Titel, macht Bild- und Querverweise dossier-tauglich."""
    datei = WURZEL / rel
    if quellen is not None:
        quellen.add(datei)
    text = datei.read_text(encoding="utf-8")
    zeilen = text.split("\n")
    titel = rel
    for i, z in enumerate(zeilen):
        if z.startswith("# "):
            titel = z[2:].strip()
            if praefix:
                titel = f"{praefix} — {titel}"
            zeilen[i] = f'<a id="{slug(rel)}"></a>\n\n# {titel}'
            break
    text = "\n".join(zeilen)

    def ersetze(m: re.Match) -> str:
        bild, alt, ziel = m.group(1), m.group(2), m.group(3)
        if ziel.startswith(("http://", "https://", "mailto:", "#")):
            return m.group(0)
        pfad, _, _anker = ziel.partition("#")
        absolut = (datei.parent / pfad).resolve()
        if bild:
            if quellen is not None and absolut.exists():
                quellen.add(absolut)
            return f"![{alt}]({absolut.as_uri()})"
        if pfad.endswith(".md"):
            try:
                rel_ziel = str(absolut.relative_to(WURZEL))
            except ValueError:
                rel_ziel = ""
            if rel_ziel in im_dossier:
                return f"[{alt}](#{slug(rel_ziel)})"
            return alt
        if absolut.exists():
            return f"[{alt}]({absolut.as_uri()})"
        return alt

    return titel, LINK.sub(ersetze, text)


def diagramme_quer(html: str) -> str:
    """Jedes SVG-Bild auf eine eigene Querformat-Seite — zusammen mit der Überschrift davor und dem Absatz danach."""
    zeilen = html.split("\n")
    aus: list[str] = []
    i = 0
    while i < len(zeilen):
        z = zeilen[i]
        if BILDZEILE.match(z.strip()) and ".svg" in z:
            kopf = ""
            while aus and not aus[-1].strip():
                aus.pop()
            if aus and re.match(r"^<h[2-4][ >]", aus[-1].strip()):
                kopf = aus.pop()
            j = i + 1
            while j < len(zeilen) and not zeilen[j].strip():
                j += 1
            text = ""
            if j < len(zeilen) and zeilen[j].strip().startswith("<p>") and "<img" not in zeilen[j]:
                text = zeilen[j]
                i = j
            aus.append(f'<div class="diagramm">{kopf}<div class="bild">{z}</div>{text}</div>')
        else:
            aus.append(z)
        i += 1
    return "\n".join(aus)


def zellentext(inner: str) -> str:
    return unescape(TAG.sub("", inner)).strip()


def breite_pt(text: str, mono: bool = False, fett: bool = False) -> float:
    """Geschätzte Breite eines Textes in der Tabellenschrift (Punkt)."""
    if mono:
        em = len(text) * EM_MONO
    else:
        em = sum(EM_LEER if c.isspace() else EM_KLEIN if c.islower() else EM_GROSS if c.isupper() else EM_ZIFFER if c.isdigit() else EM_SONST
                 for c in text)
    return em * TABELLENSCHRIFT_PT * RESERVE * (FETT if fett else 1)


def stuecke_liste(wort: str, stueck: int) -> list[str]:
    """Zerlegt eine Zeichenkette in Stücke bis `stueck` Zeichen: zuerst an Pfad- und Satzzeichen, sonst gleichmässig."""
    teile: list[str] = []
    for t in TRENNER.split(wort):
        if t and teile and len(teile[-1]) + len(t) <= stueck:
            teile[-1] += t
        elif t:
            teile.append(t)
    aus: list[str] = []
    for t in teile:
        anzahl = -(-len(t) // stueck)
        laenge = -(-len(t) // anzahl)
        aus.extend(t[k:k + laenge] for k in range(0, len(t), laenge))
    return aus


def stuecke(wort: str, stueck: int, im_code: bool) -> str:
    """HTML einer überlangen Zeichenkette mit Umbruchstellen: <wbr> in Code und an Satzzeichen, sonst weicher Trennstrich."""
    teile = stuecke_liste(wort, stueck)
    aus = []
    for n, kopf in enumerate(teile):
        rest = teile[n + 1][:1] if n + 1 < len(teile) else ""
        weich = not im_code and kopf[-1:].isalpha() and rest.isalpha()
        aus.append(escape(kopf, quote=False) + ("" if not rest else "&shy;" if weich else "<wbr>"))
    return "".join(aus)


def zellen_woerter(inner: str, fett: bool) -> list[tuple[str, bool, bool]]:
    """Die Wörter einer Zelle (an Chromes Umbruchstellen getrennt) mit Schriftart: (Wort, mono, fett)."""
    aus: list[tuple[str, bool, bool]] = []
    im_code = im_fett = 0
    for teil in TAG_ODER_TEXT.split(inner):
        if teil.startswith("<"):
            im_code += 1 if teil.startswith("<code") else -1 if teil.startswith("</code") else 0
            im_fett += 1 if teil.startswith(("<strong", "<b>")) else -1 if teil.startswith(("</strong", "</b>")) else 0
            continue
        aus.extend((w, im_code > 0, fett or im_fett > 0) for w in WORTGRENZE.split(unescape(teil)) if w)
    return aus


def zelle_brechbar(inner: str, stueck: int) -> str:
    """Kurze Code-Spannen bleiben ganz; Zeichenketten länger als `stueck` bekommen Umbruchstellen."""
    inner = CODE_GANZ.sub(lambda m: f'<code class="ganz">{m.group(1)}</code>' if len(unescape(m.group(1))) <= stueck else m.group(0), inner)
    aus: list[str] = []
    im_code = 0
    for teil in TAG_ODER_TEXT.split(inner):
        if teil.startswith("<"):
            im_code += 1 if teil.startswith("<code") else -1 if teil.startswith("</code") else 0
            aus.append(teil)
            continue
        aus.append(re.sub(r"\S+", lambda m: stuecke(unescape(m.group(0)), stueck, im_code > 0)
                          if len(unescape(m.group(0))) > stueck else m.group(0), teil))
    return "".join(aus)


def tabelle_ausrichten(tabelle: str) -> str:
    """Eine Tabelle: Spalten, deren Zellen alle kurz sind (Nr., Kürzel, Daten), werden nicht umbrochen;
    in den übrigen Spalten bekommen nur überlange Zeichenketten Trennstellen. Das Stückmass sinkt, bis die
    geschätzten Mindestbreiten aller Spalten auf die Seite passen — so läuft keine Tabelle über den Rand."""
    spalten: list[list[tuple[str, list[tuple[str, bool, bool]]]]] = []   # je Spalte: (Zellentext, Wörter) jeder Zelle
    for zeile in ZEILE.findall(tabelle):
        for i, (tag, _, inner) in enumerate(ZELLE.findall(zeile)):
            while len(spalten) <= i:
                spalten.append([])
            spalten[i].append((zellentext(inner), zellen_woerter(inner, tag == "th")))
    if not spalten:
        return tabelle
    laengste_zelle = [max((len(text) for text, _ in zellen), default=0) for zellen in spalten]
    kurz = [l <= KURZ for l in laengste_zelle]
    budget = SEITENBREITE_PT - len(spalten) * ZELLENRAND_PT
    stueck = STUECK_MAX

    def spaltenbreite(i: int, s: int) -> float:
        """Geschätzte Mindestbreite: ohne Umbruch die ganze Zelle, sonst das breiteste Wort bzw. Stück."""
        if kurz[i]:
            return max((sum(breite_pt(w, mono, fett) for w, mono, fett in woerter) + breite_pt(" " * (len(woerter) - 1))
                        for _, woerter in spalten[i] if woerter), default=0)
        return max((breite_pt(stueck_, mono, fett) for _, woerter in spalten[i] for w, mono, fett in woerter
                    for stueck_ in (stuecke_liste(w, s) if len(w) > s else [w])), default=0)

    while sum(spaltenbreite(i, stueck) for i in range(len(spalten))) > budget:
        if stueck > STUECK_MIN:
            stueck -= 1
        elif any(kurz):
            kurz[max((i for i in range(len(spalten)) if kurz[i]), key=lambda i: laengste_zelle[i])] = False
        else:
            break

    def zelle(m: re.Match, i: int) -> str:
        tag, attr, inner = m.groups()
        if kurz[i]:
            return f'<{tag}{attr} class="kurz">{inner}</{tag}>'
        return f"<{tag}{attr}>{zelle_brechbar(inner, stueck)}</{tag}>"

    def zeile(m: re.Match) -> str:
        zaehler = iter(range(len(spalten)))
        return ZELLE.sub(lambda z: zelle(z, next(zaehler)), m.group(0))

    return ZEILE.sub(zeile, tabelle)


def tabellen_spalten(html: str) -> str:
    """Spaltenbreiten aller Tabellen am Inhalt ausrichten (Chrome verteilt sonst die Breite nach Textmenge,
    und Spalten mit Kürzeln wie «MK-01» brechen Zeichen für Zeichen um)."""
    return TABELLE.sub(lambda m: tabelle_ausrichten(m.group(0)), html)


def titelseite(d: Dossier, datum: str, commit: str, titel_liste: list[str]) -> str:
    punkte = "".join(f"<li>{t}</li>" for t in titel_liste)
    felder = "".join(f"<tr><th>{k}</th><td>{v}</td></tr>" for k, v in d.felder)
    return f"""
<div class="titelseite">
  <div class="produkt">Nguyen Development · {PRODUKT}</div>
  <h1 class="titel">{d.titel}</h1>
  <div class="untertitel">{d.untertitel}</div>
  <table>{felder}
    <tr><th>Stand</th><td>{datum} · baut auf Commit {commit} auf</td></tr>
    <tr><th>Quelle</th><td>dokumentationen/ im Repo — dieses PDF wird bei jeder Änderung neu gebaut</td></tr>
  </table>
  <div class="inhalt"><strong>Dieses Dossier enthält</strong><ol>{punkte}</ol></div>
</div>
"""


def baue(d: Dossier, html_behalten: bool = False) -> Path:
    im_dossier = {rel for rel, _ in d.teile}
    teile, titel_liste = [], []
    for rel, praefix in d.teile:
        titel, text = bereite_dokument(rel, praefix, im_dossier)
        titel_liste.append(titel)
        teile.append(text)
    datum, commit = stand()
    arbeit = Path(tempfile.mkdtemp(prefix=f"dossier-{d.schluessel}-"))
    md = arbeit / "dossier.md"
    md.write_text("\n\n".join(teile) + "\n", encoding="utf-8")
    (arbeit / "titelseite.html").write_text(titelseite(d, datum, commit, titel_liste), encoding="utf-8")
    (arbeit / "kopf.html").write_text(f'<style>@page {{ @top-right {{ content: "{d.kopfzeile}"; }} }} '
                                      f'@page quer {{ @top-right {{ content: "{d.kopfzeile}"; }} }}</style>', encoding="utf-8")
    html = arbeit / "dossier.html"
    subprocess.run([
        "pandoc", str(md), "-f", "gfm", "-t", "html5", "--standalone", "--toc", "--toc-depth=2", "--wrap=none",
        "--css", str(CSS), "--include-in-header", str(arbeit / "kopf.html"), "--include-before-body", str(arbeit / "titelseite.html"),
        "--metadata", f"pagetitle={d.titel}", "--metadata", "lang=de", "-o", str(html),
    ], check=True)
    html.write_text(tabellen_spalten(diagramme_quer(html.read_text(encoding="utf-8"))), encoding="utf-8")
    ziel = WURZEL / d.ziel
    ziel.parent.mkdir(parents=True, exist_ok=True)
    subprocess.run([str(CHROME), "--headless=new", "--disable-gpu", "--no-pdf-header-footer", "--virtual-time-budget=15000",
                    f"--print-to-pdf={ziel}", html.as_uri()], check=True, capture_output=True)
    if html_behalten:
        shutil.copy(html, ziel.with_suffix(".html"))
    print(f"PDF: {ziel.relative_to(PROJEKT)} ({ziel.stat().st_size // 1024} KB, Stand {datum}, Commit {commit})")
    return ziel


def quellen_von(d: Dossier) -> set[Path]:
    quellen: set[Path] = {CSS, Path(__file__).resolve()}
    im_dossier = {rel for rel, _ in d.teile}
    for rel, praefix in d.teile:
        bereite_dokument(rel, praefix, im_dossier, quellen)
    return quellen


def pruefen() -> int:
    """Meldet Dossiers, deren PDF älter ist als eine ihrer Quellen. Rückgabe 1, wenn eines veraltet ist."""
    veraltet = []
    for d in DOSSIERS.values():
        ziel = WURZEL / d.ziel
        if not ziel.exists():
            veraltet.append((d, "fehlt"))
            continue
        pdf_zeit = ziel.stat().st_mtime
        alt = [q for q in quellen_von(d) if q.exists() and q.stat().st_mtime > pdf_zeit + 1]
        if alt:
            veraltet.append((d, ", ".join(str(q.relative_to(WURZEL)) for q in alt[:4]) + (" …" if len(alt) > 4 else "")))
    if veraltet:
        print("Veraltete PDF-Dossiers:")
        for d, grund in veraltet:
            print(f"  {d.ziel}: {grund}")
        print("Neu bauen: python3 dokumentationen/werkzeuge/pdf-bauen.py --nur " + " ".join(d.schluessel for d, _ in veraltet))
        return 1
    print("Alle PDF-Dossiers sind auf dem Stand ihrer Quellen.")
    return 0


def main(argv: list[str]) -> int:
    if "--liste" in argv:
        for d in DOSSIERS.values():
            print(f"{d.schluessel:3s} {d.ziel:60s} {len(d.teile)} Dokumente")
        return 0
    if "--pruefen" in argv:
        return pruefen()
    if shutil.which("pandoc") is None:
        print("pandoc fehlt (brew install pandoc)", file=sys.stderr)
        return 1
    if not CHROME.exists():
        print("Google Chrome fehlt unter /Applications", file=sys.stderr)
        return 1
    gewuenscht = argv[argv.index("--nur") + 1:] if "--nur" in argv else list(DOSSIERS)
    gewuenscht = [g for g in gewuenscht if not g.startswith("--")]
    for schluessel in gewuenscht:
        if schluessel not in DOSSIERS:
            print(f"Unbekanntes Dossier: {schluessel} (bekannt: {', '.join(DOSSIERS)})", file=sys.stderr)
            return 1
        baue(DOSSIERS[schluessel], html_behalten="--html" in argv)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
