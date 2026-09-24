#!/bin/bash
# Spotpreis aWATTar - postupgrade: Konfiguration wiederherstellen
#
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# Zum Sicherungsort und zur Bedeutung der Argumente siehe preupgrade.sh.
#
# ZUR NOTWENDIGKEIT - hier stand bis 1.2.19 das GEGENTEIL:
#
#   "LoxBerry loescht config/plugins/<ordner> beim Upgrade nicht. Die
#    Sicherung ist damit ein zweiter Boden, kein tragendes Teil."
#
# Das ist falsch, und es steht im selben Ordner nachgemessen daneben: der
# Kommentar in preupgrade.sh nennt purge_installation ausdruecklich, weil
# genau dieses Verzeichnis vor postupgrade abgeraeumt wird. In
# sbin/plugininstall.pl (Zweig master, 21.08.2026) steht der Aufruf im
# UPGRADE-Zweig (:886), und sein Rumpf loescht config/plugins/<ordner>/ und
# data/plugins/<ordner>/ mit rm -rf, ohne Pruefung auf $option eq "all"
# (:1629 ff.). Erst danach legt der Installer die Ordner neu an (:916).
#
# Beim Upgrade ueberlebt in config/ und data/ also NICHTS. Diese Sicherung
# ist das tragende Teil, nicht der zweite Boden. Der zweite Boden ist die
# Sicherungskopie neben dem Konfigordner, die weiter unten gelesen wird -
# sie liegt NEBEN dem Verzeichnis und wird deshalb nicht mitgeloescht.

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
# leer, und das Skript legte config/plugins/<ordner>, log/plugins/<ordner>
# und data/plugins/<ordner> ab der LAUFWERKSWURZEL an (in WSL gemessen,
# Pruefung-Spotpreis-aWATTar-1.2.28, Fall K8). general.json ist die
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
    echo "<WARNING> Es wurde nichts angelegt und nichts zurueckgespielt."
    exit 1
fi
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
# Traegt eine spot.json INHALT? Klasse C (Bestand-2026-09-18/klasse-C):
# bis 1.2.27 wurde hier nur zurueckgespielt, wenn spot.json LEER oder "{}"
# war ([ ! -s ]), und die Zweitschrift wurde gar nicht angesehen. Eine
# abgeschnittene, kaputte oder nur Vorgaben tragende spot.json galt als
# "vorhanden", die heile Zweitschrift blieb liegen; eine kaputte Zweitschrift
# wurde ueber "{}" kopiert (in WSL gemessen, Pruefung-Spotpreis-aWATTar-1.2.28,
# Faelle Q3 bis Q6).
# Inhalt heisst: lesbares JSON-Objekt UND ein Aktionstoken ODER mindestens ein
# Wert, der von spot_vorgaben() der installierten Bibliothek abweicht. Bauart
# cf_mit_inhalt() aus Intercom 2.2.12. Rueckgabe 0 ja, 1 nein, 2 nicht
# pruefbar (kein php, keine Bibliothek) - dann wird nichts zurueckgespielt;
# spot_config() heilt eine fehlende oder leere Datei zur Laufzeit ohnehin.
sp_inhalt() {
    [ -s "$1" ] || return 1
    sp_lib="$BASE/webfrontend/html/plugins/$PFOLDER/spot_lib.php"
    if [ ! -f "$sp_lib" ] || ! command -v php >/dev/null 2>&1; then
        return 2
    fi
    php -d allow_url_fopen=0 -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d) || !$d) { exit(1); }
        if (isset($d["token"]) && is_string($d["token"]) && $d["token"] !== "") { exit(0); }
        require $argv[2];
        $v = spot_vorgaben();
        foreach ($d as $k => $w) {
            if (array_key_exists($k, $v) && $w != $v[$k]) { exit(0); }
        }
        exit(1);' "$1" "$sp_lib" >/dev/null 2>&1
    sp_rc=$?
    [ "$sp_rc" = 0 ] || [ "$sp_rc" = 1 ] || return 2
    return "$sp_rc"
}
# Zurueckgespielt wird nur, wenn spot.json KEINEN und die Zweitschrift EINEN
# Inhalt traegt. Eine verdraengte spot.json, die nicht leer und nicht "{}"
# ist, bleibt als spot.json.kaputt.<zeit> mit 0600 daneben liegen.
sp_zurueckspielen() {
    sp_inhalt "$CF"; sp_cf=$?
    sp_inhalt "$BK"; sp_bk=$?
    if [ "$sp_cf" = 1 ] && [ "$sp_bk" = 0 ]; then
        if [ -s "$CF" ] && [ "$(cat "$CF" 2>/dev/null)" != "{}" ]; then
            sp_weg="$CF.kaputt.$(date +%Y%m%d%H%M%S)"
            if cp -p "$CF" "$sp_weg" 2>/dev/null; then
                chmod 600 "$sp_weg" 2>/dev/null
                echo "<INFO> Die bisherige spot.json trug keine Einstellungen; sie liegt als $(basename "$sp_weg") daneben."
            fi
        fi
        cp -p "$BK" "$CF" && echo "$1"
    elif [ "$sp_cf" = 1 ] && [ "$sp_bk" = 1 ]; then
        echo "<INFO> Die Zweitschrift $(basename "$BK") traegt keine Einstellungen - nicht zurueckgespielt."
    elif [ "$sp_cf" = 2 ] || [ "$sp_bk" = 2 ]; then
        echo "<WARNING> Der Inhalt von spot.json bzw. der Zweitschrift liess sich nicht pruefen"
        echo "<WARNING> (php oder spot_lib.php fehlt) - nichts zurueckgespielt."
    fi
}
if [ -f "$BK" ]; then
    sp_zurueckspielen "<OK> Konfiguration aus der Sicherungskopie geholt."
fi

# Rechte NACH der Wiederherstellung, nicht davor: "cp -p" oben traegt die
# Rechte der Sicherung mit und dreht ein frueheres chmod zurueck. In dieser
# Konfiguration steht das Aktionstoken; wer es lesen kann, kann den Endpunkt
# abfragen und jedes Formular der Oberflaeche absenden. Hausstandard 0600
# (Regeln/05, 13.09.2026). Die Zweitschrift traegt dasselbe Geheimnis.
chmod 600 "$CF" 2>/dev/null
if [ -f "$BK" ]; then
    chmod 600 "$BK" 2>/dev/null
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
