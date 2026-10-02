<?php
/**
 * Spotpreis aWATTar - Admin-Oberflaeche
 * Reiter: Einstellungen | Einbindung in Loxone | Test | Logdateien
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg aus general.json als
 * stdClass) und wuerde gleichnamige Plugin-Variablen ueberschreiben - daher
 * tragen hier ALLE Variablen ein sp_-Praefix.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

/* Die Bibliothek: welche Lage gilt, entscheidet der eigene Ablageort, nicht
 * die Reihenfolge der Versuche. Liegt diese Datei unter
 * <Wurzel>/webfrontend/htmlauth/plugins/<ordner>, ist sie installiert, sonst
 * liegt sie in einem ausgepackten Archiv. Bis 1.2.27 wurde der gerechnete
 * Kandidat dirname(__DIR__, 3)/html/plugins/<name>/spot_lib.php VOR der
 * eigenen Bibliothek probiert - aus einem Archiv unter / war das
 * /html/plugins/<name>/spot_lib.php ab der Laufwerkswurzel, und was dort lag,
 * lief als Bibliothek (in WSL gemessen, Pruefung-Spotpreis-aWATTar-1.2.28,
 * Fall C5; Bauart Spotpreis-Tibber 0.9.19). */
if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'htmlauth') {
    $sp_libcand = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/spot_lib.php';
} else {
    $sp_libcand = dirname(__DIR__) . '/html/spot_lib.php';
}
if (!is_file($sp_libcand)) {
    echo '<p><b>Fehler:</b> spot_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once $sp_libcand;

/* Die Pfade aus spot_paths() - EINE Rechnung fuer Oberflaeche, Endpunkt und
 * Minutenlauf. Bis 1.2.27 rechnete diese Datei sie selbst nach, mit eigener
 * Wurzelsuche (ohne config/system/general.json) und festem Rueckfall
 * 'spotpreis', und rief diese Suche auf, bevor sie definiert war: ohne
 * LBHOMEDIR brach die Seite mit "Call to undefined function" ab (in WSL
 * gemessen, Pruefung-Spotpreis-aWATTar-1.2.28, Fall C6).
 *
 * Die eigene Heilung, die hier bis 1.2.27 stand (Zweitschrift per @copy()
 * zurueck, wenn spot.json fehlte oder leer war), ist entfallen: sie legte die
 * Datei mit dem Aktionstoken mit den Rechten der umask an, 644 (Fall N2c), und
 * spot_config() heilt ohnehin - mit 0600. */
$sp_pfade = spot_paths();
$sp_lbhome = $sp_pfade['lbhome'];
$sp_plugin = $sp_pfade['plugin'];
if ($sp_lbhome !== '') {
    $sp_sdk = $sp_lbhome . '/libs/phplib/loxberry_system.php';
    if (file_exists($sp_sdk)) {
        require_once $sp_sdk;
        require_once $sp_lbhome . '/libs/phplib/loxberry_web.php';
    }
}
$sp_cfgdir = dirname($sp_pfade['config']);
$sp_bkfile = $sp_pfade['backup'];
$sp_logfile = $sp_pfade['log'];
$sp_cfgfile = $sp_pfade['config'];

$sp_saved = false;
$sp_err = '';
$sp_note = '';
/* Beanstandungen werden GESAMMELT, nicht ueberschrieben: prueft ein
 * Speichervorgang mehrere Felder, gehoeren alle Meldungen zusammen
 * ausgegeben. Eine einzelne Zuweisung verschluckt die vorherigen, und der
 * Benutzer korrigiert dann einen Fehler nach dem anderen. */
$sp_fehler = array();
/* Wird gesetzt, wenn eine Beanstandung das Speichern verhindert hat - und
 * unten auch AUSGEGEBEN. Bis 1.2.19 stand diese Variable genau einmal in
 * der Datei, naemlich in ihrer Zuweisung: der Anwender las die
 * Beanstandung, erfuhr aber nicht, dass darueber auch seine uebrigen
 * Eingaben liegengeblieben sind. */
$sp_nichts_gespeichert = false;
/* Energie-1 C2: welche Marstek-Felder beanstandet sind (fuer die Markierung)
 * und ob "Token loeschen" angehakt war (X-2: nach einer Beanstandung steht
 * der Haken wieder da). Das Token selbst kommt nie zurueck ins Formular. */
$sp_mt_mangel = array();
$sp_x2_mt_weg = false;
/* Die erlaubten Werte des Fristfeldes: -1 fuer "keine Frist" und die
 * Stunden 0 bis 23. Als Text, weil das Formular Text liefert. */
$sp_stunden_wahl = array_merge(array('-1'), array_map('strval', range(0, 23)));

/* ==================================================================
 * WACHPOSTEN - EIN Posten, nicht eine Abfrage je Zweig
 * ==================================================================
 *
 * Traegt die Anfrage kein gueltiges Formularmerkmal, wird $_POST GELEERT.
 * Damit laeuft danach kein einziger Zweig mehr an, ohne dass einer von
 * ihnen davon wissen muss.
 *
 * Der naheliegende Weg - je Zweig ein "&& $sp_ist_post" - wirkt nur, wenn
 * wirklich JEDER Zweig daran haengt, und einen vergisst man. Im Bestand
 * sind es bei anderen Linien 15 von 17 und 13 von 15 gewesen.
 *
 * Der aktive Reiter wird ausdruecklich aufgehoben: sonst springt die Seite
 * nach einer Abweisung auf Einstellungen zurueck, und der Anwender sucht
 * seine Meldung dort, wo sie nicht steht.
 *
 * ACHTUNG bei jeder Erweiterung: ein neuer Knopf braucht spot_fmt() in
 * seinem Formular, sonst tut er nichts und meldet einen Fehler, den es
 * nicht gibt. Der Reiter Test zaehlt beides gegeneinander.
 * ================================================================== */
$sp_ist_post = ($_SERVER['REQUEST_METHOD'] === 'POST');
$sp_fmt_fehlt = false;
if ($sp_ist_post && function_exists('spot_formtoken_ok') && !spot_formtoken_ok()) {
    $sp_behalten = isset($_POST['activetab']) ? (string) $_POST['activetab'] : null;
    $_POST = array();
    if ($sp_behalten !== null) { $_POST['activetab'] = $sp_behalten; }
    $sp_ist_post = false;
    $sp_fmt_fehlt = true;
}

// Der Reiter kommt aus einem abgesendeten Formular (activetab) oder aus der
// Adresse (?form=..., bis 1.2.26 ?tab=...). Letzteres brauchen die Reiter, seit sie echte Verweise
// sind - siehe die Reiterleiste weiter unten.
/* EINE Quelle fuer Reihenfolge, Positivliste und Beschriftung. Die Namen
 * standen bis 1.1.1 an zwei Stellen: in diesem Muster und weiter unten im
 * Feld $sp_reiter; die Flaechen-ids kamen als dritte dazu. Wer einen Reiter
 * ergaenzt und eine davon vergisst, bekommt keinen Fehler, sondern eine
 * Seite, die nach jedem Absenden auf Einstellungen zurueckspringt - und
 * sucht den Grund an der falschen Stelle. Die Beschriftungen brauchen
 * spot_t() und kommen weiter unten dazu. */
$sp_reiter_ids = array('settings', 'mqtt', 'loxone', 'costs', 'test', 'log');

$sp_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : ((isset($_GET['form']) && is_string($_GET['form'])) ? 'tab-' . $_GET['form']
    /* ?tab= ist der Name bis 1.2.26 und bleibt als Ausweichname gueltig -
     * bestehende Lesezeichen reissen nicht. Die Hausform ist ?form=. */
    : ((isset($_GET['tab']) && is_string($_GET['tab'])) ? 'tab-' . $_GET['tab'] : ''));
$sp_tab = preg_match('/^tab-(' . implode('|', $sp_reiter_ids) . ')$/', $sp_wunsch)
    ? $sp_wunsch : 'tab-' . $sp_reiter_ids[0];

/* Ausgabe des Planer-Selbsttests. Er rechnet nur, spricht mit niemandem und
 * braucht keine Preise - deshalb ein einfacher Knopf ohne Nebenwirkung.
 * Stand bis 1.2.12 VOR der Reiterwahl und hat sie danach ueberschrieben;
 * jetzt steht er dahinter, wie alle anderen Zweige auch. */
$sp_plantest = '';
/* O2 (PRG): der Planer-Selbsttest und der Knopf "Eigenen Endpunkt jetzt
 * aufrufen" laufen nach der Umleitung EINMAL im GET (Merker in der
 * Einmalmeldung) - ein F5 auf der Ergebnisseite wiederholt nichts. */
$sp_plantest_an = ($sp_ist_post && isset($_POST['plantest']));
$sp_ep_an = ($sp_ist_post && isset($_POST['endpunkt_test']));
if ($sp_plantest_an || $sp_ep_an) {
    $sp_tab = 'tab-test';
}
/* X-2: die Eingaben eines beanstandeten Formulars und die beanstandeten Felder. */
$sp_eingaben = array();
$sp_feldfehler = array();

/* M1 (Pruefbericht mqtt, B2; Entscheidung Nr. 26): Praefixwechsel und "MQTT
 * aus" raeumen die zurueckbehaltenen Themen unter dem ALTEN Praefix ab - ueber
 * spot_mqtt_praefix_leeren(), mit Ruecklesen beim Broker -, merken das alte
 * Praefix fuer die Deinstallation vor und loeschen den Merker der zuletzt
 * gesendeten Werte. Ein neues Praefix (oder MQTT an) bekommt sofort den vollen
 * Satz. Bis 1.2.31 geschah nichts davon: unter dem neuen Praefix kam bis zu
 * 30 min nichts an, unter dem alten blieben audio, push, fix und plan/budget
 * fuer immer zurueckbehalten. Rueckgabe: Saetze fuer die Meldung. */
$sp_mqtt_wechsel = function ($alt_an, $alt_pf, $neu_an, $neu_pf) {
    $saetze = array();
    if ($alt_an && (!$neu_an || $neu_pf !== $alt_pf)) {
        spot_mqtt_praefix_merken($alt_pf);
        $e = spot_mqtt_praefix_leeren($alt_pf);
        if ((int) $e['rc'] === 2) {
            $saetze[] = sprintf(spot_t('TEXT.MQTT_LEER_NICHT'), $alt_pf);
        } elseif ($e['nachgelesen'] && !$e['offen']) {
            $saetze[] = sprintf(spot_t('TEXT.MQTT_LEER_BESTAETIGT'), $alt_pf);
        } elseif ($e['nachgelesen']) {
            $saetze[] = sprintf(spot_t('TEXT.MQTT_LEER_OFFEN'), $alt_pf, count($e['offen']));
        } else {
            $saetze[] = sprintf(spot_t('TEXT.MQTT_LEER_UNBESTAETIGT'), $alt_pf);
        }
        @unlink(spot_mqtt_merker());
        @unlink(spot_tmpdir() . '/mqtt_beat');
        spot_log('MQTT: ' . ($neu_an ? 'Praefix ' . $alt_pf . ' -> ' . $neu_pf : 'ausgeschaltet (Praefix ' . $alt_pf . ')')
            . ' - ' . implode(' ', $e['zeilen']));
    }
    if ($neu_an && (!$alt_an || $neu_pf !== $alt_pf)) {
        @unlink(spot_mqtt_merker());
        $n = spot_mqtt_publish(spot_state(), true);
        if ($n > 0) {
            @touch(spot_tmpdir() . '/mqtt_beat');
            $saetze[] = sprintf(spot_t('TEXT.MQTT_VOLLSATZ'), (int) $n, $neu_pf);
        } else {
            $saetze[] = spot_t('TEXT.MQTT_VOLLSATZ_NICHT');
        }
    }
    return $saetze;
};

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
// ---------- Loxone-Vorlage herunterladen ----------
// Vor jeder Ausgabe, sonst stehen HTML-Reste in der XML-Datei.
if ($sp_ist_post && isset($_POST['vorlage']) && function_exists('spot_vorlage')) {
    list($sp_vname, $sp_vinhalt) = spot_vorlage();
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $sp_vname . '"');
    echo $sp_vinhalt;
    exit;
}

// ---------- Protokoll leeren ----------
if ($sp_ist_post && isset($_POST['clearlog'])) {
    @mkdir(dirname($sp_logfile), 0775, true);
    // Der Satz kommt aus der Sprachdatei - bis 1.2.19 stand er fest auf
    // Deutsch im Quelltext.
    @file_put_contents($sp_logfile, '[' . date('Y-m-d H:i:s') . '] '
        . spot_t('TEXT.LOG_GELEERT') . "\n");
    $sp_tab = 'tab-log';
}

// ---------- Jetzt abrufen ----------
if ($sp_ist_post && isset($_POST['fetchnow']) && function_exists('spot_state')) {
    $sp_s = spot_state(true);
    // $sp_note geht durch sp_e() - hier gehoert Klartext hin, keine HTML-Entities.
    $sp_note = $sp_s['ok']
        ? sprintf(spot_t('TEXT.ABRUF_OK'), $sp_s['heute']['n'],
                  $sp_s['tomorrow_ok'] ? sprintf(spot_t('TEXT.ABRUF_STUNDEN'), $sp_s['morgen']['n'])
                                       : spot_t('TEXT.ABRUF_OFFEN'))
        : spot_t('TEXT.ABRUF_FEHL');
}

// ---------- Testansage (S1; mit PRG: F5 spricht nicht noch einmal) ----------
if ($sp_ist_post && isset($_POST['testansage'])) {
    $sp_ta_st = spot_state();
    $sp_ta_text = spot_announce_text($sp_ta_st);
    if ($sp_ta_text === '') {
        $sp_ta_text = spot_t('ANSAGE.TEST_LEER');
    }
    $sp_ta_ok = spot_say($sp_ta_text);
    $sp_ta_was = spot_ansage_letzte();
    if (in_array($sp_ta_was, array('OK', 'FEHLER', 'KEINE_IP', 'AUDIOSERVER'), true)) {
        $sp_ta_was = spot_t('TEXT.ANSAGE_LETZT_' . $sp_ta_was);
    }
    if ($sp_ta_ok) {
        $sp_note = sprintf(spot_t('TEXT.TESTANSAGE_OK'), $sp_ta_was);
    } else {
        $sp_fehler[] = sprintf(spot_t('TEXT.TESTANSAGE_FEHL'), $sp_ta_was);
    }
    $sp_tab = 'tab-test';
}

// ---------- Speichern ----------
if ($sp_ist_post && isset($_POST['token_neu'])) {
    $sp_c = spot_config();
    $sp_c['token'] = spot_token_erzeugen();
    if (spot_config_save($sp_c)) { $sp_note = spot_t('TEXT.TOKEN_NEU'); }
    else { $sp_err = sprintf(spot_t('TEXT.SPEICHERN_FEHL'), 'spot.json'); }
    $sp_tab = 'tab-loxone';
}

if ($sp_ist_post && isset($_POST['token_weg'])) {
    $sp_c = spot_config();
    $sp_c['token'] = '';
    if (spot_config_save($sp_c)) { $sp_note = spot_t('TEXT.TOKEN_WEG'); }
    else { $sp_err = sprintf(spot_t('TEXT.SPEICHERN_FEHL'), 'spot.json'); }
    $sp_tab = 'tab-loxone';
}

// ---------- MQTT speichern (eigener Reiter seit 1.2.5, Hausstandard) ----------
if ($sp_ist_post && isset($_POST['mqtt_save'])) {
    /* O4/M2 (Pruefbericht oberflaeche, Befund 7; mqtt, B3): das Thema wird
     * geprueft, nicht still gesaeubert - bis 1.2.31 wurde "Spot AWattar!" zu
     * "SpotAWattar" und "haus/spot/" unveraendert gespeichert. Nur Leerraum am
     * Rand geht still weg. Bei einer Beanstandung wird nichts gespeichert
     * (auch der Haken nicht), die Eingaben kommen zurueck (X-2). */
    $sp_mq = spot_config();
    $sp_mq_alt_an = !empty($sp_mq['mqtt_enabled']);
    $sp_mq_alt_pf = spot_mqtt_praefix($sp_mq);
    $sp_mq_an = isset($_POST['mqtt_enabled']) ? 1 : 0;
    $sp_mq_pf = isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : '';
    $sp_mq_pf = is_string($sp_mq_pf) ? trim($sp_mq_pf) : $sp_mq_pf;
    $sp_mq_m = spot_wert_gegen($sp_mq_pf, spot_schranken()['mqtt_topic']);
    if ($sp_mq_m !== null) {
        $sp_fehler[] = sprintf(spot_t('WERT.FELD'), 'mqtt_topic', spot_wert_text($sp_mq_m));
        $sp_nichts_gespeichert = true;
        $sp_eingaben = spot_eingaben_sammeln('mqtt_save', array('mqtt_topic'));
    } else {
        $sp_mq['mqtt_enabled'] = $sp_mq_an;
        $sp_mq['mqtt_topic'] = $sp_mq_pf;
        if (spot_config_save($sp_mq)) {
            $sp_saved = true;
            $sp_mq_s = $sp_mqtt_wechsel($sp_mq_alt_an, $sp_mq_alt_pf, (bool) $sp_mq_an, $sp_mq_pf);
            if ($sp_mq_s) {
                $sp_note = implode(' ', $sp_mq_s);
            }
        } else {
            $sp_err = sprintf(spot_t('TEXT.SPEICHERN_FEHL'), $sp_cfgfile);
        }
    }
    $sp_tab = 'tab-mqtt';
}

