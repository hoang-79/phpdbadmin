# ADR-012 — SSH-Tunnel über das System-ssh

| Feld | Wert |
|---|---|
| Status | Angenommen |
| Datum | 29.10.2026 (TB 11, offener Punkt der Solution-Architektur § 16) |
| Betrifft | ConnectionFactory, SshTunnels, SshCommand, HostPolicy, ConnectionTester |

## Kontext

Datenbanken auf Servern sind oft nur über SSH erreichbar (V-08). phpdbadmin baut Verbindungen je Anfrage (ADR-002) und prüft jeden Host gegen die erlaubte Liste (V-05, T-05). Ein Tunnel darf diese Prüfung nicht umgehen, Schlüssel dürfen nicht im Klartext auf der Platte liegen, und kein Hilfsprozess darf die Anfrage überleben. Neue Abhängigkeiten sind nicht erwünscht.

## Optionen

| Option | Vorteile | Nachteile |
|---|---|---|
| A — PHP-Erweiterung `ssh2` | in PHP, keine Prozesse | Erweiterung fehlt in Herd und bei den meisten Hostern; Weiterleitung nur als Stream, PDO kann ihn nicht nutzen |
| B — Paket `phpseclib` | reines PHP | neue Abhängigkeit; Weiterleitung bräuchte einen eigenen lokalen Socket-Server in PHP |
| **C — `ssh -N -L` als Hilfsprozess** | OpenSSH ist überall da, wo es Server gibt; Host-Schlüssel, Algorithmen und Agent-freie Anmeldung erprobt; PDO verbindet ganz gewöhnlich zu `127.0.0.1:<Port>` | Prozess je Tunnel; Meldungen nur als Text auf stderr; `proc_open` muss erlaubt sein |

## Entscheid

**Option C.** `SshTunnels` startet `ssh` über Symfony Process mit einer Argumentliste (nie über eine Shell): `-N -F /dev/null -L 127.0.0.1:<freier Port>:<Ziel>:<Port>`, `BatchMode=yes`, `ExitOnForwardFailure=yes`, `StrictHostKeyChecking=accept-new` gegen die eigene `storage/app/ssh/known_hosts` mit `HostKeyAlias` = Name des Servers, ohne Agent und ohne fremde Schlüssel, `LogLevel=INFO` (nur so meldet ssh ein unerreichbares Ziel hinter dem Tunnel). Der private Schlüssel liegt verschlüsselt in der App-Datenbank (oder als Datei in einem erlaubten Ordner) und für ssh nur als Kopie mit 0600, bis die Anmeldung steht; eine Passphrase entfernt `ssh-keygen -p` aus dieser Kopie, die Passphrase geht über die Standardeingabe. Bereit ist der Tunnel, wenn das lokale Ende Verbindungen annimmt (Zeitgrenze `PHPDBADMIN_SSH_TIMEOUT`).

Geprüft wird vor dem Start: der SSH-Server wie jeder Host, das Ziel aus Sicht des Tunnels nach denselben Regeln (`HostPolicy::resolveTunnelTarget`: ein erlaubter Name geht unverändert an den SSH-Server, sonst nur eine geprüfte Adresse).

## Konsequenzen

- Ein Tunnel lebt so lange wie seine Verbindung (`ConnectionFactory::release`), mehrere Verbindungen einer Anfrage teilen ihn; am Ende der Anfrage (`terminating`) und notfalls beim Herunterfahren von PHP werden alle beendet.
- «Verbindung testen» zeigt den Tunnel als eigenen Schritt mit Fingerabdruck; Fehler werden aus der Meldung von ssh zugeordnet (Schlüssel abgelehnt, Host-Schlüssel geändert, nicht erreichbar, Zeitüberschreitung, Ziel hinter dem Tunnel).
- `TunnelFailed` ist eine Unterart von `ConnectionNotAllowed` — überall, wo eine Verbindung nicht zustande kommt, erscheint der Tunnel mit derselben Fehlerkarte.
- Ohne `proc_open` oder ohne OpenSSH gibt es keinen Tunnel (Meldung «SSH nicht verfügbar»); SQLite braucht nie einen.

Siehe [Solution-Architektur](../../03-foundation/solution-architecture.md) · [Protokoll TB 11](../timebox-11-vergleich-migration-herd-ssh.md)
