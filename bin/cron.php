<?php
/* ---- Sperre gegen Parallellaeufe (Muster fer_sperre, FerienFeiertage) ----
 *
 * Der Abruf der Boersenpreise wartet auf ein Netz. Dauert der Lauf laenger als der Cron-Takt,
 * startet der naechste, waehrend dieser noch laeuft: doppelte Abrufe,
 * doppelte Meldungen, im schlimmsten Fall zwei Schreibvorgaenge auf dieselbe
 * Datei. Die Sperre ist nicht blockierend - wer nicht drankommt, geht
 * kommentarlos wieder (der naechste Takt kommt ohnehin gleich).
 */
/* Die Sperrdatei traegt den ORDNERNAMEN dieser Installation. Bis 1.2.19
 * hiess sie fest 'spot_cron.lock'; zwei Installationen desselben Plugins
 * sperrten sich damit gegenseitig aus, und je Minute lief nur eine von
 * beiden - lautlos, denn wer nicht drankommt, geht kommentarlos. REGELN_2.
 *
 * basename(__DIR__) ist der bin-Ordner des Plugins, also der Pluginordner
 * selbst. Die Bibliothek steht hier noch nicht bereit; spot_paths() ist
 * deshalb keine Wahl. */
/* Aus der Deinstallation (uninstall/uninstall) kommt ein Aufruf mit
 * --mqtt-leeren: die zurueckbehaltenen Themen der Linie leeren und beim
 * Broker nachlesen (spot_mqtt_leeren() in spot_lib.php) - ohne Sperre, ohne
 * Abruf, ohne Protokoll. Haelt gerade ein Minutenlauf die Sperre, darf die
 * Deinstallation nicht daran scheitern. */
$spot_leeren = in_array('--mqtt-leeren', isset($argv) ? (array) $argv : array(), true);
if (!$spot_leeren) {
    $spot_sperrdatei = sys_get_temp_dir() . '/' . basename(dirname(__DIR__)) . '_cron.lock';
    $spot_sperre = @fopen($spot_sperrdatei, 'c');
    if ($spot_sperre === false || !flock($spot_sperre, LOCK_EX | LOCK_NB)) {
        exit(0);
    }
}

/**
 * Spotpreis aWATTar - minutlicher Cron-Lauf (via cron/cron.01min)
 *
 * 1. Zustand aktualisieren (Marktdaten-Cache 15 min, Zustand 5 min).
 * 2. Stuendliche Ansage und Meldung "Preise fuer morgen sind da".
 * 3. MQTT bei Aenderung, mindestens stuendlich.
 * 4. Tages-Statistik fortschreiben.
 *
 * ===================================================================
 * ZUM ABLAGEORT - bitte nicht zurueckverschieben
 * ===================================================================
 *
 * Bis 1.1.0 lag diese Datei unter webfrontend/html/. Das ist der
 * UNANGEMELDETE Bereich: LoxBerry veroeffentlicht ihn als
 * /plugins/<ordner>/, ohne Anmeldung. Jeder im Netz konnte damit
 *
 *     http://<loxberry>/plugins/spotpreis/cron.php
 *
 * aufrufen und den Minutenlauf ausloesen - samt Sprachansage,
 * Pushnachricht, MQTT-Veroeffentlichung und, wenn eingeschaltet,
 * spot_marstek_control(): einem HTTP-Aufruf an den Hausspeicher mit
 * Ladeleistung und Laufzeit.
 *
 * Ein Cron-Skript hat im Web-Verzeichnis nichts zu suchen. Es wird von
 * der Kommandozeile gestartet, nicht vom Browser. Hier unter bin/ ist es
 * ueber HTTP gar nicht erst erreichbar.
 *
 * Die Bibliothek bleibt unter webfrontend/html/: sie wird auch vom
 * Miniserver-Endpunkt spot.php und von der Oberflaeche gebraucht, und
 * EINE Datei ist besser als drei Kopien, die auseinanderlaufen. Sie
 * definiert nur Funktionen - ein Aufruf ueber HTTP liefert nichts.
 */