if ($sp_ist_post && isset($_POST['save'])) {
    /* O4 (Pruefbericht oberflaeche, Befunde 5-7; code, Befund 8 = aWATTar-k4;
     * Entscheidungen Nr. 16 und 19): JEDES Feld wird mit derselben Pruefung wie
     * das Zurueckspielen geprueft (spot_wert_gegen(), Schranken in
     * spot_schranken()). Ein unzulaessiger Wert wird beanstandet - nie still
     * geklemmt, ersetzt oder verworfen. Bei EINER Beanstandung wird NICHTS
     * gespeichert, alle Maengel werden genannt, die Felder markiert, und die
     * Eingaben kommen zurueck (X-2). Still bleiben nur: Leerraum am Rand, das
     * Komma als Dezimalzeichen, die Sprache in Kleinbuchstaben (sinngemaess
     * Entscheidung Nr. 21), ein leerer Monatswert (= nicht gepflegt), eine
     * leere Lautstaerke (= die des anderen Plugins) und ein leeres Kennwortfeld
     * (= unveraendert). Bis 1.2.31 wurde hier "gekappt und gesagt" - und
     * gespeichert; 13 von 15 Faellen ganz ohne Hinweis, aus der Frist 25 wurde
     * "keine Frist", aus der Sprache "D3" ein "d". */
    $sp_s = spot_schranken();
    $sp_post = function ($k, $vorgabe = '') { return isset($_POST[$k]) ? $_POST[$k] : $vorgabe; };
    $sp_txt = function ($roh) { return is_string($roh) ? trim($roh) : $roh; };
    /* Eine Zahl aus dem Formular: Komma als Dezimalzeichen still. Was keine
     * schlichte Dezimalzahl ist (leer, "abc", 1e400, 0x10), bleibt Text und
     * faellt in der Pruefung als "keine Zahl" auf. */
    $sp_num = function ($roh) {
        if (!is_string($roh)) { return $roh; }
        $v = str_replace(',', '.', trim($roh));
        if (preg_match('/^-?[0-9]{1,9}(\.[0-9]{1,9})?$/', $v)) {
            return strpos($v, '.') === false ? (int) $v : (float) $v;
        }
        return $v;
    };
    /* Pruefen, beanstanden, und nur einen geprueften Wert in seinen Typ bringen
     * (zahl -> float, ganz -> int), wie ihn die Datei bis 1.2.31 trug. */
    $sp_pruef = function ($wert, $schranke, $feld, $name, $markieren = true) use (&$sp_fehler, &$sp_feldfehler) {
        $m = spot_wert_gegen($wert, $schranke);
        if ($m !== null) {
            $sp_fehler[] = sprintf(spot_t('WERT.FELD'), $name, spot_wert_text($m));
            if ($markieren) { $sp_feldfehler[] = $feld; }
            return $wert;
        }
        if ($schranke[0] === 'zahl') { return (float) $wert; }
        if ($schranke[0] === 'ganz') { return (int) $wert; }
        return $wert;
    };
    $sp_alt = spot_config();
    $sp_new = array();
    $sp_markt = $sp_txt($sp_post('market'));
    $sp_new['market'] = $sp_pruef(is_string($sp_markt) ? strtolower($sp_markt) : $sp_markt, $sp_s['market'], 'market', 'market');
    foreach (array('netz', 'steuer', 'konzession', 'umlagen', 'aufschlag', 'grundpreis', 'vat', 'cheap',
                   'expensive', 'window') as $sp_k) {
        $sp_new[$sp_k] = $sp_pruef($sp_num($sp_post($sp_k)), $sp_s[$sp_k], $sp_k, $sp_k);
    }
    $sp_new['profil_ein'] = $sp_pruef($sp_txt($sp_post('profil_ein')), $sp_s['profil_ein'], 'profil_ein', 'profil_ein');
    // ---- Schaltregeln ----
    $sp_new['regeln'] = array();
    $sp_rfelder = array('name' => 't', 'art' => 't', 'n' => 'z', 'von' => 'z', 'bis' => 'z', 'horizont' => 'z',
                        'schwelle' => 'z', 'prozent' => 'z', 'neg' => 'h', 'rang' => 'z', 'leistung' => 'z',
                        'energie' => 'z', 'frist' => 'z', 'pv_sperre' => 'z', 'soc_min' => 'z', 'soc_max' => 'z',
                        'min_lauf' => 'z', 'min_pause' => 'z');
    for ($sp_i = 0; $sp_i < SPOT_REGELN; $sp_i++) {
        $sp_g = function ($feld) use ($sp_i) {
            $a = isset($_POST[$feld]) ? $_POST[$feld] : array();
            return (is_array($a) && isset($a[$sp_i])) ? $a[$sp_i] : '';
        };
        $sp_ak = $sp_g('r_aktiv');
        $sp_rr = array('aktiv' => (is_string($sp_ak) && (int) $sp_ak) ? 1 : 0);
        foreach ($sp_rfelder as $sp_k => $sp_art) {
            $sp_roh = $sp_g('r_' . $sp_k);
            if ($sp_art === 'h') {
                $sp_rr[$sp_k] = (is_string($sp_roh) && (int) $sp_roh) ? 1 : 0;
                continue;
            }
            $sp_rr[$sp_k] = $sp_pruef($sp_art === 'z' ? $sp_num($sp_roh) : $sp_txt($sp_roh), $sp_s['regel.' . $sp_k],
                'r_' . $sp_k . '[' . $sp_i . ']', sprintf(spot_t('WERT.REGEL'), $sp_i + 1, $sp_k));
        }
        $sp_new['regeln'][$sp_i] = $sp_rr;
        /* Die Pruefungen ueber mehrere Felder einer Regel - nur, wenn deren Werte
         * selbst in Ordnung sind (sonst steht die Beanstandung schon oben). */
        $sp_zahl = function ($w) { return is_int($w) || is_float($w); };
        if (!$sp_zahl($sp_rr['energie']) || !$sp_zahl($sp_rr['leistung']) || !$sp_zahl($sp_rr['n'])
            || !$sp_zahl($sp_rr['frist']) || !$sp_zahl($sp_rr['soc_min']) || !$sp_zahl($sp_rr['soc_max'])) {
            continue;
        }
        /* Ein Fenster, das laenger ist als die Frist erlaubt, ist ein
         * Widerspruch - er wird gemeldet statt still zurechtgebogen. Gemessen
         * wird die LAUFZEIT (energie / leistung), nicht 'n'. */
        $sp_lauf = ($sp_rr['energie'] > 0 && $sp_rr['leistung'] > 0)
            ? $sp_rr['energie'] / $sp_rr['leistung'] : $sp_rr['n'];
        if ($sp_rr['aktiv'] && $sp_rr['frist'] >= 0 && $sp_lauf > 24) {
            $sp_fehler[] = sprintf(spot_t('REGEL.FEHLER_FRIST'), $sp_i + 1);
        }
        if ($sp_rr['aktiv'] && $sp_rr['energie'] > 0 && $sp_rr['leistung'] <= 0) {
            $sp_fehler[] = sprintf(spot_t('REGEL.FEHLER_ENERGIE_OHNE_LEISTUNG'), $sp_i + 1);
        }
        // MIT der Frage nach 'aktiv': eine abgeschaltete Regel hindert nichts.
        if ($sp_rr['aktiv'] && $sp_rr['soc_min'] > 0 && $sp_rr['soc_max'] > 0
            && $sp_rr['soc_min'] >= $sp_rr['soc_max']) {
            $sp_fehler[] = sprintf(spot_t('REGEL.FEHLER_SOC_REIHE'), $sp_i + 1);
        }
    }
    // ---- Fahrplaner, global ----
    foreach (array('budget_kw', 'pv_bonus', 'pv_schwelle', 'budget2_kw', 'budget2_von', 'budget2_bis') as $sp_k) {
        $sp_new[$sp_k] = $sp_pruef($sp_num($sp_post($sp_k)), $sp_s[$sp_k], $sp_k, $sp_k);
    }
    $sp_new['hysterese'] = isset($_POST['hysterese']) ? 1 : 0;
    foreach (array('pv_quelle', 'pv_einheit', 'last_quelle', 'last_einheit', 'pv_url', 'pv_pfad', 'pv_zeitfeld',
                   'pv_wertfeld', 'soc_url', 'soc_pfad', 'last_url', 'last_pfad', 'last_zeitfeld',
                   'last_wertfeld') as $sp_k) {
        $sp_new[$sp_k] = $sp_pruef($sp_txt($sp_post($sp_k)), $sp_s[$sp_k], $sp_k, $sp_k);
    }
    /* Dieselben Wachen wie bisher: eine Quelle ohne Pfad oder ohne Feldnamen
     * kann nichts liefern. */
    if ($sp_new['last_quelle'] === 'liste'
        && ($sp_new['last_zeitfeld'] === '' || $sp_new['last_wertfeld'] === '')) {
        $sp_fehler[] = spot_t('LAST.FEHLER_FELDNAMEN');
    }
    if ($sp_new['last_quelle'] !== '' && $sp_new['last_pfad'] === '') {
        $sp_fehler[] = spot_t('LAST.FEHLER_PFAD');
    }
    if ($sp_new['last_quelle'] !== '' && $sp_new['last_url'] === '') {
        $sp_fehler[] = spot_t('LAST.FEHLER_URL_FEHLT');
    }
    if ($sp_new['pv_quelle'] === 'liste'
        && ($sp_new['pv_zeitfeld'] === '' || $sp_new['pv_wertfeld'] === '')) {
        $sp_fehler[] = spot_t('PLAN.FEHLER_FELDNAMEN');
    }
    if ($sp_new['pv_quelle'] !== '' && $sp_new['pv_quelle'] !== 'forecast_solar'
        && $sp_new['pv_pfad'] === '') {
        $sp_fehler[] = spot_t('PLAN.FEHLER_PFAD');
    }
    $sp_new['wp_enabled'] = isset($_POST['wp_enabled']) ? 1 : 0;
    $sp_new['wp_name'] = $sp_pruef($sp_txt($sp_post('wp_name')), $sp_s['wp_name'], 'wp_name', 'wp_name');
    foreach (array('wp_netz', 'wp_konzession') as $sp_k) {
        $sp_new[$sp_k] = $sp_pruef($sp_num($sp_post($sp_k)), $sp_s[$sp_k], $sp_k, $sp_k);
    }
    $sp_new['co2_enabled'] = isset($_POST['co2_enabled']) ? 1 : 0;
    foreach (array('co2_clean', 'fixed_price', 'fix_grund', 'fix_sofortbonus', 'fix_neubonus', 'fix_neubonus_pct',
                   'fix_rabatt') as $sp_k) {
        $sp_new[$sp_k] = $sp_pruef($sp_num($sp_post($sp_k)), $sp_s[$sp_k], $sp_k, $sp_k);
    }
    /* Monatsverbraeuche: sobald mindestens einer gepflegt ist, ergibt ihre
     * Summe den Jahresverbrauch. Ein leerer Monat heisst "nicht gepflegt" (0). */
    $sp_new['months'] = array();
    $sp_msum = 0.0;
    $sp_min = $sp_post('months', array());
    if (!is_array($sp_min)) {
        $sp_fehler[] = sprintf(spot_t('WERT.FELD'), 'months', spot_wert_text(array('MONATE')));
        $sp_min = array();
    }
    for ($sp_i = 0; $sp_i < 12; $sp_i++) {
        $sp_v = isset($sp_min[$sp_i]) ? $sp_min[$sp_i] : '';
        $sp_v = (is_string($sp_v) && trim($sp_v) === '') ? 0.0 : $sp_num($sp_v);
        $sp_v = $sp_pruef($sp_v, array('zahl', 0, 20000), 'months[' . $sp_i . ']', 'months.' . $sp_i);
        $sp_new['months'][$sp_i] = is_float($sp_v) ? $sp_v : 0.0;
        $sp_msum += is_float($sp_v) ? $sp_v : 0.0;
    }
    $sp_new['consumption'] = $sp_msum > 0 ? (int) round($sp_msum)
        : $sp_pruef($sp_num($sp_post('consumption')), $sp_s['consumption'], 'consumption', 'consumption');
    $sp_new['shift_kwh'] = $sp_pruef($sp_num($sp_post('shift_kwh')), $sp_s['shift_kwh'], 'shift_kwh', 'shift_kwh');
    $sp_new['marstek_enabled'] = isset($_POST['marstek_enabled']) ? 1 : 0;
    /* Leer heisst "automatisch die eigene LoxBerry-Adresse". Alles andere muss
     * eine http- oder https-Adresse sein (spot_url_ok). Bis 1.2.31 setzte eine
     * abgewiesene Adresse nur einen Hinweis, und der Rest wurde trotzdem
     * gespeichert (aWATTar-k4). */
    $sp_murl = $sp_txt($sp_post('marstek_url'));
    if (spot_wert_gegen($sp_murl, $sp_s['marstek_url']) !== null) {
        $sp_fehler[] = spot_t('TEXT.MARSTEK_URL_FEHL');
        $sp_mt_mangel[] = 'marstek_url';
    }
    $sp_new['marstek_url'] = $sp_murl;
    foreach (array('marstek_hours', 'marstek_power') as $sp_k) {
        $sp_new[$sp_k] = $sp_pruef($sp_num($sp_post($sp_k)), $sp_s[$sp_k], $sp_k, $sp_k);
    }
    $sp_new['marstek_neg'] = isset($_POST['marstek_neg']) ? 1 : 0;
    /* ---- Aktionstoken des Marstek (Energie-1 C2, Entscheidung Nr. 25) ----
     *
     * Wie ein Kennwort: ein leeres Feld heisst "unveraendert", der Haken
     * loescht, beides zugleich ist ein Widerspruch. Leerraum am Rand wird
     * still abgeschnitten (Nr. 19), alles andere, was nicht die Form des
     * Marstek-Tokens hat, wird beanstandet - nichts gespeichert (Nr. 16).
     * Eine Liste (marstek_token[]=...) ist eine Beanstandung, kein TypeError. */
    $sp_mt = isset($sp_alt['marstek_token']) ? (string) $sp_alt['marstek_token'] : '';
    $sp_mt_roh = isset($_POST['marstek_token']) ? $_POST['marstek_token'] : '';
    $sp_x2_mt_weg = isset($_POST['marstek_token_weg']);
    if (!is_string($sp_mt_roh)) {
        $sp_fehler[] = spot_t('TEXT.MARSTEK_TOKEN_FORM');
        $sp_mt_mangel[] = 'marstek_token';
        $sp_mt_roh = '';
    } else {
        $sp_mt_roh = trim($sp_mt_roh);
        if ($sp_mt_roh !== '' && $sp_x2_mt_weg) {
            $sp_fehler[] = spot_t('TEXT.MARSTEK_TOKEN_BEIDES');
            $sp_mt_mangel[] = 'marstek_token';
        } elseif ($sp_mt_roh !== '' && !spot_marstek_token_form_ok($sp_mt_roh)) {
            $sp_fehler[] = spot_t('TEXT.MARSTEK_TOKEN_FORM');
            $sp_mt_mangel[] = 'marstek_token';
        } elseif ($sp_mt_roh !== '') {
            $sp_mt = $sp_mt_roh;
        } elseif ($sp_x2_mt_weg) {
            $sp_mt = '';
        }
    }
    $sp_new['marstek_token'] = $sp_mt;
    /* Ein Token in der Adresse gehoert ins eigene Feld - gemessen wird die
     * EINGABE. */
    $sp_murl_roh = isset($_POST['marstek_url']) && is_string($_POST['marstek_url'])
        ? trim($_POST['marstek_url']) : '';
    $sp_murl_tok = ($sp_murl_roh !== '' && spot_marstek_url_hat_token($sp_murl_roh));
    if ($sp_murl_tok) {
        $sp_fehler[] = spot_t('TEXT.MARSTEK_URL_TOKEN');
        $sp_mt_mangel[] = 'marstek_url';
    }
    /* Eingeschaltet ohne Token waere still wirkungslos: der Marstek weist
     * jeden Sollwert mit 403 ab. Das ist bis 1.2.29 so gewesen. */
    if ($sp_new['marstek_enabled'] && $sp_new['marstek_token'] === '' && !$sp_murl_tok
        && !in_array('marstek_token', $sp_mt_mangel, true)) {
        $sp_fehler[] = spot_t('TEXT.MARSTEK_KEIN_TOKEN');
        $sp_mt_mangel[] = 'marstek_token';
    }
    /* P6 (aWATTar-c2b, ab Werk aus): mit dem Haken "fremde Schreiber
     * beanstanden" wird eine eingeschaltete Kopplung nicht gespeichert, solange
     * der Marstek in seiner letzten Antwort (hoechstens 15 min alt) mehr als
     * einen Schreiber gemeldet hat (;SCHREIBER=n, Marstek ab 1.1.19). Eine
     * Antwort ohne das Feld (aeltere Fassung, oder noch nie gesendet) ist nur
     * ein Hinweis - abgewiesen wird dann nichts. Eine Abfrage, die ohne Senden
     * die Schreiber nennt, bietet der Marstek nicht an. */
    $sp_new['marstek_fremd_beanstanden'] = isset($_POST['marstek_fremd_beanstanden']) ? 1 : 0;
    $sp_p6_hinweis = '';
    if ($sp_new['marstek_enabled'] && $sp_new['marstek_fremd_beanstanden']) {
        $sp_me = spot_marstek_ergebnis_lesen();
        if ($sp_me !== null && (int) $sp_me['schreiber'] >= 2 && time() - (int) $sp_me['ts'] <= 900) {
            $sp_fehler[] = sprintf(spot_t('TEXT.MARSTEK_FREMD_BEANSTANDET'), (int) $sp_me['schreiber']);
            $sp_feldfehler[] = 'marstek_enabled';
        } elseif ($sp_me === null || (int) $sp_me['schreiber'] < 0) {
            $sp_p6_hinweis = spot_t('TEXT.MARSTEK_FREMD_UNBEKANNT');
        }
    }
    // MQTT wohnt im eigenen Reiter mit eigenem Formular - aus dem Bestand.
    $sp_new['mqtt_enabled'] = isset($sp_alt['mqtt_enabled']) ? (int) $sp_alt['mqtt_enabled'] : 0;
    $sp_new['mqtt_topic'] = isset($sp_alt['mqtt_topic']) && $sp_alt['mqtt_topic'] !== '' ? $sp_alt['mqtt_topic'] : 'spot_awattar';
    // ---- Meldungen ----
    $sp_hroh = $sp_post('hours', array());
    $sp_hours = array();
    if (!is_array($sp_hroh)) {
        $sp_hroh = array('x');
    }
    foreach ($sp_hroh as $sp_h) {
        $sp_hours[] = (is_string($sp_h) && preg_match('/^[0-9]{1,2}$/', $sp_h)) ? (int) $sp_h : $sp_h;
    }
    $sp_hours = $sp_pruef($sp_hours, $sp_s['notify.hours'], 'hours[]', 'notify.hours');
    if (is_array($sp_hours) && spot_wert_gegen($sp_hours, $sp_s['notify.hours']) === null) {
        sort($sp_hours);
    }
    $sp_new['notify'] = array(
        'audio' => isset($_POST['notify_audio']) ? 1 : 0,
        'push' => isset($_POST['notify_push']) ? 1 : 0,
        'hours' => $sp_hours,
        'only_cheap' => isset($_POST['only_cheap']) ? 1 : 0,
        'negative' => isset($_POST['neg_always']) ? 1 : 0,
        'tomorrow' => isset($_POST['notify_tomorrow']) ? 1 : 0,
    );
    // ---- Sprachausgabe ----
    $sp_lang = $sp_txt($sp_post('tts_lang'));
    $sp_laut = $sp_txt($sp_post('tts_google_laut'));
    $sp_new['tts'] = array(
        'mode' => $sp_pruef($sp_txt($sp_post('tts_mode')), $sp_s['tts.mode'], 'tts_mode', 'tts.mode'),
        'ip' => $sp_pruef($sp_txt($sp_post('tts_ip')), $sp_s['tts.ip'], 'tts_ip', 'tts.ip'),
        'port' => $sp_pruef($sp_num($sp_post('tts_port')), $sp_s['tts.port'], 'tts_port', 'tts.port'),
        'zones' => $sp_pruef($sp_txt($sp_post('tts_zones')), $sp_s['tts.zones'], 'tts_zones', 'tts.zones'),
        'volume' => $sp_pruef($sp_num($sp_post('tts_volume')), $sp_s['tts.volume'], 'tts_volume', 'tts.volume'),
        'lang' => $sp_pruef(is_string($sp_lang) ? strtolower($sp_lang) : $sp_lang, $sp_s['tts.lang'], 'tts_lang', 'tts.lang'),
        'template' => $sp_pruef($sp_txt($sp_post('tts_template')), $sp_s['tts.template'], 'tts_template', 'tts.template'),
        'alexa_token' => '',
        'alexa_geraet' => $sp_pruef($sp_txt($sp_post('tts_alexa_geraet')), $sp_s['tts.alexa_geraet'], 'tts_alexa_geraet', 'tts.alexa_geraet'),
        'google_token' => '',
        'google_geraet' => $sp_pruef($sp_txt($sp_post('tts_google_geraet')), $sp_s['tts.google_geraet'], 'tts_google_geraet', 'tts.google_geraet'),
        'google_laut' => $sp_laut === '' ? -1
            : $sp_pruef($sp_num($sp_laut), $sp_s['tts.google_laut'], 'tts_google_laut', 'tts.google_laut'),
    );
    /* S1: die Sprechtoken wie das Marstek-Token - leer = unveraendert, Haken
     * loescht, beides zugleich, falsche Form oder Liste: beanstandet. Eine
     * gewaehlte Ausgabeart ohne Token ebenso (sie bliebe still wirkungslos). */
    $sp_tok_getippt = false;
    foreach (array('alexa' => 'alexang', 'google' => 'cc4lox') as $sp_a => $sp_modus) {
        $sp_feld = 'tts_' . $sp_a . '_token';
        $sp_tk_neu = (isset($sp_alt['tts'][$sp_a . '_token']) && is_string($sp_alt['tts'][$sp_a . '_token']))
            ? $sp_alt['tts'][$sp_a . '_token'] : '';
        $sp_roh = $sp_post($sp_feld);
        $sp_weg = isset($_POST[$sp_feld . '_weg']);
        if (!is_string($sp_roh)) {
            $sp_fehler[] = spot_t('TEXT.SPRECH_TOKEN_FORM');
            $sp_feldfehler[] = $sp_feld;
        } else {
            $sp_roh = trim($sp_roh);
            $sp_tok_getippt = $sp_tok_getippt || $sp_roh !== '';
            if ($sp_roh !== '' && $sp_weg) {
                $sp_fehler[] = spot_t('TEXT.SPRECH_TOKEN_BEIDES');
                $sp_feldfehler[] = $sp_feld;
            } elseif ($sp_roh !== '' && !spot_sprech_token_ok($sp_roh)) {
                $sp_fehler[] = spot_t('TEXT.SPRECH_TOKEN_FORM');
                $sp_feldfehler[] = $sp_feld;
            } elseif ($sp_roh !== '') {
                $sp_tk_neu = $sp_roh;
            } elseif ($sp_weg) {
                $sp_tk_neu = '';
            }
        }
        $sp_new['tts'][$sp_a . '_token'] = $sp_tk_neu;
        if ($sp_new['tts']['mode'] === $sp_modus && $sp_tk_neu === '' && !in_array($sp_feld, $sp_feldfehler, true)) {
            $sp_fehler[] = spot_t($sp_a === 'alexa' ? 'TEXT.SPRECH_ALEXA_OHNE_TOKEN' : 'TEXT.SPRECH_GOOGLE_OHNE_TOKEN');
            $sp_feldfehler[] = $sp_feld;
        }
    }
    // Das Token des Endpunkts gehoert nicht ins Formular (eigene Knoepfe).
    $sp_new['token'] = isset($sp_alt['token']) ? $sp_alt['token'] : '';

    // Ein eingetipptes Token kommt nach einer Beanstandung nicht wieder ins
    // Formular (Kennwortfeld) - das muss dastehen.
    if ($sp_fehler && $sp_mt_roh !== '') {
        $sp_fehler[] = spot_t('TEXT.MARSTEK_TOKEN_NICHT_UEBERNOMMEN');
    }
    if ($sp_fehler && $sp_tok_getippt) {
        $sp_fehler[] = spot_t('TEXT.SPRECH_TOKEN_NICHT_UEBERNOMMEN');
    }
    if ($sp_fehler) {
        // Nichts schreiben, solange etwas beanstandet ist (Nr. 16).
        $sp_nichts_gespeichert = true;
        $sp_eingaben = spot_eingaben_sammeln('save', $sp_feldfehler);
    } elseif (spot_config_save($sp_new)) {
        $sp_saved = true;
        if ($sp_p6_hinweis !== '') {
            $sp_note = $sp_p6_hinweis;
        }
    } else {
        $sp_err = sprintf(spot_t('TEXT.SPEICHERN_FEHL'), $sp_cfgfile);
    }
}

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das.
 *
 * DER BLOCK STEHT VOR DEM LADEN - das ist nicht Geschmackssache.
 * Bis 1.2.12 stand er dahinter, und das hatte zwei Folgen, beide gemessen:
 * nach einem erfolgreichen Zurueckspielen zeigte die Seite weiter den ALTEN
 * Stand (die Konfiguration war zu diesem Zeitpunkt laengst gelesen), und
 * ein Klick auf Speichern nahm die Sicherung wieder zurueck. */
if ($sp_ist_post && isset($_POST['spot_sichern'])) {
    list($sp_sname, $sp_sinhalt) = spot_sicherung_schreiben();
    if ($sp_sname !== '') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $sp_sname . '"');
        echo $sp_sinhalt;
        exit;
    }
    $sp_fehler[] = spot_t('TEXT.SICH_SCHREIBFEHLER');
}

/* ---------------- Verlauf als CSV ----------------
 * Auch ein Download, also auch hier oben - und mit exit. */
if ($sp_ist_post && isset($_POST['spot_csv'])) {
    list($sp_cname, $sp_cinhalt) = spot_history_csv();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $sp_cname . '"');
    echo $sp_cinhalt;
    exit;
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei
 * des Servers unterschieben. Dann die Groessengrenze - eine Sicherung
 * dieses Plugins ist wenige Kilobyte gross; alles darueber wird gar
 * nicht erst gelesen. */
if ($sp_ist_post && isset($_POST['spot_zurueck'])) {
    if (!isset($_FILES['spot_sicherung']) || !is_array($_FILES['spot_sicherung'])
        || !isset($_FILES['spot_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['spot_sicherung']['tmp_name'])) {
        $sp_fehler[] = spot_t('TEXT.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['spot_sicherung']['size'] > 262144) {
        $sp_fehler[] = spot_t('TEXT.SICH_ZU_GROSS');
    } else {
        $sp_rs_alt = spot_config();
        list($spot_neu, $spot_mangel, $spot_n) = spot_sicherung_lesen(
            (string) @file_get_contents($_FILES['spot_sicherung']['tmp_name']));
        if ($spot_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert
             * wird nichts. */
            $sp_fehler[] = spot_t('TEXT.SICH_ABGELEHNT') . ' ' . implode(' ', $spot_mangel);
        } elseif (spot_config_save($spot_neu)) {
            $sp_note = sprintf(spot_t('TEXT.SICH_UEBERNOMMEN'), $spot_n);
            /* M1: auch eine Sicherung kann das Praefix wechseln oder MQTT ausschalten. */
            $sp_rs_s = $sp_mqtt_wechsel(!empty($sp_rs_alt['mqtt_enabled']), spot_mqtt_praefix($sp_rs_alt),
                !empty($spot_neu['mqtt_enabled']), spot_mqtt_praefix($spot_neu));
            if ($sp_rs_s) {
                $sp_note .= ' ' . implode(' ', $sp_rs_s);
            }
        } else {
            $sp_fehler[] = spot_t('TEXT.SICH_SCHREIBFEHLER');
        }
    }
}

/* ================= JEDER POST ENDET MIT EINER UMLEITUNG (O2) =================
 *
 * Regeln/04 und Entscheidung Nr. 19 (PRG): header('Location: ...', true, 303)
 * und exit; das Ergebnis reist als Einmalmeldung (spot_meldung_ablegen()). Die
 * Downloads (Vorlage, Sicherung, Verlauf) sind oben schon mit exit fertig.
 * Auch die Abweisung durch den Wachposten geht diesen Weg. Bis 1.2.31
 * antwortete jeder POST mit der Seite selbst: F5 speicherte noch einmal und
 * wuerfelte das Token neu (Pruefbericht oberflaeche, Befunde 2 und 3). */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!spot_meldung_ablegen(array(
            'note' => (string) $sp_note, 'err' => (string) $sp_err, 'fehler' => array_values($sp_fehler),
            'gespeichert' => $sp_saved ? 1 : 0, 'nichts' => $sp_nichts_gespeichert ? 1 : 0,
            'fmt_fehlt' => $sp_fmt_fehlt ? 1 : 0, 'mt_mangel' => array_values($sp_mt_mangel),
            'mt_weg' => $sp_x2_mt_weg ? 1 : 0, 'felder' => array_values($sp_feldfehler),
            'eingaben' => $sp_eingaben, 'plantest' => $sp_plantest_an ? 1 : 0, 'endpunkt' => $sp_ep_an ? 1 : 0))) {
        spot_log('Die Einmalmeldung liess sich nicht schreiben - das Ergebnis des letzten Knopfdrucks '
            . 'ist nach der Umleitung nicht zu sehen.');
    }
    header('Location: index.php?form=' . substr($sp_tab, 4), true, 303);
    exit;
}
$sp_einmal = spot_meldung_abholen();
if ($sp_einmal !== null) {
    $sp_ein_txt = function ($k) use ($sp_einmal) {
        return (isset($sp_einmal[$k]) && is_string($sp_einmal[$k])) ? $sp_einmal[$k] : '';
    };
    $sp_ein_liste = function ($k) use ($sp_einmal) {
        return (isset($sp_einmal[$k]) && is_array($sp_einmal[$k])) ? array_values(array_filter($sp_einmal[$k], 'is_string')) : array();
    };
    $sp_note = $sp_ein_txt('note');
    $sp_err = $sp_ein_txt('err');
    $sp_fehler = $sp_ein_liste('fehler');
    $sp_saved = !empty($sp_einmal['gespeichert']);
    $sp_nichts_gespeichert = !empty($sp_einmal['nichts']);
    $sp_fmt_fehlt = !empty($sp_einmal['fmt_fehlt']);
    $sp_mt_mangel = $sp_ein_liste('mt_mangel');
    $sp_x2_mt_weg = !empty($sp_einmal['mt_weg']);
    $sp_feldfehler = $sp_ein_liste('felder');
    $sp_eingaben = spot_eingaben_pruefen(isset($sp_einmal['eingaben']) ? $sp_einmal['eingaben'] : null);
    $sp_plantest_an = !empty($sp_einmal['plantest']);
    $sp_ep_an = !empty($sp_einmal['endpunkt']);
}
if ($sp_plantest_an) {
    list($sp_pt_n, $sp_pt_f, $sp_plantest) = plan_selbsttest();
}

// ---------- Laden ----------
/* Die Vorgaben kommen aus spot_vorgaben() und NICHT aus einer zweiten,
 * hier abgeschriebenen Liste. Bis 1.2.12 stand hier eine Kopie mit 25 von
 * 49 Schluesseln - jeder neue Schluessel musste an zwei Stellen nachgezogen
 * werden, und wer es vergass, bekam keine Fehlermeldung, sondern eine
 * undefinierte Variable, die PHP lautlos als leer behandelt.
 * spot_config() vervollstaendigt ohnehin aus derselben Quelle. */
$sp_cfg = function_exists('spot_config') ? spot_config() : array();
if (!is_array($sp_cfg)) { $sp_cfg = array(); }
if (function_exists('spot_vorgaben')) { $sp_cfg += spot_vorgaben(); }
$sp_notify = is_array($sp_cfg['notify']) ? $sp_cfg['notify'] : array();
$sp_notify += array('audio' => 0, 'push' => 0, 'hours' => array(), 'only_cheap' => 0, 'negative' => 1, 'tomorrow' => 0);
$sp_hoursel = array_map('intval', (array) $sp_notify['hours']);
$sp_tts = is_array($sp_cfg['tts']) ? $sp_cfg['tts'] : array();
$sp_tts += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091, 'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');

$sp_st = function_exists('spot_state') ? spot_state() : array();
$sp_loglines = array();
if (is_file($sp_logfile)) {
    $sp_loglines = spot_log_ende($sp_logfile, 300);
}


/* Hier stand bis 1.2.27 eine eigene Kopie der Wurzelsuche
 * lb_wurzel_ermitteln - ohne config/system/general.json und erst NACH ihrem
 * ersten Aufruf oben definiert. Die Pfade kommen jetzt aus spot_paths() der
 * Bibliothek, die Suche aus spot_lib.php. */

function sp_n($v, $d = 2) { return number_format((float) $v, $d, ',', '.'); }

