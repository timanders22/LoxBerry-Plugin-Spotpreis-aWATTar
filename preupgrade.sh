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
# Die Wurzel: $5 (vom Installer) oder $LBHOMEDIR, wenn dort config/plugins
# und data/plugins liegen - sonst vom eigenen Ablageort AUFWAERTS SUCHEN, bis
# ein Verzeichnis config/plugins, data/plugins UND config/system/general.json
# traegt. Keine feste Ebenenzahl und kein fest verdrahteter Systempfad danach.
# Findet sich nichts, wird GEWARNT statt vollzogen.
#
# Bis 1.2.27 stand hier nur die Zeile darueber. Ohne beides war BASE
# leer, und das Skript legte data/plugins/<ordner>.upgrade_sicherung ab
# der LAUFWERKSWURZEL an (in WSL gemessen,
# Pruefung-Spotpreis-aWATTar-1.2.28, Fall K7). general.json ist die
# Bedingung aus dem Raumklima-Vorfall (Regeln/06): ein LoxBerry hat die Datei
# immer, ein Pruefstandsrest nie. Bauart Spotpreis-Tibber 0.9.18.
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
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    BASE=$(sp_wurzel_suchen "$(dirname "$(readlink -f "$0")")") || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden: weder als"
    echo "<WARNING> fuenftes Argument noch in \$LBHOMEDIR, und oberhalb von"
    echo "<WARNING> $(dirname "$(readlink -f "$0")") traegt kein Verzeichnis"
    echo "<WARNING> config/plugins, data/plugins und config/system/general.json."
    echo "<WARNING> Es wurde nichts gesichert."
    exit 1
fi

# ---------- Marke "Aktualisierung laeuft" - als Erstes ----------
# Entscheidung 1 (29.09.2026, ohne Altersgrenze nach Nr. 8): preinstall.sh
# und postinstall.sh erkennen eine Aktualisierung allein an dieser Marke;
# postupgrade.sh raeumt sie ab. Sie liegt NEBEN dem Datenordner, weil
# purge_installation den Ordner selbst loescht. Laesst sie sich nicht
# anlegen, hielte preinstall.sh das Update fuer eine Neuinstallation und
# legte die Zweitschrift beiseite - deshalb Rueckgabewert 2, vor jedem
# Aufraeumen (Bauform Abfahrtsassistent 1.6.21). Vorher festhalten, ob schon
# eine Marke lag: dann ist ein frueherer Versuch DIESES Updates abgebrochen
# (siehe unten bei der Update-Sicherung). Anlass: Pruefbericht installer,
# Faelle D, D2, D4 und E.
case "$PFOLDER" in
    ''|*/*|*..*)
        echo "<FAIL> Unzulaessiger Ordnername '$PFOLDER' - dieses Skript endet mit Rueckgabewert 2."
        exit 2 ;;
esac
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
SP_MARKE_VORHER=0
[ -f "$MARKE" ] && SP_MARKE_VORHER=1
# In geschweiften Klammern: sonst schreibt die Schale ihre eigene Meldung
# ("cannot create ...") am 2>/dev/null vorbei ins Protokoll.
{ date +%s > "$MARKE"; } 2>/dev/null
if ! grep -qx '[0-9][0-9]*' "$MARKE" 2>/dev/null; then
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen."
    echo "<FAIL> Ohne sie hielte die Installation dieses Update fuer eine Neuinstallation und legte"
    echo "<FAIL> die Einstellungen beiseite. Dieses Skript endet mit Rueckgabewert 2."
    exit 2
fi

if [ -n "$ARGV6" ] && [ -d "$ARGV6" ]; then
    SICHERUNG="$ARGV6/spotpreis_upgrade"
else
    echo "<INFO> Kein Arbeitsordner uebergeben - Rueckfall auf den bisherigen Weg"
    SICHERUNG="${ARGV1:-spotpreis}_upgrade"
fi

mkdir -p "$SICHERUNG" 2>/dev/null

# HIER STAND EIN MERKER .upgrade_pfad IM KONFIGURATIONSORDNER, den
# postupgrade.sh als ersten von drei Wegen lesen sollte - mit der
# Begruendung, das sei "die eine Stelle, an der beide auseinanderlaufen".
#
# Er kann dort nie ankommen: purge_installation entfernt genau dieses
# Verzeichnis, bevor postupgrade laeuft (plugininstall.pl, Aufruf im
# Upgrade-Zweig; der rm -rf trifft config/plugins/<ordner>/ ohne Pruefung
# auf $option eq "all"). Nachgestellt: nach preupgrade da, nach dem
# Abraeumen weg, nach dem Neuanlegen durch den Installer weg.
#
# Der Zweig in postupgrade.sh war damit tot und das rm -f darauf ebenfalls.
# Gefaehrlich war es nicht - der Rueckfall auf das sechste Argument traegt -,
# aber die Zusicherung im Kommentar sagte das Gegenteil dessen, was der Code
# tut. Beide Skripte rechnen den Pfad ohnehin aus DEMSELBEN Argument aus, und
# das ist die eine Stelle, an der sie nicht auseinanderlaufen koennen.
#
# Ausgebaut am 02.09.2026, zusammen mit Ultraschall, WOLF-ISM-NG und
# WaermepumpeCloud. Die Schwesterlinie Smartmeter classic hatte denselben
# Merker schon in 2.3.14 aus demselben Grund entfernt.

echo "<INFO> Sicherungsordner: $SICHERUNG"
if cp -p "$BASE/config/plugins/$PFOLDER/spot.json" "$SICHERUNG/spot.json" 2>/dev/null; then
    echo "<OK> Konfiguration gesichert."
else
    echo "<INFO> Keine bestehende spot.json gefunden - nichts zu sichern."
fi


# ---------- Langzeitwerte retten ----------
# die Preishistorie, die nur vorwaerts waechst und sich nicht nachladen laesst.
# Der Installer loescht data/plugins/<x>/ bei JEDEM Update - gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026): &purge_installation steht
# im Upgrade-Zweig (:886), und ihr Rumpf loescht ohne Bedingung (:1631).
# Deshalb NEBEN den Ordner: "rm -rf .../<x>/" trifft den Nachbarn mit dem
# Punkt nicht. postinstall.sh holt ihn zurueck und raeumt ihn weg.
LANG_SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
# Eine Update-Sicherung aus einem FRUEHEREN Vorgang zuerst wegraeumen
# (Entscheidung 1: bei einem Upgrade wird nie ein Bestand aus einem frueheren
# Vorgang eingespielt). Bisher legte dieses Skript mit mkdir -p darueber an,
# und postinstall.sh holte Historie und Merker des frueheren Vorgangs in die
# laufende Anlage (in WSL gemessen, Pruefbericht installer, Fall E).
# Ausnahme: lag die Marke schon vor diesem Lauf, ist ein Versuch DIESES
# Updates abgebrochen, womoeglich nach dem Abraeumen des Datenordners - dann
# ist die alte Sicherung der einzige Stand und bleibt.
if [ "$SP_MARKE_VORHER" = "0" ] && { [ -e "$LANG_SICHER" ] || [ -L "$LANG_SICHER" ]; }; then
    case "$LANG_SICHER" in
        */data/plugins/?*.upgrade_sicherung) rm -rf "${LANG_SICHER:?}" 2>/dev/null ;;
    esac
    if [ -e "$LANG_SICHER" ] || [ -L "$LANG_SICHER" ]; then
        echo "<FAIL> Eine Update-Sicherung aus einem frueheren Vorgang liess sich nicht entfernen: $LANG_SICHER"
        echo "<FAIL> postinstall.sh spielte sie sonst zurueck. Dieses Skript endet mit Rueckgabewert 2."
        exit 2
    fi
    echo "<INFO> Eine Update-Sicherung aus einem frueheren Vorgang wurde entfernt: $LANG_SICHER"
