<?php
/* ---- Sperre gegen Parallellaeufe (Muster fer_sperre, FerienFeiertage) ----
 *
 * Der Abruf der Boersenpreise wartet auf ein Netz. Dauert der Lauf laenger als der Cron-Takt,
 * startet der naechste, waehrend dieser noch laeuft: doppelte Abrufe,
 * doppelte Meldungen, im schlimmsten Fall zwei Schreibvorgaenge auf dieselbe
 * Datei. Die Sperre ist nicht blockierend - wer nicht drankommt, geht
 * kommentarlos wieder (der naechste Takt kommt ohnehin gleich).
 */
$spot_sperrdatei = sys_get_temp_dir() . '/spot_cron.lock';
$spot_sperre = @fopen($spot_sperrdatei, 'c');
if ($spot_sperre === false || !flock($spot_sperre, LOCK_EX | LOCK_NB)) {
    exit(0);
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

// Installiert liegt die Bibliothek unter
// <home>/webfrontend/html/plugins/<ordner>/spot_lib.php, im ausgepackten
// Archiv daneben. Beide Wege werden probiert, damit sich das Skript auch
// vor der Installation von Hand starten laesst.
$spot_lib = '';
foreach (array(
    dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/'
        . basename(__DIR__) . '/spot_lib.php',
    dirname(__DIR__) . '/webfrontend/html/spot_lib.php',
) as $spot_kandidat) {
    if (is_readable($spot_kandidat)) {
        $spot_lib = $spot_kandidat;
        break;
    }
}
if ($spot_lib === '') {
    fwrite(STDERR, "spot_lib.php nicht gefunden - Plugin neu installieren.\n");
    exit(1);
}
require_once $spot_lib;

$st = spot_state();

/* Der Laufzaehler wird als ERSTES weitergedreht - vor allem, was scheitern
 * kann. Er beantwortet die Frage "laeuft der Cron ueberhaupt noch?", und
 * die soll auch dann noch beantwortbar sein, wenn der Abruf bei aWATTar
 * gerade nicht durchgeht. Ein Zaehler, der nur bei Erfolg weiterzaehlt,
 * misst den Erfolg, nicht den Lauf - dafuer gibt es OK. */
spot_lauf_weiter();

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
$sig = json_encode(array($st['cur'], $st['rank'], $st['level'], $st['tomorrow_ok'],
                         $st['heute']['avg'], $st['morgen']['avg'], $st['fenster'], $st['co2'],
                         spot_ann_active($st), spot_ptest_active()));
$sigf = spot_tmpdir() . '/mqtt_sig.txt';
$beat = spot_tmpdir() . '/mqtt_beat';
$old = is_file($sigf) ? (string) file_get_contents($sigf) : '';
if ($sig !== $old || !is_file($beat) || time() - filemtime($beat) > 1800) {
    spot_mqtt_publish($st);
    @file_put_contents($sigf, $sig);
    @touch($beat);
} else {
    /* Nichts hat sich geaendert - der volle Satz bleibt aus. Das
     * Lebenszeichen geht trotzdem hinaus, und zwar bei JEDEM Durchgang.
     *
     * Genau darin besteht seine Aufgabe: ein virtueller Eingang behaelt
     * seinen letzten Wert, und bei MQTT mit Retain ueberlebt er sogar einen
     * Neustart des Miniservers. Stirbt der Cron, steht in Loxone weiter der
     * Preis vom Ausfallzeitpunkt - das ist keine fehlende Auskunft, sondern
     * eine Falschaussage, und sie sieht aus wie eine richtige. */
    spot_mqtt_lebenszeichen($st);
}

if ((int) date('G') === 23 && (int) date('i') >= 50) {
    spot_history_add($st); // Tageswerte kurz vor Mitternacht sichern
}
echo "OK\n";
