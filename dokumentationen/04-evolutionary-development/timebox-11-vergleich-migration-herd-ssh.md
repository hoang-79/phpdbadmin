# Timebox 11 — Schemavergleich, Laravel-Migration, Herd-Erkennung, SSH-Tunnel

**Stand:** 29.10.2026 · ✅ gebaut und getestet in vier Scheiben auf einem eigenen Zweig (Arbeitskopie) — Browser-Durchlauf (auch auf Hoangs Mac mit Herd) und Zusammenführen im Hauptstrang offen.

## Ziel

Komfort für Entwickler: zwei Schemas vergleichen und das Ziel per Skript angleichen — auch zwischen zwei Treibern —, aus einer Tabelle eine Laravel-Migration erzeugen, Verbindungen aus Herd vorschlagen und Server hinter SSH erreichen, ohne dass dabei Geheimnisse den Server verlassen oder die erlaubten Hosts umgangen werden.

## Umfang

| Nr. | Anforderung (CR-001 § 4) | Prio | Ergebnis |
|---|---|---|---|
| S-08 | Schemavergleich zweier Datenbanken mit Änderungsskript | C | ✅ |
| S-09 | Laravel-Migration aus einer bestehenden Tabelle erzeugen | C | ✅ |
| V-07 | Herd-Dienste und `.env` der Herd-Sites als Vorschläge erkennen | C | ✅ (Durchlauf auf Hoangs Mac offen; Erkennung gegen seinen Herd-Ordner gelesen: 3 Dienste, 30 Sites in 60 ms) |
| V-08 | SSH-Tunnel | C | ✅ |

Keine Wireframes (Stufe 4); gestaltet im Stil der Wireframes 04 (Verbindung anlegen), 06 (SQL-Vorschau) und 08 (Struktur).

## Was entstanden ist

**Gestalt eines Schemas (`app/Services/Shape`).** `ShapeReader` liest Tabellen über Laravels Introspektion (Spalten, Indizes, Fremdschlüssel) und die Checks des Dialekts — mit denselben Regeln für die Art wie die Strukturansicht (JSON in MariaDB über `json_valid`, eigene Aufzählungen in PostgreSQL). Jede Spalte bekommt ihre Laravel-Spalte (`LaravelTypes`: `bigInteger` + `unsigned`, `string(100)`, `decimal(10, 2)`, `enum` auch aus den Checks, die Laravel in PostgreSQL und SQLite anlegt), Vorgaben werden vergleichbar gelesen (`DefaultValue`: MySQL ohne, MariaDB mit Anführungszeichen, PostgreSQL mit Typumwandlung, `current_timestamp()` = `now()` = `CURRENT_TIMESTAMP`).

**Vergleich (S-08).** `ShapeComparer` vergleicht Tabellen, Spalten (gleicher Treiber: Typ im Original; verschiedene Treiber: Art mit dem, was Laravel auf dem anderen Treiber daraus macht, dazu Länge, wo beide eine melden), NULL, Vorgabe, Autoinkrement, Indizes und Fremdschlüssel über Spalten und Ziel statt über Namen. `ChangeScriptBuilder` baut daraus das Skript im Dialekt des Ziels über `Blueprint::toSql()` der Zielverbindung; ausgeführt wird nichts. Entfernen, was nur im Ziel steht, nur auf Wunsch.

**Migration (S-09).** `MigrationWriter` schreibt `Schema::create` mit Kurzformen (`id()`, `timestamps()`, `softDeletes()`, `rememberToken()`), Fremdschlüssel mit Regeln, Indizes ohne Namen, wo Laravel denselben vergäbe; was Laravel nicht kennt, steht als Kommentar mit Hinweis darin.

**Herd (V-07).** `HerdDiscovery` liest `config/services.plist` (Dienste mit Port; ob einer läuft, zeigt ein offener Port), `config/valet/config.json` (geparkte Ordner und der Ordner der verknüpften Sites) und die `.env` jeder Site (`DB_*` oder `DB_URL`).

