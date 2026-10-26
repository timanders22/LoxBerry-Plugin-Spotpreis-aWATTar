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

# Die Marke von preupgrade.sh (data/plugins/<ordner>.upgrade_laeuft) raeumt
# dieses Skript ab - per trap, also auch, wenn es vorzeitig endet; der
# Rueckgabewert bleibt der des Skripts. Bliebe sie liegen, hielte sich eine
# spaetere Neuinstallation fuer eine Aktualisierung (Entscheidung 1 und Nr. 8:
# ohne Altersgrenze). Anlass: Pruefbericht installer, I1; Bauform
# Abfahrtsassistent 1.6.21.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
sp_marke_weg() {
    sp_rc=$?
    case "$MARKE" in
        */data/plugins/?*.upgrade_laeuft) rm -f "${MARKE:?}" 2>/dev/null ;;
    esac
    if [ -e "$MARKE" ]; then
        echo "<WARNING> Die Marke $MARKE liess sich nicht entfernen - bitte von Hand loeschen, sonst haelt sich eine spaetere Neuinstallation fuer eine Aktualisierung."
    fi
    exit "$sp_rc"
}
trap sp_marke_weg EXIT
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

# W4 (Gruppe 27, 1.2.39): eine Konfiguration nie halb kopieren. Bis 1.2.38 stand hier "cp -p" direkt auf das
# Ziel - brach die Kopie ab (volle Platte), lag eine abgeschnittene spot.json mit dem Token da. Jetzt in eine Datei
# daneben (umask 077, also 0600 von Anfang an), Gegenprobe mit cmp, dann mv -f (unteilbar im selben Verzeichnis).
# Die Rechte setzt weiter der Block am Ende (chmod 600). Rueckgabe 0 nur, wenn das Ziel die Quelle traegt.
sp_kopie() {
    sp_neu="$2.neu.$$"
    rm -f "${sp_neu:?}" 2>/dev/null
    if ! ( umask 077; cp "$1" "$sp_neu" ) 2>/dev/null || ! cmp -s "$1" "$sp_neu"; then
        rm -f "${sp_neu:?}" 2>/dev/null
        return 1
    fi
    if ! mv -f "$sp_neu" "$2" 2>/dev/null; then
        rm -f "${sp_neu:?}" 2>/dev/null
        return 1
    fi
    return 0
}

if [ -f "$SICHERUNG/spot.json" ]; then
    sp_kopie "$SICHERUNG/spot.json" "$BASE/config/plugins/$PFOLDER/spot.json" && \
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
        sp_kopie "$BK" "$CF" && echo "$1"
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