/* Die Bibliothek: welche Lage gilt, entscheidet der eigene Ablageort, nicht
 * die Reihenfolge der Versuche. Installiert liegt diese Datei unter
 * <Wurzel>/bin/plugins/<ordner> und die Bibliothek unter
 * <Wurzel>/webfrontend/html/plugins/<ordner>, im ausgepackten Archiv unter
 * <archiv>/bin und <archiv>/webfrontend/html. Bis 1.2.27 standen zwei
 * Kandidaten in Reihe, der gerechnete VOR dem eigenen: aus einem Archiv unter
 * /<name> war das /webfrontend/html/plugins/bin/spot_lib.php ab der
 * Laufwerkswurzel, und was dort lag, lief als Bibliothek (in WSL gemessen,
 * Pruefung-Spotpreis-aWATTar-1.2.28, Fall C8; Bauart Spotpreis-Tibber
 * 0.9.19). */
if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'bin') {
    $spot_lib = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/'
        . basename(__DIR__) . '/spot_lib.php';
} else {
    $spot_lib = dirname(__DIR__) . '/webfrontend/html/spot_lib.php';
}
if (!is_readable($spot_lib)) {
    fwrite(STDERR, "spot_lib.php nicht gefunden - Plugin neu installieren.\n");
    exit(1);
}
require_once $spot_lib;

if ($spot_leeren) {
    exit(spot_mqtt_leeren());
}
/* Ohne Wurzel, oder aus einem Archiv heraus, das nicht in der gefundenen
 * Wurzel installiert liegt: nichts tun (spot_keine_wurzel_abbruch() in
 * spot_lib.php). Bis 1.2.27 lief der Minutenlauf aus einem ausgepackten
 * Archiv unterhalb einer echten Wurzel einfach los und schrieb in die Ordner
 * der Anlage (config, data, log), in einem fremden Baum ohne general.json
 * ebenso (in WSL gemessen, Pruefung-Spotpreis-aWATTar-1.2.28, Faelle B6, B7,
 * H2). */
spot_keine_wurzel_abbruch('cron.php');

$st = spot_state();

/* Der Laufzaehler wird als ERSTES weitergedreht - vor allem, was scheitern
 * kann. Er beantwortet die Frage "laeuft der Cron ueberhaupt noch?", und
 * die soll auch dann noch beantwortbar sein, wenn der Abruf bei aWATTar
 * gerade nicht durchgeht. Ein Zaehler, der nur bei Erfolg weiterzaehlt,
 * misst den Erfolg, nicht den Lauf - dafuer gibt es OK. */
spot_lauf_weiter();

/* Fehlende Schluessel einmal in die Datei schreiben (Regeln/05). Die
 * Funktion tut nichts, solange nichts fehlt - geschrieben wird also nur beim
 * ersten Lauf nach einem Update. */
spot_config_vervollstaendigen();

spot_announce_check();
spot_marstek_control($st); // nur aktiv, wenn in den Einstellungen eingeschaltet

/* ---------------- Monatsbericht am Monatsersten ----------------
 *
 * Bis 1.1.1 stand hier die Bedingung
 *     (int) date('j') === 1 && date('H:i') === '08:05'
 * Das Fenster ist damit genau EINE Minute breit. Nachgemessen:
 *
 *   Lauf startet 08:05:00 bis 08:05:59  -> Bericht kommt
 *   Lauf startet 08:06:00 oder spaeter  -> Bericht faellt aus,
 *                                          und zwar fuer den ganzen Monat
 *
 * Ein Cron-Lauf, der sich unter Last um eine Minute verspaetet oder ganz
 * ausfaellt (Neustart, Update, Stromausfall um 8 Uhr), kostet also den
 * Bericht bis zum naechsten Monatsersten.
 *
 * Jetzt: am Ersten ab 8 Uhr, sobald der Merker fuer diesen Monat fehlt.
 *
 * WO DER MERKER LIEGT, IST DER GANZE WITZ. Der naheliegende Ort /tmp waere
 * falsch: /tmp ist auf dem LoxBerry fluechtig. Ein Neustart am Monatsersten
 * um 10 Uhr - und der Bericht kaeme ein ZWEITES Mal, samt Ansage. Der
 * Merker liegt deshalb in data/plugins/<ordner>, das Neustart und
 * Plugin-Update uebersteht. spot_merker_setzen() legt ihn mit fopen($f,'x')
 * an: schlaegt fehl, wenn er schon da ist, und zwar unteilbar.
 */
