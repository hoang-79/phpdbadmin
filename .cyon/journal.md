# cyon-Journal

## 30.10.2026 — Website phpdbadmin.net veröffentlicht

- Absicht: Statische Landingpage aus `website/` auf der eigenen Domain (Entscheid Hoang: «Eigene Domain», danach
  `phpdbadmin.net` bei cyon registriert und «du kannst direkt alles machen dort»).
- Vorgefunden per MCP `domain_list`/`site_get`: `phpdbadmin.net` als Addon-Domain im Hosting `hegodidy`
  (Webhosting Double, `s116`), Dokumentstamm `/home/hegodidy/public_html/phpdbadmin.net` (nur leerer `cgi-bin`),
  DNS zeigt hierher, TLS Let's Encrypt für `phpdbadmin.net` und `www.phpdbadmin.net` aktiv bis 28.01.2027,
  Weiterleitung auf HTTPS aktiv.
- Per MCP `deploy_prepare` (static, `dedicated_static_output`): Ziel bestätigt, Verfahren rsync über SSH.
- Per SSH (`cyon-krelora`, bestehender Schlüssel, kein Deploy-Schlüssel nötig):
  `PHPDBADMIN_WEBSITE_ZIEL=cyon-krelora:public_html/phpdbadmin.net website/hochladen.sh` — baut `website/dist`
  und lädt mit `rsync -az --delete --chmod=Dgo+rx,Fgo+r --exclude '.well-known/' --exclude 'cgi-bin/'` hoch.
  Merkmal `.phpdbadmin-website` im Ziel; das Skript ersetzt nur einen leeren oder schon so befüllten Ordner.
- Danach geprüft: alle sieben Seiten 200, unbekannte Pfade 404 mit eigener Seite, `http://` und `www.` per 301
  auf `https://phpdbadmin.net/`, Ordnerlisten 403, CSP/HSTS/Cache-Header aus der `.htaccess` kommen an, im
  Browser nur Anfragen an die eigene Domain und keine Konsolenmeldung.
- Keine Sicherung nötig (Ordner war leer). Aktualisieren: derselbe Befehl; zurück: vorherigen Stand aus Git bauen
  und hochladen.

## 13.11.2026 — Website auf Version 1.4.0

- `PHPDBADMIN_WEBSITE_ZIEL=cyon-krelora:public_html/phpdbadmin.net website/hochladen.sh` (rsync `--delete` in den
  Ordner mit Marker `.phpdbadmin-website`): Version 1.4.0 im Kopf, Fuss und in den strukturierten Daten, Kachel
  «Server-Verwaltung» nennt Analysieren, Optimieren, Prüfen und Reparieren.
- Danach geprüft: Startseite DE und EN zeigen 1.4.0 und den neuen Text, CSP/HSTS/Cache-Header wie bisher.
- Zurück: vorherigen Stand aus Git bauen und mit demselben Befehl hochladen.

## 14.11.2026 — Website auf Version 1.5.0

- `PHPDBADMIN_WEBSITE_ZIEL=cyon-krelora:public_html/phpdbadmin.net website/hochladen.sh` (zweimal, rsync `--delete` in
  den Ordner mit Marker `.phpdbadmin-website`): Version 1.5.0 im Kopf, Fuss und in den strukturierten Daten; «Wie
  aktualisiere ich?» und das Kleingedruckte zur Installation nennen den Knopf unter Verwaltung → Aktualisierung.
- Zweiter Upload: «Verlassen meine Daten den Server?» und die Datenschutzerklärung (DE/EN, Stand 14.11.2026) nennen
  das Nachsehen nach neuen Versionen bei GitHub (Adresse des Servers und Version, abschaltbar).
- Danach geprüft: alle vier Seiten zeigen die neuen Texte, CSP/HSTS/Cache-Header wie bisher.
- Zurück: vorherigen Stand aus Git bauen und mit demselben Befehl hochladen.