/** Balkendiagramm der Stundenpreise (heute + morgen). */
function sp_chart($st) {
    $rows = array();
    foreach (array('heute', 'morgen') as $tag) {
        if (empty($st[$tag]['hours'])) { continue; }
        foreach ($st[$tag]['hours'] as $h => $r) {
            $rows[] = array($tag, (int) $h, (float) $r['ct'], (int) $r['ts']);
        }
    }
    if (!$rows) {
        return '<div class="sm-small">' . spot_t('TEXT.KEINE_PREISDATEN') . '</div>';
    }
    $w = 900; $h = 190; $x0 = 40; $y0 = 10; $pw = $w - $x0 - 10; $ph = $h - $y0 - 34;
    $vals = array_map(function ($r) { return $r[2]; }, $rows);
    $mx = max($vals); $mn = min(0, min($vals));
    $span = max(0.001, $mx - $mn);
    $bw = $pw / max(1, count($rows));
    $now = time(); $hstart = $now - ($now % 3600);
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;height:auto;background:#fafafa;border:1px solid #e0e0e0;border-radius:8px;" xmlns="http://www.w3.org/2000/svg">';
    for ($i = 0; $i <= 4; $i++) {
        $v = $mn + $span * $i / 4;
        $y = $y0 + $ph - $ph * ($v - $mn) / $span;
        $svg .= '<line x1="' . $x0 . '" y1="' . round($y, 1) . '" x2="' . ($x0 + $pw) . '" y2="' . round($y, 1) . '" stroke="#e5e5e5"/>';
        $svg .= '<text x="' . ($x0 - 5) . '" y="' . round($y + 3, 1) . '" font-size="9" fill="#999" text-anchor="end">' . number_format($v, 0) . '</text>';
    }
    foreach ($rows as $i => $r) {
        $x = $x0 + $i * $bw;
        $y = $y0 + $ph - $ph * ($r[2] - $mn) / $span;
        $base = $y0 + $ph - $ph * (0 - $mn) / $span;
        $col = $r[3] === $hstart ? '#e65100' : ($r[0] === 'heute' ? '#6dac20' : '#9ccc65');
        if ($r[2] < 0) { $col = '#1565c0'; }
        $top = min($y, $base); $hh = max(1, abs($base - $y));
        $svg .= '<rect x="' . round($x + 1, 1) . '" y="' . round($top, 1) . '" width="' . round(max(1, $bw - 2), 1) . '" height="' . round($hh, 1) . '" fill="' . $col . '"><title>' . $r[1] . ' ' . spot_t('TEXT.UHR_3') . ' ('
            . spot_t($r[0] === 'heute' ? 'TEXT.SVG_HEUTE' : 'TEXT.SVG_MORGEN') . '): ' . number_format($r[2], 2) . ' ct</title></rect>';
        if ($r[1] % 3 === 0) {
            $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 16) . '" font-size="8" fill="#999" text-anchor="middle">' . $r[1] . '</text>';
        }
    }
    $mid = $x0 + $pw * (count(array_filter($rows, function ($r) { return $r[0] === 'heute'; })) / max(1, count($rows)));
    if ($mid > $x0 && $mid < $x0 + $pw) {
        $svg .= '<line x1="' . round($mid, 1) . '" y1="' . $y0 . '" x2="' . round($mid, 1) . '" y2="' . ($y0 + $ph) . '" stroke="#bbb" stroke-dasharray="4,3"/>';
        $svg .= '<text x="' . round($mid + 4, 1) . '" y="' . ($y0 + 12) . '" font-size="9" fill="#999">'
              . spot_t('TEXT.SVG_MORGEN') . '</text>';
    }
    $svg .= '<text x="' . $x0 . '" y="' . ($h - 3) . '" font-size="9" fill="#999">'
          . spot_t('TEXT.SVG_ACHSE') . '</text>';
    return $svg . '</svg>';
}

$sp_mon = function_exists('spot_months') ? spot_months() : array('use' => 0, 'kwh' => array_fill(0, 12, 0.0), 'summe' => 0);
$sp_ownurl = function_exists('spot_marstek_default_url') ? spot_marstek_default_url() : 'http://127.0.0.1/plugins/marstekvenus/marstek.php';

/* Freiwilliges Token fuer den unangemeldeten Endpunkt. $sp_tk haengt an jede
 * Adresse den passenden Zusatz - ohne Token bleibt er leer, dann sehen die
 * Knoepfe und die Beispieladressen aus wie bisher. */
$sp_token = (isset($sp_cfg['token']) && spot_endpunkt_token_form_ok($sp_cfg['token'])) ? $sp_cfg['token'] : '';
$sp_tk  = $sp_token !== '' ? '?token=' . rawurlencode($sp_token) : '';   // erster Parameter
$sp_tk2 = $sp_token !== '' ? '&amp;token=' . rawurlencode($sp_token) : ''; // weiterer Parameter
$sp_frame = class_exists('LBWeb', false);
$sp_host = sp_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');
$sp_addon = (float) $sp_cfg['netz'] + (float) $sp_cfg['steuer'] + (float) $sp_cfg['konzession'] + (float) $sp_cfg['umlagen'] + (float) $sp_cfg['aufschlag'];

/* ==================================================================
 * BEANSTANDUNGEN AUSGEBEN - an EINER Stelle, fuer ALLE Zweige
 * ==================================================================
 *
 * Bis 1.2.12 wurde $sp_fehler nur INNERHALB des Speicherzweigs in $sp_err
 * gefaltet. Alles, was der Sicherungszweig hineinschrieb, fiel damit
 * heraus: eine abgelehnte Sicherungsdatei erzeugte gar keine Meldung - die
 * Seite lud neu und sah aus wie vorher. Gemessen mit einer fremden Datei:
 * 0 Fehlerkaesten, 0 Ablehnungstexte, Konfiguration richtigerweise
 * unveraendert - nur erfuhr es niemand.
 *
 * Wer einen Zweig ergaenzt, schreibt seine Beanstandungen nach
 * $sp_fehler[] und muss sich um die Ausgabe nicht mehr kuemmern.
 * ================================================================== */
/* Hier wurden bis 1.2.31 die still geklemmten Werte als Hinweis angehaengt.
 * Seit dem Durchgang 01.10.2026 wird nichts mehr geklemmt (O4, Nr. 19): ein
 * Wert ausserhalb seiner Schranke ist eine Beanstandung. */
if ($sp_fehler) {
    $sp_err = ($sp_err !== '' ? $sp_err . ' | ' : '') . implode(' | ', $sp_fehler);
    /* Der Satz gehoert HINTER die Beanstandungen: erst wird gesagt, was
     * beanstandet ist, dann die Folge daraus. */
    if ($sp_nichts_gespeichert) {
        $sp_err .= ' | ' . spot_t('TEXT.NICHTS_GESPEICHERT');
    }
}
/* Der Wachposten hat abgewiesen. Das ist KEIN Bedienfehler des Anwenders -
 * die haeufigste Ursache ist eine Seite, die zu lange offen stand. Der Text
 * sagt deshalb, was zu tun ist, statt "Zugriff verweigert" zu rufen. */
if ($sp_fmt_fehlt) {
    $sp_err = ($sp_err !== '' ? $sp_err . ' | ' : '') . spot_t('TEXT.FMT_ABGEWIESEN');
}

if ($sp_frame) {
    LBWeb::lbheader('Spotpreis aWATTar', 'https://wiki.loxberry.de/', 'help.html');
}
/* O1: die Seite wird gepuffert, damit die Selbstpruefung im Reiter Test die
 * Verschachtelung der Flaechen am FERTIGEN HTML messen kann (ganz unten). */
ob_start();

?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Und beim
   Auswahlfeld liegt das unsichtbare <select> ueber dem Knopf und faengt die
   Klicks ab; wer es gestaltet, schiebt es weg. Deshalb wird ausschliesslich
   der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht: fehlen sie, kommt
   der Hover-Zustand vom Rahmen und ist unlesbar. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln — bewusst ein anderer Name als sm-knopfreihe.
   Beide zu verwechseln hat am 26.07.2026 die Statusanzeige zerlegt. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }

.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar.
   Ohne diese zwei Zeilen stehen alle fuenf Reiter untereinander.
   MIT ihnen und OHNE serverseitiges sm-active ist die Seite dagegen
   vollstaendig leer, sobald das Skript nicht laeuft - genau das war bis
   07.08.2026 der Fall. Die Klasse gehoert deshalb schon ins ausgelieferte
   HTML, siehe die Reiterleiste weiter unten. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