if ((int) date('j') === 1 && (int) date('G') >= 8) {
    if (spot_merker_setzen('monatsbericht_' . date('Ym'))) {
        $mc = spot_month_compare(2);
        array_shift($mc); // laufender Monat -> wir wollen den abgeschlossenen Vormonat
        $vm = $mc ? reset($mc) : null;
        if ($vm) {
            spot_log('MONATSBERICHT ' . $vm['monat'] . ': dynamisch (gewichtet) ' . $vm['dynp'] . ' ct, fest '
                . $vm['fix'] . ' ct -> ' . ($vm['diff'] >= 0 ? 'dynamisch waere guenstiger gewesen um ' : 'fester Tarif war guenstiger um ')
                . abs($vm['diff']) . ' ct/kWh (' . abs($vm['euro']) . ' EUR)');
            $cfgm = spot_config();
            if (!empty($cfgm['notify']['audio'])) {
                $t = spot_month_text($vm);
                if ($t !== '') {
                    spot_say($t);
                }
            }
        } else {
            // Kein Vormonat in der Statistik - dann ist auch nichts zu
            // berichten. Der Merker bleibt trotzdem gesetzt, sonst wuerde
            // es jede Minute des Tages erneut versucht.
            spot_log('Monatsbericht: noch keine Werte fuer den Vormonat vorhanden.');
        }
    }
}

// ann und ptest gehoeren in die Signatur: sie wechseln minutengenau, und ohne sie
// wuerde das Meldefenster erst beim naechsten Stundenschlag veroeffentlicht.
/* MQTT: nur Aenderungen, der volle Satz halbstuendlich, das Lebenszeichen
 * bei JEDEM Durchgang (Regeln/07).
 *
 * Bis 1.2.26 entschied hier eine Signatur ueber einige Werte, ob der VOLLE
 * Satz hinausging - 89 Datagramme in einem Stoss, mindestens stuendlich.
 * Die Signatur musste jede schaltende Groesse kennen (sie war in 1.2.19
 * deshalb schon einmal zu klein); jetzt vergleicht spot_mqtt_publish()
 * jedes Thema einzeln mit dem zuletzt gesendeten Wert, und keine Liste
 * muss mehr gepflegt werden. Wer nichts geaendert hat, schickt nichts.
 *
 * Der volle Satz alle 30 Minuten muss bleiben: ein neu gestarteter Broker
 * haette die Werte sonst nicht, und ein am Gateway verworfenes Datagramm
 * wuerde nie nachgeholt. */
$beat = spot_tmpdir() . '/mqtt_beat';
$voll = !is_file($beat) || time() - filemtime($beat) > 1800;
if (spot_mqtt_publish($st, $voll) > 0 && $voll) {
    @touch($beat);
}
/* Das Lebenszeichen geht bei JEDEM Durchgang hinaus, am Filter vorbei.
 * Genau darin besteht seine Aufgabe: ein virtueller Eingang behaelt seinen
 * letzten Wert. Stirbt der Cron, steht in Loxone sonst weiter der Preis vom
 * Ausfallzeitpunkt - eine Falschaussage, die aussieht wie eine richtige. */
spot_mqtt_lebenszeichen($st);

if ((int) date('G') === 23 && (int) date('i') >= 50) {
    spot_history_add($st); // Tageswerte kurz vor Mitternacht sichern
}
echo "OK\n";
