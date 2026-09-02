#!/bin/bash
# Spotpreis aWATTar - postupgrade: Konfiguration wiederherstellen
#
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# Zum Sicherungsort und zur Bedeutung der Argumente siehe preupgrade.sh.
#
# Anmerkung zur Notwendigkeit: dieses Plugin liefert KEINEN config-Ordner
# mit, der Installer kann die spot.json also gar nicht ueberschreiben, und
# LoxBerry loescht config/plugins/<ordner> beim Upgrade nicht. Die Sicherung
# ist damit ein zweiter Boden, kein tragendes Teil - sie bleibt trotzdem,
# weil sie nichts kostet und der Tag kommt, an dem doch eine Vorlage
# mitgeliefert wird.

ARGV1=$1
ARGV3=$3
ARGV5=$5
ARGV6=$6

PFOLDER="${ARGV3:-spotpreis}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Der Sicherungsort wird aus DEMSELBEN Argument gerechnet wie in
# preupgrade.sh - siehe die ausfuehrliche Begruendung dort. Ein Merker
# .upgrade_pfad im Konfigurationsordner stand hier bis 02.09.2026 an erster
# Stelle; purge_installation entfernt dieses Verzeichnis, bevor dieses Skript
# laeuft, der Zweig war also tot.
if [ -n "$ARGV6" ] && [ -d "$ARGV6" ]; then
    SICHERUNG="$ARGV6/spotpreis_upgrade"
else
    SICHERUNG="${ARGV1:-spotpreis}_upgrade"
fi

mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" \
         "$BASE/data/plugins/$PFOLDER" 2>/dev/null

if [ -f "$SICHERUNG/spot.json" ]; then
    cp -p "$SICHERUNG/spot.json" "$BASE/config/plugins/$PFOLDER/spot.json" && \
        echo "<OK> Konfiguration wiederhergestellt."
else
    echo "<INFO> Keine gesicherte Konfiguration unter $SICHERUNG - vorhandene bleibt unveraendert."
fi

# Zweiter Boden: die Sicherungskopie, die die Oberflaeche bei jedem
# Speichern neben dem Konfigordner ablegt.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/spot.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF" && echo "<OK> Konfiguration aus der Sicherungskopie geholt."
    fi
fi

# Hier stand "rm -f $MERKER". Mit dem Merker ist auch das entfallen - die
# Variable gab es danach nicht mehr, und "rm -f ''" ist kein Aufraeumen,
# sondern eine Zeile, die aussieht wie eines.
# Der Arbeitsordner des Installers wird von LoxBerry selbst aufgeraeumt.
# Nur der Rueckfallweg gehoert uns.
case "$SICHERUNG" in
    /*) : ;;
    *)  rm -rf "$SICHERUNG" ;;
esac

exit 0
