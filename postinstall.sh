#!/bin/bash
# Spotpreis aWATTar - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-spotpreis}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Die Wurzel: $5 (vom Installer) oder $LBHOMEDIR, wenn dort config/plugins
# und data/plugins liegen - sonst vom eigenen Ablageort AUFWAERTS SUCHEN, bis
# ein Verzeichnis config/plugins, data/plugins UND config/system/general.json
# traegt. Keine feste Ebenenzahl und kein fest verdrahteter Systempfad danach.
# Findet sich nichts, wird GEWARNT statt vollzogen.
#
# Bis 1.2.27 stand hier nur die Zeile darueber. Ohne beides war BASE
# leer, und das Skript legte config/plugins/<ordner>/spot.json und
# data/plugins/<ordner> ab der LAUFWERKSWURZEL an (in WSL gemessen,
# Pruefung-Spotpreis-aWATTar-1.2.28, Fall K5). general.json ist die
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
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
if [ ! -f "$BASE/config/plugins/$PFOLDER/spot.json" ]; then
    echo '{}' > "$BASE/config/plugins/$PFOLDER/spot.json"
fi
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/spot.json"
# Aktualisierung oder Neuinstallation - das sagt allein die Marke von
# preupgrade.sh (kein Altersvergleich, Entscheidung 1 und Nr. 8). Bisher
# entschied das Vorhandensein von Zweitschrift und Update-Sicherung, und eine
# Neuinstallation spielte Token, Tarifwerte, Historie und Merker einer
# frueheren Installation ein (Pruefbericht installer, Faelle D und D4).
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
SP_MARKE=0
[ -f "$MARKE" ] && SP_MARKE=1
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
# Nur bei einer Aktualisierung. Bei einer Neuinstallation hat preinstall.sh
# eine Zweitschrift einer frueheren Installation schon nach .alt gelegt;
# liegt trotzdem eine da (preinstall.sh konnte sie nicht verschieben), bleibt
# sie hier unberuehrt.
if [ "$SP_MARKE" = "1" ] && [ -f "$BK" ]; then
    sp_zurueckspielen "<OK> Konfiguration aus Sicherung wiederhergestellt."
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

# Merkmal "frisch installiert" (Pruefbericht installer, Zusatz zu I1): Bei
# einer Neuinstallation liegt danach data/plugins/<ordner>/marke_frisch mit
# dem Zeitpunkt. Solange es liegt, heilt die Bibliothek nicht aus einer
# Zweitschrift; spot_config_save() loescht es nach dem ersten erfolgreichen
# Speichern. Es heisst marke_*, damit preupgrade.sh und der Abschnitt unten es
# wie die uebrigen Merker ueber ein Update tragen. Bei einer Aktualisierung
# wird es hier nicht angelegt. Rechte wie die Merker aus spot_merker_setzen():
# die der Umgebung (umask), wie dort beim Anlegen.
if [ "$SP_MARKE" = "0" ]; then
    SP_FRISCH="$BASE/data/plugins/$PFOLDER/marke_frisch"
    if [ ! -s "$SP_FRISCH" ]; then
        { date -Iseconds > "$SP_FRISCH"; } 2>/dev/null
        if ! grep -q '^[0-9]' "$SP_FRISCH" 2>/dev/null; then
            echo "<WARNING> Das Merkmal $SP_FRISCH liess sich nicht anlegen."
        fi
    fi
fi
echo "<OK> Installation abgeschlossen. Bitte Plugin-Oberflaeche oeffnen und Preisbestandteile pruefen."

