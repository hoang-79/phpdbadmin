## Anmelden {#anmelden}

phpdbadmin kennt kein Passwort für Konten. Sie melden sich mit einem **Passkey** an (Fingerabdruck, Gesicht oder Geräte-PIN) oder mit einem **Code per Mail**, der 10 Minuten gilt. Passkeys verwalten Sie unter _Konto → Sicherheit_.

Neue Konten entstehen nur per **Einladung** durch eine Administratorin oder einen Administrator.

## Verbindungen {#verbindungen}

Eine Verbindung führt zu einem Datenbank-Server (MariaDB, MySQL, PostgreSQL) oder zu einer SQLite-Datei. Beim Anlegen testet phpdbadmin die Verbindung und warnt, wenn das Datenbankkonto mehr darf als nötig (Superuser, Dateien auf dem Server).

- **Umgebung** — _lokal_, _Test_ oder _Produktion_: Farbe, Symbol und Wort im Kopf zeigen immer, wo Sie gerade arbeiten.
- **Schreibgeschützt** — phpdbadmin schreibt über diese Verbindung nichts, auch nicht im SQL-Editor.
- **Passwort nur für diese Sitzung** — phpdbadmin speichert es nicht und fragt nach jeder Anmeldung neu.
- Erreichbar sind nur Server und Ordner, die in der Installation erlaubt sind (`PHPDBADMIN_ALLOWED_HOSTS`, `PHPDBADMIN_SQLITE_PATHS`).

## Arbeitsbildschirm {#arbeitsbildschirm}

Links der **Navigator** mit Datenbanken bzw. Schemas, Tabellen und Sichten — `/` setzt den Fokus in seinen Filter. Oben die **Reiter** der offenen Objekte und Abfragen. Mit **⌘K** (Windows/Linux: Strg+K) öffnet die Befehlspalette: Objekte, Verbindungen und Aktionen suchen.

## Daten ansehen {#daten}

Das Raster lädt beim Scrollen weiter. **Filter** kombinieren Sie über _+ Filter_; die Suche durchsucht Textspalten. Die Zahl der Zeilen ist bei grossen Tabellen geschätzt (≈) — _genau zählen_ fragt nach. `NULL` steht kursiv und ist etwas anderes als ein leerer Text.

Fremdschlüssel sind Links: zur Zeile, auf die sie zeigen, und im Zeilen-Detail zu den Zeilen, die auf diese zeigen. Ein Klick auf die Zeilennummer oder **Leertaste** öffnet das Zeilen-Detail.

## Daten ändern {#bearbeiten}

Doppelklick, **Eingabe** oder **F2** bearbeitet eine Zelle; im Zeilen-Detail bearbeiten Sie die ganze Zeile. Nichts wird sofort geschrieben: Geänderte Zellen sind markiert, unten zählt eine Leiste die Änderungen.

1. **Vorschau** zeigt jede Anweisung mit ihren Werten — und welche Zeilen `ON DELETE CASCADE` mitlöschen würde.
2. **Anwenden** schreibt alles in einer Transaktion: Trifft eine Anweisung nicht genau eine Zeile, wird nichts geschrieben.
3. **Rückgängig machen** stellt den Zustand vorher wieder her — solange niemand die Zeilen inzwischen geändert hat.

Auf **Produktion** geben Sie zur Bestätigung den Namen der Verbindung ein. Tabellen ohne Primärschlüssel und Sichten lassen sich nur lesen.

## Schreibschutz auf Zeit {#schreibschutz}

Eine schreibgeschützte Verbindung lässt sich für **15 Minuten** freigeben — im Menü der Verbindung, mit einem Grund. Grund und Ende stehen im Protokoll. Das geht nur mit dem Recht _schreiben_; _wieder sperren_ beendet die Freigabe sofort.

## SQL-Editor {#sql}

