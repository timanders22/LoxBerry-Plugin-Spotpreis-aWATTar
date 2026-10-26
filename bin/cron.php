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
 * selbst. */
/* Aus der Deinstallation (uninstall/uninstall) kommt ein Aufruf mit
 * --mqtt-leeren: die zurueckbehaltenen Themen der Linie leeren und beim
 * Broker nachlesen (spot_mqtt_leeren() in spot_lib.php) - ohne Sperre, ohne
 * Abruf, ohne Protokoll. Haelt gerade ein Minutenlauf die Sperre, darf die
 * Deinstallation nicht daran scheitern.
 *
 * Gruppe 27 (1.2.39): zwei Schalter dazu, ebenfalls ohne die Sperre des
 * Minutenlaufs -
 *   --haus-leeren  aus der Deinstallation: die fuenf retained Themen von
 *                  haus/strompreis mit Ruecklesen abraeumen, nur wenn diese
 *                  Installation Anbieter ist oder war (spot_haus_leeren_ausgabe());
 *   --haus-lesen   der abgekoppelte Leser von haus/speicher (V1): eigene Sperre
 *                  haus_lesen.lock, wartet bis 65 s auf den Zaehler des Anbieters
 *                  (spot_haus_leser_lauf()); gestartet vom Minutenlauf, der nie
 *                  darauf wartet.
 * W18 (Pruefbericht): jeder andere Schalter wird abgewiesen (Rueckgabe 2, ohne
 * Lauf) - bis 1.2.38 lief cron.php mit einem vertippten Schalter als
 * gewoehnlicher Minutenlauf los. */
$spot_argv = isset($argv) ? array_slice((array) $argv, 1) : array();
foreach ($spot_argv as $spot_a) {
    if (!in_array($spot_a, array('--mqtt-leeren', '--haus-leeren', '--haus-lesen'), true)) {
        fwrite(STDERR, "cron.php: unbekannter Schalter '" . preg_replace('/[^\x20-\x7E]/', '?', (string) $spot_a)
            . "' - erlaubt sind --mqtt-leeren, --haus-leeren und --haus-lesen. Es lief nichts.\n");
        exit(2);
    }
}
$spot_leeren = in_array('--mqtt-leeren', $spot_argv, true);
$spot_haus_leeren = in_array('--haus-leeren', $spot_argv, true);
$spot_haus_lesen = in_array('--haus-lesen', $spot_argv, true);

/**
 * Spotpreis aWATTar - minutlicher Cron-Lauf (via cron/cron.01min)
 *
 * 1. Zustand aktualisieren (Marktdaten-Cache 15 min, Zustand 5 min).
 * 2. Stuendliche Ansage und Meldung "Preise fuer morgen sind da".
 * 3. MQTT bei Aenderung, mindestens stuendlich.
 * 4. Tages-Statistik fortschreiben.
 * 5. Seit 1.2.39: Waechter der Befehlsbruecke, Leser haus/speicher starten,
 *    Anbieter haus/strompreis.
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

if ($spot_leeren || $spot_haus_leeren) {
    /* Aus der Deinstallation: nichts heilen, nichts anlegen (spot_nur_lesen()). Die Rueckgabe sagt nur etwas ueber
     * die Themen unter dem eigenen Praefix; --haus-leeren gibt immer 0 zurueck (die Deinstallation laeuft weiter). */
    spot_nur_lesen(true);
    $spot_rc = 0;
    if ($spot_leeren) {
        $spot_rc = spot_mqtt_leeren();
    }
    if ($spot_haus_leeren) {
        spot_haus_leeren_ausgabe();
    }
    exit($spot_rc);
}
/* Ohne Wurzel, oder aus einem Archiv heraus, das nicht in der gefundenen
 * Wurzel installiert liegt: nichts tun (spot_keine_wurzel_abbruch() in
 * spot_lib.php). Bis 1.2.27 lief der Minutenlauf aus einem ausgepackten
 * Archiv unterhalb einer echten Wurzel einfach los und schrieb in die Ordner
 * der Anlage (config, data, log), in einem fremden Baum ohne general.json
 * ebenso (in WSL gemessen, Pruefung-Spotpreis-aWATTar-1.2.28, Faelle B6, B7,
 * H2). */
