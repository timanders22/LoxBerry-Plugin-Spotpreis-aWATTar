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
