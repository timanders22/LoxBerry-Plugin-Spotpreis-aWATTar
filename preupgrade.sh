#!/bin/bash
# Spotpreis aWATTar - preupgrade: Konfiguration sichern
#
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# ---------------------------------------------------------------------------
# ZU DEN ARGUMENTEN - hier wird oft das Falsche angenommen
#
# Der LoxBerry-Installer ruft dieses Skript so auf (sbin/plugininstall.pl):
#
#   cd "$tempfolder" && "$script" "$tempfile" "$pname" "$pfolder" \
#                       "$pversion" "$lbhomedir" "$tempfolder"
#
# $1 ist $tempfile - eine ZUFALLSKENNUNG aus zehn Zeichen (&generate(10)),
# KEIN Pfad. Der absolute Arbeitsordner kommt als SECHSTES Argument.
#
# Bis 1.1.1 stand hier mkdir -p "$ARGV1" und danach cp ... "$ARGV1/...".
# Das lief - aber nur, weil der Installer vorher in seinen Arbeitsordner
# wechselt: es entstand ein relativer Ordner mit dem Namen der Kennung
# darin. Ein Skript, das nur wegen des Arbeitsverzeichnisses des Aufrufers
# funktioniert, ist eine Falle fuer den naechsten, der es anfasst.
#
# Jetzt wird der Arbeitsordner ausdruecklich benutzt, mit Rueckfall auf den
# bisherigen Weg, falls eine aeltere LoxBerry-Fassung das sechste Argument
# nicht liefert.
#
# WARUM NICHT NACH /tmp: /tmp ist auf dem LoxBerry fluechtig. Der
# Arbeitsordner des Installers liegt unter data/system/tmp und wird vom
# Installer selbst aufgeraeumt - und zwar ERST NACH postupgrade
# (plugininstall.pl: der Abschnitt "Cleaning" steht hinter dem Aufruf).
#
# WARUM DAS PROTOKOLL NICHT MEHR GESICHERT WIRD: log/plugins liegt auf dem
# LoxBerry fest auf der Ramdisk (sbin/createtmpfsfoldersinit.sh bindet den
# Ordner dorthin). Es ist nach jedem Neustart ohnehin leer - eine Sicherung
# haette fluechtige Daten von der Ramdisk in die Ramdisk kopiert.
# ---------------------------------------------------------------------------

ARGV1=$1
ARGV3=$3
ARGV5=$5
ARGV6=$6

PFOLDER="${ARGV3:-spotpreis}"
BASE="${ARGV5:-$LBHOMEDIR}"

if [ -n "$ARGV6" ] && [ -d "$ARGV6" ]; then
    SICHERUNG="$ARGV6/spotpreis_upgrade"
else
    echo "<INFO> Kein Arbeitsordner uebergeben - Rueckfall auf den bisherigen Weg"
    SICHERUNG="${ARGV1:-spotpreis}_upgrade"
fi

mkdir -p "$SICHERUNG" 2>/dev/null
# Den benutzten Ort hinterlegen, damit postupgrade.sh ihn nicht erneut
# erraten muss - das waere die eine Stelle, an der beide auseinanderlaufen.
mkdir -p "$BASE/config/plugins/$PFOLDER" 2>/dev/null
echo "$SICHERUNG" > "$BASE/config/plugins/$PFOLDER/.upgrade_pfad" 2>/dev/null

echo "<INFO> Sicherungsordner: $SICHERUNG"
if cp -p "$BASE/config/plugins/$PFOLDER/spot.json" "$SICHERUNG/spot.json" 2>/dev/null; then
    echo "<OK> Konfiguration gesichert."
else
    echo "<INFO> Keine bestehende spot.json gefunden - nichts zu sichern."
fi

exit 0