fi
mkdir -p "$LANG_SICHER" 2>/dev/null
chmod 0700 "$LANG_SICHER" 2>/dev/null
# Nicht nur die Historie: auch die MERKER. Sie verhindern, dass der
# Monatsbericht und die Ansage fuer morgen ein zweites Mal kommen, und
# genau dafuer liegen sie hier statt in /tmp. Ohne diese Zeilen war die
# Zusage "ueberlebt ein Plugin-Update" nicht eingeloest: ein Update am
# Monatsersten nach 8 Uhr loeschte den Merker, und der Bericht kam ein
# zweites Mal - genau das Fehlerbild, das der Ablageort vermeiden soll.
# Der Preis-Zwischenspeicher (markt_*.json) bleibt absichtlich
# draussen: er laesst sich nachladen.
# mqtt_praefixe.json (die Liste frueher benutzter MQTT-Praefixe, die die
# Oberflaeche schreibt) gehoert dazu: ohne sie raeumten Abraeumen und
# Deinstallation nach einem Update nur noch das aktuelle Praefix ab
# (Bauliste M1).
for LANG_F in history.csv laufzaehler mqtt_praefixe.json; do
    [ -f "$BASE/data/plugins/$PFOLDER/$LANG_F" ] \
        && cp -p "$BASE/data/plugins/$PFOLDER/$LANG_F" "$LANG_SICHER/$LANG_F" 2>/dev/null
done
for LANG_F in "$BASE/data/plugins/$PFOLDER"/marke_*; do
    [ -f "$LANG_F" ] && cp -p "$LANG_F" "$LANG_SICHER/" 2>/dev/null
done
# Die Wirkung pruefen, nicht den Rueckgabewert: liegt hinterher etwas da?
if [ -n "$(ls -A "$LANG_SICHER" 2>/dev/null)" ]; then
    echo "<OK> Langzeitwerte gesichert."
fi
exit 0