/* ==================================================================
   Bis hierher wortgetreu aus VORLAGE_hausstandard.css.html (Stand
   17.09.2026). Bis 1.2.26 stand hier ein eigener Block: 17 Regeln der
   Vorlage fehlten, darunter die Knopffarben mit !important und ihre
   Hover-Farben, 7 wichen ab, und die Flaechen hiessen sm-pane statt
   sm-seite. Ab hier folgen NUR Regeln, die die Vorlage nicht kennt.
   ================================================================== */
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select, .sm-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 150px; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; font-weight: 600; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-warn { background: #fff8e1; border: 1px solid #ffe082; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
/* Breite Tabellen rollen SELBST, statt die Seite nach rechts zu schieben. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-hours { display: flex; flex-wrap: wrap; gap: 4px; margin: 6px 0; }
.sm-hours label { display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; background: #f5f5f5; border: 1px solid #ddd; border-radius: 6px; padding: 5px 9px; margin: 0; font-weight: 500; font-size: 0.85em; width: 95px; box-sizing: border-box; }
.sm-hours label:hover { background: #eef7e4; border-color: #6dac20; }
.sm-months { display: flex; flex-wrap: wrap; gap: 8px; margin: 6px 0; }
.sm-months > div { width: 108px; }
.sm-months label { margin: 0 0 2px; font-size: 0.8em; font-weight: 600; color: #555; white-space: nowrap; min-height: 0; }
.sm-months input { padding: 6px 8px; font-size: 0.9em; text-align: right; }
/* Beschriftungen einer Zeile auf gleiche Hoehe bringen, damit die Eingabefelder
   waagrecht fluchten - auch wenn ein Text zweizeilig umbricht */
.sm-row > div > label:not([style]) { min-height: 2.6em; display: flex; align-items: flex-end; }
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-knopfreihe form { margin: 0; display: flex; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen (Regeln/04). Die Raute im
   SVG wird als %23 geschrieben: eine rohe Raute beendet in einer CSS-Adresse
   den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* X-2: beanstandete Felder (spot_eingaben_einsetzen()). */
.sm-wrap .sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }

</style>
<div class="sm-wrap">

<?php if ($sp_saved) { ?><div class="sm-alert sm-ok"><b><?php echo spot_t('TEXT.KONFIGURATION_GESPEICHERT'); ?></b> <?php echo spot_t('TEXT.INKL_SICHERUNGSKOPIE_FR_UPDATES'); ?></div><?php } ?>
<?php if ($sp_note !== '') { ?><div class="sm-alert sm-ok"><?= sp_e($sp_note) ?></div><?php } ?>
<?php if ($sp_err !== '') { ?><div class="sm-alert sm-err"><b><?php echo spot_t('TEXT.FEHLER'); ?></b> <?= sp_e($sp_err) ?></div><?php } ?>

<?php if (!empty($sp_st)) { ?>
<div class="sm-alert sm-info">
<?php if ($sp_st['ok']) { ?>
<b><?php echo spot_t('TEXT.JETZT'); ?><?= (int) $sp_st['stunde'] ?> <?php echo spot_t('TEXT.UHR'); ?> <?= sp_n($sp_st['cur'], 2) ?> <?php echo spot_t('TEXT.CT_KWH'); ?></b>
<?php echo spot_t('TEXT.DAVON_BRSE'); ?> <?= sp_n($sp_st['cur_boerse'], 2) ?> <?php echo spot_t('TEXT.CT_NCHSTE_STUNDE'); ?> <?= sp_n($sp_st['next'], 2) ?> <?php echo spot_t('TEXT.CT_RANG'); ?> <?= (int) $sp_st['rank'] >= 1
      ? (int) $sp_st['rank'] . ' ' . spot_t('TEXT.VON') . ' ' . (int) $sp_st['n']
      : '&ndash; (' . sp_e(sprintf(spot_t('TEXT.RANG_HORIZONT'), isset($sp_st['n_bekannt']) ? (int) $sp_st['n_bekannt'] : (int) $sp_st['n'], SPOT_RANG_MIN_STUNDEN)) . ')' ?> <?php echo spot_t('TEXT.NIVEAU'); ?> <?= $sp_st['level'] == 1 ? '<b>' . spot_t('TEXT.GNSTIG') . '</b>'
      : ($sp_st['level'] == 3 ? '<b>' . spot_t('TEXT.TEUER') . '</b>' : spot_t('TEXT.NORMAL')) ?>
<?= $sp_st['neg'] ? ' &middot; <b>' . spot_t('TEXT.BRSENPREIS_NEGATIV') . '</b>' : '' ?><br>
<?php echo spot_t('TEXT.HEUTE_MIN'); ?> <?= sp_n($sp_st['heute']['minp'], 2) ?> ct <?php echo spot_t('TEXT.UM'); ?> <?= (int) $sp_st['heute']['minh'] ?> <?php echo spot_t('TEXT.UHR_MAX'); ?> <?= sp_n($sp_st['heute']['maxp'], 2) ?> ct <?php echo spot_t('TEXT.UM'); ?> <?= (int) $sp_st['heute']['maxh'] ?> <?php echo spot_t('TEXT.UHR_SCHNITT'); ?> <?= sp_n($sp_st['heute']['avg'], 2) ?> ct
<?php if ($sp_st['tomorrow_ok']) { ?><br><?php echo spot_t('TEXT.MORGEN_MIN'); ?> <?= sp_n($sp_st['morgen']['minp'], 2) ?> ct <?php echo spot_t('TEXT.UM'); ?> <?= (int) $sp_st['morgen']['minh'] ?> <?php echo spot_t('TEXT.UHR_3'); ?> &middot;
<?php echo spot_t('TEXT.MAX'); ?> <?= sp_n($sp_st['morgen']['maxp'], 2) ?> ct <?php echo spot_t('TEXT.UM'); ?> <?= (int) $sp_st['morgen']['maxh'] ?> <?php echo spot_t('TEXT.UHR_3'); ?> &middot;
<?php echo spot_t('TEXT.SCHNITT_2'); ?> <?= sp_n($sp_st['morgen']['avg'], 2) ?> ct<?php } else { ?><br><?php echo spot_t('TEXT.MORGEN_NOCH_NICHT_VERFFENTLICHT_KO'); ?><?php } ?>
<?php if ($sp_st['fenster']['in'] >= 0) { ?><br><?php echo spot_t('TEXT.GNSTIGSTES'); ?> <?= (int) $sp_st['fenster_len'] ?><?php echo spot_t('TEXT.STUNDEN_FENSTER_AB'); ?> <?= (int) $sp_st['fenster']['h'] ?> <?php echo spot_t('TEXT.UHR_2'); ?><?= $sp_st['fenster']['in'] == 0 ? spot_t('TEXT.JETZT_2')
      : sprintf(spot_t('TEXT.IN_STUNDEN'), (int) $sp_st['fenster']['in']) ?><?php echo spot_t('TEXT.SCHNITT'); ?> <?= sp_n($sp_st['fenster']['ct'], 2) ?> ct<?php } ?>
<?php if (!empty($sp_st['wp_on'])) { ?><br><?= sp_e($sp_st['wp_name']) ?> <?php echo spot_t('TEXT.14A'); ?> <b><?= sp_n($sp_st['wp_cur'], 2) ?> ct/kWh</b> <?php echo spot_t('TEXT.NCHSTE_STUNDE'); ?> <?= sp_n($sp_st['wp_next'], 2) ?> ct<?php } ?>
<?php if (!empty($sp_st['co2_ok'])) { ?><br><?php echo spot_t('TEXT.CO_8322_INTENSITT'); ?> <b><?= (int) $sp_st['co2'] ?> <?php echo spot_t('TEXT.G_KWH'); ?></b><?= !empty($sp_st['co2_clean']) ? ' <b>' . spot_t('TEXT.SAUBER') . '</b> ' : ' ' ?>
&middot; <?php echo spot_t('TEXT.SAUBERSTE_STUNDE'); ?> <?= (int) $sp_st['co2_minh'] ?> <?php echo spot_t('TEXT.UHR_MIT'); ?> <?= (int) $sp_st['co2_min'] ?> <?php echo spot_t('TEXT.G_SCHNITT'); ?> <?= (int) $sp_st['co2_avg'] ?> g<?php } ?>
<br><?php echo spot_t('TEXT.TARIFVERGLEICH_LAUFENDER_MONAT_DYN'); ?> <b><?= sp_n($sp_st['dyn_monat'], 2) ?> ct</b> <?php echo spot_t('TEXT.GEGEN_FEST'); ?> <b><?= sp_n($sp_st['fix'], 2) ?> ct</b>
<?php echo spot_t('TEXT.TEXT'); ?> <?= $sp_st['diff_monat'] >= 0 ? spot_t('TEXT.DYN_WAERE_GUENSTIGER_UM') . ' '
      : '<b>' . spot_t('TEXT.FESTER_TARIF_IST_GNSTIGER_UM') . '</b> ' ?>
<?= sp_n(abs($sp_st['diff_monat']), 2) ?> <?php echo spot_t('TEXT.CT_KWH_2'); ?><?= sp_n(abs($sp_st['euro_monat']), 2) ?> <?php echo spot_t('TEXT.TEXT_2'); ?>
<br><?php echo spot_t('TEXT.VERSCHIEBE_POTENZIAL_7_TAGE'); ?> <?= sp_n($sp_st['shift_ct'], 2) ?> <?php echo spot_t('TEXT.CT_KWH_SPANNE_RUND'); ?> <b><?= sp_n($sp_st['shift_jahr'], 2) ?> <?php echo spot_t('TEXT.IM_JAHR'); ?></b>
<div style="margin-top:8px;"><?= sp_chart($sp_st) ?></div>
<?php } else { ?>
<b><?php echo spot_t('TEXT.NOCH_KEINE_PREISDATEN_GELADEN'); ?></b> <?php echo spot_t('TEXT.BITTE_UNTEN_DIE_PREISBESTANDTEILE_'); ?>
<?php } ?>
</div>
<?php } ?>

<?php
/*
 * Die Reiter sind echte Verweise, keine <div>. Vorher stand hier
 * <div class="sm-tab" data-pane="..."> - und weil alle Flaechen bis zum Lauf
 * des JavaScripts auf display:none stehen, war die Seite ohne JavaScript
 * vollstaendig leer. Jetzt setzt der Server die Klasse sm-active an Reiter
 * UND Flaeche; das JavaScript spart nur noch den Seitenaufbau.
 */
$sp_beschriftung = array(
    'settings' => 'REITER.EINSTELLUNGEN', 'mqtt' => 'REITER.MQTT',
    'loxone'   => 'REITER.LOXONE',
    'costs'    => 'REITER.KOSTEN',        'test'   => 'REITER.TEST',
    'log'      => 'REITER.LOG',
);
$sp_reiter = array();
foreach ($sp_reiter_ids as $sp_i) {
    // Faellt eine Beschriftung aus, steht dort die Kennung - ein Reiter ohne
    // Aufschrift waere schlimmer als einer mit haesslichem Namen.
    $sp_reiter['tab-' . $sp_i] = isset($sp_beschriftung[$sp_i])
        ? spot_t($sp_beschriftung[$sp_i]) : $sp_i;
}
?>
<?php
/* DIE REITERLEISTE STEHT AUSGESCHRIEBEN - das ist Absicht.
 *
 * Bis 1.2.12 entstand sie aus einer foreach-Schleife. Das sieht sauberer
 * aus und hat einen Preis, den man nicht sieht: hausstandard_pruefen.py
 * findet die Reiter dann nicht mehr und meldet in der Spalte "tab" einen
 * Strich - seit jeher, ohne dass es jemandem aufgefallen waere. Eine
 * Korrektur, die eine Pruefung blind macht, ist keine.
 *
 * Die Aufloesung ist nicht "Schleife oder Hand", sondern beides:
 * ausschreiben UND die Uebereinstimmung im Reiter Test nachrechnen lassen.
 * Die Zeile PRUEF.REITER haelt die drei Stellen gegeneinander - die Liste
 * $sp_reiter_ids, diese Leiste und die ids der Flaechen. Wer einen Reiter
 * ergaenzt und eine der drei vergisst, bekommt dort ein Kreuz statt einer
 * Seite, die nach jedem Absenden auf Einstellungen zurueckspringt.
 */
?>
<div class="sm-tabs">
    <a class="sm-tab<?php echo $sp_tab === 'tab-settings' ? ' sm-active' : ''; ?>"
       data-ziel="tab-settings" href="index.php?form=settings"><?php echo $sp_reiter['tab-settings']; ?></a>
    <a class="sm-tab<?php echo $sp_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>"
       data-ziel="tab-mqtt" href="index.php?form=mqtt"><?php echo $sp_reiter['tab-mqtt']; ?></a>
    <a class="sm-tab<?php echo $sp_tab === 'tab-loxone' ? ' sm-active' : ''; ?>"
       data-ziel="tab-loxone" href="index.php?form=loxone"><?php echo $sp_reiter['tab-loxone']; ?></a>
    <a class="sm-tab<?php echo $sp_tab === 'tab-costs' ? ' sm-active' : ''; ?>"
       data-ziel="tab-costs" href="index.php?form=costs"><?php echo $sp_reiter['tab-costs']; ?></a>
    <a class="sm-tab<?php echo $sp_tab === 'tab-test' ? ' sm-active' : ''; ?>"
       data-ziel="tab-test" href="index.php?form=test"><?php echo $sp_reiter['tab-test']; ?></a>
    <a class="sm-tab<?php echo $sp_tab === 'tab-log' ? ' sm-active' : ''; ?>"
       data-ziel="tab-log" href="index.php?form=log"><?php echo $sp_reiter['tab-log']; ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?php echo $sp_tab === 'tab-settings' ? ' sm-active' : ''; ?>" id="tab-settings">
<?php /* O8 (Pruefbericht oberflaeche, Befund 14): EINE Legende oben im Reiter,
         vor dem ersten Knopf (Regeln/04). Bis 1.2.31 stand sie erst am
         Sicherungsblock, unter fuenf Knoepfen. */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo spot_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo spot_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo spot_t('LEGENDE.AKTION'); ?></span>
</div>
<?php ob_start(); /* X-2: das Formular laeuft durch spot_eingaben_einsetzen() */ ?>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<?php echo spot_fmt(); ?>

<h2><?php echo spot_t('TEXT.MARKT'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo spot_t('TEXT.PREISZONE_API'); ?></label>
        <select data-role="none" name="market" id="market" onchange="spMarket()">
            <option value="de"<?= $sp_cfg['market'] === 'de' ? ' selected' : '' ?>><?php echo spot_t('TEXT.DEUTSCHLAND_API_AWATTAR_DE'); ?></option>
            <option value="at"<?= $sp_cfg['market'] === 'at' ? ' selected' : '' ?>><?php echo spot_t('TEXT.OUML_STERREICH_API_AWATTAR_AT'); ?></option>
        </select>
        <div class="sm-small"><?php echo spot_t('TEXT.QUELLE_OFFENE_AWATTAR_API_EPEX_SPO'); ?></div>
    </div>
</div>

<h2><?php echo spot_t('TEXT.PREISZUSAMMENSETZUNG_ENDPREIS'); ?></h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo spot_t('TEXT.ALLE_ANGABEN_IN'); ?> <b><?php echo spot_t('TEXT.CT_KWH_NETTO'); ?></b><?php echo spot_t('TEXT.ENDPREIS_BRSENPREIS_SUMME_DER_AUFS'); ?></div>
<div class="sm-row">
    <div>
        <label><?php echo spot_t('TEXT.NETZENTGELTE'); ?></label>
        <input data-role="none" type="text" name="netz" value="<?= sp_e($sp_cfg['netz']) ?>" placeholder="6.47">
        <div class="sm-small"><?php echo spot_t('TEXT.INKL_MESSSTELLENBETRIEB_STARK_REGI'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.STROMSTEUER'); ?></label>
        <?php /* Der Feldname heisst "steuer" und wird NICHT uebersetzt.
                 Bis 1.2.12 stand hier name="s<?= spot_t('TEXT.TEUER') ?>" -
                 ein Rest des automatischen Uebersetzungslaufs, der das Wort
                 "teuer" INNERHALB von "steuer" durch einen Sprachschluessel
                 ersetzt hatte. Auf Deutsch ergab das zufaellig wieder
                 "steuer", auf Englisch "sexpensive": das Feld kam nie an,
                 und jedes Speichern schrieb still den Vorgabewert 2,05
                 statt des angezeigten Werts. Gemessen an 8.4 und 7.4.
                 Ein Feldname ist eine Schnittstelle, kein Anzeigetext. */ ?>
        <input data-role="none" type="text" name="steuer" value="<?= sp_e($sp_cfg['steuer']) ?>" placeholder="2.05">
        <div class="sm-small"><?php echo spot_t('TEXT.DE_2_05_AT_ELEKTRIZITTSABGABE_1_50'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.KONZESSIONSABGABE'); ?></label>
        <input data-role="none" type="text" name="konzession" value="<?= sp_e($sp_cfg['konzession']) ?>" placeholder="2.39">
        <div class="sm-small"><?php echo spot_t('TEXT.NACH_GEMEINDEGRE_BIS_25_000_EW'); ?> <b>1,32</b> <?php echo spot_t('TEXT.BIS_100_000'); ?> <b>1,59</b> <?php echo spot_t('TEXT.BIS_500_000'); ?> <b>1,99</b> <?php echo spot_t('TEXT.BER_500_000'); ?> <b>2,39</b>.</div>
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?php echo spot_t('TEXT.UMLAGEN'); ?></label>
        <input data-role="none" type="text" name="umlagen" value="<?= sp_e($sp_cfg['umlagen']) ?>" placeholder="2.945">
        <div class="sm-small"><?php echo spot_t('TEXT.2026_KWKG_0_446_OFFSHORE_NETZUMLAG'); ?> <b>2,945</b>.</div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.ANBIETER_AUFSCHLAG'); ?></label>
        <input data-role="none" type="text" name="aufschlag" value="<?= sp_e($sp_cfg['aufschlag']) ?>" placeholder="0.0">
        <div class="sm-small"><?php echo spot_t('TEXT.MARGE_ARBEITSPREIS_AUFSCHLAG_DES_D'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.UMSATZSTEUER'); ?></label>
        <input data-role="none" type="text" name="vat" id="vat" value="<?= sp_e($sp_cfg['vat']) ?>" placeholder="19">
        <div class="sm-small"><?php echo spot_t('TEXT.DEUTSCHLAND_19_OUML_STERREICH_20'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.GRUNDPREIS_EUR_MONAT'); ?></label>
        <input data-role="none" type="text" name="grundpreis" value="<?= sp_e($sp_cfg['grundpreis']) ?>" placeholder="5.27">
        <div class="sm-small"><?php echo spot_t('TEXT.NETZ_GRUNDPREIS_MESSSTELLENBETRIEB'); ?></div>
    </div>
</div>
<div class="sm-alert sm-ok" style="margin-top:10px;"><?php echo spot_t('TEXT.AKTUELLE_SUMME_DER_AUFSCHLGE'); ?> <b><?= sp_n($sp_addon, 3) ?> ct/kWh netto</b>
<?php echo spot_t('TEXT.BEI_EINEM_BRSENPREIS_VON_8_00_CT_E'); ?> <b><?= sp_n((8 + $sp_addon) * (1 + (float) $sp_cfg['vat'] / 100), 2) ?> ct/kWh</b> <?php echo spot_t('TEXT.ENDPREIS'); ?></div>

<h2><?php echo spot_t('TEXT.ZWEITER_PREISSATZ_STEUERBARE_VERBR'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="wp_enabled" <?= !empty($sp_cfg['wp_enabled']) ? 'checked' : '' ?>><?php echo spot_t('TEXT.ZWEITEN_PREISSATZ_BERECHNEN_UND_AU'); ?>
</label>
<div class="sm-small"><?php echo spot_t('TEXT.FR_WRMEPUMPE_ODER_WALLBOX_MIT'); ?> <b><?php echo spot_t('TEXT.EIGENEM_ZHLPUNKT'); ?></b> <?php echo spot_t('TEXT.NACH_14A_ENWG_MODUL_1_REDUZIERTES_'); ?><span class="sm-mono"><?php echo spot_t('TEXT.WPCUR'); ?></span>/<span class="sm-mono"><?php echo spot_t('TEXT.WPNEXT'); ?></span><?php echo spot_t('TEXT.IDEAL_UM_WRMEPUMPE_UND_HAUSHALT_GE'); ?></div>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label><?php echo spot_t('TEXT.BEZEICHNUNG'); ?></label>
        <input data-role="none" type="text" name="wp_name" value="<?= sp_e($sp_cfg['wp_name']) ?>" placeholder="<?= sp_e(spot_t('TEXT.WP_PLATZHALTER')) ?>">
    </div>
    <div>
        <label><?php echo spot_t('TEXT.NETZENTGELT_14A_CT_KWH'); ?></label>
        <input data-role="none" type="text" name="wp_netz" value="<?= sp_e($sp_cfg['wp_netz']) ?>" placeholder="3.43">
        <div class="sm-small"><?php echo spot_t('TEXT.BEISPIEL_NETZGEBIET_2026_STEUERBAR'); ?> <b>3,43</b> <?php echo spot_t('TEXT.SPEICHERHEIZUNG_1_71_ELEKTROMOBILI'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.KONZESSIONSABGABE_CT_KWH'); ?></label>
        <input data-role="none" type="text" name="wp_konzession" value="<?= sp_e($sp_cfg['wp_konzession']) ?>" placeholder="0.61">
        <div class="sm-small"><?php echo spot_t('TEXT.SCHWACHLAST_HCHSTBETRAG_NACH_2_ABS'); ?> <b>0,61</b>.</div>
    </div>
</div>

<h2><?php echo spot_t('TEXT.CO_8322_INTENSITT_DES_STROMMIXES'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="co2_enabled" <?= !empty($sp_cfg['co2_enabled']) ? 'checked' : '' ?>><?php echo spot_t('TEXT.CO_8322_WERTE_ABRUFEN_FRAUNHOFER_I'); ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label><?php echo spot_t('TEXT.SCHWELLE_SAUBER_G_CO_8322_KWH'); ?></label>
        <input data-role="none" type="text" name="co2_clean" value="<?= sp_e($sp_cfg['co2_clean']) ?>" placeholder="200">
        <div class="sm-small"><?php echo spot_t('TEXT.DARUNTER_MELDET_DAS_PLUGIN'); ?> <span class="sm-mono"><?php echo spot_t('TEXT.CO2CLEAN_1'); ?></span> <?php echo spot_t('TEXT.FR_JETZT_IST_OUML_KOSTROM_ZEIT_TYP'); ?></div>
    </div>
</div>

<h2><?php echo spot_t('TEXT.VERGLEICH_FESTER_TARIF_GEGEN_DYNAM'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo spot_t('TEXT.MEIN_FESTER_ARBEITSPREIS_CT_KWH_BR'); ?></label>
        <input data-role="none" type="text" name="fixed_price" value="<?= sp_e($sp_cfg['fixed_price']) ?>" placeholder="30.90">
        <div class="sm-small"><?php echo spot_t('TEXT.ARBEITSPREIS_DES_AKTUELLEN_TARIFS_'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.GRUNDPREIS_FESTER_TARIF_MONAT'); ?></label>
        <input data-role="none" type="text" name="fix_grund" value="<?= sp_e($sp_cfg['fix_grund']) ?>" placeholder="12.90">
        <div class="sm-small"><?php echo spot_t('TEXT.GRUNDGEBHR_DES_LIEFERVERTRAGS_MEIS'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.JAHRESVERBRAUCH_KWH'); ?></label>
        <input data-role="none" type="text" name="consumption" id="consumption" value="<?= (int) $sp_cfg['consumption'] ?>" placeholder="3500"<?= $sp_mon['use'] ? ' readonly style="background:#f0f0f0;"' : '' ?>>
        <div class="sm-small" id="consumption_hint"><?= $sp_mon['use']
            ? spot_t('TEXT.VERBRAUCH_AUS_MONATEN')
            : spot_t('TEXT.VERBRAUCH_JAHRESWERT') ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.TGLICH_VERSCHIEBBARE_MENGE_KWH'); ?></label>
        <input data-role="none" type="text" name="shift_kwh" value="<?= sp_e($sp_cfg['shift_kwh']) ?>" placeholder="3">
        <div class="sm-small"><?php echo spot_t('TEXT.WASCH_SPLMASCHINE_WARMWASSER_E_AUT'); ?></div>
    </div>
</div>

<div class="sm-small" style="margin-top:10px;"><b><?php echo spot_t('TEXT.BONI_UND_RABATTE_DES_FESTEN_TARIFS'); ?></b> <?php echo spot_t('TEXT.NUR_SO_LSST_SICH_DER_TATSCHLICH_GE'); ?></div>
<div class="sm-row">
    <div>
        <label><?php echo spot_t('TEXT.SOFORTBONUS_EINMALIG'); ?></label>
        <input data-role="none" type="text" name="fix_sofortbonus" value="<?= sp_e($sp_cfg['fix_sofortbonus']) ?>" placeholder="0">
        <div class="sm-small"><?php echo spot_t('TEXT.WIRD_MEIST_NACH_WENIGEN_WOCHEN_AUS'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.NEUKUNDENBONUS'); ?></label>
        <input data-role="none" type="text" name="fix_neubonus" value="<?= sp_e($sp_cfg['fix_neubonus']) ?>" placeholder="0">
        <div class="sm-small"><?php echo spot_t('TEXT.FESTER_BETRAG_NACH_DEM_ERSTEN_LIEF'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.ODER_NEUKUNDENBONUS'); ?></label>
        <input data-role="none" type="text" name="fix_neubonus_pct" value="<?= sp_e($sp_cfg['fix_neubonus_pct']) ?>" placeholder="0">
        <div class="sm-small"><?php echo spot_t('TEXT.PROZENT_VOM_JAHRESBETRAG_ARBEITSPR'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.ABSCHLAGSRABATT_AUF_RECHNUNGSBETRA'); ?></label>
        <input data-role="none" type="text" name="fix_rabatt" value="<?= sp_e($sp_cfg['fix_rabatt']) ?>" placeholder="0">
        <div class="sm-small"><?php echo spot_t('TEXT.LAUFENDER_RABATT_GILT'); ?> <b><?php echo spot_t('TEXT.DAUERHAFT'); ?></b> <?php echo spot_t('TEXT.NICHT_NUR_IM_ERSTEN_JAHR_Z_B_27'); ?></div>
    </div>
</div>

<div class="sm-small" style="margin-top:10px;"><b><?php echo spot_t('TEXT.NETZBEZUG_JE_MONAT_KWH'); ?></b> <?php echo spot_t('TEXT.OPTIONAL_ABER_DEUTLICH_GENAUER_MIT'); ?></div>
<div class="sm-months">
<?php $sp_mnames = array();
for ($sp_i = 1; $sp_i <= 12; $sp_i++) { $sp_mnames[] = spot_t('MONAT.M' . $sp_i); }
for ($sp_i = 0; $sp_i < 12; $sp_i++) { ?>
    <div>
        <label><?= $sp_mnames[$sp_i] ?></label>
        <input data-role="none" type="text" class="sm-mkwh" name="months[<?= $sp_i ?>]" value="<?= $sp_mon['kwh'][$sp_i] > 0 ? sp_e(rtrim(rtrim(number_format($sp_mon['kwh'][$sp_i], 1, '.', ''), '0'), '.')) : '' ?>" placeholder="<?php echo spot_t('TEXT.TEXT_8'); ?>" oninput="spSum()">
    </div>
<?php } ?>
</div>
<div class="sm-alert sm-ok" id="sp_msum" style="margin-top:6px;"><?php echo spot_t('TEXT.SUMME_DER_MONATSWERTE'); ?> <b><?= $sp_mon['use'] ? sp_n($sp_mon['summe'], 0) . ' kWh' : sp_e(spot_t('TEXT.MONATE_KEINE')) ?></b><?= $sp_mon['use'] ? ' &mdash; ' . sp_e(spot_t('TEXT.MONATE_ALS_JAHR')) : '' ?></div>
<div class="sm-small"><?php echo spot_t('TEXT.DER_MONATSVERGLEICH_WIRD'); ?> <b><?php echo spot_t('TEXT.LASTPROFIL_GEWICHTET'); ?></b> <?php echo spot_t('TEXT.GERECHNET_HAUSHALTS_PROFIL_EIN_EIN'); ?> <b>Test</b><?php echo spot_t('TEXT.IM_PROTOKOLL_UND_AM_MONATSERSTEN_A'); ?></div>

<h2><?php echo spot_t('TEXT.KOPPLUNG_MIT_DEM_MARSTEK_SPEICHER_'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="marstek_enabled" <?= !empty($sp_cfg['marstek_enabled']) ? 'checked' : '' ?>><?php echo spot_t('TEXT.SPEICHER_IN_DEN_GNSTIGSTEN_STUNDEN'); ?>
</label>
<div class="sm-alert sm-info" style="margin-top:6px;"><?php echo spot_t('TEXT.NUR_EINSCHALTEN_WENN_DIE_RANG_LOGI'); ?> <b><?php echo spot_t('TEXT.NICHT'); ?></b> <?php echo spot_t('TEXT.IN_LOXONE_GEBAUT_IST_SONST_BERSCHR'); ?> <span class="sm-mono"><?php echo spot_t('TEXT.RANK'); ?></span> <?php echo spot_t('TEXT.AUS_SCHRITT_2'); ?></div>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label><?php echo spot_t('TEXT.ENDPUNKT_DES_MARSTEK_PLUGINS'); ?></label>
        <input data-role="none" type="text" name="marstek_url" value="<?= sp_e($sp_cfg['marstek_url']) ?>" placeholder="<?= sp_e($sp_ownurl) ?>"<?= in_array('marstek_url', $sp_mt_mangel, true) ? ' aria-invalid="true" style="border:2px solid #c62828;"' : '' ?>>
        <div class="sm-small"><?php echo spot_t('TEXT.LEER_LASSEN_AUTOMATISCH'); ?> <span class="sm-mono"><?= sp_e($sp_ownurl) ?></span> <?php echo spot_t('TEXT.EIGENE_LOXBERRY_ADRESSE'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.IN_DEN_X_GNSTIGSTEN_STUNDEN_LADEN'); ?></label>
        <input data-role="none" type="number" name="marstek_hours" value="<?= (int) $sp_cfg['marstek_hours'] ?>" min="1" max="12">
    </div>
    <div>
        <label><?php echo spot_t('TEXT.LADELEISTUNG_W'); ?></label>
        <input data-role="none" type="number" name="marstek_power" value="<?= (int) $sp_cfg['marstek_power'] ?>" min="100" max="10000">
    </div>
    <div>
        <label style="min-height:2.6em;display:flex;align-items:flex-end;"><?php echo spot_t('TEXT.TEXT_3'); ?></label>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
            <input data-role="none" type="checkbox" name="marstek_neg" <?= !empty($sp_cfg['marstek_neg']) ? 'checked' : '' ?>><?php echo spot_t('TEXT.BEI_NEGATIVEM_PREIS_IMMER_LADEN'); ?>
        </label>
    </div>
</div>

<?php $sp_mt_gesetzt = isset($sp_cfg['marstek_token']) && (string) $sp_cfg['marstek_token'] !== ''; ?>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label for="sp_marstek_token"><?= sp_e(spot_t('TEXT.MARSTEK_TOKEN_L')) ?></label>
        <input data-role="none" type="password" id="sp_marstek_token" name="marstek_token" value="" autocomplete="new-password" placeholder="<?= sp_e(spot_t($sp_mt_gesetzt ? 'TEXT.MARSTEK_TOKEN_GESETZT' : 'TEXT.MARSTEK_TOKEN_LEER')) ?>"<?= in_array('marstek_token', $sp_mt_mangel, true) ? ' aria-invalid="true" style="border:2px solid #c62828;"' : '' ?>>
        <div class="sm-small"><?= sp_e(spot_t('TEXT.MARSTEK_TOKEN_H')) ?></div>
    </div>
    <div>
        <label style="min-height:2.6em;display:flex;align-items:flex-end;">&nbsp;</label>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
            <input data-role="none" type="checkbox" name="marstek_token_weg" value="1" <?= !empty($sp_x2_mt_weg) ? 'checked' : '' ?>><?= sp_e(spot_t('TEXT.MARSTEK_TOKEN_WEG')) ?>
        </label>
    </div>
</div>
<div class="sm-small"><?= sp_e(spot_t('TEXT.MARSTEK_NUR_GUENSTIG')) ?></div>
<div class="sm-alert sm-warn" style="margin-top:6px;"><?= sp_e(spot_t('TEXT.MARSTEK_FREMDSCHREIBER')) ?></div>
<label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-weight:600;">
    <input data-role="none" type="checkbox" name="marstek_fremd_beanstanden" value="1" <?= !empty($sp_cfg['marstek_fremd_beanstanden']) ? 'checked' : '' ?>><?= sp_e(spot_t('TEXT.MARSTEK_FREMD_L')) ?>
</label>
<div class="sm-small"><?= sp_e(spot_t('TEXT.MARSTEK_FREMD_H')) ?></div>

<h2><?php echo spot_t('TEXT.SCHWELLEN_UND_FENSTER'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo spot_t('TEXT.SCHWELLE_GNSTIG_CT_KWH_ENDPREIS'); ?></label>
        <input data-role="none" type="text" name="cheap" value="<?= sp_e($sp_cfg['cheap']) ?>" placeholder="20">
        <div class="sm-small"><?php echo spot_t('TEXT.DARUNTER_MELDET_DAS_PLUGIN_NIVEAU_'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.SCHWELLE_TEUER_CT_KWH_ENDPREIS'); ?></label>
        <input data-role="none" type="text" name="expensive" value="<?= sp_e($sp_cfg['expensive']) ?>" placeholder="35">
        <div class="sm-small"><?php echo spot_t('TEXT.DARBER_NIVEAU_3_LEVEL_3_IDEAL_ZUM_'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.LNGE_DES_GNSTIGSTEN_FENSTERS_H'); ?></label>
        <input data-role="none" type="number" name="window" value="<?= (int) $sp_cfg['window'] ?>" min="1" max="12">
        <div class="sm-small"><?php echo spot_t('TEXT.Z_B_3_FR_WASCHMASCHINE_SPLMASCHINE'); ?></div>
    </div>
</div>

<h2><?php echo spot_t('PLAN.H_TITEL'); ?></h2>
<div class="sm-hinweis"><?php echo spot_t('PLAN.ERKLAERUNG'); ?></div>
<div class="sm-row">
  <div>
    <label><?php echo spot_t('PLAN.L_BUDGET_KW'); ?></label>
    <input data-role="none" type="text" name="budget_kw" value="<?= sp_e($sp_cfg['budget_kw']) ?>" placeholder="0">
    <div class="sm-small"><?php echo spot_t('PLAN.H_BUDGET_KW'); ?></div>
  </div>
  <div>
    <label><?php echo spot_t('PLAN.L_PV_BONUS'); ?></label>
    <input data-role="none" type="text" name="pv_bonus" value="<?= sp_e($sp_cfg['pv_bonus']) ?>" placeholder="0">
    <div class="sm-small"><?php echo spot_t('PLAN.H_PV_BONUS'); ?></div>
  </div>
  <div>
    <label><?php echo spot_t('PLAN.L_PV_SCHWELLE'); ?></label>
    <input data-role="none" type="number" name="pv_schwelle" value="<?= (int) $sp_cfg['pv_schwelle'] ?>" min="1" max="100000">
    <div class="sm-small"><?php echo spot_t('PLAN.H_PV_SCHWELLE'); ?></div>
  </div>
</div>
<div class="sm-row">
  <div>
    <label><?php echo spot_t('PLAN.L_BUDGET2_KW'); ?></label>
    <input data-role="none" type="text" name="budget2_kw" value="<?= sp_e($sp_cfg['budget2_kw']) ?>" placeholder="0">
    <div class="sm-small"><?php echo spot_t('PLAN.H_BUDGET2_KW'); ?></div>
  </div>
  <div>
    <label><?php echo spot_t('PLAN.L_BUDGET2_VON'); ?></label>
    <input data-role="none" type="number" name="budget2_von" value="<?= (int) $sp_cfg['budget2_von'] ?>" min="0" max="23">
  </div>
  <div>
    <label><?php echo spot_t('PLAN.L_BUDGET2_BIS'); ?></label>
    <input data-role="none" type="number" name="budget2_bis" value="<?= (int) $sp_cfg['budget2_bis'] ?>" min="0" max="23">
    <div class="sm-small"><?php echo spot_t('PLAN.H_BUDGET2_ZEIT'); ?></div>
  </div>
</div>
<label style="display:inline-flex;align-items:center;gap:8px;margin-top:8px;font-weight:600;">
  <input data-role="none" type="checkbox" name="hysterese" <?= !empty($sp_cfg['hysterese']) ? 'checked' : '' ?>>
  <?php echo spot_t('PLAN.L_HYSTERESE'); ?>
</label>
<div class="sm-small"><?php echo spot_t('PLAN.H_HYSTERESE'); ?></div>
<div class="sm-row">
  <div>
    <label><?php echo spot_t('PLAN.L_PV_QUELLE'); ?></label>
    <select data-role="none" name="pv_quelle">
<?php foreach (array('', 'forecast_solar', 'objekt', 'liste') as $sp_q2) { ?>
      <option value="<?= sp_e($sp_q2) ?>"<?= $sp_cfg['pv_quelle'] === $sp_q2 ? ' selected' : '' ?>><?= sp_e(spot_t('PLAN.QUELLE_' . ($sp_q2 === '' ? 'AUS' : strtoupper($sp_q2)))) ?></option>
<?php } ?>
    </select>
  </div>
  <div>
    <label><?php echo spot_t('PLAN.L_PV_URL'); ?></label>
    <input data-role="none" type="text" name="pv_url" value="<?= sp_e($sp_cfg['pv_url']) ?>" placeholder="https://api.forecast.solar/estimate/...">
    <div class="sm-small"><?php echo spot_t('PLAN.H_PV_URL'); ?></div>
  </div>
  <div>
    <label><?php echo spot_t('PLAN.L_PV_EINHEIT'); ?></label>
    <select data-role="none" name="pv_einheit">
<?php foreach (array('wh', 'w', 'kw') as $sp_e3) { ?>
      <option value="<?= $sp_e3 ?>"<?= $sp_cfg['pv_einheit'] === $sp_e3 ? ' selected' : '' ?>><?= sp_e(spot_t('PLAN.EINHEIT_' . strtoupper($sp_e3))) ?></option>
<?php } ?>
    </select>
    <div class="sm-small"><?php echo spot_t('PLAN.H_PV_EINHEIT'); ?></div>
  </div>
</div>
<div class="sm-row">
  <div>
    <label><?php echo spot_t('PLAN.L_PV_PFAD'); ?></label>
    <input data-role="none" type="text" name="pv_pfad" value="<?= sp_e($sp_cfg['pv_pfad']) ?>" placeholder="forecasts">
    <div class="sm-small"><?php echo spot_t('PLAN.H_PV_PFAD'); ?></div>
  </div>
  <div>
    <label><?php echo spot_t('PLAN.L_PV_ZEITFELD'); ?></label>
    <input data-role="none" type="text" name="pv_zeitfeld" value="<?= sp_e($sp_cfg['pv_zeitfeld']) ?>" placeholder="period_end">
  </div>
  <div>
    <label><?php echo spot_t('PLAN.L_PV_WERTFELD'); ?></label>
    <input data-role="none" type="text" name="pv_wertfeld" value="<?= sp_e($sp_cfg['pv_wertfeld']) ?>" placeholder="pv_estimate">
    <div class="sm-small"><?php echo spot_t('PLAN.H_PV_FELDER'); ?></div>
  </div>
</div>
<div class="sm-row">
  <div>
    <label><?php echo spot_t('PLAN.L_SOC_URL'); ?></label>
    <input data-role="none" type="text" name="soc_url" value="<?= sp_e($sp_cfg['soc_url']) ?>" placeholder="http://loxberry/plugins/...">
    <div class="sm-small"><?php echo spot_t('PLAN.H_SOC_URL'); ?></div>
  </div>
  <div>
    <label><?php echo spot_t('PLAN.L_SOC_PFAD'); ?></label>
    <input data-role="none" type="text" name="soc_pfad" value="<?= sp_e($sp_cfg['soc_pfad']) ?>" placeholder="geraete.1.soc">
    <div class="sm-small"><?php echo spot_t('PLAN.H_SOC_PFAD'); ?></div>
  </div>
</div>

<h2><?php echo spot_t('LAST.H_TITEL'); ?></h2>
<div class="sm-hinweis"><?php echo spot_t('LAST.ERKLAERUNG'); ?></div>
<div class="sm-row">
  <div>
    <label><?php echo spot_t('LAST.L_QUELLE'); ?></label>
    <select data-role="none" name="last_quelle">
<?php foreach (array('', 'objekt', 'liste') as $sp_lq2) { ?>
      <option value="<?= sp_e($sp_lq2) ?>"<?= (string) $sp_cfg['last_quelle'] === $sp_lq2 ? ' selected' : '' ?>><?= sp_e(spot_t('LAST.QUELLE_' . ($sp_lq2 === '' ? 'AUS' : strtoupper($sp_lq2)))) ?></option>
<?php } ?>
    </select>
    <div class="sm-small"><?php echo spot_t('LAST.H_QUELLE'); ?></div>
  </div>
  <div>
    <label><?php echo spot_t('LAST.L_URL'); ?></label>
    <input data-role="none" type="text" name="last_url" value="<?= sp_e($sp_cfg['last_url']) ?>" placeholder="http://loxberry/plugins/smartmeter/...">
    <div class="sm-small"><?php echo spot_t('LAST.H_URL'); ?></div>
  </div>
  <div>
    <label><?php echo spot_t('LAST.L_EINHEIT'); ?></label>
    <select data-role="none" name="last_einheit">
<?php foreach (array('kwh', 'wh', 'w', 'kw') as $sp_le2) { ?>
      <option value="<?= $sp_le2 ?>"<?= (string) $sp_cfg['last_einheit'] === $sp_le2 ? ' selected' : '' ?>><?= sp_e(spot_t('LAST.EINHEIT_' . strtoupper($sp_le2))) ?></option>
<?php } ?>
    </select>
  </div>
</div>
<div class="sm-row">
  <div>
    <label><?php echo spot_t('LAST.L_PFAD'); ?></label>
    <input data-role="none" type="text" name="last_pfad" value="<?= sp_e($sp_cfg['last_pfad']) ?>" placeholder="stunden">
    <div class="sm-small"><?php echo spot_t('LAST.H_PFAD'); ?></div>
  </div>
  <div>
    <label><?php echo spot_t('LAST.L_ZEITFELD'); ?></label>
    <input data-role="none" type="text" name="last_zeitfeld" value="<?= sp_e($sp_cfg['last_zeitfeld']) ?>" placeholder="zeit">
  </div>
  <div>
    <label><?php echo spot_t('LAST.L_WERTFELD'); ?></label>
    <input data-role="none" type="text" name="last_wertfeld" value="<?= sp_e($sp_cfg['last_wertfeld']) ?>" placeholder="kwh">
    <div class="sm-small"><?php echo spot_t('LAST.H_FELDER'); ?></div>
  </div>
</div>
<?php
/* Was zuletzt wirklich angekommen ist - und der Grund, wenn nichts kam.
 * Eine Einstellung, die man nicht nachsehen kann, ist eine Behauptung. */
if ($sp_cfg['last_quelle'] !== '' && trim((string) $sp_cfg['last_url']) !== '') {
    $sp_lg = spot_lastgang();
    $sp_lg_n = count($sp_lg['werte']);
?>
<div class="sm-alert <?= $sp_lg['meldung'] !== '' ? 'sm-err' : ($sp_lg_n > 0 ? 'sm-ok' : 'sm-warn') ?>">
<?php if ($sp_lg['meldung'] !== '') { ?>
  <?= sp_e(spot_t('PLANMELD.' . $sp_lg['meldung'])) ?>
<?php } else { ?>
  <?= sprintf(sp_e(spot_t('LAST.STAND')), (int) $sp_lg_n,
        $sp_lg['ts'] > 0 ? date('H:i', (int) $sp_lg['ts']) : '&ndash;') ?>
<?php } ?>
</div>
<?php } ?>
<?php
$sp_umw = spot_umwelt();
if ($sp_cfg['pv_quelle'] !== '' || $sp_cfg['soc_url'] !== '') { ?>
<div class="sm-alert <?= (!empty($sp_umw['pv_meldung']) || !empty($sp_umw['soc_meldung'])) ? 'sm-err' : 'sm-info' ?>">
  <?php /* OHNE sp_e(): der Wert traegt <b>-Auszeichnung, und beide
         eingesetzten Werte sind Zahlen aus sp_n() oder ein fester
         Gedankenstrich - da kommt nichts vom Anwender her. Bis 1.2.18
         stand hier sp_e(), und der Anwender las woertlich <b>3,5 kWh</b>. */ ?>
  <?= sprintf(spot_t('PLAN.STAND'),
      $sp_umw['pv_summe'] === null ? '&ndash;' : sp_n($sp_umw['pv_summe'], 1),
      $sp_umw['soc'] === null ? '&ndash;' : sp_n($sp_umw['soc'], 0)) ?>
<?php if (!empty($sp_umw['pv_meldung'])) { ?>
  <br>PV: <?= sp_e(spot_t('PLANMELD.' . $sp_umw['pv_meldung'])) ?>
<?php } ?>
<?php if (!empty($sp_umw['soc_meldung'])) { ?>
  <br><?php echo spot_t('PLAN.SPEICHER'); ?>: <?= sp_e(spot_t('PLANMELD.' . $sp_umw['soc_meldung'])) ?>
<?php } ?>
</div>
<?php } ?>

<h2><?php echo spot_t('REGEL.H_TITEL'); ?></h2>
<div class="sm-hinweis"><?php echo spot_t('REGEL.ERKLAERUNG'); ?></div>
<?php for ($sp_i = 0; $sp_i < SPOT_REGELN; $sp_i++) {
    $sp_r = $sp_cfg['regeln'][$sp_i]; ?>
<div class="sm-step">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:600;">
    <input data-role="none" type="checkbox" name="r_aktiv[<?= $sp_i ?>]" value="1" <?= !empty($sp_r['aktiv']) ? 'checked' : '' ?>>
    <?= sprintf(spot_t('REGEL.L_AKTIV'), $sp_i + 1) ?>
  </label>
  <div class="sm-row" style="margin-top:8px;">
    <div>
      <label><?php echo spot_t('REGEL.L_NAME'); ?></label>
      <input data-role="none" type="text" name="r_name[<?= $sp_i ?>]" value="<?= sp_e($sp_r['name']) ?>" placeholder="<?php echo spot_t('REGEL.P_NAME'); ?>">
    </div>
    <div>
      <label><?php echo spot_t('REGEL.L_ART'); ?></label>
      <select data-role="none" name="r_art[<?= $sp_i ?>]">
<?php foreach (array('fenster', 'stunden', 'schwelle', 'mittel') as $sp_a) { ?>
        <option value="<?= $sp_a ?>"<?= $sp_r['art'] === $sp_a ? ' selected' : '' ?>><?= sp_e(spot_t('REGEL.ART_' . strtoupper($sp_a))) ?></option>
<?php } ?>
      </select>
    </div>
  </div>
  <div class="sm-row">
    <div>
      <label><?php echo spot_t('REGEL.L_N'); ?></label>
      <input data-role="none" type="number" name="r_n[<?= $sp_i ?>]" value="<?= (int) $sp_r['n'] ?>" min="1" max="12">
    </div>
    <div>
      <label><?php echo spot_t('REGEL.L_SCHWELLE'); ?></label>
      <input data-role="none" type="text" name="r_schwelle[<?= $sp_i ?>]" value="<?= sp_e($sp_r['schwelle']) ?>">
    </div>
    <div>
      <label><?php echo spot_t('REGEL.L_PROZENT'); ?></label>
      <input data-role="none" type="number" name="r_prozent[<?= $sp_i ?>]" value="<?= (int) $sp_r['prozent'] ?>" min="0" max="90">
    </div>
  </div>
  <div class="sm-row">
    <div>
      <label><?php echo spot_t('REGEL.L_VON'); ?></label>
      <input data-role="none" type="number" name="r_von[<?= $sp_i ?>]" value="<?= (int) $sp_r['von'] ?>" min="0" max="23">
    </div>
    <div>
      <label><?php echo spot_t('REGEL.L_BIS'); ?></label>
      <input data-role="none" type="number" name="r_bis[<?= $sp_i ?>]" value="<?= (int) $sp_r['bis'] ?>" min="0" max="23">
    </div>
    <div>
      <label><?php echo spot_t('REGEL.L_HORIZONT'); ?></label>
      <input data-role="none" type="number" name="r_horizont[<?= $sp_i ?>]" value="<?= (int) $sp_r['horizont'] ?>" min="1" max="48">
    </div>
    <div>
      <label><?php echo spot_t('REGEL.L_FRIST'); ?></label>
      <select data-role="none" name="r_frist[<?= $sp_i ?>]">
        <option value="-1"<?= (int) $sp_r['frist'] < 0 ? ' selected' : '' ?>><?php echo spot_t('REGEL.FRIST_KEINE'); ?></option>
<?php for ($sp_h = 0; $sp_h < 24; $sp_h++) { ?>
        <option value="<?= $sp_h ?>"<?= (int) $sp_r['frist'] === $sp_h ? ' selected' : '' ?>><?= sprintf('%02d:00', $sp_h) ?></option>
<?php } ?>
      </select>
      <div class="sm-small"><?php echo spot_t('REGEL.H_FRIST'); ?></div>
    </div>
  </div>
  <div class="sm-row">
    <div>
      <label><?php echo spot_t('REGEL.L_RANG'); ?></label>
      <input data-role="none" type="number" name="r_rang[<?= $sp_i ?>]" value="<?= (int) $sp_r['rang'] ?>" min="1" max="99">
      <div class="sm-small"><?php echo spot_t('REGEL.H_RANG'); ?></div>
    </div>
    <div>
      <label><?php echo spot_t('REGEL.L_LEISTUNG'); ?></label>
      <input data-role="none" type="text" name="r_leistung[<?= $sp_i ?>]" value="<?= sp_e($sp_r['leistung']) ?>" placeholder="0">
      <div class="sm-small"><?php echo spot_t('REGEL.H_LEISTUNG'); ?></div>
    </div>
    <div>
      <label><?php echo spot_t('REGEL.L_ENERGIE'); ?></label>
      <input data-role="none" type="text" name="r_energie[<?= $sp_i ?>]" value="<?= sp_e($sp_r['energie']) ?>" placeholder="0">
      <div class="sm-small"><?php echo spot_t('REGEL.H_ENERGIE'); ?></div>
    </div>
  </div>
  <div class="sm-row">
    <div>
      <label><?php echo spot_t('REGEL.L_PV_SPERRE'); ?></label>
      <input data-role="none" type="text" name="r_pv_sperre[<?= $sp_i ?>]" value="<?= sp_e($sp_r['pv_sperre']) ?>" placeholder="0">
      <div class="sm-small"><?php echo spot_t('REGEL.H_PV_SPERRE'); ?></div>
    </div>
    <div>
      <label><?php echo spot_t('REGEL.L_SOC_MIN'); ?></label>
      <input data-role="none" type="number" name="r_soc_min[<?= $sp_i ?>]" value="<?= (int) $sp_r['soc_min'] ?>" min="0" max="100">
    </div>
    <div>
      <label><?php echo spot_t('REGEL.L_SOC_MAX'); ?></label>
      <input data-role="none" type="number" name="r_soc_max[<?= $sp_i ?>]" value="<?= (int) $sp_r['soc_max'] ?>" min="0" max="100">
      <div class="sm-small"><?php echo spot_t('REGEL.H_SOC'); ?></div>
    </div>
  </div>
  <div class="sm-row">
    <div>
      <label><?php echo spot_t('REGEL.L_MIN_LAUF'); ?></label>
      <input data-role="none" type="number" name="r_min_lauf[<?= $sp_i ?>]" value="<?= (int) $sp_r['min_lauf'] ?>" min="0" max="720">
      <div class="sm-small"><?php echo spot_t('REGEL.H_MIN_LAUF'); ?></div>
    </div>
    <div>
      <label><?php echo spot_t('REGEL.L_MIN_PAUSE'); ?></label>
      <input data-role="none" type="number" name="r_min_pause[<?= $sp_i ?>]" value="<?= (int) $sp_r['min_pause'] ?>" min="0" max="720">
      <div class="sm-small"><?php echo spot_t('REGEL.H_MIN_PAUSE'); ?></div>
    </div>
    <div></div>
  </div>
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="r_neg[<?= $sp_i ?>]" value="1" <?= !empty($sp_r['neg']) ? 'checked' : '' ?>>
    <?php echo spot_t('REGEL.L_NEG'); ?>
  </label>
  <div class="sm-small"><?= sprintf(spot_t('REGEL.H_AUSGANG'), $sp_i + 1, $sp_i + 1, $sp_i + 1, $sp_i + 1) ?></div>
</div>
<?php } ?>
<div class="sm-feld">
  <label for="profil_ein"><?php echo spot_t('REGEL.L_PROFIL'); ?></label>
  <select data-role="none" id="profil_ein" name="profil_ein">
<?php foreach (array('aus', 'absolut', 'relativ', 'beides') as $sp_pv) { ?>
    <option value="<?= $sp_pv ?>"<?= (string) $sp_cfg['profil_ein'] === $sp_pv ? ' selected' : '' ?>><?= sp_e(spot_t('REGEL.PROFIL_' . strtoupper($sp_pv))) ?></option>
<?php } ?>
  </select>
  <div class="sm-small"><?php echo spot_t('REGEL.H_PROFIL'); ?></div>
</div>

<h2><?php echo spot_t('TEXT.ANSAGE_UND_PUSH_JE_STUNDE'); ?></h2>
<div style="margin-bottom:6px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($sp_notify['audio']) ? 'checked' : '' ?>><?php echo spot_t('TEXT.AUDIOAUSGABE_AKTIV'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($sp_notify['push']) ? 'checked' : '' ?>><?php echo spot_t('TEXT.PUSH_NACHRICHT_AKTIV'); ?>
    </label>
    <div class="sm-small"><?php echo spot_t('TEXT.BEIDES_AN_ANSAGE_PUSH_NUR_EINES_AN'); ?> <span class="sm-mono"><?php echo spot_t('TEXT.ANN_1'); ?></span> <?php echo spot_t('TEXT.ANLEITUNG_SCHRITT_4'); ?></div>
</div>
<div class="sm-small"><b><?php echo spot_t('TEXT.STUNDEN_AUSWHLEN'); ?></b><?php echo spot_t('TEXT.ZU_DENEN_DIE_PREISANSAGE_KOMMEN_SO'); ?></div>
<div class="sm-hours">
<?php for ($sp_h = 0; $sp_h < 24; $sp_h++) { ?>
    <label><input data-role="none" type="checkbox" name="hours[]" value="<?= $sp_h ?>" <?= in_array($sp_h, $sp_hoursel, true) ? 'checked' : '' ?>> <?= sprintf('%02d', $sp_h) ?> <?php echo spot_t('TEXT.UHR_3'); ?></label>
<?php } ?>
</div>
<div class="sm-knopfreihe">
    <button data-role="none" type="button" class="sm-btn sm-b-technik" onclick="spHours(1)"><?php echo spot_t('TEXT.ALLE'); ?></button>
    <button data-role="none" type="button" class="sm-btn sm-b-technik" onclick="spHours(0)"><?php echo spot_t('TEXT.KEINE'); ?></button>
    <button data-role="none" type="button" class="sm-btn sm-b-technik" onclick="spHours(2)"><?php echo spot_t('TEXT.NUR_TAGSBER_721'); ?></button>
</div>
<div style="margin-top:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="only_cheap" <?= !empty($sp_notify['only_cheap']) ? 'checked' : '' ?>><?php echo spot_t('TEXT.NUR_MELDEN_WENN_DER_PREIS_UNTER_DE'); ?>
    </label><br>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="neg_always" <?= !empty($sp_notify['negative']) ? 'checked' : '' ?>><?php echo spot_t('TEXT.BEI_NEGATIVEM_BRSENPREIS_IMMER_MEL'); ?>
    </label><br>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_tomorrow" <?= !empty($sp_notify['tomorrow']) ? 'checked' : '' ?>><?php echo spot_t('TEXT.EINMAL_TGLICH_MELDEN_SOBALD_DIE_PR'); ?>
    </label>
</div>

<h2><?php echo spot_t('TEXT.SPRACHAUSGABE'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo spot_t('TEXT.AUDIO_AUSGABE'); ?></label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="spTtsMode()">
            <option value="musicserver"<?= $sp_tts['mode'] === 'musicserver' ? ' selected' : '' ?>><?php echo spot_t('TEXT.LOXONE_MUSIC_SERVER_KLASSISCH'); ?></option>
            <option value="ms4h"<?= $sp_tts['mode'] === 'ms4h' ? ' selected' : '' ?>><?php echo spot_t('TEXT.AUDIOSERVER4HOME_MUSICSERVER4HOME'); ?></option>
            <option value="audioserver"<?= $sp_tts['mode'] === 'audioserver' ? ' selected' : '' ?>><?php echo spot_t('TEXT.ORIGINAL_LOXONE_AUDIOSERVER_VIA_LO'); ?></option>
            <option value="custom"<?= $sp_tts['mode'] === 'custom' ? ' selected' : '' ?>><?php echo spot_t('TEXT.EIGENE_URL_VORLAGE'); ?></option>
            <option value="alexang"<?= $sp_tts['mode'] === 'alexang' ? ' selected' : '' ?>><?php echo spot_t('TEXT.TTS_ALEXANG'); ?></option>
            <option value="cc4lox"<?= $sp_tts['mode'] === 'cc4lox' ? ' selected' : '' ?>><?php echo spot_t('TEXT.TTS_CC4LOX'); ?></option>
        </select>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.IP_DES_AUDIO_SERVERS'); ?></label>
        <input data-role="none" type="text" name="tts_ip" value="<?= sp_e($sp_tts['ip']) ?>" placeholder="<?= sp_e(spot_t('TEXT.IP_PLATZHALTER')) ?>">
    </div>
    <div>
        <label><?php echo spot_t('TEXT.PORT'); ?></label>
        <input data-role="none" type="number" name="tts_port" value="<?= (int) $sp_tts['port'] ?>" min="1" max="65535">
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?php echo spot_t('TEXT.ZONEN'); ?></label>
        <input data-role="none" type="text" name="tts_zones" value="<?= sp_e($sp_tts['zones']) ?>" placeholder="<?= sp_e(spot_t('TEXT.ZONEN_PLATZHALTER')) ?>">
        <div class="sm-small"><?php echo spot_t('TEXT.ZONENNUMMERN_MIT_KOMMA_Z_B'); ?> <span class="sm-mono">2,4,6</span><?php echo spot_t('TEXT.DIE_LAUTSTRKE_KOMMT_AUS_DEM_FELD_D'); ?> <span class="sm-mono"><?php echo spot_t('TEXT.ZONE_LAUTSTRKE'); ?></span> <?php echo spot_t('TEXT.Z_B'); ?> <span class="sm-mono">2~25,4~40</span><?php echo spot_t('TEXT.LEERZEICHEN_NACH_DEM_KOMMA_SIND_ER'); ?> <span class="sm-mono">2,4,6</span> <?php echo spot_t('TEXT.UND'); ?> <span class="sm-mono">2, 4, 6</span> <?php echo spot_t('TEXT.FUNKTIONIEREN_BEIDE'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.LAUTSTRKE'); ?></label>
        <input data-role="none" type="number" name="tts_volume" value="<?= (int) $sp_tts['volume'] ?>" min="1" max="100">
    </div>
    <div>
        <label><?php echo spot_t('TEXT.SPRACHE'); ?></label>
        <input data-role="none" type="text" name="tts_lang" value="<?= sp_e($sp_tts['lang']) ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label><?php echo spot_t('TEXT.URL_VORLAGE_FR_AUDIOSERVER4HOME_MS'); ?></label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="<?php echo spot_t('TEXT.HTTP'); ?>{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= sp_e($sp_tts['template']) ?></textarea>
    <div class="sm-small"><?php echo spot_t('TEXT.PLATZHALTER'); ?> <span class="sm-mono"><?php echo spot_t('TEXT.IP_PORT_ZONES_VOL_LANG_TEXT'); ?></span><?php echo spot_t('TEXT.LEER_STANDARD_VORLAGE'); ?></div>
</div>
<div id="tts_audioserver_hint" class="sm-alert sm-info" style="display:none;">
    <?php echo spot_t('TEXT.DER_ORIGINALE_LOXONE_AUDIOSERVER_B'); ?> <b><?php echo spot_t('TEXT.KEINE_HTTP_TTS_SCHNITTSTELLE'); ?></b><?php echo spot_t('TEXT.IN_DIESEM_MODUS_SPRICHT_DAS_PLUGIN'); ?>
    <span class="sm-mono">ANN=1</span> (<?php echo spot_t('TEXT.ANLEITUNG_SCHRITT4'); ?>).
</div>
<?php /* S1: Alexa-NG und Google-Lautsprecher (Chromecast 4 Lox NG). Das Sprechtoken
         ist ein Kennwortfeld: leer lassen behaelt es, der Haken loescht es, es
         steht nie im Formular, in der Einmalmeldung oder in der Sicherung. */ ?>
<div id="tts_alexa_row" style="<?= $sp_tts['mode'] === 'alexang' ? '' : 'display:none;' ?>">
<div class="sm-row">
    <div>
        <label for="sp_tts_alexa_token"><?= sp_e(spot_t('TEXT.SPRECH_ALEXA_TOKEN_L')) ?></label>
        <input data-role="none" type="password" id="sp_tts_alexa_token" name="tts_alexa_token" value="" autocomplete="new-password" placeholder="<?= sp_e((string) $sp_tts['alexa_token'] !== '' ? sprintf(spot_t('TEXT.SPRECH_TOKEN_GESETZT'), strlen((string) $sp_tts['alexa_token'])) : spot_t('TEXT.SPRECH_TOKEN_LEER')) ?>"<?= in_array('tts_alexa_token', $sp_feldfehler, true) ? ' aria-invalid="true" style="border:2px solid #c62828;"' : '' ?>>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;"><input data-role="none" type="checkbox" name="tts_alexa_token_weg" value="1"><?= sp_e(spot_t('TEXT.SPRECH_TOKEN_WEG')) ?></label>
    </div>
    <div>
        <label for="sp_tts_alexa_geraet"><?= sp_e(spot_t('TEXT.SPRECH_GERAET_L')) ?></label>
        <input data-role="none" type="text" id="sp_tts_alexa_geraet" name="tts_alexa_geraet" value="<?= sp_e($sp_tts['alexa_geraet']) ?>" placeholder="<?= sp_e(spot_t('TEXT.SPRECH_GERAET_P')) ?>">
    </div>
</div>
<div class="sm-small"><?php echo spot_t('TEXT.SPRECH_ALEXA_H'); ?></div>
</div>
<div id="tts_google_row" style="<?= $sp_tts['mode'] === 'cc4lox' ? '' : 'display:none;' ?>">
<div class="sm-row">
    <div>
        <label for="sp_tts_google_token"><?= sp_e(spot_t('TEXT.SPRECH_GOOGLE_TOKEN_L')) ?></label>
        <input data-role="none" type="password" id="sp_tts_google_token" name="tts_google_token" value="" autocomplete="new-password" placeholder="<?= sp_e((string) $sp_tts['google_token'] !== '' ? sprintf(spot_t('TEXT.SPRECH_TOKEN_GESETZT'), strlen((string) $sp_tts['google_token'])) : spot_t('TEXT.SPRECH_TOKEN_LEER')) ?>"<?= in_array('tts_google_token', $sp_feldfehler, true) ? ' aria-invalid="true" style="border:2px solid #c62828;"' : '' ?>>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;"><input data-role="none" type="checkbox" name="tts_google_token_weg" value="1"><?= sp_e(spot_t('TEXT.SPRECH_TOKEN_WEG')) ?></label>
    </div>
    <div>
        <label for="sp_tts_google_geraet"><?= sp_e(spot_t('TEXT.SPRECH_GERAET_L')) ?></label>
        <input data-role="none" type="text" id="sp_tts_google_geraet" name="tts_google_geraet" value="<?= sp_e($sp_tts['google_geraet']) ?>" placeholder="<?= sp_e(spot_t('TEXT.SPRECH_GERAET_P')) ?>">
    </div>
    <div>
        <label for="sp_tts_google_laut"><?= sp_e(spot_t('TEXT.SPRECH_LAUT_L')) ?></label>
        <input data-role="none" type="text" id="sp_tts_google_laut" name="tts_google_laut" value="<?= (int) $sp_tts['google_laut'] >= 0 ? (int) $sp_tts['google_laut'] : '' ?>" placeholder="<?= sp_e(spot_t('TEXT.SPRECH_LAUT_P')) ?>">
    </div>
</div>
<div class="sm-small"><?php echo spot_t('TEXT.SPRECH_GOOGLE_H'); ?></div>
</div>

<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo spot_t('TEXT.SPEICHERN'); ?></button>
</form>
<?php echo spot_eingaben_einsetzen(ob_get_clean(), 'save', $sp_eingaben); ?>
<form action="index.php" method="post" style="margin-top:8px;">
    <input data-role="none" type="hidden" name="fetchnow" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <?php echo spot_fmt(); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" style="margin-top:0;"><?php echo spot_t('TEXT.JETZT_ABRUFEN'); ?></button>
</form>

<?php /* ===== Einstellungen sichern und zurueckspielen =====
         Bis 1.2.12 stand dieser Block AUSSERHALB jeder Reiterflaeche und
         war deshalb unter jedem Reiter sichtbar - auch unter Logdateien und
         dem Kostenvergleich. Gemessen an der gerenderten Seite: ueber dem
         Knopf standen nur sm-wrap und sm-knopfreihe, keine sm-pane.
         Er gehoert hierher, wo die Einstellungen stehen, die er sichert. */ ?>
<h2><?= spot_t('TEXT.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= spot_t('TEXT.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= spot_t('TEXT.SICH_WARNUNG') ?></div>
<?php
/* X-3 (Pruefbericht oberflaeche, Befund 11): was das Zurueckspielen abweisen
 * wuerde, steht schon am Knopf - nur die Namen. Dieselbe Pruefung wie in
 * spot_sicherung_schreiben() (dort als _warnung in der Datei). */
$sp_x3_daten = $sp_cfg;
unset($sp_x3_daten['token'], $sp_x3_daten['marstek_token'], $sp_x3_daten['tts']['alexa_token'], $sp_x3_daten['tts']['google_token']);
$sp_x3 = spot_konfig_mangel($sp_x3_daten);
if ((string) $sp_cfg['token'] !== '' && !spot_endpunkt_token_form_ok($sp_cfg['token'])) { array_unshift($sp_x3, 'token'); }
if ($sp_x3) { ?>
<div class="sm-alert sm-warn"><?= sp_e(sprintf(spot_t('TEXT.SICH_X3'), implode(', ', $sp_x3))) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <?php echo spot_fmt(); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="spot_sichern" value="1"><?= spot_t('TEXT.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <?php echo spot_fmt(); ?>
    <input data-role="none" type="file" name="spot_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="spot_zurueck" value="1"><?= spot_t('TEXT.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<!-- ================= Reiter: MQTT (eigener Reiter seit 1.2.5, Hausstandard) ================= -->
<div class="sm-seite<?php echo $sp_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" id="tab-mqtt">
<?php ob_start(); /* X-2 */ ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<?php echo spot_fmt(); ?>
<h2><?php echo spot_t('TEXT.MQTT_OPTIONAL'); ?></h2>
<?php
/* Autostart und Fassung kommen aus EINER Funktion in der Bibliothek.
 *
 * Bis 1.2.12 stand hier eine eigene, inline definierte spot_hs_autostart()
 * mit einem fest verdrahteten Rueckfall auf den Standard-Wurzelordner -
 * ein harter Systempfad in einer Oberflaeche, die den Wurzelordner ohnehin
 * kennt. (Der Pfad stand hier bis 1.2.19 woertlich. Er ist laengst
 * ausgebaut, aber harte_pfade.py meldete ihn bei jedem Lauf, und ein
 * Pruefer, der immer denselben Fehlalarm liefert, wird nicht mehr
 * gelesen.)
 * Und sie las nur den Autostart; die FASSUNG des Gateways, die darueber
 * entscheidet, was der Anwender ueberhaupt tun muss, hat sie ignoriert.
 * Beides liest spot_mqtt_gateway_info() aus demselben Block - also ohne
 * zweiten Dateizugriff. */
$sp_gw_mq = function_exists('spot_mqtt_gateway_info') ? spot_mqtt_gateway_info() : null;
if ($sp_gw_mq !== null && !$sp_gw_mq['autostart']) { ?>
<div class="sm-alert sm-warn"><b>MQTT:</b> <?php echo spot_t('TEXT.W_AUTOSTART'); ?></div>
<?php } ?>
<div class="sm-small"><?= sprintf(spot_t('LOX.ABO_FASSUNG'),
      ($sp_gw_mq !== null && (int) $sp_gw_mq['fassung'] > 0)
          ? (string) (int) $sp_gw_mq['fassung'] : spot_t('LOX.ABO_FASSUNG_UNBEKANNT')) ?>
<?php echo spot_t('LOX.ABO_VERWEIS'); ?></div>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($sp_cfg['mqtt_enabled']) ? 'checked' : '' ?>><?php echo spot_t('TEXT.PREISE_PER_MQTT_VERFFENTLICHEN'); ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label><?php echo spot_t('TEXT.TOPIC_PRFIX'); ?></label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= sp_e($sp_cfg['mqtt_topic']) ?>" placeholder="spot_awattar">
        <div class="sm-small"><?php echo spot_t('TEXT.NUTZT_DAS'); ?> <b><?php echo spot_t('TEXT.LOXBERRY_MQTT_GATEWAY'); ?></b><?php echo spot_t('TEXT.VERFFENTLICHT_BEI_AUML_NDERUNG_UND'); ?> <b><?php echo spot_t('TEXT.ALLES_WAS_AUCH_DER_HTTP_ENDPUNKT_L'); ?></b><?php echo spot_t('TEXT.SODASS_DIE_LOXONE_KONFIGURATION_GA'); ?> <?php echo spot_t('MQTT.LISTE_UNTEN'); ?><br>
        <b><?php echo spot_t('TEXT.PREISE'); ?></b> <span class="sm-mono"><?= sp_e($sp_cfg['mqtt_topic']) ?><?php echo spot_t('TEXT.CUR'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.CUR_BOERSE'); ?></span>,
        <span class="sm-mono"><?php echo spot_t('TEXT.NEXT'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.RANK_2'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.RANKD'); ?></span>,
        <span class="sm-mono"><?php echo spot_t('TEXT.LEVEL'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.NEG'); ?></span>, <span class="sm-mono">/ok</span><br>
        <b><?php echo spot_t('TEXT.HEUTE_UND_MORGEN'); ?></b> <span class="sm-mono"><?php echo spot_t('TEXT.AVG_HEUTE'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.MIN_HEUTE'); ?></span>,
        <span class="sm-mono"><?php echo spot_t('TEXT.MINH_HEUTE'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.MAX_HEUTE'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.MAXH_HEUTE'); ?></span><?php echo spot_t('TEXT.DIESELBEN_MIT'); ?> <span class="sm-mono"><?php echo spot_t('TEXT.MORGEN'); ?></span><?php echo spot_t('TEXT.DAZU'); ?> <span class="sm-mono"><?php echo spot_t('TEXT.MORGEN_OK'); ?></span><br>
        <b><?php echo spot_t('TEXT.GNSTIGSTES_FENSTER'); ?></b> <span class="sm-mono"><?php echo spot_t('TEXT.FENSTER_START'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.FENSTER_IN'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.FENSTER_CT'); ?></span><br>
        <b>CO<sub>2</sub>:</b> <span class="sm-mono">/co2</span>, <span class="sm-mono"><?php echo spot_t('TEXT.CO2_MIN'); ?></span>,
        <span class="sm-mono"><?php echo spot_t('TEXT.CO2_MINH'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.CO2_CLEAN'); ?></span><br>
        <b><?php echo spot_t('TEXT.MELDESTEUERUNG'); ?></b> <span class="sm-mono"><?php echo spot_t('TEXT.ANN'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.AUDIO'); ?></span>,
        <span class="sm-mono"><?php echo spot_t('TEXT.PUSH'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.PTEST'); ?></span><br>
        <b><?php echo spot_t('TEXT.KOSTENVERGLEICH'); ?></b> <span class="sm-mono"><?php echo spot_t('TEXT.FIX'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.DYN_MONAT'); ?></span>,
        <span class="sm-mono"><?php echo spot_t('TEXT.DIFF_MONAT'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.EURO_MONAT'); ?></span>,
        <span class="sm-mono"><?php echo spot_t('TEXT.SHIFT_JAHR'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.WP_CUR'); ?></span>, <span class="sm-mono"><?php echo spot_t('TEXT.WP_NEXT'); ?></span><br>
        <b><?php echo spot_t('LEBEN.T_TITEL'); ?></b> <span class="sm-mono">/status/ts</span>, <span class="sm-mono">/status/zaehler</span>, <span class="sm-mono">/status/ok</span><br>
        <?php echo spot_t('TEXT.DAS_MELDEFENSTER'); ?> <span class="sm-mono">/ann</span> <?php echo spot_t('TEXT.WIRD_SOFORT_VERFFENTLICHT_WENN_ES_'); ?></div>
<div class="sm-hinweis"><?php echo spot_t('LEBEN.MQTT_ERKLAERUNG'); ?></div>
    </div>
</div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo spot_t('LEGENDE.AKTION'); ?></span>
</div>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo spot_t('TEXT.SPEICHERN'); ?></button>
</form>
<?php echo spot_eingaben_einsetzen(ob_get_clean(), 'mqtt_save', $sp_eingaben); ?>
<?php /* O9 (Pruefbericht mqtt, B6): die Themenliste aus spot_mqtt_themenliste() -
         also aus derselben Quelle wie der Versand -, mit Spalte "retained"
         (Entscheidung Nr. 3) und der Bedeutung je Thema. Die Zeile
         PRUEF.MQTT_LISTE im Reiter Test haelt sie gegen die Sendemenge. */ ?>
<h3 class="sm-h3"><?php echo spot_t('MQTT.H_THEMEN'); ?></h3>
<div class="sm-hinweis"><?php echo spot_t('MQTT.OK_UNTERSCHIED'); ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?php echo spot_t('MQTT.T_THEMA'); ?></th><th><?php echo spot_t('MQTT.T_RETAINED'); ?></th><th><?php echo spot_t('MQTT.T_BEDEUTUNG'); ?></th></tr>
<?php $sp_mqpf = spot_mqtt_praefix($sp_cfg);
foreach (spot_mqtt_themenliste($sp_st ? $sp_st : null) as $sp_mt) { ?>
<tr><td><span class="sm-mono"><?= sp_e($sp_mqpf . '/' . $sp_mt[0]) ?></span></td><td><?= $sp_mt[1] ? spot_t('MQTT.JA') : spot_t('MQTT.NEIN') ?></td><td><?= sp_e(spot_t('MQTTTHEMA.' . $sp_mt[2])) ?></td></tr>
<?php } ?>
</table>
</div>
</div>

<div class="sm-seite<?php echo $sp_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">
<h2><?php echo spot_t('EM.H_TITEL'); ?></h2>
<div class="sm-hinweis"><?php echo spot_t('EM.EINLEITUNG'); ?></div>

<div class="sm-step"><b><?php echo spot_t('EM.H_SPOTOPT'); ?></b><br>
<?php echo spot_t('EM.SPOTOPT_TEXT'); ?>
<table class="sm-tbl">
<tr><th><?php echo spot_t('EM.T_VONHIER'); ?></th><th><?php echo spot_t('EM.T_ANSCHLUSS'); ?></th><th><?php echo spot_t('EM.T_BEDEUTUNG'); ?></th></tr>
<tr><td><span class="sm-mono">PH00 &hellip; PH23</span></td><td><span class="sm-mono">00:00 &hellip; 23:00</span></td><td><?php echo spot_t('EM.Z_ABSOLUT'); ?></td></tr>
<tr><td><span class="sm-mono">PR00 &hellip; PR23</span></td><td><span class="sm-mono">+0 &hellip; +23</span></td><td><?php echo spot_t('EM.Z_RELATIV'); ?></td></tr>
<tr><td><span class="sm-mono">&ndash;</span></td><td><span class="sm-mono">Tr</span></td><td><?php echo spot_t('EM.Z_TRIGGER'); ?></td></tr>
<tr><td><span class="sm-mono">&ndash;</span></td><td><span class="sm-mono">O</span></td><td><?php echo spot_t('EM.Z_O'); ?></td></tr>
</table>
<div class="sm-warnung"><?php echo spot_t('EM.SPOTOPT_WARNUNG'); ?></div>
</div>

<div class="sm-step"><b><?php echo spot_t('EM.H_EM'); ?></b><br>
<?php echo spot_t('EM.EM_TEXT'); ?>
<table class="sm-tbl">
<tr><th><?php echo spot_t('EM.T_VONHIER'); ?></th><th><?php echo spot_t('EM.T_ANSCHLUSS'); ?></th><th><?php echo spot_t('EM.T_BEDEUTUNG'); ?></th></tr>
<tr><td><span class="sm-mono">R1 &hellip; R4</span></td><td><span class="sm-mono">Prio</span></td><td><?php echo spot_t('EM.Z_PRIO'); ?></td></tr>
<tr><td><span class="sm-mono">R1 &hellip; R4</span></td><td><span class="sm-mono">O</span></td><td><?php echo spot_t('EM.Z_OFFSET'); ?></td></tr>
<tr><td><span class="sm-mono">R1 &hellip; R4</span></td><td><span class="sm-mono">MinSoc</span></td><td><?php echo spot_t('EM.Z_MINSOC'); ?></td></tr>
<tr><td><span class="sm-mono">R1 &hellip; R4</span></td><td><span class="sm-mono">Off</span></td><td><?php echo spot_t('EM.Z_OFF'); ?></td></tr>
</table>
<div class="sm-hinweis"><?php echo spot_t('EM.EM_HINWEIS'); ?></div>
</div>

<h2><?php echo spot_t('REGEL.H_VORLAGE'); ?></h2>
<div class="sm-hinweis"><?php echo spot_t('REGEL.H_VORLAGE_TEXT'); ?></div>
<form action="index.php" method="post" style="margin-bottom:14px;">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <?php echo spot_fmt(); ?>
  <input data-role="none" type="hidden" name="vorlage" value="1">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo spot_t('LEGENDE.TECHNIK'); ?></span>
</div>
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo spot_t('REGEL.K_VORLAGE'); ?></button>
</form>

<?php /* O5 (Pruefbericht oberflaeche, Befund 10): der Token-Block steht im
         Reiter Einbindung - dorthin verweisen die Texte des Endpunkts, und dorthin
         springen seine Knoepfe (activetab). Bis 1.2.31 stand er im Reiter Test. */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo spot_t('LEGENDE.AKTION'); ?></span>
</div>
<div class="sm-step">
<b><?php echo spot_t('TEXT.TOKEN_TITEL'); ?></b><br><br>
<?php echo spot_t('TEXT.TOKEN_ERKLAERUNG'); ?>
<pre class="sm-pre">http://<?= $sp_host ?>/plugins/<?= sp_e($sp_plugin) ?>/spot.php<?= sp_e($sp_token !== '' ? '?token=' . $sp_token : '') ?></pre>
<?php if ($sp_token === '') { ?>
<div class="sm-alert sm-warn"><?php echo spot_t('TEXT.TOKEN_OFFEN'); ?></div>
<form method="post" action="index.php" style="display:inline">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<?php echo spot_fmt(); ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?php echo spot_t('TEXT.TOKEN_SETZEN'); ?></button>
</form>
<?php } else { ?>
<div class="sm-alert sm-ok"><?php echo spot_t('TEXT.TOKEN_AKTIV'); ?></div>
<form method="post" action="index.php" style="display:inline">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<?php echo spot_fmt(); ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?php echo spot_t('TEXT.TOKEN_ERNEUERN'); ?></button>
<?php /* O8 (Pruefbericht oberflaeche, Befund 15): orange - der Knopf veraendert die
         Konfiguration und hebt den Schutz des Endpunkts auf. */ ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_weg" value="1"><?php echo spot_t('TEXT.TOKEN_ENTFERNEN'); ?></button>
</form>
<div class="sm-small"><?php echo spot_t('TEXT.TOKEN_ENTFERNEN_H'); ?></div>
<?php } ?>
</div>

<h2><?php echo spot_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC'); ?></h2>
<p><?php echo spot_t('TEXT.DER_MINISERVER_FRAGT_DAS_PLUGIN_AL'); ?> <b><?php echo spot_t('TEXT.ENDPREISE'); ?></b>
<?php echo spot_t('TEXT.INKL_NETZENTGELTE_ABGABEN_UND_UMSA'); ?> <b><?php echo spot_t('TEXT.ANSAGE'); ?></b> <?php echo spot_t('TEXT.SPRICHT_DAS_PLUGIN_SELBST_DEN'); ?> <b><?php echo spot_t('TEXT.PUSH_2'); ?></b> <?php echo spot_t('TEXT.VERSCHICKT_DER_MINISERVER'); ?></p>

<div class="sm-step"><b><?php echo spot_t('TEXT.SCHRITT_1_VIRTUELLER_HTTP_EINGANG_'); ?></b> <?php echo spot_t('TEXT.ABFRAGE_ALLE_300_S'); ?>
<table class="sm-tbl">
<tr><th><?php echo spot_t('TEXT.EIGENSCHAFT'); ?></th><th><?php echo spot_t('TEXT.WERT'); ?></th></tr>
<tr><td>URL</td><td><span class="sm-mono">http://<?= $sp_host ?><?php echo spot_t('TEXT.PLUGINS'); ?><?= sp_e($sp_plugin) ?><?php echo spot_t('TEXT.SPOT_PHP'); ?><?= sp_e($sp_tk) ?></span></td></tr>
<tr><td><?php echo spot_t('TEXT.ABFRAGEZYKLUS'); ?></td><td><?php echo spot_t('TEXT.300_SEKUNDEN'); ?></td></tr>
</table>
</div>

<div class="sm-step"><b><?php echo spot_t('TEXT.SCHRITT_2_BEFEHLSERKENNUNGEN'); ?></b> <?php echo spot_t('TEXT.JE_EIN_VIRTUELLER_HTTP_EINGANG_BEF'); ?>
<span class="sm-mono">\i...\i</span> <?php echo spot_t('TEXT.SUCHTEXT'); ?> <span class="sm-mono">\v</span> <?php echo spot_t('TEXT.ZAHL_DAHINTER'); ?>
<table class="sm-tbl">
<tr><th><?php echo spot_t('TEXT.BEFEHLSERKENNUNG'); ?></th><th><?php echo spot_t('TEXT.BEDEUTUNG'); ?></th><th><?php echo spot_t('TEXT.EINHEIT'); ?></th></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.ICUR_I_V'); ?></span></td><td><?php echo spot_t('TEXT.ENDPREIS_DER_AKTUELLEN_STUNDE'); ?></td><td>ct/kWh</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.INEXT_I_V'); ?></span></td><td><?php echo spot_t('TEXT.ENDPREIS_DER_NCHSTEN_STUNDE'); ?></td><td>ct/kWh</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.ICURB_I_V'); ?></span></td><td><?php echo spot_t('TEXT.REINER_BRSENANTEIL_DER_AKTUELLEN_S'); ?></td><td>ct/kWh</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.INEG_I_V'); ?></span></td><td><?php echo spot_t('TEXT.1_BRSENPREIS_NEGATIV'); ?></td><td><?php echo spot_t('TEXT.TEXT_4'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IRANK_I_V'); ?></span></td><td><?php echo spot_t('TEXT.RANG_DER_AKTUELLEN_STUNDE_IN_DEN_N'); ?></td><td>&mdash;</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IRANKD_I_V'); ?></span></td><td><?php echo spot_t('TEXT.RANG_ABSTEIGEND_1_TEUERSTE'); ?></td><td>&mdash;</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.ILEVEL_I_V'); ?></span></td><td><?php echo spot_t('TEXT.1_GNSTIG_2_NORMAL_3_TEUER_SCHWELLE'); ?></td><td>&mdash;</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IHMINP_I_V'); ?></span> / <span class="sm-mono"><?php echo spot_t('TEXT.IHMINH_I_V'); ?></span></td><td><?php echo spot_t('TEXT.GNSTIGSTER_PREIS_HEUTE_DESSEN_STUN'); ?></td><td><?php echo spot_t('TEXT.CT_KWH_023'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IHMAXP_I_V'); ?></span> / <span class="sm-mono"><?php echo spot_t('TEXT.IHMAXH_I_V'); ?></span></td><td><?php echo spot_t('TEXT.TEUERSTER_PREIS_HEUTE_DESSEN_STUND'); ?></td><td>ct/kWh, <?php echo spot_t('TEXT.023'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IHAVG_I_V'); ?></span></td><td><?php echo spot_t('TEXT.TAGESDURCHSCHNITT_HEUTE'); ?></td><td>ct/kWh</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IMINP_I_V'); ?></span> / <span class="sm-mono"><?php echo spot_t('TEXT.IMINH_I_V'); ?></span></td><td><?php echo spot_t('TEXT.GNSTIGSTER_PREIS_MORGEN_DESSEN_STU'); ?></td><td>ct/kWh, 0&ndash;23</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IMAXP_I_V'); ?></span> / <span class="sm-mono"><?php echo spot_t('TEXT.IMAXH_I_V'); ?></span></td><td><?php echo spot_t('TEXT.TEUERSTER_PREIS_MORGEN_DESSEN_STUN'); ?></td><td>ct/kWh, 0&ndash;23</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IAVG_I_V'); ?></span> / <span class="sm-mono"><?php echo spot_t('TEXT.IOK_I_V'); ?></span></td><td><?php echo spot_t('TEXT.DURCHSCHNITT_MORGEN_1_PREISE_FR_MO'); ?></td><td>&mdash;</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IWINH_I_V'); ?></span></td><td><?php echo spot_t('TEXT.STARTSTUNDE_DES_GNSTIGSTEN_X_STUND'); ?></td><td>0&ndash;23</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IWININ_I_V'); ?></span></td><td><?php echo spot_t('TEXT.BEGINNT_IN_STUNDEN_0_LUFT_GERADE'); ?></td><td>h</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IWINCT_I_V'); ?></span></td><td><?php echo spot_t('TEXT.DURCHSCHNITTSPREIS_IN_DIESEM_FENST'); ?></td><td>ct/kWh</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IANN_I_V'); ?></span></td><td><?php echo spot_t('TEXT.1_MELDEFENSTER_ERSTE_10_MIN_EINER_'); ?></td><td>&mdash;</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IPUSH_I_V'); ?></span> / <span class="sm-mono"><?php echo spot_t('TEXT.IAUDIO_I_V'); ?></span></td><td><?php echo spot_t('TEXT.FREIGABEN_AUS_DER_PLUGIN_KONFIGURA'); ?></td><td>&mdash;</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IPTEST_I_V'); ?></span></td><td><?php echo spot_t('TEXT.1_TEST_PUSHNACHRICHT_ANGEFORDERT_R'); ?></td><td>&mdash;</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.ICO2_I_V'); ?></span></td><td><?php echo spot_t('TEXT.CO_8322_INTENSITT_DES_STROMMIXES_J'); ?></td><td>g/kWh</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.ICO2MIN_I_V'); ?></span> / <span class="sm-mono"><?php echo spot_t('TEXT.ICO2MINH_I_V'); ?></span></td><td><?php echo spot_t('TEXT.SAUBERSTE_STUNDE_DER_NCHSTEN_24_H_'); ?></td><td><?php echo spot_t('TEXT.G_KWH_023'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.ICO2CLEAN_I_V'); ?></span></td><td><?php echo spot_t('TEXT.1_UNTER_DER_SAUBER_SCHWELLE_OUML_K'); ?></td><td>&mdash;</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IWPCUR_I_V'); ?></span> / <span class="sm-mono"><?php echo spot_t('TEXT.IWPNEXT_I_V'); ?></span></td><td><?php echo spot_t('TEXT.PREIS_NACH_14A_WRMEPUMPE_WALLBOX_J'); ?></td><td>ct/kWh</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IFIX_I_V'); ?></span> / <span class="sm-mono"><?php echo spot_t('TEXT.IDYNM_I_V'); ?></span></td><td><?php echo spot_t('TEXT.EIGENER_FESTPREIS_DYNAMISCHER_MONA'); ?></td><td>ct/kWh</td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.IDIFFM_I_V'); ?></span> / <span class="sm-mono"><?php echo spot_t('TEXT.IEUROM_I_V'); ?></span></td><td><?php echo spot_t('TEXT.VORTEIL_DYNAMISCH_POSITIV_BZW_FEST'); ?></td><td><?php echo spot_t('TEXT.CT_KWH_EUR'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo spot_t('TEXT.ISHIFTJ_I_V'); ?></span></td><td><?php echo spot_t('TEXT.ERSPARNIS_POTENZIAL_PRO_JAHR_DURCH'); ?></td><td>EUR</td></tr>
<tr><td><span class="sm-mono">\i;TS=\i\v</span></td><td><?php echo spot_t('LEBEN.Z_TS'); ?></td><td>s</td></tr>
<tr><td><span class="sm-mono">\i;LAUF=\i\v</span></td><td><?php echo spot_t('LEBEN.Z_LAUF'); ?></td><td>&mdash;</td></tr>
</table>
<div class="sm-warnung"><?php echo spot_t('LEBEN.ERKLAERUNG'); ?></div>
<span class="sm-small"><?php echo spot_t('TEXT.ALLE_PREISE_SIND'); ?> <b><?php echo spot_t('TEXT.CT_KWH_ALS_ENDPREIS'); ?></b><?php echo spot_t('TEXT.WER_DIE_ALTEN_EUR_WERTE_GEWOHNT_IS'); ?> <span class="sm-mono">&lt;v.1&gt; ct</span> <?php echo spot_t('TEXT.STELLEN'); ?></span>
</div>

<div class="sm-step"><b><?php echo spot_t('TEXT.SCHRITT_3_KACHELN_FR_DIE_APP'); ?></b><br>
<?php echo spot_t('TEXT.CUR_NEXT_HAVG_MINP_MAXP_ALS_ANALOG'); ?> <span class="sm-mono">&lt;v.1&gt; ct</span><?php echo spot_t('TEXT.MINH_MAXH_WINH_MIT'); ?> <span class="sm-mono"><?php echo spot_t('TEXT.V_0_UHR'); ?></span><?php echo spot_t('TEXT.RANK_OHNE_EINHEIT_VISUALISIERUNG_F'); ?>
</div>

<div class="sm-step"><b><?php echo spot_t('TEXT.SCHRITT_4_KOMPLETTE_BAUSTEIN_LISTE'); ?></b><br>
<?php
/* X-8 (Nachzug 02.10.2026, im Bau Planer-30): EINE Liste in der Hausform -
 * #, Baustein (Typ), Name (Vorschlag), Parameter, Eingaenge verbinden mit. Bis 1.2.32
 * standen hier sieben Teiltabellen ohne Nummer, mit Kurzzeichen (S1, U1, O1 ...).
 *
 * Je Zeile: Kennung => array(Gruppe, Typ, Name, Parameter, Eingaenge)
 *   Name       Sprachschluessel, oder array('mono', Text) fuer die Befehlsnamen der Vorlage
 *   Parameter  Sprachschluessel, array('mono', Text), array('mono_t', Schluessel),
 *              array('t', Schluessel, Kennung ...) (Text mit %d) oder ''
 *   Eingaenge  array(Schluessel mit %d, Kennung ...) oder ''
 * Die Nummer ist die Stelle in der Liste; Verweise gehen ueber die Kennung, damit eine
 * eingeschobene Zeile keinen Verweis verschiebt. Regel A4: ein UND/ODER hat hoechstens
 * zwei Eingaenge, eine Quelle je Eingang. */
$sp_bl = array(
    'VE'       => array('ein', 'TEXT.BL_T_VE', array('mono', 'Spotpreis aWATTar'), 'TEXT.BL_P_VE', ''),
    'ANN'      => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_ANN'), array('mono_t', 'TEXT.IANN_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'PUSH'     => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_PUSH'), array('mono_t', 'TEXT.IPUSH_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'PTEST'    => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_PTEST'), array('mono_t', 'TEXT.IPTEST_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'LEVEL'    => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_LEVEL'), array('mono_t', 'TEXT.ILEVEL_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'CUR'      => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_CUR'), array('mono_t', 'TEXT.ICUR_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'NEG'      => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_NEG'), array('mono_t', 'TEXT.INEG_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'WININ'    => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_WININ'), array('mono_t', 'TEXT.IWININ_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'WINH'     => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_WINH'), array('mono_t', 'TEXT.IWINH_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'WINCT'    => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_WINCT'), array('mono_t', 'TEXT.IWINCT_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'OK'       => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_OK'), array('mono_t', 'TEXT.IOK_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'MINH'     => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_MINH'), array('mono_t', 'TEXT.IMINH_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'MINP'     => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_MINP'), array('mono_t', 'TEXT.IMINP_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'MAXH'     => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_MAXH'), array('mono_t', 'TEXT.IMAXH_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'CO2CLEAN' => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_CO2CLEAN'), array('mono_t', 'TEXT.ICO2CLEAN_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'CO2'      => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_CO2'), array('mono_t', 'TEXT.ICO2_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'CO2MINH'  => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_CO2MINH'), array('mono_t', 'TEXT.ICO2MINH_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'DYNM'     => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_DYNM'), array('mono_t', 'TEXT.IDYNM_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'FIX'      => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_FIX'), array('mono_t', 'TEXT.IFIX_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'DIFFM'    => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_DIFFM'), array('mono_t', 'TEXT.IDIFFM_I_V'), array('TEXT.BL_E_UNTER', 'VE')),
    'TS'       => array('ein', 'TEXT.BL_T_VEB', array('mono', 'SPOT_TS'), array('mono', '\i;TS=\i\v'), array('TEXT.BL_E_UNTER', 'VE')),
    // Stundenansage-Push
    'S1'  => array('ansage', 'TEXT.BL_T_SWS', 'TEXT.MELDEFENSTER_AKTIV', 'TEXT.EIN_0_5_AUS_0_4', array('TEXT.BL_E_1', 'ANN')),
    'S2'  => array('ansage', 'TEXT.BL_T_SWS', 'TEXT.PUSH_FREIGEGEBEN', 'TEXT.EIN_0_5_AUS_0_4', array('TEXT.BL_E_1', 'PUSH')),
    'U1'  => array('ansage', 'TEXT.BL_T_UND', 'TEXT.PREIS_PUSH_JETZT', '', array('TEXT.BL_E_2', 'S1', 'S2')),
    'O1'  => array('ansage', 'TEXT.BL_T_ODER', 'TEXT.PUSH_SAMMLER', 'TEXT.EINZIGE_QUELLE_DES_BENACHRICHTIGUN', array('TEXT.BL_E_1', 'U1')),
    'B1'  => array('ansage', 'TEXT.BL_T_BENACHR', 'TEXT.PUSH_AKTUELLER_STROMPREIS', 'TEXT.TEXT_Z_B_STROMPREIS_JETZT_V1_1_CT_', array('TEXT.BL_E_1', 'O1')),
    'SPT' => array('ansage', 'TEXT.BL_T_SWS', 'TEXT.BL_N_PTEST', 'TEXT.EIN_0_5_AUS_0_4', array('TEXT.BL_E_1', 'PTEST')),
    'B2'  => array('ansage', 'TEXT.BL_T_BENACHR', 'TEXT.TEST_PUSH', 'TEXT.EIGENER_BAUSTEIN_NUR_FR_DEN_TEST', array('TEXT.BL_E_1', 'SPT')),
    // Guenstig-/Teuer-Schaltung fuer grosse Verbraucher
    'S3'  => array('gross', 'TEXT.BL_T_SWS', 'TEXT.STROM_IST_GNSTIG', 'TEXT.BL_P_S3', array('TEXT.BL_E_ODER_CT', 'LEVEL', 'CUR')),
    'S4'  => array('gross', 'TEXT.BL_T_SWS', 'TEXT.STROM_IST_TEUER', 'TEXT.EIN_2_5_AUS_2_4_AN_LEVEL', array('TEXT.BL_E_1', 'LEVEL')),
    'S5'  => array('gross', 'TEXT.BL_T_SWS', 'TEXT.BRSENPREIS_NEGATIV_2', 'TEXT.EIN_0_5_AUS_0_4', array('TEXT.BL_E_1', 'NEG')),
    'O2'  => array('gross', 'TEXT.BL_T_ODER', 'TEXT.FREIGABE_GROE_VERBRAUCHER', 'TEXT.AUF_FREIGABE_EINGANG_VON_WALLBOX_W', array('TEXT.BL_E_2', 'S3', 'S5')),
    'U2'  => array('gross', 'TEXT.BL_T_UND', 'TEXT.SPERRE_BEI_HOCHPREIS', 'TEXT.Z_B_HEIZSTAB_BOILER_SPERREN', array('TEXT.BL_E_EIGEN', 'S4')),
    // Guenstigstes Fenster nutzen
    'S6'  => array('fenster', 'TEXT.BL_T_SWS', 'TEXT.GNSTIGSTES_FENSTER_LUFT', 'TEXT.INVERTIERT_EIN_BEI_UNTERSCHREITEN_', array('TEXT.BL_E_1', 'WININ')),
    'U3'  => array('fenster', 'TEXT.BL_T_UND', 'TEXT.START_FREIGABE_GERT', 'TEXT.SCHALTSTECKDOSE_GERTE_STARTBEFEHL', array('TEXT.BL_E_TASTER', 'S6')),
    'ST1' => array('fenster', 'TEXT.BL_T_STATUS', 'TEXT.HINWEIS_KACHEL', 'TEXT.TEXT_GNSTIGSTES_FENSTER_AB_V1_0_UH', array('TEXT.BL_E_I2', 'WINH', 'WINCT')),
    // Preise fuer morgen
    'S7'  => array('morgen', 'TEXT.BL_T_SWS', 'TEXT.PREISE_FR_MORGEN_VORHANDEN', 'TEXT.EIN_0_5_AUS_0_4', array('TEXT.BL_E_1', 'OK')),
    'IG1' => array('morgen', 'TEXT.BL_T_IMPULS', 'TEXT.IMPULS_20_00_TAGESVORSCHAU', 'TEXT.20_00_UHR', ''),
    'U4'  => array('morgen', 'TEXT.BL_T_UND', 'TEXT.BL_N_U4', '', array('TEXT.BL_E_2', 'IG1', 'S7')),
    'ST2' => array('morgen', 'TEXT.BL_T_STATUS', 'TEXT.BL_N_ST2', 'TEXT.TEXT_MORGEN_AM_GNSTIGSTEN_UM_V1_0_', array('TEXT.BL_E_I3', 'MINH', 'MINP', 'MAXH')),
    'B3'  => array('morgen', 'TEXT.BL_T_BENACHR', 'TEXT.PUSH_MORGEN_GNSTIGSTE_STUNDE', array('t', 'TEXT.BL_P_TEXT_AUS', 'ST2'), array('TEXT.BL_E_1', 'U4')),
    // CO2-optimiertes Schalten
    'S8'  => array('co2', 'TEXT.BL_T_SWS', 'TEXT.OUML_KOSTROM_ZEIT', 'TEXT.EIN_0_5_AUS_0_4', array('TEXT.BL_E_1', 'CO2CLEAN')),
    'O3'  => array('co2', 'TEXT.BL_T_ODER', 'TEXT.FREIGABE_SAUBER_ODER_GNSTIG', 'TEXT.Z_B_WARMWASSER_NACHHEIZUNG_SPEICHE', array('TEXT.BL_E_2', 'S8', 'S3')),
    'ST3' => array('co2', 'TEXT.BL_T_STATUS', 'TEXT.KACHEL_STROMMIX', 'TEXT.TEXT_V1_0_G_CO2_KWH_SAUBERSTE_STUN', array('TEXT.BL_E_I2', 'CO2', 'CO2MINH')),
    // Tarifvergleich als Monatsbericht
    'IG2' => array('tarif', 'TEXT.BL_T_IMPULS', 'TEXT.BL_N_IG2', 'TEXT.BL_P_IG2', ''),
    'ST4' => array('tarif', 'TEXT.BL_T_STATUS', 'TEXT.BL_N_ST4', 'TEXT.BL_P_ST4', array('TEXT.BL_E_I3', 'DYNM', 'FIX', 'DIFFM')),
    'B4'  => array('tarif', 'TEXT.BL_T_BENACHR', 'TEXT.MONATSBERICHT_TARIFVERGLEICH', array('t', 'TEXT.BL_P_TEXT_AUS', 'ST4'), array('TEXT.BL_E_1', 'IG2')),
    'S9'  => array('tarif', 'TEXT.BL_T_SWS', 'TEXT.DYNAMISCH_WRE_GNSTIGER', 'TEXT.EIN_0_5_AUS_0_4', array('TEXT.BL_E_1', 'DIFFM')),
    'B5'  => array('tarif', 'TEXT.BL_T_BENACHR', 'TEXT.BL_N_B5', '', array('TEXT.BL_E_1', 'S9')),
    // Ausfallerkennung
    'F1'  => array('ausfall', 'TEXT.BL_T_FORMEL', 'LEBEN.B_FORMEL_NAME', array('mono', '(I1 + 1230768000) - I2'), array('TEXT.BL_E_FORMEL', 'TS')),
    'S10' => array('ausfall', 'TEXT.BL_T_SWS', 'LEBEN.B_SCHWELLE_NAME', 'LEBEN.B_SCHWELLE_EIN', array('TEXT.BL_E_1', 'F1')),
);
$sp_bl_nr = array();
foreach (array_keys($sp_bl) as $sp_bl_i => $sp_bl_k) { $sp_bl_nr[$sp_bl_k] = $sp_bl_i + 1; }
$sp_bl_text = function ($v) use ($sp_bl_nr) {
    if ($v === '' || $v === null) { return '&mdash;'; }
    if (is_string($v)) { return spot_t($v); }
    if ($v[0] === 'mono') { return '<span class="sm-mono">' . sp_e($v[1]) . '</span>'; }
    if ($v[0] === 'mono_t') { return '<span class="sm-mono">' . spot_t($v[1]) . '</span>'; }
    $schl = ($v[0] === 't') ? $v[1] : $v[0];
    $refs = array_slice($v, ($v[0] === 't') ? 2 : 1);
    $zahlen = array();
    foreach ($refs as $r) { $zahlen[] = $sp_bl_nr[$r]; }
    return vsprintf(spot_t($schl), $zahlen);
};
$sp_bl_gruppen = array();
foreach ($sp_bl as $sp_bl_k => $sp_bl_z) {
    $g = $sp_bl_z[0];
    if (!isset($sp_bl_gruppen[$g])) { $sp_bl_gruppen[$g] = array($sp_bl_nr[$sp_bl_k], $sp_bl_nr[$sp_bl_k]); }
    $sp_bl_gruppen[$g][1] = $sp_bl_nr[$sp_bl_k];
}
?>
<?php echo spot_t('TEXT.BL_EINLEITUNG'); ?>
<ul>
<?php foreach ($sp_bl_gruppen as $g => $sp_bl_vb) { ?>
<li><?php echo sprintf(spot_t('TEXT.BL_ZEILEN'), $sp_bl_vb[0], $sp_bl_vb[1], spot_t('TEXT.BL_G_' . strtoupper($g))); ?></li>
<?php } ?>
</ul>
<table class="sm-tbl">
<tr><th>#</th><th><?php echo spot_t('TEXT.BL_H_TYP'); ?></th><th><?php echo spot_t('TEXT.BL_H_NAME'); ?></th><th><?php echo spot_t('TEXT.BL_H_PARAM'); ?></th><th><?php echo spot_t('TEXT.BL_H_EIN'); ?></th></tr>
<?php foreach ($sp_bl as $sp_bl_k => $sp_bl_z) { ?>
<tr><td><?php echo $sp_bl_nr[$sp_bl_k]; ?></td><td><?php echo spot_t($sp_bl_z[1]); ?></td><td><?php echo $sp_bl_text($sp_bl_z[2]); ?></td><td><?php echo $sp_bl_text($sp_bl_z[3]); ?></td><td><?php echo $sp_bl_text($sp_bl_z[4]); ?></td></tr>
<?php } ?>
</table>
<div class="sm-hinweis"><?php echo spot_t('LEBEN.BAUSTEINE_HINWEIS'); ?></div>
<span class="sm-small"><?php echo spot_t('TEXT.DAS_PLUGIN_SENDET_DENSELBEN_BERICH'); ?></span>
<br><br><b><?php echo spot_t('TEXT.PRAXIS_ERFAHRUNGEN_ZUM_BENACHRICHT'); ?></b> <?php echo spot_t('TEXT.ERSPART_LANGE_FEHLERSUCHE'); ?><br>
<?php echo spot_t('TEXT.ER_SENDET_NUR_BEI_EINER_01_FLANKE_'); ?><br>
<?php echo spot_t('TEXT.FR_DEN_TEST_PTEST_EINEN_EIGENEN_BE'); ?><br>
<?php echo spot_t('TEXT.INVERTIERTE_SCHWELLWERTSCHALTER_EI'); ?>
</div>

<div class="sm-step"><b><?php echo spot_t('TEXT.SCHRITT_5_MQTT_ALTERNATIVE_JSON'); ?></b><br>
<?php echo spot_t('TEXT.ALLE_WERTE_GIBT_ES_AUCH_BER_DAS_LO'); ?>
<span class="sm-mono"><?= sp_e($sp_cfg['mqtt_topic']) ?>/...</span> <?php echo spot_t('TEXT.UND_ALS_JSON_FR_DRITTSOFTWARE_INKL'); ?> <b><?php echo spot_t('TEXT.ALLER_STUNDENWERTE'); ?></b> <?php echo spot_t('TEXT.FR_EIGENE_DIAGRAMME'); ?>
<span class="sm-mono">http://<?= $sp_host ?>/plugins/<?= sp_e($sp_plugin) ?><?php echo spot_t('TEXT.SPOT_PHP_JSON_1'); ?><?= $sp_tk2 ?></span>
</div>

<?php
/* ===================================================================
 * Das Abo im MQTT-Gateway - der Schritt, der bis 1.2.12 GANZ FEHLTE
 * ===================================================================
 *
 * Wer MQTT einschaltete, bekam die Themenliste und sonst nichts. Unter
 * Gateway V1 - der Vorgabe - kam damit am Miniserver kein einziger Wert
 * an, ohne dass irgendwo stand, warum. Das ist die haeufigste
 * Fehlerursache ueberhaupt, und sie war hier nicht einmal erwaehnt.
 *
 * Der Satz haengt an Mqtt.Gatewayversion und wird NICHT unbedingt
 * hingeschrieben: unter V2 schaltet der LoxBerry-Kern die Eingabeknoepfe
 * auf der Abonnement-Seite ausdruecklich ab. Wer dort den V1-Satz liest,
 * sucht ein Eingabefeld, das es nicht mehr gibt.
 *
 * Ist die Fassung nicht lesbar, stehen BEIDE Saetze da. Einen von beiden
 * zu behaupten waere fuer die Haelfte der Anlagen falsch - und eine stille
 * Falschaussage in genau der Zeile, die als haeufigste Fehlerursache gilt.
 * =================================================================== */
$sp_gw = function_exists('spot_mqtt_gateway_info') ? spot_mqtt_gateway_info() : null;
$sp_gwf = ($sp_gw === null) ? 0 : (int) $sp_gw['fassung'];
?>
<div class="sm-step"><b><?php echo spot_t('LOX.H_ABO'); ?></b><br>
<?php echo spot_t('LOX.ABO_EINLEITUNG'); ?>
<pre class="sm-pre"><?= sp_e($sp_cfg['mqtt_topic']) ?>/#</pre>
<!-- Welche Themen zurueckbehalten hinausgehen. Die Liste wird NICHT
     abgeschrieben, sondern aus spot_retain_liste() gebildet - zwei
     Listen liefen sonst auseinander, und die Oberflaeche behauptete
     etwas anderes, als der Dienst sendet. -->
<div class="sm-small"><?php echo spot_t('LOX.RETAIN_TEXT'); ?></div>
<div class="sm-pre"><?php
    $sp_rt = array_keys(spot_retain_liste());
    sort($sp_rt);
    $sp_rz = array();
    foreach ($sp_rt as $sp_r) { $sp_rz[] = $sp_cfg['mqtt_topic'] . '/' . $sp_r; }
    echo sp_e(implode('   ', $sp_rz));
?></div>
<?php if ($sp_gwf >= 2) { ?>
<div class="sm-hinweis"><?php echo spot_t('LOX.ABO_V2'); ?></div>
<?php } elseif ($sp_gwf === 1) { ?>
<div class="sm-warnung"><?php echo spot_t('LOX.ABO_PFLICHT'); ?></div>
<?php } else { ?>
<div class="sm-warnung"><?php echo spot_t('LOX.ABO_PFLICHT'); ?></div>
<div class="sm-hinweis"><?php echo spot_t('LOX.ABO_V2'); ?></div>
<div class="sm-small"><?php echo spot_t('LOX.ABO_UNBEKANNT'); ?></div>
<?php } ?>
<div class="sm-small"><?= sprintf(spot_t('LOX.ABO_FASSUNG'),
      $sp_gwf > 0 ? (string) $sp_gwf : spot_t('LOX.ABO_FASSUNG_UNBEKANNT')) ?></div>
<?php if ($sp_gw !== null && !$sp_gw['autostart']) { ?>
<div class="sm-alert sm-warn"><b>MQTT:</b> <?php echo spot_t('TEXT.W_AUTOSTART'); ?></div>
<?php } ?>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?php echo $sp_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">
<h2><?php echo spot_t('TEXT.TEST'); ?></h2>

<?php
/* ===================================================================
 * Selbstpruefung - beantwortet OHNE Loxone, ob die Einrichtung traegt
 * ===================================================================
 *
 * Drei Ausgaenge je Zeile: Haken, Kreuz, STRICH. Der Strich heisst "nicht
 * feststellbar" und ist ausdruecklich kein Haken - eine Zusammenfassung,
 * die besser aussieht als ihr schlechtester Punkt, ist schlimmer als
 * keine.
 *
 * Der Aufruf des eigenen Endpunkts kostet eine HTTP-Anfrage und laeuft
 * deshalb nur auf Knopfdruck. Ohne Knopfdruck steht dort ein Strich, nicht
 * ein Haken. */
/* SIE LAEUFT NUR IM GEOEFFNETEN REITER.
 *
 * Alle Reiterflaechen werden vom Server gerendert, auch die, die der
 * Anwender gerade nicht ansieht - nur das JavaScript blendet sie aus. Eine
 * Selbstpruefung, die den Zustand rechnet, die Vorlage baut und die eigene
 * Oberflaechendatei liest, liefe damit bei JEDEM Seitenaufbau mit, auch
 * beim Klick auf Logdateien. Gemessen hat sie den Aufbau der uebrigen
 * Reiter spuerbar verzoegert. */
$sp_ep = !empty($sp_ep_an);     // O2: nach der Umleitung einmal im GET
$sp_pruefungen = ($sp_tab === 'tab-test' && function_exists('spot_selbsttest'))
    ? spot_selbsttest($sp_ep) : array();
$sp_haken = 0; $sp_kreuz = 0; $sp_strich = 0;
foreach ($sp_pruefungen as $sp_z) {
    if ($sp_z['ok'] === 1) { $sp_haken++; } elseif ($sp_z['ok'] === 0) { $sp_kreuz++; } else { $sp_strich++; }
}
?>
<?php if (!$sp_pruefungen) { ?>
<div class="sm-alert sm-info"><?php echo spot_t('PRUEF.NUR_IM_REITER'); ?></div>
<?php } else { ?>
<?php /* O1: Zusammenfassung und die Zeile "Reiter" werden erst am fertigen
         HTML gesetzt (ganz unten in dieser Datei). */ ?>
<div class="sm-alert <!--SP_KLASSE-->">
<b><!--SP_SUMME--></b>
</div>
<?php } ?>
<table class="sm-tbl" style="width:100%;">
<tr><th style="width:2em;"></th><th><?php echo spot_t('PRUEF.T_FRAGE'); ?></th><th><?php echo spot_t('PRUEF.T_BEFUND'); ?></th></tr>
<?php foreach ($sp_pruefungen as $sp_z) {
    $sp_reiterzeile = ($sp_z['schluessel'] === 'PRUEF.REITER'); ?>
<tr><td style="text-align:center;font-weight:700;color:<?= $sp_reiterzeile ? '<!--SP_R_FARBE-->' : ($sp_z['ok'] === 1 ? '#2e7d32' : ($sp_z['ok'] === 0 ? '#c62828' : '#8d6e63')) ?>;">
<?= $sp_reiterzeile ? '<!--SP_R_ZEICHEN-->' : ($sp_z['ok'] === 1 ? '&#10003;' : ($sp_z['ok'] === 0 ? '&#10007;' : '&ndash;')) ?></td>
<td><?= sp_e(spot_t($sp_z['schluessel'])) ?></td>
<td><?= $sp_reiterzeile ? '<!--SP_R_TEXT-->' : sp_e($sp_z['text']) ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?php echo spot_t('PRUEF.STRICH_ERKLAERUNG'); ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo spot_t('LEGENDE.LESEN'); ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <?php echo spot_fmt(); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="endpunkt_test" value="1"><?php echo spot_t('PRUEF.K_ENDPUNKT'); ?></button>
  </form>
</div>
<div class="sm-small"><?php echo spot_t('PRUEF.H_ENDPUNKT'); ?></div>
<?php if (!empty($sp_cfg['marstek_enabled'])) { ?><div class="sm-small"><?= sp_e(spot_t('PRUEF.H_MARSTEK')) ?></div><?php } ?>

<h3 class="sm-h3"><?php echo spot_t('PLAN.H_FAHRPLAN'); ?></h3>
<p class="sm-small"><?php echo spot_t('PLAN.FAHRPLAN_TEXT'); ?></p>
<?php
$sp_fp = spot_fahrplan();
$sp_bel = $sp_fp['belegung'];
$sp_sl = (int) $sp_fp['slotlen'];
$sp_aktiv = array();
foreach ($sp_fp['plan'] as $sp_pz) {
    if (!empty($sp_pz['slots'])) { $sp_aktiv[] = $sp_pz; }
}
/* Nur die Scheiben zeigen, in denen ueberhaupt etwas geplant ist - eine
 * Tabelle mit 96 Zeilen, von denen 90 leer sind, liest niemand. Gedeckelt
 * bei 60 Zeilen; mehr passt auf keinen Bildschirm. */
$sp_zeiten = array_keys($sp_bel);
foreach ($sp_aktiv as $sp_pz) {
    foreach ($sp_pz['slots'] as $sp_ts) { $sp_zeiten[] = $sp_ts; }
}
$sp_zeiten = array_values(array_unique($sp_zeiten));
sort($sp_zeiten);
$sp_zeiten = array_slice($sp_zeiten, 0, 60);
$sp_budget = (float) $sp_cfg['budget_kw'];
?>
<?php if (!$sp_zeiten) { ?>
<div class="sm-hinweis"><?php echo spot_t('PLAN.FAHRPLAN_LEER'); ?></div>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?php echo spot_t('PLAN.T_ZEIT'); ?></th><th><?php echo spot_t('PLAN.T_PREIS'); ?></th>
<?php foreach ($sp_aktiv as $sp_pz) { ?>
    <th><?php echo sp_e($sp_pz['name']); ?></th>
<?php } ?>
    <th><?php echo spot_t('PLAN.T_SUMME'); ?></th></tr>
<?php foreach ($sp_zeiten as $sp_ts) {
    $sp_kw = isset($sp_bel[$sp_ts]) ? (float) $sp_bel[$sp_ts] : 0.0;
    $sp_voll = ($sp_budget > 0 && round($sp_kw, 4) >= round($sp_budget, 4));
?>
<tr<?php echo $sp_voll ? ' style="background:#fdf4ec;"' : ''; ?>>
    <td><span class="sm-mono"><?php echo date($sp_sl >= 3600 ? 'd.m. H:i' : 'd.m. H:i', $sp_ts); ?></span></td>
    <td><?php echo isset($sp_fp['preise'][$sp_ts])
        ? sp_n($sp_fp['preise'][$sp_ts], 2) : '&ndash;'; ?></td>
<?php foreach ($sp_aktiv as $sp_pz) { ?>
    <td style="text-align:center;"><?php echo in_array($sp_ts, $sp_pz['slots'], true)
        ? '<span class="sm-an">&#9632;</span>' : '&middot;'; ?></td>
<?php } ?>
    <td><?php echo $sp_kw > 0 ? sp_n($sp_kw, 2) . ' kW' : '&ndash;'; ?></td></tr>
<?php } ?>
</table>
</div>
<?php if ($sp_budget > 0) { ?>
<p class="sm-small"><?php echo spot_t('PLAN.FAHRPLAN_BUDGET'); ?></p>
<?php } } ?>

<h3 class="sm-h3"><?php echo spot_t('PLAN.H_UEBERSICHT'); ?></h3>
<p class="sm-small"><?php echo spot_t('PLAN.UEBERSICHT_TEXT'); ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?php echo spot_t('PLAN.U_REGEL'); ?></th><th><?php echo spot_t('PLAN.U_GRUND'); ?></th>
    <th><?php echo spot_t('PLAN.U_NOETIG'); ?></th><th><?php echo spot_t('PLAN.U_GEPLANT'); ?></th>
    <th><?php echo spot_t('PLAN.U_FEHLT'); ?></th><th><?php echo spot_t('PLAN.U_CT'); ?></th>
    <th><?php echo spot_t('PLAN.U_SOFORT'); ?></th><th><?php echo spot_t('PLAN.U_SPART'); ?></th></tr>
<?php foreach ($sp_fp['plan'] as $sp_pz) {
    $sp_rg = isset($sp_pz['grund']) ? (string) $sp_pz['grund'] : 'aus';
?>
<tr><td><?php echo sp_e($sp_pz['name']); ?></td>
    <td><?php echo sp_e(spot_t('PLANGRUND.' . strtoupper($sp_rg))); ?><?php
      if (!empty($sp_pz['mangel'])) {
          foreach (explode(',', (string) $sp_pz['mangel']) as $sp_mg) {
              echo '<br><span class="sm-aus">'
                 . sp_e(spot_t('PLANMANGEL.' . strtoupper(trim($sp_mg)))) . '</span>';
          }
      } ?></td>
    <td><?php echo (int) $sp_pz['noetig']; ?></td>
    <td><?php echo (int) $sp_pz['anzahl']; ?></td>
    <td><?php echo ((int) $sp_pz['fehlt'] > 0)
        ? '<span class="sm-aus">' . (int) $sp_pz['fehlt'] . '</span>' : '0'; ?></td>
    <td><?php echo $sp_pz['anzahl'] > 0 ? sp_n($sp_pz['ct'], 2) : '&ndash;'; ?></td>
    <td><?php echo $sp_pz['anzahl'] > 0 ? sp_n($sp_pz['ct_sofort'], 2) : '&ndash;'; ?></td>
    <td><?php if ($sp_pz['anzahl'] > 0) {
          echo '<b>' . sp_n($sp_pz['spart_ct'], 2) . '</b> ct/kWh';
          if ((float) $sp_pz['kwh'] > 0) {
              echo '<br>' . sp_n($sp_pz['spart_eur'], 2) . ' &euro; ('
                 . sp_n($sp_pz['kwh'], 1) . ' kWh)';
          }
        } else { echo '&ndash;'; } ?></td></tr>
<?php } ?>
</table>
<?php /* O1 (Pruefbericht oberflaeche, Befund 1): dieses </div> schliesst das
         <div class="sm-breit"> von oben. Ohne es lagen die Flaechen
         "Kostenvergleich" und "Logdateien" IN der Flaeche "Test" und waren in
         ihrem eigenen Reiter leer (gemessen: 0 px hoch). */ ?>
</div>
<div class="sm-small"><?php echo spot_t('PLAN.U_HILFE'); ?></div>

<h3 class="sm-h3"><?php echo spot_t('PLAN.H_SELBSTTEST'); ?></h3>
<p class="sm-small"><?php echo spot_t('PLAN.SELBSTTEST_TEXT'); ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo spot_t('PLAN.LEGENDE_TECHNIK'); ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <?php echo spot_fmt(); ?>
    <input data-role="none" type="hidden" name="tab" value="test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="plantest" value="1"><?php echo spot_t('PLAN.K_SELBSTTEST'); ?></button>
  </form>
</div>
<?php if (!empty($sp_plantest)) { ?>
<div class="sm-pre"><?php echo sp_e($sp_plantest); ?></div>
<?php } ?>

<h3 class="sm-h3"><?php echo spot_t('PLAN.H_KOPIEN'); ?></h3>
<p class="sm-small"><?php echo spot_t('PLAN.KOPIEN_TEXT'); ?></p>
<?php
/* Die Rechnung steht in planer.php (ab 1.1.6), nicht hier - sie ist die
 * Pruefung zu der Regel, die im Kopf derselben Datei steht, und sie darf
 * nicht in drei Kopien gefuehrt werden.
 *
 * Drei Ausgaenge, und der dritte ist Pflicht: eine Schwesterlinie, die auf
 * diesem LoxBerry nicht installiert ist, ist kein Befund und kein Haken.
 * Wer sie zu einem der beiden anderen zaehlt, bekommt eine Zeile, die bei
 * jeder Einzelinstallation gruen leuchtet, ohne je etwas verglichen zu
 * haben. */
$pl_summen = plan_pruefsummen(spot_paths()['lbhome']);
$pl_vorhanden = 0;
$pl_ungleich = 0;
foreach (array_slice($pl_summen, 1) as $pl_e) {
    if ($pl_e['lage'] === 'fehlt') { continue; }
    $pl_vorhanden++;
    if ($pl_e['lage'] === 'verschieden') { $pl_ungleich++; }
}
?>
<table class="sm-tbl">
<tr><th><?php echo spot_t('PLAN.K_LINIE'); ?></th><th><?php echo spot_t('PLAN.K_LAGE'); ?></th></tr>
<?php foreach ($pl_summen as $pl_e) { ?>
<tr><td><span class="sm-mono"><?php echo sp_e($pl_e['ordner']); ?></span></td>
    <td class="<?php echo $pl_e['lage'] === 'verschieden' ? 'sm-aus' : ($pl_e['lage'] === 'gleich' ? 'sm-an' : ''); ?>"><?php
      echo sp_e(spot_t('PLAN.LAGE_' . strtoupper($pl_e['lage']))); ?></td></tr>
<?php } ?>
</table>
<?php if ($pl_vorhanden === 0) { ?>
<div class="sm-hinweis"><?php echo spot_t('PLAN.KOPIEN_KEINE'); ?></div>
<?php } elseif ($pl_ungleich > 0) { ?>
<div class="sm-warnung"><?php echo spot_t('PLAN.KOPIEN_UNGLEICH'); ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?php echo sprintf(spot_t('PLAN.KOPIEN_GLEICH'), (int) $pl_vorhanden); ?></div>
<?php } ?>

<?php /* Der frueher hier stehende Kasten "Feldnamen gegen die Zeile" ist in
         spot_selbsttest() aufgegangen (Zeilen PRUEF.FELDER und
         PRUEF.VORLAGE, oben in der Tabelle). Zwei Stellen, die dasselbe
         messen, laufen auseinander - und dann glaubt man der bequemeren. */ ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo spot_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo spot_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo spot_t('LEGENDE.AKTION'); ?></span>
</div>

<h3 class="sm-h3"><?php echo spot_t('TEXT.ANSEHEN'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen"  href="/plugins/<?= sp_e($sp_plugin) ?>/spot.php<?= $sp_tk ?>" target="_blank"><?php echo spot_t('TEXT.LOXONE_ZEILE_ABRUFEN'); ?></a>
<a class="sm-btn sm-b-lesen"  href="/plugins/<?= sp_e($sp_plugin) ?>/spot.php?json=1<?= $sp_tk2 ?>" target="_blank"><?php echo spot_t('TEXT.JSON_ANSICHT'); ?></a>
</div>

<h3 class="sm-h3"><?php echo spot_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik"  href="/plugins/<?= sp_e($sp_plugin) ?>/spot.php?debug=1<?= $sp_tk2 ?>" target="_blank"><?php echo spot_t('TEXT.DEBUG_ALLE_STUNDENPREISE'); ?></a>
<a class="sm-btn sm-b-technik"  href="/plugins/<?= sp_e($sp_plugin) ?>/spot.php?refresh=1&amp;debug=1<?= $sp_tk2 ?>" target="_blank"><?php echo spot_t('TEXT.NEU_ABRUFEN_DEBUG'); ?></a>
</div>

<h3 class="sm-h3"><?php echo spot_t('TEXT.LST_ETWAS_AUS'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= sp_e($sp_plugin) ?>/spot.php?say=1<?= $sp_tk2 ?>" target="_blank"><?php echo spot_t('TEXT.TEST_ANSAGE_AKTUELLER_PREIS'); ?></a>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= sp_e($sp_plugin) ?>/spot.php?saytomorrow=1<?= $sp_tk2 ?>" target="_blank"><?php echo spot_t('TEXT.TEST_ANSAGE_PREISE_MORGEN'); ?></a>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= sp_e($sp_plugin) ?>/spot.php?ptest=1<?= $sp_tk2 ?>" target="_blank"><?php echo spot_t('TEXT.TEST_PUSHNACHRICHT_2'); ?></a>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <?php echo spot_fmt(); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="testansage" value="1"><?php echo spot_t('TEXT.K_TESTANSAGE'); ?></button>
  </form>
</div>
<div class="sm-small"><?php echo spot_t('TEXT.H_TESTANSAGE'); ?></div>


<div class="sm-small">
<?php echo spot_t('TEXT.TEXT_5'); ?> <b><?php echo spot_t('TEXT.LOXONE_ZEILE'); ?></b> <?php echo spot_t('TEXT.ZEIGT_GENAU_DAS_WAS_DER_MINISERVER'); ?><br>
&bull; <b><?php echo spot_t('TEXT.DEBUG'); ?></b> <?php echo spot_t('TEXT.LISTET_ALLE_STUNDENPREISE_HEUTE_UN'); ?><br>
&bull; <b><?php echo spot_t('TEXT.TEST_ANSAGE'); ?></b> <?php echo spot_t('TEXT.SPRICHT_SOFORT_IN_DEN_KONFIGURIERT'); ?><br>
&bull; <b><?php echo spot_t('TEXT.TEST_PUSHNACHRICHT'); ?></b> <?php echo spot_t('TEXT.SETZT'); ?> <span class="sm-mono"><?php echo spot_t('TEXT.PTEST_1'); ?></span> <?php echo spot_t('TEXT.FR_5_MINUTEN_DER_PUSH_KOMMT_BER_DE'); ?><br>
<?php echo spot_t('TEXT.DIE_TABELLEN_UNTEN_FLLEN_SICH_MIT_'); ?>
</div>
<?php $sp_mc = function_exists('spot_month_compare') ? spot_month_compare(12) : array(); if ($sp_mc) { ?>
<h2><?php echo spot_t('TEXT.MONATSVERGLEICH_DER_ARBEITSPREISE'); ?></h2>
<div class="sm-small" style="margin-bottom:6px;"><?php echo spot_t('TEXT.DYNAMISCH_LASTPROFIL_GEWICHTETER_M'); ?>
<?= $sp_mon['use'] ? '<b>' . spot_t('TEXT.DEN_GEPFLEGTEN_MONATSMENGEN') . '</b>'
      : sprintf(spot_t('TEXT.JAHRESVERBRAUCH_VERTEILT'), (int) $sp_cfg['consumption']) ?>
<?php echo spot_t('TEXT.SPALTE_KWH'); ?> <?php echo spot_t('TEXT.TAGE'); ?>.
<?php echo spot_t('TEXT.TABELLE_FUELLT_SICH'); ?></div>
<div class="sm-breit">
<table class="sm-tbl"><tr><th><?php echo spot_t('TEXT.MONAT'); ?></th><th><?php echo spot_t('TEXT.TAGE'); ?></th><th>kWh</th><th><?php echo spot_t('TEXT.DYNAMISCH_GEWICHTET'); ?></th><th><?php echo spot_t('TEXT.DYNAMISCH_EINFACH'); ?></th><th><?php echo spot_t('TEXT.FEST'); ?></th><th><?php echo spot_t('TEXT.VORTEIL'); ?></th><th><?php echo spot_t('TEXT.EURO'); ?></th></tr>
<?php foreach ($sp_mc as $sp_m) { ?>
<tr><td><?= sp_e(substr($sp_m['monat'], 4, 2) . '/' . substr($sp_m['monat'], 0, 4)) ?></td>
<td><?= (int) $sp_m['tage'] ?></td>
<?php /* Woher die Menge kommt, steht dabei - gemessen, gepflegt oder
         abgeleitet. Eine Zahl ohne ihre Herkunft behauptet eine
         Genauigkeit, die sie nicht hat. */ ?>
<td><?= sp_n($sp_m['kwh'], 1) ?>
<span class="sm-small" title="<?= sp_e(spot_t('LAST.Q_' . strtoupper($sp_m['quelle']))) ?>"><?=
    $sp_m['quelle'] === 'lastgang' ? ' &#9679;' : ($sp_m['quelle'] === 'monat' ? '' : ' *') ?></span></td>
<td><b><?= sp_n($sp_m['dynp'], 2) ?> ct</b></td><td><?= sp_n($sp_m['dyn'], 2) ?> ct</td>
<td><?= sp_n($sp_m['fix'], 2) ?> ct</td>
<td style="color:<?= $sp_m['diff'] >= 0 ? '#2e7d32' : '#c62828' ?>;"><b><?= ($sp_m['diff'] >= 0 ? '+' : '') . sp_n($sp_m['diff'], 2) ?> ct</b></td>
<td style="color:<?= $sp_m['euro'] >= 0 ? '#2e7d32' : '#c62828' ?>;"><?= ($sp_m['euro'] >= 0 ? '+' : '') . sp_n($sp_m['euro'], 2) ?> <?php echo spot_t('TEXT.TEXT_6'); ?></td></tr>
<?php } ?></table>
</div>
<div class="sm-small"><?php echo spot_t('TEXT.MENGE_AUS_DEM_JAHRESVERBRAUCH_ABGE'); ?></div>
<?php } ?>
<?php $sp_sh = function_exists('spot_shift_saving') ? spot_shift_saving(7) : array(); if (!empty($sp_sh['tage'])) { ?>
<h2><?php echo spot_t('TEXT.ERSPARNIS_DURCH_VERSCHOBENEN_VERBR'); ?></h2>
<div class="sm-alert sm-ok"><?php echo spot_t('TEXT.MITTLERE_SPANNE_ZWISCHEN_TAGESSCHN'); ?>
<?= (int) $sp_sh['tage'] ?> <?php echo spot_t('TEXT.TAGE_2'); ?> <b><?= sp_n($sp_sh['ct'], 2) ?> ct/kWh</b><?php echo spot_t('TEXT.WER_TGLICH'); ?> <?= sp_n($sp_sh['kwh'], 1) ?> <?php echo spot_t('TEXT.KWH_IN_DIE_GNSTIGSTE_ZEIT_VERSCHIE'); ?>
<b><?= sp_n($sp_sh['euro'], 2) ?> &euro;</b> <?php echo spot_t('TEXT.IN_DIESEN'); ?> <?= (int) $sp_sh['tage'] ?> <?php echo spot_t('TEXT.TAGEN_HOCHGERECHNET'); ?>
<b><?= sp_n($sp_sh['euro_jahr'], 2) ?> <?php echo spot_t('TEXT.EURO_IM_JAHR'); ?></b> <?php echo spot_t('TEXT.ZUSTZLICH_ZUM_REINEN_TARIFVERGLEIC'); ?></div>
<?php } ?>
<?php $sp_hist = function_exists('spot_history_read') ? spot_history_read(14) : array(); if ($sp_hist) { ?>
<h2><?php echo spot_t('TEXT.TAGESWERTE_DER_LETZTEN_TAGE'); ?></h2>
<div class="sm-breit">
<table class="sm-tbl"><tr><th><?php echo spot_t('TEXT.TAG'); ?></th><th><?php echo spot_t('TEXT.SCHNITT'); ?></th><th><?php echo spot_t('TEXT.GEWICHTET'); ?></th><th><?php echo spot_t('TEXT.MINIMUM'); ?></th><th><?php echo spot_t('TEXT.MAXIMUM'); ?></th><th>CO&#8322;</th><th><?php echo spot_t('LAST.T_GEWICHTUNG'); ?></th></tr>
<?php foreach (array_reverse($sp_hist) as $sp_r) { ?>
<tr><td><?= sp_e(substr($sp_r[0], 6, 2) . '.' . substr($sp_r[0], 4, 2) . '.' . substr($sp_r[0], 0, 4)) ?></td>
<td><?= sp_n($sp_r[1], 2) ?> ct</td><td><?= $sp_r[4] > 0 ? sp_n($sp_r[4], 2) . ' ct' : '&ndash;' ?></td>
<td><?= sp_n($sp_r[2], 2) ?> ct</td><td><?= sp_n($sp_r[3], 2) ?> ct</td>
<td><?= $sp_r[5] > 0 ? (int) $sp_r[5] . ' g' : '&ndash;' ?></td>
<td><?= !empty($sp_r[6])
      ? '<b>' . sp_e(spot_t('LAST.T_GEMESSEN')) . '</b> (' . sp_n($sp_r[7], 1) . ' kWh)'
      : sp_e(spot_t('LAST.T_PROFIL')) ?></td></tr>
<?php } ?></table>
</div>
<div class="sm-small"><?php echo spot_t('LAST.T_ERKLAERUNG'); ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <?php echo spot_fmt(); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="spot_csv" value="1"><?php echo spot_t('LAST.K_CSV'); ?></button>
  </form>
</div>
<div class="sm-small"><?php echo spot_t('LAST.H_CSV'); ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Kostenvergleich ================= -->
<div class="sm-seite<?php echo $sp_tab === 'tab-costs' ? ' sm-active' : ''; ?>" id="tab-costs">
<?php $sp_cc = function_exists('spot_cost_compare') ? spot_cost_compare() : null; if ($sp_cc) { ?>
<h2><?php echo spot_t('TEXT.KOSTENVERGLEICH_AUF_EIN_JAHR_HOCHG'); ?></h2>
<div class="sm-small" style="margin-bottom:6px;"><?php echo spot_t('TEXT.BEIDE_TARIFE_MIT_ALLEN_BESTANDTEIL'); ?> <b><?= sp_n($sp_cc['kwh'], 0) ?> kWh</b> <?php echo spot_t('TEXT.JAHRESVERBRAUCH'); ?>
<?= $sp_mon['use'] ? spot_t('TEXT.AUS_MONATSWERTEN') : spot_t('TEXT.JAHRESWERT_VERTEILT') ?><?php echo spot_t('TEXT.PREISNIVEAU_AUS'); ?> <?= (int) $sp_cc['monate_gemessen'] ?> <?php echo spot_t('TEXT.MONAT_EN_EIGENER_AUFZEICHNUNG'); ?>
<?= $sp_cc['monate_gemessen'] < 12
      ? sprintf(spot_t('TEXT.UEBRIGE_MONATE'), sp_n($sp_cc['schnitt'], 2)) : '' ?><?php echo spot_t('TEXT.JE_LNGER_DAS_PLUGIN_LUFT_DESTO_BEL'); ?></div>
<table class="sm-tbl" style="width:100%;">
<tr><th><?php echo spot_t('TEXT.POSITION'); ?></th><th style="text-align:right;"><?php echo spot_t('TEXT.FESTER_TARIF'); ?></th><th style="text-align:right;"><?php echo spot_t('TEXT.DYNAMISCHER_TARIF'); ?></th></tr>
<tr><td><?php echo spot_t('TEXT.ARBEITSPREIS_JAHR'); ?></td><td style="text-align:right;"><?= sp_n($sp_cc['fix_arbeit'], 2) ?> &euro;</td><td style="text-align:right;"><?= sp_n($sp_cc['dyn_arbeit'], 2) ?> &euro;</td></tr>
<tr><td><?php echo spot_t('TEXT.GRUNDPREIS_12_MONATE'); ?></td><td style="text-align:right;"><?= sp_n($sp_cc['fix_grund'], 2) ?> &euro;</td><td style="text-align:right;"><?= sp_n($sp_cc['dyn_grund'], 2) ?> &euro;</td></tr>
<tr><td><?php echo spot_t('TEXT.ZWISCHENSUMME'); ?></td><td style="text-align:right;"><b><?= sp_n($sp_cc['fix_zwischen'], 2) ?> &euro;</b></td><td style="text-align:right;"><b><?= sp_n($sp_cc['dyn_jahr'], 2) ?> &euro;</b></td></tr>
<?php if ($sp_cc['rabatt'] > 0) { ?>
<tr><td><?php echo spot_t('TEXT.ABSCHLAGSRABATT'); ?><?= sp_n($sp_cc['rabatt_pct'], 1) ?> %)</td><td style="text-align:right;color:#2e7d32;"><?php echo spot_t('TEXT.TEXT_7'); ?> <?= sp_n($sp_cc['rabatt'], 2) ?> &euro;</td><td style="text-align:right;">&ndash;</td></tr>
<?php } ?>
<?php if ($sp_cc['boni'] > 0) { ?>
<tr><td><?php echo spot_t('TEXT.BONI_NUR_ERSTES_JAHR'); ?></td><td style="text-align:right;color:#2e7d32;">&minus; <?= sp_n($sp_cc['boni'], 2) ?> &euro;</td><td style="text-align:right;">&ndash;</td></tr>
<?php } ?>
<tr style="background:#f5f5f5;"><td><b><?php echo spot_t('TEXT.KOSTEN_ERSTES_JAHR'); ?></b></td><td style="text-align:right;"><b><?= sp_n($sp_cc['fix_jahr1'], 2) ?> &euro;</b><br><span class="sm-small"><?= sp_n($sp_cc['fix_monat1'], 2) ?> <?php echo spot_t('TEXT.MONAT_2'); ?></span></td>
<td style="text-align:right;"><b><?= sp_n($sp_cc['dyn_jahr'], 2) ?> &euro;</b><br><span class="sm-small"><?= sp_n($sp_cc['dyn_monat'], 2) ?> <?php echo spot_t('TEXT.MONAT_2'); ?></span></td></tr>
<tr style="background:#f5f5f5;"><td><b><?php echo spot_t('TEXT.KOSTEN_FOLGEJAHR'); ?></b> <?php echo spot_t('TEXT.OHNE_BONI'); ?></td><td style="text-align:right;"><b><?= sp_n($sp_cc['fix_folge'], 2) ?> &euro;</b><br><span class="sm-small"><?= sp_n($sp_cc['fix_monatf'], 2) ?> <?php echo spot_t('TEXT.MONAT_2'); ?></span></td>
<td style="text-align:right;"><b><?= sp_n($sp_cc['dyn_jahr'], 2) ?> &euro;</b><br><span class="sm-small"><?= sp_n($sp_cc['dyn_monat'], 2) ?> <?php echo spot_t('TEXT.MONAT_2'); ?></span></td></tr>
</table>
<div class="sm-alert <?= $sp_cc['vorteilf'] >= 0 ? 'sm-ok' : 'sm-warn' ?>">
<b><?php echo spot_t('TEXT.ERSTES_JAHR'); ?></b> <?= $sp_cc['vorteil1'] >= 0
    ? sprintf(spot_t('TEXT.DYN_GUENSTIGER_GEWESEN'), sp_n(abs($sp_cc['vorteil1']), 2))
    : sprintf(spot_t('TEXT.FEST_GUENSTIGER_BONI'), sp_n(abs($sp_cc['vorteil1']), 2)) ?><br>
<b><?php echo spot_t('TEXT.FOLGEJAHR'); ?></b> <?= $sp_cc['vorteilf'] >= 0
    ? sprintf(spot_t('TEXT.DYN_GUENSTIGER'), sp_n(abs($sp_cc['vorteilf']), 2))
    : sprintf(spot_t('TEXT.FEST_BLEIBT_GUENSTIGER'), sp_n(abs($sp_cc['vorteilf']), 2)) ?>
<div class="sm-small" style="margin-top:4px;"><?php echo spot_t('TEXT.DAS_FOLGEJAHR_IST_DIE_EHRLICHERE_Z'); ?> <b><?php echo spot_t('TEXT.TEST'); ?></b><?php echo spot_t('TEXT.BEIDES_WRDE_DEN_DYNAMISCHEN_TARIF_'); ?></div>
</div>
<?php } ?>
<?php if (!$sp_cc) { ?>
<h2><?php echo spot_t('TEXT.KOSTENVERGLEICH_AUF_EIN_JAHR_HOCHG'); ?></h2>
<div class="sm-alert sm-info"><?php echo spot_t('TEXT.NOCH_KEINE_AUSWERTUNG_MGLICH_DAS_P'); ?> <b><?php echo spot_t('TEXT.EINSTELLUNGEN'); ?></b><?php echo spot_t('TEXT.OB_FESTER_ARBEITSPREIS_GRUNDPREIS_'); ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?php echo $sp_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
<h2><?php echo spot_t('TEXT.LOGDATEI'); ?></h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo spot_t('TEXT.PROTOKOLLIERT_WERDEN_PREISNDERUNGE'); ?><br><?php echo spot_t('TEXT.DATEI'); ?> <span class="sm-mono"><?= sp_e($sp_logfile) ?></span></div>
<?php if ($sp_loglines) { ?>
<div class="sm-log"><?= sp_e(implode("\n", $sp_loglines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo spot_t('TEXT.NOCH_KEINE_PROTOKOLL_EINTRGE_VORHA'); ?></div>
<?php } ?>
<form action="index.php" method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <?php echo spot_fmt(); ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo spot_t('LEGENDE.AKTION'); ?></span>
</div>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo spot_t('TEXT.LOG_LEEREN'); ?></button>
</form>
</div>

</div>
<script>
function spTtsMode() {
    var m = document.getElementById('tts_mode').value;
    document.getElementById('tts_audioserver_hint').style.display = (m === 'audioserver') ? 'block' : 'none';
    document.getElementById('tts_template_row').style.display = (m === 'ms4h' || m === 'custom') ? 'block' : 'none';
    var ra = document.getElementById('tts_alexa_row');
    if (ra) { ra.style.display = (m === 'alexang') ? 'block' : 'none'; }
    var rg = document.getElementById('tts_google_row');
    if (rg) { rg.style.display = (m === 'cc4lox') ? 'block' : 'none'; }
    var port = document.getElementsByName('tts_port')[0];
    if (m === 'musicserver' && (!port.value || port.value === '80')) { port.value = 7091; }
}
function spMarket() {
    var m = document.getElementById('market').value;
    var vat = document.getElementById('vat');
    if (m === 'at' && (vat.value === '19' || vat.value === '19.0')) { vat.value = '20'; }
    if (m === 'de' && (vat.value === '20' || vat.value === '20.0')) { vat.value = '19'; }
}
function spSum() {
    var sum = 0, gepflegt = false;
    document.querySelectorAll('.sm-mkwh').forEach(function (i) {
        var v = parseFloat(String(i.value).replace(',', '.'));
        if (!isNaN(v) && v > 0) { sum += v; gepflegt = true; }
    });
    var box = document.getElementById('sp_msum');
    var jahr = document.getElementById('consumption');
    var hint = document.getElementById('consumption_hint');
    /* Die Saetze kommen aus der Sprachdatei, nicht aus dem Skript. Bis
     * 1.2.19 standen sie hier fest auf Deutsch - in einer Oberflaeche, die
     * seit 1.1.2 zweisprachig ist. Auch die Zahlenformatierung: 'de-DE'
     * schreibt 3.500, im Englischen sind es 3,500. */
    if (gepflegt) {
        box.innerHTML = spTxt.summe.replace('%s',
            '<b>' + Math.round(sum).toLocaleString(spTxt.locale) + ' kWh</b>');
        jahr.value = Math.round(sum);
        jahr.readOnly = true;
        jahr.style.background = '#f0f0f0';
        hint.innerHTML = spTxt.aus_monaten;
    } else {
        box.innerHTML = spTxt.summe_leer;
        jahr.readOnly = false;
        jahr.style.background = '';
        hint.innerHTML = spTxt.ohne_monate;
    }
}
/* Die Saetze des Skripts, aus der Sprachdatei. json_encode maskiert
 * Anfuehrungszeichen und schliessende Skript-Marken selbst - eine
 * Zeichenkette, die von Hand zusammengesetzt wird, tut das nicht. */
var spTxt = <?= json_encode(array(
    'summe'       => spot_t('TEXT.JS_SUMME'),
    'summe_leer'  => spot_t('TEXT.JS_SUMME_LEER'),
    'aus_monaten' => spot_t('TEXT.JS_AUS_MONATEN'),
    'ohne_monate' => spot_t('TEXT.JS_OHNE_MONATE'),
    'locale'      => spot_t('TEXT.JS_LOCALE'),
), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function spHours(mode) {
    document.querySelectorAll('input[name="hours[]"]').forEach(function (c) {
        var h = parseInt(c.value, 10);
        c.checked = (mode === 1) ? true : (mode === 0 ? false : (h >= 7 && h <= 21));
    });
}
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    /* Wortgetreu wie die Vorlage (zeige): Reiter, Flaeche, die versteckten
     * activetab-Felder und die Adresszeile. */
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.ziel === id); });
        document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
        document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
        if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
    }
    /* Der Klick wird ABGEFANGEN - sonst schaltet das Skript die Flaeche um
     * UND der Anker laedt die Seite neu. Bis 1.2.19 war das so: jeder
     * Reiterklick lud die ganze Seite und nahm jede nicht gespeicherte
     * Eingabe mit.
     *
     * AUSSER BEIM REITER TEST. Dessen Inhalt wird nur gerendert, wenn er
     * angefragt ist - gemessen 104091 Byte gegen 101456 der uebrigen. Ein
     * blosses Umschalten zeigte dort eine leere Flaeche. Er behaelt deshalb
     * die Navigation.
     *
     * Das href bleibt bei ALLEN Reitern stehen: ohne Skript ist es der
     * einzige Weg, und ein Verweis auf einen Reiter soll sich weitergeben
     * lassen. Nur die Adresszeile wird nachgezogen, damit ein Neuladen
     * denselben Reiter zeigt. */
    tabs.forEach(function (t) {
        t.addEventListener('click', function (e) {
            if (t.dataset.ziel === 'tab-test') { return; }
            e.preventDefault();
            activate(t.dataset.ziel);
        });
    });
    activate(<?= json_encode($sp_tab) ?>);
    spTtsMode();
})();
</script>
<?php
/* O1: die Zeile "Passen Reiterleiste und Flaechen zusammen?" verbindet die
 * Pruefung der Namen (spot_selbsttest(), Quelltext) mit der Verschachtelung
 * der gerenderten Seite (spot_flaechen_schachtel()); die Zusammenfassung wird
 * danach gezaehlt. Die Platzhalter stehen nur bei offenem Reiter Test. */
$sp_seite = ob_get_clean();
if ($sp_pruefungen) {
    list($sp_sok, $sp_stext) = spot_flaechen_schachtel($sp_seite, $sp_reiter_ids);
    $sp_hz = 0; $sp_kz = 0; $sp_sz = 0; $sp_rz = null;
    foreach ($sp_pruefungen as $sp_z) {
        $sp_o = (int) $sp_z['ok'];
        if ($sp_z['schluessel'] === 'PRUEF.REITER') {
            $sp_o = ($sp_o === 0 || $sp_sok === 0) ? 0 : (($sp_o === 1 && $sp_sok === 1) ? 1 : 2);
            $sp_rz = array($sp_o, $sp_z['text'] . ' ' . $sp_stext);
        }
        if ($sp_o === 1) { $sp_hz++; } elseif ($sp_o === 0) { $sp_kz++; } else { $sp_sz++; }
    }
    $sp_ersatz = array(
        '<!--SP_KLASSE-->' => $sp_kz > 0 ? 'sm-err' : ($sp_sz > 0 ? 'sm-warn' : 'sm-ok'),
        '<!--SP_SUMME-->' => sprintf(sp_e(spot_t('PRUEF.ZUSAMMENFASSUNG')), $sp_hz, count($sp_pruefungen), $sp_kz, $sp_sz),
    );
    if ($sp_rz !== null) {
        $sp_ersatz['<!--SP_R_FARBE-->'] = $sp_rz[0] === 1 ? '#2e7d32' : ($sp_rz[0] === 0 ? '#c62828' : '#8d6e63');
        $sp_ersatz['<!--SP_R_ZEICHEN-->'] = $sp_rz[0] === 1 ? '&#10003;' : ($sp_rz[0] === 0 ? '&#10007;' : '&ndash;');
        $sp_ersatz['<!--SP_R_TEXT-->'] = sp_e($sp_rz[1]);
    }
    $sp_seite = strtr($sp_seite, $sp_ersatz);
}
echo $sp_seite;
if ($sp_frame) {
    LBWeb::lbfooter();
}
