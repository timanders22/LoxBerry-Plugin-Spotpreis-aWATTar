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

# ---------- Langzeitwerte zurueckholen ----------
# Gegenstueck zu preupgrade.sh. Zwischen beiden Skripten hat der Installer
# data/plugins/<x>/ vollstaendig geloescht; der Nachbar mit dem Punkt hat es
# ueberstanden. Zurueckgeholt wird nur, was fehlt - eine Neuinstallation
# findet nichts vor und faengt sauber bei null an.
LANG_SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
GERETTET=1
if [ -d "$LANG_SICHER" ]; then
    # Dieselbe Menge wie in preupgrade.sh: Historie, Laufzaehler und die
    # Merker. Zurueckgeholt wird nur, was fehlt - eine Neuinstallation
    # findet nichts vor und faengt sauber bei null an.
    MERKER=$(cd "$LANG_SICHER" 2>/dev/null && ls marke_* 2>/dev/null)
    for LANG_F in history.csv laufzaehler $MERKER; do
        if [ -f "$LANG_SICHER/$LANG_F" ] \
           && [ ! -s "$BASE/data/plugins/$PFOLDER/$LANG_F" ]; then
            mkdir -p "$BASE/data/plugins/$PFOLDER" 2>/dev/null
            cp -p "$LANG_SICHER/$LANG_F" "$BASE/data/plugins/$PFOLDER/$LANG_F" 2>/dev/null
            # Die WIRKUNG pruefen, nicht den Rueckgabewert - genau so,
            # wie es preupgrade.sh drei Zeilen vor seinem exit vormacht.
            if [ -s "$BASE/data/plugins/$PFOLDER/$LANG_F" ]; then
                echo "<OK> $LANG_F ueber das Update gerettet."
            else
                echo "<WARNING> $LANG_F konnte nicht zurueckgeholt werden."
                echo "<WARNING> Die Sicherung bleibt liegen: $LANG_SICHER"
                GERETTET=0
            fi
        fi
    done
    # Erst wegraeumen, wenn wirklich alles angekommen ist. Bis 1.2.18
    # stand das rm -rf ohne Bedingung hier. Schlug das cp fehl - Platte
    # voll, Rechte, kaputter Zielordner -, verschluckte 2>/dev/null die
    # Ursache, das && verschluckte die Erfolgsmeldung, und danach war
    # die einzige Kopie der Preishistorie weg. Sie ist die eine Datei,
    # die preupgrade.sh als nicht nachladbar bezeichnet.
    if [ "$GERETTET" = "1" ]; then
        rm -rf "$LANG_SICHER" 2>/dev/null
    fi
fi
exit 0
