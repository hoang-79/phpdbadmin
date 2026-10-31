# Gestaltung — phpdbadmin

**Stand:** 28.10.2026 · v0.1 — Vorschlag der Foundation; die Entscheide G-01 bis G-08 gelten als Annahme bis zur Sichtprüfung am echten Bildschirm (die Fragen aus den Wireframes hat Hoang am 28.10.2026 wie empfohlen entschieden). **Umgesetzt mit TB 00:** Palette hell und dunkel im Stylesheet, Inter und JetBrains Mono mit ausgeliefert, Wortmarke, Umgebungszeichen; durchgegangen gegen die Wireframes 1–4 und 13 ([Protokoll](../04-evolutionary-development/timebox-00-fundament.md#browser-durchlauf)). Noch nicht umgesetzt: Dichte (G-04, mit dem Raster in TB 01). Hoangs eigene Sichtprüfung steht aus. Farben und Kontraste stehen nur in `werkzeuge/kontraste.py`; die Tabelle in § 2 wird daraus eingesetzt (`python3 dokumentationen/werkzeuge/kontraste.py --schreiben`).

## 1. Haltung

phpdbadmin ist ein Arbeitswerkzeug für Stunden am Stück. Die Oberfläche tritt zurück, die Daten stehen vorn. Daraus folgen vier Regeln:

1. **Ruhig:** Grund und Flächen in Schiefergrau, ein einziger Akzent (Petrol) für Handlungen, Fokus und Auswahl. Farbe trägt Bedeutung, nie Schmuck.
2. **Ehrlich:** Gefahr hat eine eigene Farbe (Rot) und wird nie für etwas anderes verwendet. Die Umgebung einer Verbindung ist immer sichtbar.
3. **Dicht, aber lesbar:** Daten in tabellarischen Ziffern, Zeilen 32 px, Abstände im 4-px-Raster; nichts wird kleiner als 12 px.
4. **Gleich für alle vier:** Ein Bildschirm sieht für MySQL, MariaDB, PostgreSQL und SQLite gleich aus; der Treiber erscheint als kleines Schild, nicht als eigene Gestaltung.

Abgrenzung: Basalto (Basalt und Lava, Sechseck, IBM Plex) und Fristo haben eigene Identitäten; phpdbadmin übernimmt von dort nur Regeln (Kontraste rechnen, keine Scrollbalken, Sie-Form), keine Formen und Farben.

## 2. Farben

Die Namen werden als CSS-Variablen auf die Variablen des Starter-Kits gelegt (`--background`, `--foreground`, `--primary`, `--destructive` …) und um eigene ergänzt (`--env-local`, `--env-test`, `--env-production`, `--cell-changed`, `--cell-new`, `--cell-deleted`).

<!-- kontraste:start -->
| Name | hell | dunkel |
|---|---|---|
| `grund` | `#ffffff` | `#0b1120` |
| `flaeche` | `#f6f7f9` | `#111a2c` |
| `flaeche-stark` | `#eceff3` | `#1a2438` |
| `rand` | `#dde2e8` | `#2a3550` |
| `text` | `#1c2534` | `#e5e9f0` |
| `text-zweit` | `#4a5568` | `#a9b4c6` |
| `text-leise` | `#5f6b7d` | `#8c98ad` |
| `akzent` | `#0b6e82` | `#4fc3d9` |
| `akzent-text` | `#ffffff` | `#062a33` |
| `akzent-hell` | `#e3f2f5` | `#0f3540` |
| `erfolg` | `#1d7a46` | `#5bd08f` |
| `warnung` | `#9a5b00` | `#f2b84b` |
| `gefahr` | `#b3261e` | `#ff8a80` |
| `gefahr-text` | `#ffffff` | `#3b0a07` |
| `gefahr-hell` | `#fbeaea` | `#3a1416` |
| `umgebung-lokal` | `#4a5568` | `#a9b4c6` |
| `umgebung-test` | `#9a5b00` | `#f2b84b` |
| `umgebung-produktion` | `#b3261e` | `#ff8a80` |
| `zelle-geaendert` | `#e3f2f5` | `#0f3540` |
| `zelle-neu` | `#e6f4ea` | `#103522` |
| `zelle-geloescht` | `#fbeaea` | `#3a1416` |

| Vordergrund auf Hintergrund | Verwendung | Grenze | hell | dunkel |
|---|---|---|---|---|
| `text` auf `grund` | Fliesstext, Zellwerte | 7:1 | 15,40 ✅ | 15,46 ✅ |
| `text` auf `flaeche` | Text im Navigator und in Leisten | 7:1 | 14,37 ✅ | 14,28 ✅ |
| `text-zweit` auf `grund` | Beschriftungen, Spaltentypen | 4,5:1 | 7,53 ✅ | 8,99 ✅ |
| `text-leise` auf `grund` | NULL, Hilfetexte | 4,5:1 | 5,40 ✅ | 6,46 ✅ |
| `text-leise` auf `flaeche` | Hilfetexte auf Flächen | 4,5:1 | 5,04 ✅ | 5,97 ✅ |
| `akzent` auf `grund` | Links, Fokus, aktive Reiter | 4,5:1 | 5,89 ✅ | 9,09 ✅ |
| `akzent-text` auf `akzent` | Text auf Hauptknopf | 4,5:1 | 5,89 ✅ | 7,31 ✅ |
| `text` auf `akzent-hell` | Text in geänderter Zelle | 7:1 | 13,41 ✅ | 10,75 ✅ |
| `erfolg` auf `grund` | Erfolgsmeldung | 4,5:1 | 5,35 ✅ | 9,74 ✅ |
| `warnung` auf `grund` | Warnung, Umgebung Test | 4,5:1 | 5,43 ✅ | 10,52 ✅ |
| `gefahr` auf `grund` | Fehler, Umgebung Produktion | 4,5:1 | 6,54 ✅ | 8,25 ✅ |
| `gefahr-text` auf `gefahr` | Text auf Gefahr-Knopf | 4,5:1 | 6,54 ✅ | 7,46 ✅ |
| `gefahr` auf `gefahr-hell` | Produktions-Chip, Fehlerkarte | 4,5:1 | 5,62 ✅ | 7,12 ✅ |
| `text` auf `zelle-neu` | Text in neuer Zeile | 7:1 | 13,56 ✅ | 11,09 ✅ |
| `text` auf `zelle-geloescht` | Text in gelöschter Zeile | 7:1 | 13,24 ✅ | 13,35 ✅ |
| `rand` auf `grund` | Rasterlinien (Schmuck, keine Information) | 1,3:1 | 1,30 ✅ | 1,55 ✅ |
| `akzent` auf `flaeche` | Fokusrahmen (Nicht-Text, WCAG 1.4.11) | 3:1 | 5,49 ✅ | 8,39 ✅ |
<!-- kontraste:end -->

**Zuordnung zu den Variablen des Starter-Kits:** `grund` → `--background`, `--card`, `--popover` · `text` → `--foreground` · `akzent` → `--primary`, `--ring` · `akzent-text` → `--primary-foreground` · `flaeche` → `--muted`, `--secondary`, `--sidebar-background` · `text-leise` → `--muted-foreground` · `rand` → `--border`, `--input` · `gefahr` → `--destructive` · `gefahr-text` → `--destructive-foreground`.

**Regeln.** Akzent nur für eine Handlung je Bereich (der Hauptknopf), für Fokus und für die aktuelle Auswahl. Gefahr nur für zerstörende Handlungen, Fehler und die Umgebung «Produktion». Farbe nie allein: Jede farbige Aussage hat auch ein Wort oder ein Symbol (WCAG 1.4.1).

## 3. Umgebungen und Rasterzustände

| Zustand | Darstellung | Wo |
|---|---|---|
| Umgebung **lokal** | Schiefer, Punkt ○ | Verbindungs-Chip, Reiter, Seitentitel-Präfix fehlt |
| Umgebung **Test** | Bernstein, Punkt ◐, Präfix «TEST ·» im Seitentitel | dito |
| Umgebung **Produktion** | Rot, Punkt ●, Präfix «PROD ·», rotes Favicon, Schloss-Symbol wenn schreibgeschützt | dito; zusätzlich roter 2-px-Streifen am oberen Rand |
| Zelle **geändert** | Fläche `zelle-geaendert`, Wert fett, kleiner Punkt in der Ecke | Datenraster, bis «Anwenden» oder «Verwerfen» |
| Zeile **neu** | Fläche `zelle-neu`, Zeilennummer «+» | dito |
| Zeile **zum Löschen** | Fläche `zelle-geloescht`, Werte durchgestrichen, Zeilennummer «−» | dito |
| Wert **NULL** | `NULL` kursiv in `text-leise` — nie leer, damit NULL und leerer Text unterscheidbar bleiben | überall |
| Fremdschlüssel | Pfeil → vor dem Wert, Klick folgt dem Verweis | Datenraster |

**Favicon je Umgebung (umgesetzt 31.10.2026):** Ist eine Verbindung der Umgebung Produktion offen, zeigt der Tab die
Bildmarke auf Rot (`favicon-produktion.svg`/`.ico` aus dem [Logo-Kit](../../resources/brand/README.md), Fläche
`gefahr` #b3261e) — schon beim ersten Laden (`app.blade.php` liest die Umgebung der Seite), beim Wechsel in der App
über `useEnvironmentIcon`; beim Verlassen des Arbeitsbereichs springt es zurück. **Entscheid:** Test bekommt kein eigenes
Favicon. Rot bleibt allein der Produktion (§ 2: Gefahr nur für Zerstörendes, Fehler und Produktion), ein drittes
Symbol im Tab müsste man lernen; Test erkennt man an Bernstein und «TEST» im Verbindungs-Chip und im Seitentitel. Das
Präfix «PROD ·» bzw. «TEST ·» im Seitentitel setzt jede Seite des Arbeitsbereichs seit V-04 selbst (`titlePrefix` in
`resources/js/lib/environment.ts`; Server-Seiten über `ServerHeader`) — geprüft 31.10.2026 im Browser: «PROD · customers
· shop · MySQL · phpdbadmin», lokal ohne Präfix.

## 4. Typografie

| Rolle | Schrift | Grösse / Zeile | Hinweis |
|---|---|---|---|
| Oberfläche | **Inter** (OFL 1.1) | 14 px / 20 px, Überschriften 16–20 px | `font-variant-numeric: tabular-nums` in Rastern und Zahlen |
| Daten im Raster | Inter, tabellarische Ziffern | 13 px / 32 px Zeile (kompakt 28 px) | lange Werte abgeschnitten mit «…», ganz in der Detail-Schublade |
| SQL, DDL, Bezeichner | **JetBrains Mono** (OFL 1.1) | 13 px / 20 px | Editor, Vorschau, Typnamen |
| Kleinste Schrift | Inter | 12 px | nur Schilder und Zähler |

Beide Schriften werden wie heute Instrument Sans über `laravel-vite-plugin/fonts` im Build mitgeliefert — keine Abfrage an einen Schriftendienst zur Laufzeit. Die OFL-Lizenz der Schriften verträgt sich mit der MIT-Lizenz des Codes (die Schriftdateien behalten ihre Lizenz).

## 5. Raster, Abstände, Formen

- **4-px-Raster**; Innenabstände 8/12/16 px, Abstand zwischen Bereichen 24 px.
- **Radien:** 6 px für Felder und Knöpfe, 10 px für Karten und Schubladen, 999 px für Chips.
- **Linien:** 1 px `rand`; der Fokusrahmen ist 2 px `akzent` mit 2 px Abstand und immer sichtbar bei Tastaturbedienung.
- **Schatten:** nur für schwebende Ebenen (Befehlspalette, Menüs, Schubladen), sonst flach.
- **Symbole:** Lucide (im Starter-Kit installiert), 16 px in Leisten, 20 px in Leerzuständen; Symbole stehen nie allein ohne Beschriftung oder Tooltip.

## 6. Bausteine

| Baustein | Verhalten |
|---|---|
| **Kopfleiste** | Verbindungs-Chip (Umgebung, Name, Treiber-Schild, Schloss bei Schreibschutz) · Befehlspalette-Feld mit ⌘K · Kontomenü |
| **Navigator** | Baum Verbindung → Datenbank → Schema → Gruppe → Objekt; Suche oben; Gespeichertes und Verlauf unten; geöffnete Datenbank und geöffnetes Schema über den Pfeil zuklappbar (der Name führt zur Übersicht, der Filter klappt wieder auf); Papierkorb am Zeilenende beim Überfahren und mit dem Tastaturfokus (ohne Mauszeiger immer sichtbar): Datenbank (PostgreSQL «alle Datenbanken», MySQL/MariaDB ohne festen Datenbanknamen) bzw. Schema löschen — Vorschau mit SQL und Verlust, Bestätigung mit dem Namen, nie Vorlagen, `postgres` oder die App-Datenbank; Breite am rechten Rand ziehbar (180–640 px, höchstens 60 % des Fensters; Pfeiltasten ±16 px, mit Umschalt ±64 px; Doppelklick: 256 px), im Browser gemerkt; auf schmalen Fenstern als Schublade |
| **Schema-Übersicht** | Tabellen und Sichten als **Kacheln**, **Liste** (Spalten Name, Art, Zeilen ≈, Grösse, Kommentar; Klick auf den Kopf sortiert) oder **kompakt** (nur Namen in Spalten); Filter über Name und Kommentar, Art (alle · Tabellen · Sichten), Sortierung nach Name, Zeilen, Grösse, Art (Zeilen und Grösse zuerst absteigend, Unbekanntes hinten), Seiten mit 25/50/100/250/alle je Seite. Alles speichert das Konto von selbst (`users.preferences`), den Filter nur für das jeweilige Schema; «zurück» landet auf derselben Seite |
| **Reiter** | Daten · Struktur · Beziehungen · SQL je Objekt; offene Objekte als Reiterleiste, die umbricht statt zu scrollen |
| **Filterleiste** | Bedingungen als Chips («status = offen»), «+ Filter», Umschalter «WHERE frei»; Zeilenzahl rechts (genau oder «≈») |
| **Datenraster** | virtualisiert; erste Spalte fixiert; Spaltenbreite ziehbar und gemerkt; waagrechtes Scrollen nur hier, mit Verlaufskante und Chevron (P-10) |
| **Änderungsleiste** | erscheint erst, wenn etwas geändert ist: «n Änderungen» · SQL ansehen · Verwerfen · Anwenden |
| **SQL-Vorschau** | Schublade von rechts oder unten; Mono; Hinweis je Treiber («in einer Transaktion» bzw. «nicht rückrollbar»); Kopieren · Im Editor öffnen |
| **Bestätigung mit Namenseingabe** | für DROP, TRUNCATE, DELETE ohne WHERE und jede Änderung auf Produktion: Zahl betroffener Zeilen, Name eintippen, Knopf erst dann aktiv |
| **Detail-Schublade** | alle Felder einer Zeile, lange Werte (JSON formatiert, Binärdaten mit Grösse und Herunterladen), Verweise vor und zurück — inline, nie ein Fenster in der Schublade |
| **Fehlerkarte** | Klartext oben («Die Spalte `total` darf nicht leer sein»), darunter der Originaltext des Servers mit Code, aufklappbar; nie gebundene Werte (maskiert) |
| **Leerzustand** | Symbol, ein Satz, nächster Schritt als Knopf («Zeile einfügen», «Daten importieren») |
| **Code-Eingabe** | ein Feld je Ziffer (Wunsch Hoang, 29.10.2026 — ersetzt «ein Feld, keine Kästchen»); `one-time-code` und Einfügen verteilen den Code auf alle Felder, Rücktaste und Pfeile springen; sind alle sechs gefüllt, schickt die Seite selbst ab — kein Knopf «Anmelden». Falscher Code: Felder leer, Fokus vorne, Fehler darunter |
| **Mails** | eigenes Layout (`resources/views/mail`): Logo eingebettet (Content-ID), weisse Karte auf `flaeche`, Fusszeile mit Absender-Host; Stile inline. Code-Mail: ein Kästchen je Ziffer, darunter der Code am Stück zum Markieren per Doppelklick; die Vorschauzeile im Posteingang nennt nie den Code. Einladung mit Knopf und Link darunter |
| **Befehlspalette** | ⌘K / Ctrl+K; Objekte, Aktionen, Verbindungen, zuletzt Benutztes; Pfeiltasten und Enter |
| **Warnschild Konto** | an der Verbindung: «Dieses Konto darf Dateien auf dem Server lesen» mit Link zur Empfehlung (V-10) |

## 7. Text und Ton

- **Sie-Form**, kurze Sätze, Verben auf Knöpfen («Anwenden», «Verwerfen», «Verbindung testen»), keine Fachwörter, wo ein Alltagswort reicht — SQL-Begriffe bleiben im Original (`WHERE`, `NULL`).
- **Fehler** nennen Ursache und nächsten Schritt; der Servertext steht darunter, nicht stattdessen.
- **Englisch** britisch, gleiche Struktur; Schlüssel der Übersetzung = deutscher Text (wie Basalto).

## 8. Schmale Fenster

- Unter 768 px: Navigator als Schublade, Reiter brechen um, Raster wird zur **Kartenliste** (eine Karte je Zeile, die ersten vier Spalten, Rest in der Detail-Schublade). Bearbeiten bleibt möglich, ist aber auf den Desktop ausgelegt.
- **Keine Scrollbalken** (Regel Hoang, 15.10.2026; umgesetzt 29.10.2026): Jeder Bereich, der scrollt — Seite, Navigator, Raster, Editor, Schubladen, Befehlspalette, Reiterleiste —, blendet an dem Rand aus, an dem es weitergeht (oben, unten, links, rechts; 40 px, im Editor 24 px), und zeigt dort einen runden Chevron. Sticky Kopfzeile und `#`-Spalte des Rasters bleiben scharf; der Chevron erscheint nur, wo der Bereich zuoberst liegt (nicht unter einem Dialog). Umsetzung zentral in `resources/js/lib/scrollHints.ts` und `app.css`: Bauteile mit `overflow-auto`/`-y-auto`/`-x-auto` brauchen nichts weiter; fixierte Kopfzeilen nennt `data-scroll-hint-top`, fixierte Spalten `data-scroll-hint-left`.

## 9. Bewegung

Kurz und funktional: 120–160 ms für Schubladen und Menüs, keine Animation im Raster; `prefers-reduced-motion` schaltet alles ab.

## 10. Entscheide (Annahme bis zur Sichtprüfung mit TB 00)

| Nr. | Frage | Vorschlag |
|---|---|---|
| G-01 | Grundfarben | Schiefergrau mit einem Akzent Petrol `#0b6e82` (dunkel `#4fc3d9`) |
| G-02 | Schriften | Inter für die Oberfläche, JetBrains Mono für SQL und Bezeichner — beide OFL, im Build mitgeliefert |
| G-03 | Umgebungsfarben | lokal Schiefer, Test Bernstein, Produktion Rot — mit Punkt-Symbol und Präfix im Seitentitel |
| G-04 | Dichte | Standard 32 px Zeilenhöhe, «kompakt» 28 px je Konto wählbar |
| G-05 | Dunkelmodus | gleichwertig, folgt dem System, umschaltbar im Kontomenü |
| G-06 | Logo | **Logo-Kit seit 29.10.2026** ([`resources/brand`](../../resources/brand/README.md)): Bildmarke = «db»-Ligatur mit gemeinsamem Stamm aus JetBrains Mono Bold, weiss auf Petrol im abgerundeten Quadrat; Schriftzug «phpdbadmin» in JetBrains Mono SemiBold, «db» im Akzent — beides als Pfade. Logo quer und gestapelt, je hell, dunkel, schwarz, weiss; Favicons, Apple-Symbol, Link-Vorschau, Mail-Logo. In der App zeichnet `Wordmark.vue` Bildmarke und Schriftzug mit den Akzentvariablen |
| G-07 | Datenraster auf dem Telefon | Kartenliste statt Tabelle |
| G-08 | Bestätigungen | Namenseingabe nur bei zerstörenden Handlungen und auf Produktion — sonst genügt die Vorschau |