**⌘↩** führt die Anweisung unter dem Cursor aus, **⇧⌘↩** alle. Jede Anweisung hat eine Zeitgrenze (Vorgabe 30 Sekunden) und eine Zeilengrenze (1'000); _Abbrechen_ stoppt sie auf dem Server. Anweisungen, die Daten oder Struktur zerstören (`DROP`, `TRUNCATE`, `DELETE` ohne `WHERE` …), verlangen eine Bestätigung.

Der **Verlauf** speichert Ihre Abfragen 90 Tage; gespeicherte Abfragen bleiben, bis Sie sie löschen.

**⌘E** erklärt die Anweisung unter dem Cursor: Der Plan erscheint als Baum mit geschätzten Zeilen und Kosten, dazu Hinweise wie «vollständiger Durchlauf einer grossen Tabelle». _Mit ANALYZE messen_ führt eine lesende Anweisung dafür wirklich aus.

Mit **KI fragen** schreibt Claude eine Anweisung aus Ihrer Frage — wenn die Installation es eingeschaltet hat und Sie unter _Konto → KI-Hilfe_ Ihren eigenen Schlüssel hinterlegt haben. Gesendet werden nur Ihre Frage und das Schema (Tabellen, Spalten, Typen, Schlüssel), nie Daten. Der Vorschlag landet im Editor; ausgeführt wird er erst, wenn Sie es tun.

## Sichten, Routinen und Diagramm {#objekte}

Im Navigator stehen neben den Tabellen auch Sichten, Routinen, Trigger, Ereignisse, Sequenzen und Typen. Ihre Definition lässt sich ansehen, kopieren, im Editor öffnen und — mit dem Recht _schreiben_ — ändern oder löschen; wie beim Bearbeiten von Daten mit Vorschau zuerst.

Das **Beziehungsdiagramm** eines Schemas zeigt die Tabellen mit ihren Fremdschlüsseln. Kästen lassen sich verschieben, die Ansicht zoomen; _Als SVG_ lädt das Bild herunter. Bei sehr vielen Tabellen wählen Sie, welche erscheinen.

## Struktur ändern {#struktur}

_Tabelle ändern_ auf der Seite Struktur: Spalten hinzufügen, ändern, umbenennen, löschen; Indizes und Fremdschlüssel. Die Vorschau zeigt das SQL, das genau so ausgeführt wird. MySQL und MariaDB führen Strukturänderungen Anweisung für Anweisung aus und können sie nicht zurückrollen — die Vorschau sagt es. Löschen, Leeren und Spalten mit Daten entfernen verlangen den Namen der Tabelle. _Weitere Aktionen_ benennt um, kopiert, leert oder löscht eine Tabelle.

## Vergleichen und Migration {#vergleich}

_Schemas vergleichen_ (in der Verbindungsübersicht) stellt zwei Schemas gegenüber — auch zwischen MySQL, MariaDB, PostgreSQL und SQLite — und erzeugt ein Änderungsskript im Dialekt des Ziels. Ausgeführt wird nichts: Sie kopieren das Skript oder öffnen es im SQL-Editor des Ziels.

_Laravel-Migration_ auf der Seite Struktur erzeugt aus einer Tabelle eine Migrationsdatei für ein Laravel-Projekt.

## Export und Import {#export-import}

Im Menü der Verbindung oder mit _Exportieren_ im Kopf einer Tabelle: **CSV**, **JSON** oder **SQL**. Grosse Exporte laufen im Hintergrund; die Datei liegt 24 Stunden zum Herunterladen bereit.

Beim **Import** einer CSV-Datei ordnen Sie die Spalten zu und machen zuerst einen **Probelauf** — er zeigt Fehlerzeilen mit Zeilennummer und schreibt nichts. Eine SQL-Datei wird Anweisung für Anweisung ausgeführt.

## Server-Verwaltung {#server}

Im Menü der Verbindung unter _Server_: Übersicht, laufende **Prozesse** (beenden), **Variablen**, **Konten und Rechte** und **Wartung** (z. B. `ANALYZE`, `OPTIMIZE`, `VACUUM`). Jede Änderung zeigt zuerst ihr SQL und steht danach im Protokoll. Ändern verlangt das Recht _verwalten_ und eine Verbindung ohne Schreibschutz.

## Verbindungen über SSH {#ssh}

Ein Datenbank-Server, der nur über einen SSH-Server erreichbar ist: im Formular der Verbindung _SSH-Tunnel_ einschalten, SSH-Host, Benutzer und privaten Schlüssel angeben (eingefügt und verschlüsselt gespeichert oder als Datei in einem erlaubten Ordner). Beim ersten Verbinden merkt sich phpdbadmin den Host-Schlüssel und zeigt seinen Fingerabdruck; ändert er sich später, verbindet phpdbadmin nicht mehr.

Läuft phpdbadmin lokal mit Laravel Herd, schlägt das Formular die Herd-Dienste und die Datenbanken Ihrer Herd-Sites vor.

## Konten, Rechte und Protokoll {#konten}

Je Verbindung gibt es drei Rechte, die aufeinander aufbauen: **lesen**, **schreiben**, **verwalten**. Persönliche Verbindungen sieht nur ihr Besitzer; Administratoren teilen Verbindungen mit anderen Konten.

Das **Protokoll** (Verwaltung) hält jede schreibende Aktion fest — wer, wann, auf welcher Verbindung, mit welchem SQL. Werte stehen dort nur als Platzhalter.

Unter _Konto → Sitzungen_ sehen Sie, wo Ihr Konto angemeldet ist, und beenden fremde Sitzungen — etwa auf einem verlorenen Gerät.

## Tastenkürzel {#kuerzel}

**?** zeigt alle Tastenkürzel. Die wichtigsten: **⌘K** Befehlspalette, **/** Navigator filtern, **⌘↩** Anweisung ausführen, **⌘E** Anweisung erklären, **Eingabe** bzw. **F2** Zelle bearbeiten, **Esc** abbrechen.