**SSH (V-08, [ADR-012](adr/ADR-012-ssh-tunnel-system-ssh.md)).** `SshTunnels` startet `ssh -N -L` über Symfony Process; `ConnectionFactory` verbindet zum lokalen Ende und gibt den Tunnel mit der Verbindung frei. `HostPolicy::resolveTunnelTarget` prüft das Ziel aus Sicht des Tunnels, `resolveSshKeyPath` Schlüsseldateien. `KnownHosts` verwaltet `storage/app/ssh/known_hosts` (Fingerabdruck, vergessen).

**Seiten und Adressen.**

| Methode | Adresse | Name | Seite |
|---|---|---|---|
| GET | `compare` | `compare` | `compare/Index` — Quelle, Ziel, Liste, Skript (Kopieren, Herunterladen, im SQL-Editor des Ziels öffnen); Einstieg «Schemas vergleichen» in der Verbindungsübersicht |
| GET | `c/{connection}/s/{schema}/o/{object}/migration` | `workspace.object.migration` | `workspace/Migration` — Datei zum Kopieren und Herunterladen; Einstieg «Laravel-Migration» in der Strukturansicht |
| DELETE | `connections/{connection}/ssh-host-key` | `connections.ssh-host-key.destroy` | Host-Schlüssel vergessen (verwalten, Protokoll `connection.ssh_host_key_forgotten`) |

Das Formular «Verbindung anlegen» zeigt Administratoren lokal die Vorschläge aus Herd (nachgeladen, mit Skelett) und hat für Server einen Abschnitt «SSH-Tunnel».

## Abnahmekriterien

| Anforderung | Kriterium | Nachweis |
|---|---|---|
| S-08 | Test mit zwei Ständen | Je Treiber zwei Schemas auf demselben Server (zweite Datenbank `…_b`, in PostgreSQL Schema `vergleich_b`, in SQLite zweite Datei): alle Unterschiede gefunden (Tabelle neu und nur im Ziel, Länge, NULL, Vorgabe, Spalte neu und nur im Ziel, eindeutiger Index statt gewöhnlichem, Index neu, Fremdschlüssel mit anderer Regel); Skript angewendet → erneuter Vergleich ohne Unterschied; ohne «entfernen» bleibt genau, was nur im Ziel steht. **Gegenseitig zwischen Treibern:** MySQL → PostgreSQL, PostgreSQL → MySQL, SQLite → MariaDB, MariaDB → SQLite, PostgreSQL → SQLite, MySQL → MariaDB — Skript angewendet, danach keine Unterschiede in Tabellen, Spalten (Art), Indizes, Fremdschlüsseln |
| S-09 | Migration erzeugt dieselbe Tabelle auf einem anderen Treiber | Generierte Datei in einen Ordner geschrieben, mit `include` geladen, `up()` gegen die Testdatenbank des anderen Treibers (MySQL → PostgreSQL, PostgreSQL → MySQL, SQLite → MariaDB, MariaDB → SQLite), danach mit dem Vergleich aus S-08 geprüft: drei Tabellen mit Fremdschlüssel ohne Unterschied |
| V-07 | Durchlauf auf Hoangs Mac | Test gegen einen nachgebauten Herd-Ordner (Dienste, geparkte und verknüpfte Sites, `.env` mit `DB_URL`, SQLite, nicht erlaubter Host); Passwörter weder im Vorschlag noch in der nachgeladenen Antwort; Passwort nur für denselben Host, Port und Benutzer. Erkennung gegen Hoangs echten Herd-Ordner gelesen (ohne Passwörter auszugeben). **Browser-Durchlauf offen** |
| V-08 | Test gegen einen lokalen SSH-Dienst | `SshTunnelTest` gegen `PHPDBADMIN_TEST_SSH_*`: Tunnel zu MariaDB mit Fingerabdruck, gespeicherte Verbindung öffnet lesend und schreibend über **einen** Tunnel und schliesst ihn am Ende, Schlüssel abgelehnt, Host-Schlüssel geändert, Datenbank hinter dem Tunnel nicht erreichbar. Auf diesem Mac läuft kein SSH-Dienst (Remote Login aus) — der Test überspringt dann sichtbar; für die Abnahme lief er gegen einen vorübergehend gestarteten `sshd` als Benutzer auf `127.0.0.1:22222` (nur Schlüssel), 10 von 10 bestanden. Ohne Dienst laufen immer: falsche und richtige Passphrase, nicht erlaubter SSH-Server, Ziel ausserhalb der Liste, Schlüsseldatei ausserhalb der Ordner |