spot_keine_wurzel_abbruch('cron.php');

/* W19 (Gruppe 27, 1.2.39; Hausform Abfuhrkalender 1.4.26): solange ein Update
 * laeuft, laesst der Minutenlauf den Takt aus. Die Marke
 * data/plugins/<ordner>.upgrade_laeuft legt preupgrade.sh als Erstes an,
 * postupgrade.sh raeumt sie ab. Bis 1.2.38 lief ein Takt auch in der Luecke
 * zwischen Archivkopie und postinstall.sh - mit abgeraeumtem Datenordner: der
 * Monatsbericht-Merker fehlte, und am Monatsersten kam der Bericht ein zweites
 * Mal. Hoechstens eine Stunde: bleibt die Marke nach einem abgebrochenen
 * Update liegen, laeuft der Takt danach wieder. Vor Laufzaehler, Abruf,
 * Ansage, Monatsbericht, Bruecke und Haus-Themen. */
$spot_p = spot_paths();
$spot_marke = $spot_p['lbhome'] . '/data/plugins/' . $spot_p['plugin'] . '.upgrade_laeuft';
clearstatcache(true, $spot_marke);
if (is_file($spot_marke) && time() - (int) filemtime($spot_marke) < 3600) {
    echo "UPGRADE\n";
    exit(0);
}

if ($spot_haus_lesen) {
    /* Der abgekoppelte Leser (V1) - eigene Sperre, nie die des Minutenlaufs. */
    echo spot_haus_leser_lauf(65) . "\n";
    exit(0);
}

/* W18 (Gruppe 27, 1.2.39): die Sperre steht jetzt HINTER der Archivpruefung -
 * ein Aufruf aus einem ausgepackten Archiv legt keine Sperrdatei mehr an -,
 * und sie wird mit 'ce' geoeffnet (close-on-exec). N2: seit 1.2.39 startet der
 * Minutenlauf einen Kindprozess (den Leser haus/speicher); ohne 'e' erbte das
 * Kind den Deskriptor und hielte 65 s lang jeden folgenden Minutenlauf an
 * (Gedaechtnis sperre-vererbt-sich-an-kinder). Die Schale des Lesers schliesst
 * zusaetzlich jede Nummer ueber 2 (spot_haus_abnehmer_starten()).
 *
 * C3 (Pruefbericht code, Befund 3; installer, I5): basename(__DIR__), wie
 * der Kommentar oben es sagt. Bis 1.2.31 stand hier dirname(__DIR__) -
 * installiert ist das "plugins", die Sperrdatei hiess fuer JEDE Installation
 * plugins_cron.lock, und zwei Installationen sperrten sich wieder
 * gegenseitig aus (gemessen: die zweite endete mit rc 0, ohne Laufzaehler). */
$spot_sperrdatei = sys_get_temp_dir() . '/' . basename(__DIR__) . '_cron.lock';
$spot_sperre = @fopen($spot_sperrdatei, 'ce');
if ($spot_sperre === false || !flock($spot_sperre, LOCK_EX | LOCK_NB)) {
    exit(0);
}

/* Der Laufzaehler wird als ERSTES weitergedreht - vor allem, was scheitern
 * kann. Er beantwortet die Frage "laeuft der Cron ueberhaupt noch?", und
 * die soll auch dann noch beantwortbar sein, wenn der Abruf bei aWATTar
 * gerade nicht durchgeht. Ein Zaehler, der nur bei Erfolg weiterzaehlt,
 * misst den Erfolg, nicht den Lauf - dafuer gibt es OK.
 *
 * P5 (Pruefbericht code, Befund 6): bis 1.2.31 stand spot_state() - mit den
 * Abrufen - DAVOR, und der Kommentar sagte das Gegenteil. Gemessen mit einer
 * Gegenstelle, die jeden Verbindungsaufbau verschluckt: Laufzaehler erst 44 s
 * nach dem Start geschrieben. */