# ---------- Langzeitwerte zurueckholen ----------
# Gegenstueck zu preupgrade.sh. Zwischen beiden Skripten hat der Installer
# data/plugins/<x>/ vollstaendig geloescht; der Nachbar mit dem Punkt hat es
# ueberstanden.
#
# Nur bei einer Aktualisierung (Marke). Bisher entschied das Vorhandensein
# der Sicherung: eine Neuinstallation holte Preishistorie, Laufzaehler und
# Merker einer frueheren Installation zurueck und meldete "ueber das Update
# gerettet" (Pruefbericht installer, Fall D4). Eine liegengebliebene
# Sicherung hat preinstall.sh nach .alt gelegt.
#
# Uebernommen heisst (Pruefbericht installer, Fall K): zurueckkopiert und mit
# cmp bestaetigt; bei history.csv, die schon im Datenordner liegt,
# zusammengefuehrt; bei laufzaehler, mqtt_praefixe.json und marke_* eine schon
# vorhandene, nicht leere Zieldatei (sie gewinnt). Der Minutentakt kann
# zwischen dem Kopieren und diesem Skript laufen und schreibt ab 23:50 eine
# Tageszeile in history.csv (spot_history_add()). Bisher wurde die gesicherte
# Historie dann uebergangen und die Sicherung trotzdem weggeraeumt - die ganze
# Preishistorie war weg (gemessen: 4 Zeilen vorher, 1 danach). Die Sicherung
# wird nie weggeraeumt, solange eine gesicherte Datei nicht uebernommen ist.
LANG_SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
LANG_ZIEL="$BASE/data/plugins/$PFOLDER"
# Historie vereinigen: Schluessel ist die erste Spalte (JJJJMMTT vor dem ;),
# jedes Datum einmal, bei gleichem Datum gilt die NEUE Zeile, nach Datum
# sortiert; Zeilen ohne Datum (etwa ein Kopf) bleiben oben. Geschrieben wird
# in eine Datei daneben mit den Rechten der bisherigen; erst nach der Pruefung
# kommt sie per mv an ihren Platz (unteilbar im selben Verzeichnis).
# Rueckgabe 0 = zusammengefuehrt und geprueft, sonst 1 (nichts veraendert).
sp_historie_vereinen() {
    sp_alt="$1"
    sp_neu="$2"
    sp_tmp="$2.vereint.$$"
    sp_d='^[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]$'
    rm -f "${sp_tmp:?}" 2>/dev/null
    # Aeussere Klammer: sonst schreibt die Schale ihre eigene Meldung
    # ("Permission denied") am 2>/dev/null vorbei ins Protokoll (gemessen,
    # Fall K3 des Installer-Teilbaus).
    { {
        awk -F';' -v d="$sp_d" '$0 != "" && $1 !~ d && !($0 in s) { s[$0] = 1; print }' "$sp_neu" "$sp_alt"
        awk -F';' -v d="$sp_d" '$1 ~ d && !($1 in s) { s[$1] = 1; print }' "$sp_neu" "$sp_alt" \
            | LC_ALL=C sort -t';' -k1,1
    } > "$sp_tmp"; } 2>/dev/null
    # Die Wirkung pruefen: jede Zeile der neuen Datei steht unveraendert darin,
    # jedes Datum der gesicherten ebenfalls.
    sp_fehlt=$(awk -F';' -v d="$sp_d" '
        FNR == NR { z[$0] = 1; if ($1 ~ d) k[$1] = 1; next }
        FILENAME == ARGV[2] && $0 != "" && !($0 in z) { n++ }
        FILENAME == ARGV[3] && $1 ~ d && !($1 in k) { n++ }
        END { print n + 0 }' "$sp_tmp" "$sp_neu" "$sp_alt" 2>/dev/null)
    if [ "$sp_fehlt" != "0" ] || [ ! -s "$sp_tmp" ]; then
        rm -f "${sp_tmp:?}" 2>/dev/null
        return 1
    fi
    chmod --reference="$sp_neu" "$sp_tmp" 2>/dev/null
    if [ "$(stat -c %a "$sp_tmp" 2>/dev/null)" != "$(stat -c %a "$sp_neu" 2>/dev/null)" ]; then
        rm -f "${sp_tmp:?}" 2>/dev/null
        return 1
    fi
    sp_summe=$(cksum < "$sp_tmp")
    if ! mv -f "$sp_tmp" "$sp_neu" 2>/dev/null \
       || [ "$(cksum < "$sp_neu" 2>/dev/null)" != "$sp_summe" ]; then
        rm -f "${sp_tmp:?}" 2>/dev/null
        return 1
    fi
    SP_HIST_ZEILEN=$(grep -c . "$sp_neu" 2>/dev/null)
    return 0
}
GERETTET=1
if [ "$SP_MARKE" = "1" ] && [ -d "$LANG_SICHER" ]; then
    # Dieselbe Menge wie in preupgrade.sh: Historie, Laufzaehler, die Liste
    # der MQTT-Praefixe und die Merker.
    MERKER=$(cd "$LANG_SICHER" 2>/dev/null && ls marke_* 2>/dev/null)
    for LANG_F in history.csv laufzaehler mqtt_praefixe.json $MERKER; do
        [ -f "$LANG_SICHER/$LANG_F" ] || continue
        if [ -s "$LANG_ZIEL/$LANG_F" ]; then
            if [ "$LANG_F" = "history.csv" ]; then
                if sp_historie_vereinen "$LANG_SICHER/$LANG_F" "$LANG_ZIEL/$LANG_F"; then
                    echo "<OK> history.csv ueber das Update gerettet und mit den inzwischen geschriebenen Zeilen zusammengefuehrt ($SP_HIST_ZEILEN Zeilen)."
                else
                    echo "<WARNING> history.csv liess sich nicht mit den inzwischen geschriebenen Zeilen zusammenfuehren."
                    echo "<WARNING> Die Sicherung bleibt liegen: $LANG_SICHER"
                    GERETTET=0
                fi
            fi
            # laufzaehler, mqtt_praefixe.json, marke_*: die vorhandene Datei gewinnt.
            continue
        fi
        mkdir -p "$LANG_ZIEL" 2>/dev/null
        cp -p "$LANG_SICHER/$LANG_F" "$LANG_ZIEL/$LANG_F" 2>/dev/null
        # Die WIRKUNG pruefen, nicht den Rueckgabewert: liegt die Datei
        # byteweise gleich da?
        if [ -f "$LANG_ZIEL/$LANG_F" ] && cmp -s "$LANG_SICHER/$LANG_F" "$LANG_ZIEL/$LANG_F"; then
            echo "<OK> $LANG_F ueber das Update gerettet."
        else
            echo "<WARNING> $LANG_F konnte nicht zurueckgeholt werden."
            echo "<WARNING> Die Sicherung bleibt liegen: $LANG_SICHER"
            GERETTET=0
        fi
    done
    # Erst wegraeumen, wenn wirklich alles angekommen ist. Bis 1.2.18
    # stand das rm -rf ohne Bedingung hier. Schlug das cp fehl - Platte
    # voll, Rechte, kaputter Zielordner -, verschluckte 2>/dev/null die
    # Ursache, das && verschluckte die Erfolgsmeldung, und danach war
    # die einzige Kopie der Preishistorie weg. Sie ist die eine Datei,
    # die preupgrade.sh als nicht nachladbar bezeichnet.
    if [ "$GERETTET" = "1" ]; then
        case "$LANG_SICHER" in
            */data/plugins/?*.upgrade_sicherung) rm -rf "${LANG_SICHER:?}" 2>/dev/null ;;
        esac
    fi
fi
exit 0