Querschnitt: Vergleich und Migration brauchen nur «ansehen» (es wird nichts ausgeführt), fremde Verbindungen sind 404 (T-16). Passwort, Schlüssel und Passphrase gehen nie an den Browser (Seiten, nachgeladene Eigenschaften, JSON von «Verbindung testen») und nie ins Protokoll — dort steht nur `ssh: true`. Schlüssel liegen verschlüsselt (Rohwert ≠ Klartext, Test).

## Gestaltungsentscheide

| Bereich | Entscheid | Grund |
|---|---|---|
| Vergleich | eigene Seite unter `compare`, Einstieg in der Übersicht; Quelle und Ziel nebeneinander, auf dem Telefon untereinander mit Tausch-Knopf | zwei Verbindungen passen nicht in den Arbeitsbildschirm einer Verbindung |
| Vergleichen | erst auf Knopfdruck (`run=1`), alles in der Adresse | die Wahl lädt nur die Listen; ein Vergleich lässt sich neu laden und teilen |
| Begriffe | «neu» (nur in der Quelle, wird angelegt), «nur im Ziel», «geändert» mit den Merkmalen (Typ, Länge, NULL, Vorgabe) | «fehlt» wäre je nach Blickrichtung doppeldeutig |
| Skript | Kopf mit Quelle, Ziel, Verfahren des Treibers (ADR-003); Hinweise über dem Skript; im SQL-Editor bis 4'000 Zeichen | die Adresse des Editors trägt die Vorlage; längere Skripte kopieren oder herunterladen |
| Migration | eigene Seite mit Kopf der Tabelle; in der Strukturansicht nur ein Link | Struktur bearbeitet parallel TB 05 |
| Herd | Dienste zuerst, dann sechs Sites, «Alle zeigen»; nicht erlaubte Vorschläge sichtbar, aber gesperrt | keine Scrollbalken; man sieht, was ginge |
| SSH | Abschnitt im Formular, Schlüssel einfügen oder Datei auf dem Server; Fingerabdruck nach dem Test; «Host-Schlüssel vergessen» nur, wenn einer gespeichert ist | Fingerabdruck beim ersten Mal vergleichen können |

## Entscheide in der Timebox

