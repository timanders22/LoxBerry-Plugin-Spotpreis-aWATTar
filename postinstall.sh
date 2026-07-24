#!/bin/bash
# Spotpreis aWATTar - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-spotpreis}"
BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
if [ ! -f "$BASE/config/plugins/$PFOLDER/spot.json" ]; then
    echo '{}' > "$BASE/config/plugins/$PFOLDER/spot.json"
fi
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/spot.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF"
        echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi
echo "<OK> Installation abgeschlossen. Bitte Plugin-Oberflaeche oeffnen und Preisbestandteile pruefen."
exit 0
