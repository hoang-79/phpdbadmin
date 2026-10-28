#!/bin/sh
# Richtet den Git-Hook ein, der vor jedem Commit prüft, ob alle PDF-Dossiers auf dem Stand ihrer Quellen sind.
# Er baut nichts selbst (das dauert), sondern bricht den Commit mit dem Hinweis ab, welche Dossiers neu zu bauen sind.
# Aufruf einmalig: sh dokumentationen/werkzeuge/hook-einrichten.sh
set -e
WURZEL=$(git rev-parse --show-toplevel)
HOOK="$WURZEL/.git/hooks/pre-commit"
cat > "$HOOK" <<'HOOKEOF'
#!/bin/sh
# Nur prüfen, wenn Dokumentation im Commit steckt
if git diff --cached --name-only | grep -q '^dokumentationen/'; then
  python3 dokumentationen/werkzeuge/pdf-bauen.py --pruefen || {
    echo "→ PDF-Dossiers neu bauen und mit 'git add dokumentationen' in den Commit nehmen."; exit 1; }
fi
HOOKEOF
chmod +x "$HOOK"
echo "Hook eingerichtet: $HOOK"
