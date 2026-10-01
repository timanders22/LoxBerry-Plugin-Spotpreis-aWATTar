#!/bin/bash
# Spotpreis aWATTar - preinstall: Reste einer frueheren Installation beiseitelegen
#
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# Anlass: Pruefbericht installer (Durchgang 01.10.2026), Faelle D, D2, D4 und
# D6; Entscheidung 1 vom 29.09.2026; Bauform Abfahrtsassistent 1.6.21.
#
# Der Installer ruft dieses Skript bei JEDEM Einbau auf, nach dem Abraeumen
# der alten Fassung und VOR dem Kopieren von Konfiguration, Cron-Datei und
# Oberflaeche (sbin/plugininstall.pl: preupgrade :846, purge :874,
# preinstall :877, Cron :990, HTML :1066 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich, Entscheidung 1 und Nr. 8). Dann tut es nichts:
# Zweitschrift und Update-Sicherung werden von postinstall.sh und
# postupgrade.sh gebraucht.
#
# Ohne Marke ist es eine NEUINSTALLATION. Eine liegengebliebene Zweitschrift
# (config/plugins/<ordner>.backup.json) und eine liegengebliebene
# Update-Sicherung (data/plugins/<ordner>.upgrade_sicherung) einer frueheren
# Installation gehen nach <name>.alt, gemeldet mit genau einer <WARNING>.
# Bisher spielte postinstall.sh beide zurueck, und schon der erste
# Minutentakt nach dem Kopieren holte Aktionstoken und Tarifwerte der
# frueheren Installation aus der Zweitschrift (in WSL gemessen, Faelle D und
# D2; Fall D4: Preishistorie, Laufzaehler und Merker). Die Selbstheilung der
# Bibliothek liest .alt nie; uninstall/uninstall raeumt es ab.

ARGV3=$3
ARGV5=$5

PFOLDER="${ARGV3:-spotpreis}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Wurzelsuche wie in preupgrade.sh, postinstall.sh und postupgrade.sh. Weil
# dieses Skript Dateien verschiebt, wird config/system/general.json auch fuer
# $5 bzw. $LBHOMEDIR verlangt (Regeln/06, Raumklima-Vorfall), nicht erst in
# der Suche.
sp_wurzel_suchen() {
    sp_v=$(cd "$1" 2>/dev/null && pwd -P) || return 1
    sp_i=0
    while [ -n "$sp_v" ] && [ "$sp_v" != "/" ] && [ "$sp_i" -lt 8 ]; do
        if [ -d "$sp_v/config/plugins" ] && [ -d "$sp_v/data/plugins" ] \
           && [ -f "$sp_v/config/system/general.json" ]; then
            echo "$sp_v"
            return 0
        fi
        sp_v=$(dirname "$sp_v")
        sp_i=$((sp_i + 1))
    done
    return 1
}
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    BASE=$(sp_wurzel_suchen "$(dirname "$(readlink -f "$0")")") || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postinstall.sh und postupgrade.sh spielen zurueck.
    exit 0
fi

BK="$BASE/config/plugins/$PFOLDER.backup.json"
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
BEISEITE=""
FEST=""
for ZIEL in "$BK" "$SICHER"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        # mv -T: liegt noch ein Ordner .alt da, wuerde mv sonst HINEIN verschieben.
        mv -fT "$ZIEL" "$ZIEL.alt" 2>/dev/null
        # Die Wirkung pruefen: das Original ist weg, die Ablage ist da.
        if [ ! -e "$ZIEL" ] && [ ! -L "$ZIEL" ] && { [ -e "$ZIEL.alt" ] || [ -L "$ZIEL.alt" ]; }; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
# Die Zweitschrift traegt das Aktionstoken - auch beiseitegelegt nur fuer den Eigentuemer.
[ -f "$BK.alt" ] && [ ! -L "$BK.alt" ] && chmod 600 "$BK.alt" 2>/dev/null

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    SP_TEXT="<WARNING> Neuinstallation: Einstellungen und Bestaende einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && SP_TEXT="$SP_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && SP_TEXT="$SP_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$SP_TEXT"
fi
exit 0