| Entscheid | Begründung |
|---|---|
| SSH über das System-ssh, nicht `ssh2` oder phpseclib ([ADR-012](adr/ADR-012-ssh-tunnel-system-ssh.md)) | keine neue Abhängigkeit; PDO verbindet gewöhnlich zum lokalen Ende |
| `HostKeyAlias` = Name des SSH-Servers, verbunden wird zur geprüften Adresse | DNS-Rebinding bleibt ausgeschlossen, und ein anderer Server unter demselben Namen fällt trotzdem auf |
| Passphrase mit `ssh-keygen -p` über die Standardeingabe aus einer Kopie entfernen | `BatchMode` fragt nie; über die Befehlszeile stünde sie in `ps` |
| `LogLevel=INFO` | nur so meldet ssh «channel N: open failed», wenn die Datenbank hinter dem Tunnel fehlt |
| `TunnelFailed` als Unterart von `ConnectionNotAllowed` | alle Fehlerkarten (Arbeitsbildschirm, Raster, Editor, Server) zeigen den Tunnel ohne eigene Zweige |
| Ziel hinter dem Tunnel: erlaubter Name unverändert, sonst geprüfte Adresse | «nach denselben Regeln aus Sicht des Tunnels» — der Tunnel öffnet keinen Weg, den die Liste nicht erlaubt |
| Zwischen Treibern Art statt Typ vergleichen, Länge nur, wo beide eine kennen | SQLite speichert keine Länge, PostgreSQL kennt kein `unsigned`, `dateTime` und `timestamp` sind dort gleich |
| Spalten von Fremdschlüsseln bekommen den Ganzzahltyp ihres Ziels (Skript und Migration) | MySQL lehnt `bigint` → `bigint unsigned` ab; aus PostgreSQL und SQLite kommen Schlüssel ohne «unsigned» |
| Index, den MySQL für einen Fremdschlüssel selbst anlegt, zählt nicht | er fehlte sonst in jedem Vergleich gegen PostgreSQL und SQLite |
| Namen von Indizes und Schlüsseln zwischen Treibern neu vergeben (`tabelle_spalten_art`, höchstens 60 Zeichen) | in PostgreSQL und SQLite sind Indexnamen je Schema eindeutig, in MySQL je Tabelle |
| SQLite: alles einer Tabelle in einem Blueprint, Umbau-Befehle zusammen | sonst liest der zweite Umbau den alten Stand und stellt einen entfernten Fremdschlüssel wieder her |
| PostgreSQL: geänderte Aufzählung als Text ändern, Check bleibt | `ALTER COLUMN … TYPE` kann keinen Check anlegen |
| Herd nur bei `APP_ENV=local` und vorhandenem Ordner, nur Administratoren; Passwort nur für denselben Host, Port und Benutzer | fremde `.env` enthalten Geheimnisse anderer Projekte |
| Migration ohne `down()` für die SSH-Spalten | der Wächter `db-migration-guard` lässt kein Entfernen von Spalten zu |

## Tests

111 neue Tests; Suite gesamt 949, davon 924 bestanden und 25 übersprungen (20 wie bisher, dazu die 5 Tests gegen einen SSH-Dienst, der auf diesem Mac nicht läuft). Mit dem vorübergehenden SSH-Dienst bestand `SshTunnelTest` 10 von 10. Testläufe mit `PHPDBADMIN_TEST_DATABASE=phpdbadmin_test_vergleich`; zweite Datenbanken heissen `phpdbadmin_test_vergleich_b` bzw. Schema `vergleich_b` und werden im Test wieder entfernt.

| Datei | Tests | MySQL | MariaDB | PostgreSQL | SQLite | ohne Datenbank |
|---|---|---|---|---|---|---|
| `Services/Shape/ShapeComparerTest` (je Treiber 3, sechs Treiberpaare) | 18 | 3 + 3 Paare | 3 + 3 Paare | 3 + 3 Paare | 3 + 3 Paare | — |
| `Services/Shape/MigrationWriterTest` (vier Paare, Methoden, Ausbruch) | 6 | 1 + 2 Paare | 2 Paare | 2 Paare | 2 Paare | 1 |
| `Http/Controllers/CompareControllerTest` | 7 | — | — | — | 7 | — |
| `Http/Controllers/Workspace/MigrationControllerTest` | 3 | — | — | — | 3 | — |
| `Unit/Services/Shape/DefaultValueTest` | 28 | — | — | — | — | 28 |
| `Connections/SshTunnelTest` (5 gegen SSH-Dienst, 5 immer) | 10 | — | 5 | — | — | 5 |
| `Unit/Connections/SshCommandTest`, `SshTunnelsTest` | 15 | — | — | — | — | 15 |
| `Unit/Connections/HostPolicyTest` (neu: Ziel hinter dem Tunnel, Schlüsseldatei) | 9 | — | — | — | — | 9 |
| `Connections/HerdDiscoveryTest` | 4 | — | — | — | — | 4 |
| `Http/Controllers/Connections/ConnectionControllerTest` (neu: SSH 7, Herd 3) | 10 | — | — | — | — | 10 |
| `Http/Controllers/Connections/TestConnectionControllerTest` (neu: SSH) | 1 | — | — | — | — | 1 |
| **zusammen** | **111** | | | | | |

Prüfkette vor jedem Commit: `vp check`, `vue-tsc`, Pint, Larastan, PHPUnit — grün.