# ---------- Gruppe 27 (1.2.39): endpunkt_ein=1 fuer bestehende Anlagen (E3; Entwurf 3.4, E-E10, N5) ----------
# Bis 1.2.38 antwortete spot.php ohne Schalter. Eine bestehende Anlage behaelt den HTTP-Lesezweig: in spot.json und in
# der Zweitschrift wird endpunkt_ein=1 nachgetragen, wenn die Datei eingerichtet ist (ein Schluessel ausser token und
# den Merkern _...) und den Schluessel noch nicht kennt. Ueber eine Nebendatei (.neu, umask 077) mit Gegenprobe (gleich
# bis auf den Schluessel), dann mv, 0600. Scheitert es, gilt die Lese-Regel in spot_config_normalisieren() (dieselbe
# Bedingung -> 1) - kein Unterschied. Ein zweiter Lauf aendert nichts; eine "{}"-Datei bleibt, wie sie ist (Neuinstallation:
# endpunkt_ein 0).
sp_endpunkt_nachtragen() {
    [ -s "$1" ] || return 0
    command -v php >/dev/null 2>&1 || { echo "<WARNING> php fehlt - endpunkt_ein in $(basename "$1") nicht nachgetragen; es gilt die Lese-Regel."; return 0; }
    php -d allow_url_fopen=0 -r 'umask(077); $f = $argv[1]; $roh = (string) @file_get_contents($f); $d = json_decode($roh, true);
        if (!is_array($d) || array_key_exists("endpunkt_ein", $d)) { exit(3); }
        $ein = false;
        foreach (array_keys($d) as $k) { $k = (string) $k; if ($k !== "token" && !($k !== "" && $k[0] === "_")) { $ein = true; break; } }
        if (!$ein) { exit(3); }
        $d["endpunkt_ein"] = 1;
        $js = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($js === false) { exit(1); }
        $neu = $f . ".neu";
        if (@file_put_contents($neu, $js) !== strlen($js)) { @unlink($neu); exit(1); }
        $g = json_decode((string) @file_get_contents($neu), true);
        if (!is_array($g) || !isset($g["endpunkt_ein"]) || $g["endpunkt_ein"] !== 1) { @unlink($neu); exit(1); }
        unset($g["endpunkt_ein"]);
        if ($g !== json_decode($roh, true)) { @unlink($neu); exit(1); }
        if (!@rename($neu, $f)) { @unlink($neu); exit(1); }
        exit(0);' -- "$1" 2>/dev/null
    sp_e=$?
    if [ "$sp_e" = 0 ]; then
        chmod 600 "$1" 2>/dev/null
        echo "<INFO> Konfiguration ergaenzt ($(basename "$1")): endpunkt_ein=1 (bestehende Anlage - der HTTP-Lesezweig fuer Loxone bleibt erreichbar; abschalten im Reiter Einbindung)."
    elif [ "$sp_e" != 3 ]; then
        echo "<WARNING> endpunkt_ein liess sich in $(basename "$1") nicht nachtragen - es gilt die Lese-Regel (der Lesezweig bleibt erreichbar)."
    fi
    return 0
}
sp_endpunkt_nachtragen "$CF"
sp_endpunkt_nachtragen "$BK"

# Hier stand "rm -f $MERKER". Mit dem Merker ist auch das entfallen - die
# Variable gab es danach nicht mehr, und "rm -f ''" ist kein Aufraeumen,
# sondern eine Zeile, die aussieht wie eines.
# Der Arbeitsordner des Installers wird von LoxBerry selbst aufgeraeumt.
# Nur der Rueckfallweg gehoert uns.
case "$SICHERUNG" in
    /*) : ;;
    *)  rm -rf "$SICHERUNG" ;;
esac

# Gruppe 27 (1.2.39): erst die Marke weg (die Bruecke startet nicht, solange eine frische Marke liegt; der trap am
# Ende findet sie dann nicht mehr), dann die Befehlsbruecke starten.
case "$MARKE" in
    */data/plugins/?*.upgrade_laeuft) rm -f "${MARKE:?}" 2>/dev/null ;;
esac
# Gruppe 27 (1.2.39, V3): die Befehlsbruecke starten - befehlsbruecke.sh startet nur mit dem Haken "Befehle ueber
# MQTT annehmen" (ab Werk aus) und MQTT, und nicht, solange eine frische Upgrade-Marke liegt. Der Waechter im
# Minutenlauf holt einen ausgelassenen Start binnen einer Minute nach.
BBSH="$BASE/bin/plugins/$PFOLDER/befehlsbruecke.sh"
if [ -f "$BBSH" ]; then
    BB_AUS=$(timeout 40 bash "$BBSH" start 2>&1 | head -n 1)
    case "$BB_AUS" in
        *gestartet*|*laeuft*) echo "<OK> Befehlsbruecke: $BB_AUS" ;;
        "Haken aus - kein Start.") echo "<INFO> Befehlsbruecke: aus (Reiter MQTT, Haken 'Befehle ueber MQTT annehmen')." ;;
        *) echo "<WARNING> Befehlsbruecke: $BB_AUS - der Minutentakt versucht es erneut." ;;
    esac
fi

exit 0