spot_lauf_weiter();

/* Gruppe 27 (1.2.39, V1): den abgekoppelten Leser haus/speicher starten - so
 * frueh wie moeglich, damit sein Fenster (bis 65 s) eine ganze Sendeperiode des
 * Anbieters abdeckt. Der Minutenlauf wartet nie darauf; ohne den Haken
 * haus_speicher_ein verfaellt nur die Ablage. */
spot_haus_abnehmer_starten(spot_config(), __FILE__);

/* Gruppe 27 (1.2.39, V3): der Waechter der Befehlsbruecke (Bauart Octopus
 * oc_cron). Er startet sie mit dem Haken "Befehle ueber MQTT annehmen" und
 * MQTT, haelt sie sonst an und meldet <praefix>/status/befehle. Ein Fehler
 * darin darf den Minutenlauf nicht aufhalten. Keine zweite Zeile in
 * cron.01min. */
try {
    spot_bruecke_waechter();
} catch (Throwable $spot_e) {
    spot_log('Waechter der Befehlsbruecke abgebrochen (' . get_class($spot_e) . ': ' . $spot_e->getMessage()
        . ') - der Minutenlauf ist davon nicht betroffen.');
}

/* P4: Ein ?refresh=1 am Endpunkt ruft nicht mehr selbst ab, sondern bestellt
 * den Abruf hier (Merker im Zwischenspeicher, hoechstens eine Stunde alt).
 * Seit 1.2.39 bestellt auch der MQTT-Befehl abruf hier (spot_befehl_ausfuehren()). */
$spot_bestellt = spot_tmpdir() . '/abruf_bestellt';
$spot_neu_holen = is_file($spot_bestellt) && time() - (int) @filemtime($spot_bestellt) < 3600;
if (is_file($spot_bestellt)) {
    @unlink($spot_bestellt);
}
$st = spot_state($spot_neu_holen);
if ($spot_neu_holen) {
    spot_log('Abruf bestellt (?refresh=1 bzw. MQTT-Befehl abruf) - Marktdaten neu abgerufen: heute '
        . (int) $st['heute']['n'] . ', morgen ' . (int) $st['morgen']['n'] . ' Stunden.');
}

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
 *
 * W17 (Gruppe 27, 1.2.39; Bauart Octopus oc_cron): der Vormonat wird am
 * SCHLUESSEL gesucht (spot_vormonat_eintrag()). history.csv schreibt die Zeile
 * des Tages erst um 23:50 (unten); am Ersten um 8 Uhr gibt es den laufenden
 * Monat also noch nicht, und das alte spot_month_compare(2) + array_shift()
 * warf den Vormonat weg und berichtete ueber den Vor-Vormonat (gemessen: am
 * 01.11. der September). "Noch keine Werte" heisst jetzt nur noch: den
 * Vormonat gibt es wirklich nicht.
 */
if ((int) date('j') === 1 && (int) date('G') >= 8) {
    if (spot_merker_setzen('monatsbericht_' . date('Ym'))) {
        $vm = spot_vormonat_eintrag();
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
$spot_cfg = spot_config();
if (!empty($spot_cfg['mqtt_enabled'])) {
    /* W16 (Gruppe 27, 1.2.39): das Abo des Gateways V1 traegt das eingestellte
     * Praefix - auch nach einem Update, das die mitgelieferte Datei einspielt.
     * Geschrieben wird nur bei Abweichung. */
    spot_abo_datei(true);
}
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

/* Gruppe 27 (1.2.39, E-E1): Anbieter haus/strompreis - nach dem Lebenszeichen,
 * mit demselben Zaehler. Nur mit dem Haken haus_strompreis_ein und MQTT; sonst
 * raeumt der Lauf hoechstens alle 10 Minuten ab, solange diese Installation
 * Anbieter war (spot_haus_lauf()). */
spot_haus_lauf($st, spot_config());

if ((int) date('G') === 23 && (int) date('i') >= 50) {
    spot_history_add($st); // Tageswerte kurz vor Mitternacht sichern
}
echo "OK\n";