**SSH-Dienst für den Test** (nicht im Repo; so lief die Abnahme): Host- und Client-Schlüssel mit `ssh-keygen`, `sshd_config` mit `Port 22222`, `ListenAddress 127.0.0.1`, eigener `AuthorizedKeysFile`, `PasswordAuthentication no`, `UsePAM no`, `AllowTcpForwarding local`; Start mit `/usr/sbin/sshd -D -e -f sshd_config` als Benutzer, danach `PHPDBADMIN_TEST_SSH_PORT=22222 PHPDBADMIN_TEST_SSH_KEY=<client_key> php artisan test --filter SshTunnelTest`.

## Commits

`59cd1af` Scheibe 1: Schemavergleich mit Änderungsskript (S-08) · `c1d4fa8` Scheibe 2: Laravel-Migration (S-09) · `3c7fffc` Scheibe 3: SSH-Tunnel (V-08) · `ccb37c2` SSH: Datenbank hinter dem Tunnel · `998012a` Scheibe 4: Herd-Erkennung (V-07) · `f1af885` SSH: Schlüsseldatei bleibt beim Bearbeiten · Doku (dieser Commit).

## Offen

- **Browser-Durchlauf** bei 1440, 768 und 375 px, hell und dunkel — Vergleich, Migration, Formular mit Herd-Vorschlägen und SSH-Abschnitt — Hauptstrang; V-07 auf Hoangs Mac.
- **Vergleich unter der Verbindung:** die Seite nimmt `?source=<Verbindung>` schon an; ein Einstieg aus dem Arbeitsbildschirm fehlt (Navigator und Kopf gehören TB 10a bzw. dem Hauptstrang).
- **Nicht übertragbar** und als Hinweis im Skript: Checks ausser Aufzählung und JSON, eigene Typen in PostgreSQL (Aufzählungen als Text), Ausdrücke als Vorgabe zwischen Treibern (ausser `CURRENT_TIMESTAMP`), berechnete Spalten zwischen Treibern, `tinyblob`/`mediumblob`/`longblob` (Laravel kennt nur `blob`), Kommentare und Kollationen werden nicht verglichen.
- **Volltext** wird nur zwischen MySQL und MariaDB übertragen; nach PostgreSQL und SQLite als gewöhnlicher Index (Hinweis), von PostgreSQL ein Index auf `text` nach MySQL scheitert ohne Präfixlänge.
- **SQLite-Umbau** im Skript braucht beim Ausführen `PRAGMA foreign_keys = OFF` nur, wenn die Zielverbindung Fremdschlüssel prüft — Laravel schreibt die Pragmas dann selbst ins Skript.
- Ein Tunnel prüft die Bereitschaft, indem er einmal zum lokalen Ende verbindet — die Datenbank sieht dabei eine leere Verbindung.
- SSH-Agent, Passwort-Anmeldung bei SSH und Sprungserver (`ProxyJump`) sind bewusst nicht im Umfang.

## Gelernt

- PHP merkt sich aufgelöste Pfade: `symlink()` meldet nach `rm -rf` im selben Prozess «File exists», bis `clearstatcache(true)` (Lektion im Vault).
- OpenSSH schreibt «channel N: open failed» erst ab `LogLevel=INFO` — mit `ERROR` bleibt ein unerreichbares Ziel hinter dem Tunnel stumm (Lektion im Vault).
- `ssh-keygen -p` liest die alte Passphrase von der Standardeingabe, wenn kein Terminal da ist — Geheimnisse an Hilfsprozesse über stdin, nie über die Befehlszeile (Lektion im Vault).
- Der Sprachtest entfernt Kommentare per Muster und sieht `'/**'` in einer PHP-Zeichenkette als Kommentar — Übersetzungen dazwischen galten als «nicht verwendet» (Lektion im Vault).
- Ein `sshd` als Benutzer auf einem hohen Port reicht auf macOS für echte Tunnel-Tests, ohne Remote Login einzuschalten (Lektion im Vault).
