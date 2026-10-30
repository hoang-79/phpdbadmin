#!/usr/bin/env bash
#
# Website von phpdbadmin bauen und hochladen — per SSH und rsync, z. B. zu cyon.
#
#   PHPDBADMIN_WEBSITE_ZIEL=cyon-phpdbadmin:public_html/phpdbadmin.ch website/hochladen.sh
#
# Das Ziel ist «SSH-Host:Ordner», der Ordner relativ zum Home des Kontos (ohne ~). Er gehört allein der Website:
# rsync --delete ersetzt alles darin. Deshalb lädt das Skript nur in einen leeren Ordner oder in einen, den es schon
# einmal befüllt hat (Merkmal .phpdbadmin-website). Andere Adresse als https://phpdbadmin.ch/: PHPDBADMIN_WEBSITE_URL.
set -euo pipefail

ziel="${PHPDBADMIN_WEBSITE_ZIEL:?PHPDBADMIN_WEBSITE_ZIEL fehlt, z. B. cyon-phpdbadmin:public_html/phpdbadmin.ch}"
url="${PHPDBADMIN_WEBSITE_URL:-https://phpdbadmin.ch/}"
host="${ziel%%:*}"
ordner="${ziel#*:}"
if [[ "$host" == "$ziel" || ! "$ordner" =~ ^[A-Za-z0-9._-][A-Za-z0-9._/-]*$ || "$ordner" == *..* ]]; then
    echo "Ziel als «SSH-Host:Ordner» angeben, Ordner relativ zum Home, ohne ~ und .. (bekommen: $ziel)" >&2
    exit 1
fi

cd "$(dirname "$0")/.."
python3 website/bauen.py --basis "$url"

# Nur leer (ausser der Zertifikatsprüfung in .well-known) oder schon von hier befüllt — sonst nichts löschen.
if ! ssh "$host" "mkdir -p -- '$ordner' && cd -- '$ordner' && { [ -f .phpdbadmin-website ] || [ -z \"\$(ls -A | grep -v '^\.well-known\$')\" ]; }"; then
    echo "Im Ziel $ziel liegen fremde Dateien. Prüfen und leeren (oder einen eigenen Ordner wählen), dann erneut." >&2
    exit 1
fi

rsync -az --delete --chmod=Dgo+rx,Fgo+r --exclude '.well-known/' website/dist/ "$host:$ordner/"
echo "Hochgeladen nach $ziel."

# Gegenprobe: Antwortet die Startseite, und greifen die Regeln der .htaccess?
echo
curl -sI "$url" | grep -i -E '^(HTTP|cache-control|content-security-policy|strict-transport)' || echo "$url antwortet (noch) nicht — DNS oder Zertifikat prüfen."
