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

$sp_lbhome = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
$sp_plugin = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
if ($sp_lbhome && is_dir($sp_lbhome . '/config/plugins/' . $sp_plugin) === false) {
    $sp_plugin = basename(dirname(__DIR__));
    if (is_dir($sp_lbhome . '/config/plugins/' . $sp_plugin) === false) {
        $sp_plugin = 'spotpreis';
    }
}
if ($sp_lbhome) {
    $sp_sdk = $sp_lbhome . '/libs/phplib/loxberry_system.php';
    if (file_exists($sp_sdk)) {
        require_once $sp_sdk;
        require_once $sp_lbhome . '/libs/phplib/loxberry_web.php';
    }
    $sp_cfgdir = $sp_lbhome . '/config/plugins/' . $sp_plugin;
    $sp_bkfile = $sp_lbhome . '/config/plugins/' . $sp_plugin . '.backup.json';
    $sp_logfile = $sp_lbhome . '/log/plugins/' . $sp_plugin . '/spot.log';
} else {
    $sp_cfgdir = dirname(dirname(__DIR__)) . '/config';
    $sp_bkfile = $sp_cfgdir . '/spot.backup.json';
    $sp_logfile = sys_get_temp_dir() . '/spotpreis/spot.log';
}
$sp_cfgfile = $sp_cfgdir . '/spot.json';

foreach (array(
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $sp_plugin . '/spot_lib.php',
    dirname(__DIR__) . '/html/spot_lib.php',
) as $sp_libcand) {
    if (is_file($sp_libcand)) {
        require_once $sp_libcand;
        break;
    }
}

if ((!is_file($sp_cfgfile) || trim((string) @file_get_contents($sp_cfgfile)) === '' || trim((string) @file_get_contents($sp_cfgfile)) === '{}') && is_file($sp_bkfile)) {
    @mkdir($sp_cfgdir, 0775, true);
    @copy($sp_bkfile, $sp_cfgfile);
}

$sp_saved = false;
$sp_err = '';
$sp_note = '';
/* Beanstandungen werden GESAMMELT, nicht ueberschrieben: prueft ein
 * Speichervorgang mehrere Felder, gehoeren alle Meldungen zusammen
 * ausgegeben. Eine einzelne Zuweisung verschluckt die vorherigen, und der
 * Benutzer korrigiert dann einen Fehler nach dem anderen. */
$sp_fehler = array();
/* Die erlaubten Werte des Fristfeldes: -1 fuer "keine Frist" und die
 * Stunden 0 bis 23. Als Text, weil das Formular Text liefert. */
$sp_stunden_wahl = array_merge(array('-1'), array_map('strval', range(0, 23)));
/* Ausgabe des Planer-Selbsttests. Er rechnet nur, spricht mit niemandem und
 * braucht keine Preise - deshalb ein einfacher Knopf ohne Nebenwirkung. */
$sp_plantest = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plantest'])) {
    list($sp_pt_n, $sp_pt_f, $sp_plantest) = plan_selbsttest();
    $sp_tab = 'tab-test';
}
// Der Reiter kommt aus einem abgesendeten Formular (activetab) oder aus der
// Adresse (?tab=...). Letzteres brauchen die Reiter, seit sie echte Verweise
// sind - siehe die Reiterleiste weiter unten.
/* EINE Quelle fuer Reihenfolge, Positivliste und Beschriftung. Die Namen
 * standen bis 1.1.1 an zwei Stellen: in diesem Muster und weiter unten im
 * Feld $sp_reiter; die Flaechen-ids kamen als dritte dazu. Wer einen Reiter
 * ergaenzt und eine davon vergisst, bekommt keinen Fehler, sondern eine
 * Seite, die nach jedem Absenden auf Einstellungen zurueckspringt - und
 * sucht den Grund an der falschen Stelle. Die Beschriftungen brauchen
 * spot_t() und kommen weiter unten dazu. */
$sp_reiter_ids = array('settings', 'loxone', 'costs', 'test', 'log');

$sp_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : (isset($_GET['tab']) ? 'tab-' . (string) $_GET['tab'] : '');
$sp_tab = preg_match('/^tab-(' . implode('|', $sp_reiter_ids) . ')$/', $sp_wunsch)
    ? $sp_wunsch : 'tab-' . $sp_reiter_ids[0];

// ---------- Loxone-Vorlage herunterladen ----------
// Vor jeder Ausgabe, sonst stehen HTML-Reste in der XML-Datei.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage']) && function_exists('spot_vorlage')) {
    list($sp_vname, $sp_vinhalt) = spot_vorlage();
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $sp_vname . '"');
    echo $sp_vinhalt;
    exit;
}

// ---------- Protokoll leeren ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($sp_logfile), 0775, true);
    @file_put_contents($sp_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n");
    $sp_tab = 'tab-log';
}

// ---------- Jetzt abrufen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetchnow']) && function_exists('spot_state')) {
    $sp_s = spot_state(true);
    // $sp_note geht durch sp_e() - hier gehoert Klartext hin, keine HTML-Entities.
    $sp_note = $sp_s['ok']
        ? sprintf(spot_t('TEXT.ABRUF_OK'), $sp_s['heute']['n'],
                  $sp_s['tomorrow_ok'] ? sprintf(spot_t('TEXT.ABRUF_STUNDEN'), $sp_s['morgen']['n'])
                                       : spot_t('TEXT.ABRUF_OFFEN'))
        : spot_t('TEXT.ABRUF_FEHL');
}

// ---------- Speichern ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_neu'])) {
    $sp_c = spot_config();
    $sp_c['token'] = spot_token_erzeugen();
    if (spot_config_save($sp_c)) { $sp_note = spot_t('TEXT.TOKEN_NEU'); }
    else { $sp_err = sprintf(spot_t('TEXT.SPEICHERN_FEHL'), 'spot.json'); }
    $sp_tab = 'tab-loxone';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_weg'])) {
    $sp_c = spot_config();
    $sp_c['token'] = '';
    if (spot_config_save($sp_c)) { $sp_note = spot_t('TEXT.TOKEN_WEG'); }
    else { $sp_err = sprintf(spot_t('TEXT.SPEICHERN_FEHL'), 'spot.json'); }
    $sp_tab = 'tab-loxone';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    function sp_f($k, $def) {
        $v = str_replace(',', '.', (string) (isset($_POST[$k]) ? $_POST[$k] : ''));
        return is_numeric($v) ? (float) $v : $def;
    }
    $sp_new = array();
    $sp_new['market'] = (isset($_POST['market']) && $_POST['market'] === 'at') ? 'at' : 'de';
    $sp_new['netz'] = max(0, min(50, sp_f('netz', 9.0)));
    $sp_new['steuer'] = max(0, min(20, sp_f('steuer', 2.05)));
    $sp_new['konzession'] = max(0, min(20, sp_f('konzession', 1.32)));
    $sp_new['umlagen'] = max(0, min(20, sp_f('umlagen', 2.945)));
    $sp_new['aufschlag'] = max(-10, min(30, sp_f('aufschlag', 0.0)));
    $sp_new['grundpreis'] = max(0, min(100, sp_f('grundpreis', 0.0)));
    $sp_new['vat'] = max(0, min(30, sp_f('vat', 19.0)));
    $sp_new['cheap'] = max(0, min(200, sp_f('cheap', 20.0)));
    $sp_new['expensive'] = max(0, min(400, sp_f('expensive', 35.0)));
    $sp_new['window'] = max(1, min(12, (int) (isset($_POST['window']) ? $_POST['window'] : 3)));
    $sp_pm = (string) (isset($_POST['profil_ein']) ? $_POST['profil_ein'] : 'absolut');
    $sp_new['profil_ein'] = in_array($sp_pm, array('aus', 'absolut', 'relativ', 'beides'), true) ? $sp_pm : 'absolut';
    // ---- Schaltregeln ----
    $sp_new['regeln'] = array();
    for ($sp_i = 0; $sp_i < SPOT_REGELN; $sp_i++) {
        $sp_g = function ($feld, $def = '') use ($sp_i) {
            $a = isset($_POST[$feld]) ? (array) $_POST[$feld] : array();
            return isset($a[$sp_i]) ? $a[$sp_i] : $def;
        };
        $sp_art = (string) $sp_g('r_art', 'fenster');
        $sp_new['regeln'][$sp_i] = array(
            'aktiv' => (int) $sp_g('r_aktiv', 0) ? 1 : 0,
            // Der Name landet im Kommentar der Loxone-Vorlage - deshalb nur
            // Steuerzeichen und Anfuehrungszeichen raus, nicht hart filtern.
            'name' => trim(preg_replace('/[\x00-\x1F\x7F"]/', '', (string) $sp_g('r_name'))),
            'art' => in_array($sp_art, array('fenster', 'stunden', 'schwelle', 'mittel'), true) ? $sp_art : 'fenster',
            'n' => max(1, min(12, (int) $sp_g('r_n', 3))),
            'von' => max(0, min(23, (int) $sp_g('r_von', 0))),
            'bis' => max(0, min(23, (int) $sp_g('r_bis', 0))),
            'horizont' => max(1, min(48, (int) $sp_g('r_horizont', 24))),
            'schwelle' => max(-100, min(200, (float) str_replace(',', '.', (string) $sp_g('r_schwelle', 20)))),
            'prozent' => max(0, min(90, (int) $sp_g('r_prozent', 20))),
            'neg' => (int) $sp_g('r_neg', 0) ? 1 : 0,
            // ---- Fahrplaner ----
            'rang' => max(1, min(99, (int) $sp_g('r_rang', 50))),
            'leistung' => max(0, min(100, (float) str_replace(',', '.', (string) $sp_g('r_leistung', 0)))),
            'energie' => max(0, min(500, (float) str_replace(',', '.', (string) $sp_g('r_energie', 0)))),
            // -1 heisst "keine Frist". Das Auswahlfeld liefert -1 als Text.
            'frist' => (in_array((string) $sp_g('r_frist', '-1'), $sp_stunden_wahl, true))
                       ? (int) $sp_g('r_frist', -1) : -1,
            'pv_sperre' => max(0, min(500, (float) str_replace(',', '.', (string) $sp_g('r_pv_sperre', 0)))),
            'soc_min' => max(0, min(100, (int) $sp_g('r_soc_min', 0))),
            'soc_max' => max(0, min(100, (int) $sp_g('r_soc_max', 0))),
        );
        /* Ein Fenster, das laenger ist als die Frist erlaubt, ist ein
         * Widerspruch - und einer, den man beim Eintragen leicht macht
         * ("6 Stunden, fertig um 5 Uhr", eingestellt um 1 Uhr). Er wird
         * gemeldet statt still zurechtgebogen; der Planer nimmt dann,
         * was er kriegen kann, und das faellt sonst niemandem auf. */
        $sp_rr = $sp_new['regeln'][$sp_i];
        if ($sp_rr['aktiv'] && $sp_rr['frist'] >= 0 && $sp_rr['energie'] <= 0
            && $sp_rr['n'] > 24) {
            $sp_fehler[] = sprintf(spot_t('REGEL.FEHLER_FRIST'), $sp_i + 1);
        }
        if ($sp_rr['aktiv'] && $sp_rr['energie'] > 0 && $sp_rr['leistung'] <= 0) {
            $sp_fehler[] = sprintf(spot_t('REGEL.FEHLER_ENERGIE_OHNE_LEISTUNG'), $sp_i + 1);
        }
        if ($sp_rr['soc_min'] > 0 && $sp_rr['soc_max'] > 0
            && $sp_rr['soc_min'] >= $sp_rr['soc_max']) {
            $sp_fehler[] = sprintf(spot_t('REGEL.FEHLER_SOC_REIHE'), $sp_i + 1);
        }
    }
    // ---- Fahrplaner, global ----
    $sp_new['budget_kw'] = max(0, min(200, (float) str_replace(',', '.', (string) (isset($_POST['budget_kw']) ? $_POST['budget_kw'] : 0))));
    $sp_new['pv_bonus'] = max(0, min(100, (float) str_replace(',', '.', (string) (isset($_POST['pv_bonus']) ? $_POST['pv_bonus'] : 0))));
    $sp_new['pv_schwelle'] = max(1, min(100000, (int) (isset($_POST['pv_schwelle']) ? $_POST['pv_schwelle'] : 500)));
    $sp_q = (string) (isset($_POST['pv_quelle']) ? $_POST['pv_quelle'] : '');
    $sp_new['pv_quelle'] = in_array($sp_q, array('', 'forecast_solar', 'objekt', 'liste'), true) ? $sp_q : '';
    $sp_e2 = (string) (isset($_POST['pv_einheit']) ? $_POST['pv_einheit'] : 'wh');
    $sp_new['pv_einheit'] = in_array($sp_e2, array('wh', 'w', 'kw'), true) ? $sp_e2 : 'wh';
    foreach (array('pv_url', 'pv_pfad', 'pv_zeitfeld', 'pv_wertfeld', 'soc_url', 'soc_pfad') as $sp_f2) {
        // Nur Steuerzeichen und Anfuehrungszeichen raus. Ein hartes Filtern
        // auf eine Positivliste zerstoert eingefuegte Adressen - belegt am
        // ACTi-Plugin am 26.07.2026.
        $sp_new[$sp_f2] = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
            (string) (isset($_POST[$sp_f2]) ? $_POST[$sp_f2] : '')));
    }
    foreach (array('pv_url', 'soc_url') as $sp_f2) {
        if ($sp_new[$sp_f2] !== '' && !preg_match('#^https?://#i', $sp_new[$sp_f2])) {
            $sp_fehler[] = sprintf(spot_t('PLAN.FEHLER_URL'), spot_t('PLAN.L_' . strtoupper($sp_f2)));
        }
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
    $sp_new['wp_name'] = trim((string) (isset($_POST['wp_name']) ? $_POST['wp_name'] : '')) !== '' ? trim((string) $_POST['wp_name']) : 'Wärmepumpe';
    $sp_new['wp_netz'] = max(0, min(50, sp_f('wp_netz', 3.43)));
    $sp_new['wp_konzession'] = max(0, min(20, sp_f('wp_konzession', 0.61)));
    $sp_new['co2_enabled'] = isset($_POST['co2_enabled']) ? 1 : 0;
    $sp_new['co2_clean'] = max(0, min(1000, sp_f('co2_clean', 200)));
    $sp_new['fixed_price'] = max(0, min(200, sp_f('fixed_price', 30.90)));
    $sp_new['fix_grund'] = max(0, min(500, sp_f('fix_grund', 12.90)));
    $sp_new['fix_sofortbonus'] = max(0, min(5000, sp_f('fix_sofortbonus', 0)));
    $sp_new['fix_neubonus'] = max(0, min(5000, sp_f('fix_neubonus', 0)));
    $sp_new['fix_neubonus_pct'] = max(0, min(100, sp_f('fix_neubonus_pct', 0)));
    $sp_new['fix_rabatt'] = max(0, min(100, sp_f('fix_rabatt', 0)));
    // Monatsverbraeuche: sobald mindestens einer gepflegt ist, ergibt ihre Summe
    // den Jahresverbrauch (PV-Haushalte: Sommer wenig, Winter viel Zukauf)
    $sp_new['months'] = array();
    $sp_msum = 0;
    $sp_min = isset($_POST['months']) ? (array) $_POST['months'] : array();
    for ($sp_i = 0; $sp_i < 12; $sp_i++) {
        $sp_v = str_replace(',', '.', (string) (isset($sp_min[$sp_i]) ? $sp_min[$sp_i] : ''));
        $sp_v = is_numeric($sp_v) ? max(0, min(20000, (float) $sp_v)) : 0.0;
        $sp_new['months'][$sp_i] = round($sp_v, 1);
        $sp_msum += $sp_v;
    }
    $sp_new['consumption'] = $sp_msum > 0
        ? (int) round($sp_msum)
        : max(100, min(100000, (int) (isset($_POST['consumption']) ? $_POST['consumption'] : 3500)));
    $sp_new['shift_kwh'] = max(0, min(100, sp_f('shift_kwh', 3.0)));
    $sp_new['marstek_enabled'] = isset($_POST['marstek_enabled']) ? 1 : 0;
    /* Leer heisst "automatisch die eigene LoxBerry-Adresse". Alles andere
     * muss eine http- oder https-Adresse sein: file:// und php://filter
     * wuerden sonst beliebige Dateien in das Protokoll holen (nachgemessen,
     * siehe spot_url_ok). */
    $sp_murl = trim((string) (isset($_POST['marstek_url']) ? $_POST['marstek_url'] : ''));
    if ($sp_murl !== '' && !spot_url_ok($sp_murl)) {
        $sp_err = spot_t('TEXT.MARSTEK_URL_FEHL');
        // Den bisher gespeicherten Wert behalten, statt ihn durch eine
        // abgewiesene Eingabe zu ersetzen. $sp_cfg wird erst weiter unten
        // gefuellt, deshalb hier unmittelbar nachsehen.
        $sp_alt = function_exists('spot_config') ? spot_config() : array();
        $sp_murl = isset($sp_alt['marstek_url']) ? trim((string) $sp_alt['marstek_url']) : '';
        if ($sp_murl !== '' && !spot_url_ok($sp_murl)) { $sp_murl = ''; }
    }
    $sp_new['marstek_url'] = $sp_murl;
    $sp_new['marstek_hours'] = max(1, min(12, (int) (isset($_POST['marstek_hours']) ? $_POST['marstek_hours'] : 4)));
    $sp_new['marstek_power'] = max(100, min(10000, (int) (isset($_POST['marstek_power']) ? $_POST['marstek_power'] : 2500)));
    $sp_new['marstek_neg'] = isset($_POST['marstek_neg']) ? 1 : 0;
    $sp_new['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    $sp_new['mqtt_topic'] = preg_replace('#[^\w/\-]#', '', (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : 'spot_awattar')) ?: 'spot_awattar';
    $sp_hours = array();
    foreach ((array) (isset($_POST['hours']) ? $_POST['hours'] : array()) as $sp_h) {
        $sp_h = (int) $sp_h;
        if ($sp_h >= 0 && $sp_h <= 23) {
            $sp_hours[] = $sp_h;
        }
    }
    sort($sp_hours);
    $sp_new['notify'] = array(
        'audio' => isset($_POST['notify_audio']) ? 1 : 0,
        'push' => isset($_POST['notify_push']) ? 1 : 0,
        'hours' => $sp_hours,
        'only_cheap' => isset($_POST['only_cheap']) ? 1 : 0,
        'negative' => isset($_POST['neg_always']) ? 1 : 0,
        'tomorrow' => isset($_POST['notify_tomorrow']) ? 1 : 0,
    );
    $sp_mode = (string) (isset($_POST['tts_mode']) ? $_POST['tts_mode'] : 'musicserver');
    $sp_new['tts'] = array(
        'mode' => in_array($sp_mode, array('musicserver', 'ms4h', 'audioserver', 'custom'), true) ? $sp_mode : 'musicserver',
        'ip' => trim((string) (isset($_POST['tts_ip']) ? $_POST['tts_ip'] : '')),
        'port' => max(1, min(65535, (int) (isset($_POST['tts_port']) ? $_POST['tts_port'] : 7091))),
        'zones' => trim((string) (isset($_POST['tts_zones']) ? $_POST['tts_zones'] : '1')),
        'volume' => max(1, min(100, (int) (isset($_POST['tts_volume']) ? $_POST['tts_volume'] : 8))),
        'lang' => preg_replace('/[^a-z]/', '', strtolower((string) (isset($_POST['tts_lang']) ? $_POST['tts_lang'] : 'de'))) ?: 'de',
        'template' => trim((string) (isset($_POST['tts_template']) ? $_POST['tts_template'] : '')),
    );
    // Das Token gehoert nicht ins Formular - es wird ueber eigene Knoepfe
    // im Reiter Loxone gesetzt. Ohne diese Zeile loeschte jedes Speichern
    // der Einstellungen das Token.
    $sp_alt2 = function_exists('spot_config') ? spot_config() : array();
    $sp_new['token'] = isset($sp_alt2['token']) ? (string) $sp_alt2['token'] : '';

    // Unteilbar schreiben, Sicherungskopie anlegen, Zwischenspeicher leeren -
    // alles in spot_config_save().
    if ($sp_fehler) {
        // Nichts schreiben, solange etwas beanstandet ist - sonst stuende
        // die Haelfte der Eingabe in der Datei und die andere nicht.
        $sp_err = implode(' | ', $sp_fehler);
    } elseif (spot_config_save($sp_new)) {
        $sp_saved = true;
    } else {
        $sp_err = sprintf(spot_t('TEXT.SPEICHERN_FEHL'), $sp_cfgfile);
    }
}

// ---------- Laden ----------
$sp_cfg = function_exists('spot_config') ? spot_config() : array();
if (!is_array($sp_cfg)) { $sp_cfg = array(); }
$sp_cfg += array('market' => 'de', 'netz' => 6.47, 'steuer' => 2.05, 'konzession' => 2.39, 'umlagen' => 2.945,
    'aufschlag' => 0.0, 'grundpreis' => 5.27, 'vat' => 19.0, 'cheap' => 20.0, 'expensive' => 35.0, 'window' => 3,
    'wp_enabled' => 0, 'wp_name' => 'Wärmepumpe', 'wp_netz' => 3.43, 'wp_konzession' => 0.61,
    'co2_enabled' => 1, 'co2_clean' => 200, 'fixed_price' => 30.90, 'consumption' => 3500,
    'months' => array(), 'shift_kwh' => 3.0,
    'marstek_enabled' => 0, 'marstek_url' => '', 'token' => '',
    'marstek_hours' => 4, 'marstek_power' => 2500, 'marstek_neg' => 1,
    'mqtt_enabled' => 0, 'mqtt_topic' => 'spot_awattar', 'notify' => array(), 'tts' => array());
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

function sp_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
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
        $svg .= '<rect x="' . round($x + 1, 1) . '" y="' . round($top, 1) . '" width="' . round(max(1, $bw - 2), 1) . '" height="' . round($hh, 1) . '" fill="' . $col . '"><title>' . $r[1] . ' Uhr (' . $r[0] . '): ' . number_format($r[2], 2) . ' ct</title></rect>';
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
$sp_token = isset($sp_cfg['token']) ? (string) $sp_cfg['token'] : '';
$sp_tk  = $sp_token !== '' ? '?token=' . rawurlencode($sp_token) : '';   // erster Parameter
$sp_tk2 = $sp_token !== '' ? '&amp;token=' . rawurlencode($sp_token) : ''; // weiterer Parameter
$sp_frame = class_exists('LBWeb', false);
if ($sp_frame) {
    LBWeb::lbheader('Spotpreis aWATTar', 'https://wiki.loxberry.de/', '');
}
$sp_host = sp_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');
$sp_addon = (float) $sp_cfg['netz'] + (float) $sp_cfg['steuer'] + (float) $sp_cfg['konzession'] + (float) $sp_cfg['umlagen'] + (float) $sp_cfg['aufschlag'];
?>
<style>
.sm-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select, .sm-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 150px; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-warn { background: #fff8e1; border: 1px solid #ffe082; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; text-shadow: none !important; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }
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
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button { text-shadow: none !important; box-shadow: none !important; }
.sm-wrap a.sm-btn, .sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover { color: #fff !important; text-decoration: none; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Standard aller Plugins) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; text-shadow: none !important; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-btn.sm-b-lesen   { background: #6dac20; }
.sm-btn.sm-b-technik { background: #546e7a; }
.sm-btn.sm-b-aktion  { background: #e0620d; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
</style>
<div class="sm-wrap">

<?php if ($sp_saved) { ?><div class="sm-alert sm-ok"><b><?php echo spot_t('TEXT.KONFIGURATION_GESPEICHERT'); ?></b> <?php echo spot_t('TEXT.INKL_SICHERUNGSKOPIE_FR_UPDATES'); ?></div><?php } ?>
<?php if ($sp_note !== '') { ?><div class="sm-alert sm-ok"><?= sp_e($sp_note) ?></div><?php } ?>
<?php if ($sp_err !== '') { ?><div class="sm-alert sm-err"><b><?php echo spot_t('TEXT.FEHLER'); ?></b> <?= sp_e($sp_err) ?></div><?php } ?>

<?php if (!empty($sp_st)) { ?>
<div class="sm-alert sm-info">
<?php if ($sp_st['ok']) { ?>
<b><?php echo spot_t('TEXT.JETZT'); ?><?= (int) $sp_st['stunde'] ?> <?php echo spot_t('TEXT.UHR'); ?> <?= sp_n($sp_st['cur'], 2) ?> <?php echo spot_t('TEXT.CT_KWH'); ?></b>
<?php echo spot_t('TEXT.DAVON_BRSE'); ?> <?= sp_n($sp_st['cur_boerse'], 2) ?> <?php echo spot_t('TEXT.CT_NCHSTE_STUNDE'); ?> <?= sp_n($sp_st['next'], 2) ?> <?php echo spot_t('TEXT.CT_RANG'); ?> <?= (int) $sp_st['rank'] ?> <?php echo spot_t('TEXT.VON'); ?> <?= (int) $sp_st['n'] ?> <?php echo spot_t('TEXT.NIVEAU'); ?> <?= $sp_st['level'] == 1 ? '<b>' . spot_t('TEXT.GNSTIG') . '</b>'
      : ($sp_st['level'] == 3 ? '<b>' . spot_t('TEXT.TEUER') . '</b>' : spot_t('TEXT.NORMAL')) ?>
<?= $sp_st['neg'] ? ' &middot; <b>' . spot_t('TEXT.BRSENPREIS_NEGATIV') . '</b>' : '' ?><br>
<?php echo spot_t('TEXT.HEUTE_MIN'); ?> <?= sp_n($sp_st['heute']['minp'], 2) ?> ct um <?= (int) $sp_st['heute']['minh'] ?> <?php echo spot_t('TEXT.UHR_MAX'); ?> <?= sp_n($sp_st['heute']['maxp'], 2) ?> ct um <?= (int) $sp_st['heute']['maxh'] ?> <?php echo spot_t('TEXT.UHR_SCHNITT'); ?> <?= sp_n($sp_st['heute']['avg'], 2) ?> ct
<?php if ($sp_st['tomorrow_ok']) { ?><br><?php echo spot_t('TEXT.MORGEN_MIN'); ?> <?= sp_n($sp_st['morgen']['minp'], 2) ?> ct um <?= (int) $sp_st['morgen']['minh'] ?> <?php echo spot_t('TEXT.UHR_3'); ?> &middot;
<?php echo spot_t('TEXT.MAX'); ?> <?= sp_n($sp_st['morgen']['maxp'], 2) ?> ct um <?= (int) $sp_st['morgen']['maxh'] ?> <?php echo spot_t('TEXT.UHR_3'); ?> &middot;
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
    'settings' => 'REITER.EINSTELLUNGEN', 'loxone' => 'REITER.LOXONE',
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
<div class="sm-tabs">
<?php foreach ($sp_reiter as $sp_id => $sp_bez) { ?>
    <a class="sm-tab<?php echo $sp_tab === $sp_id ? ' sm-active' : ''; ?>"
       data-pane="<?php echo sp_e($sp_id); ?>"
       href="index.php?tab=<?php echo sp_e(substr($sp_id, 4)); ?>"><?php echo $sp_bez; ?></a>
<?php } ?>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-pane<?php echo $sp_tab === 'tab-settings' ? ' sm-active' : ''; ?>" id="tab-settings">
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo spot_t('TEXT.MARKT'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo spot_t('TEXT.PREISZONE_API'); ?></label>
        <select data-role="none" name="market" id="market" onchange="spMarket()">
            <option value="de"<?= $sp_cfg['market'] === 'de' ? ' selected' : '' ?><?php echo spot_t('TEXT.DEUTSCHLAND_API_AWATTAR_DE'); ?></option>
            <option value="at"<?= $sp_cfg['market'] === 'at' ? ' selected' : '' ?><?php echo spot_t('TEXT.OUML_STERREICH_API_AWATTAR_AT'); ?></option>
        </select>
        <div class="sm-small"><?php echo spot_t('TEXT.QUELLE_OFFENE_AWATTAR_API_EPEX_SPO'); ?></div>
    </div>
</div>

<h2><?php echo spot_t('TEXT.PREISZUSAMMENSETZUNG_ENDPREIS'); ?></h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo spot_t('TEXT.ALLE_ANGABEN_IN'); ?> <b><?php echo spot_t('TEXT.CT_KWH_NETTO'); ?></b><?php echo spot_t('TEXT.ENDPREIS_BRSENPREIS_SUMME_DER_AUFS'); ?></div>
<div class="sm-row">
    <div>
        <label><?php echo spot_t('TEXT.NETZENTGELTE'); ?></label>
        <input data-role="none" type="text" name="netz" value="<?= sp_e($sp_cfg['netz']) ?>" placeholder="9.0">
        <div class="sm-small"><?php echo spot_t('TEXT.INKL_MESSSTELLENBETRIEB_STARK_REGI'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.STROMSTEUER'); ?></label>
        <input data-role="none" type="text" name="s<?php echo spot_t('TEXT.TEUER'); ?>" value="<?= sp_e($sp_cfg['steuer']) ?>" placeholder="2.05">
        <div class="sm-small"><?php echo spot_t('TEXT.DE_2_05_AT_ELEKTRIZITTSABGABE_1_50'); ?></div>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.KONZESSIONSABGABE'); ?></label>
        <input data-role="none" type="text" name="konzession" value="<?= sp_e($sp_cfg['konzession']) ?>" placeholder="1.32">
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
        <input data-role="none" type="text" name="grundpreis" value="<?= sp_e($sp_cfg['grundpreis']) ?>" placeholder="0">
        <div class="sm-small"><?php echo spot_t('TEXT.NETZ_GRUNDPREIS_MESSSTELLENBETRIEB'); ?></div>
    </div>
</div>
<div class="sm-alert sm-ok" style="margin-top:10px;"><?php echo spot_t('TEXT.AKTUELLE_SUMME_DER_AUFSCHLGE'); ?> <b><?= sp_n($sp_addon, 3) ?> ct/kWh netto</b>
<?php echo spot_t('TEXT.BEI_EINEM_BRSENPREIS_VON_8_00_CT_E'); ?> <b><?= sp_n((8 + $sp_addon) * (1 + (float) $sp_cfg['vat'] / 100), 2) ?> ct/kWh</b> <?php echo spot_t('TEXT.ENDPREIS'); ?></div>

<h2><?php echo spot_t('TEXT.ZWEITER_PREISSATZ_STEUERBARE_VERBR'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="wp_enabled" <?= !empty($sp_cfg['wp_enabled']) ? 'checked' : '' ?><?php echo spot_t('TEXT.ZWEITEN_PREISSATZ_BERECHNEN_UND_AU'); ?>
</label>
<div class="sm-small"><?php echo spot_t('TEXT.FR_WRMEPUMPE_ODER_WALLBOX_MIT'); ?> <b><?php echo spot_t('TEXT.EIGENEM_ZHLPUNKT'); ?></b> <?php echo spot_t('TEXT.NACH_14A_ENWG_MODUL_1_REDUZIERTES_'); ?><span class="sm-mono"><?php echo spot_t('TEXT.WPCUR'); ?></span>/<span class="sm-mono"><?php echo spot_t('TEXT.WPNEXT'); ?></span><?php echo spot_t('TEXT.IDEAL_UM_WRMEPUMPE_UND_HAUSHALT_GE'); ?></div>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label><?php echo spot_t('TEXT.BEZEICHNUNG'); ?></label>
        <input data-role="none" type="text" name="wp_name" value="<?= sp_e($sp_cfg['wp_name']) ?>" placeholder="Wärmepumpe">
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
    <input data-role="none" type="checkbox" name="co2_enabled" <?= !empty($sp_cfg['co2_enabled']) ? 'checked' : '' ?><?php echo spot_t('TEXT.CO_8322_WERTE_ABRUFEN_FRAUNHOFER_I'); ?>
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
        <input data-role="none" type="text" class="sm-mkwh" name="months[]" value="<?= $sp_mon['kwh'][$sp_i] > 0 ? sp_e(rtrim(rtrim(number_format($sp_mon['kwh'][$sp_i], 1, '.', ''), '0'), '.')) : '' ?>" placeholder="<?php echo spot_t('TEXT.TEXT_8'); ?>" oninput="spSum()">
    </div>
<?php } ?>
</div>
<div class="sm-alert sm-ok" id="sp_msum" style="margin-top:6px;"><?php echo spot_t('TEXT.SUMME_DER_MONATSWERTE'); ?> <b><?= $sp_mon['use'] ? sp_n($sp_mon['summe'], 0) . ' kWh' : 'noch keine Werte gepflegt' ?></b><?= $sp_mon['use'] ? ' &mdash; dieser Wert wird als Jahresverbrauch gespeichert.' : '' ?></div>
<div class="sm-small"><?php echo spot_t('TEXT.DER_MONATSVERGLEICH_WIRD'); ?> <b><?php echo spot_t('TEXT.LASTPROFIL_GEWICHTET'); ?></b> <?php echo spot_t('TEXT.GERECHNET_HAUSHALTS_PROFIL_EIN_EIN'); ?> <b>Test</b><?php echo spot_t('TEXT.IM_PROTOKOLL_UND_AM_MONATSERSTEN_A'); ?></div>

<h2><?php echo spot_t('TEXT.KOPPLUNG_MIT_DEM_MARSTEK_SPEICHER_'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="marstek_enabled" <?= !empty($sp_cfg['marstek_enabled']) ? 'checked' : '' ?><?php echo spot_t('TEXT.SPEICHER_IN_DEN_GNSTIGSTEN_STUNDEN'); ?>
</label>
<div class="sm-alert sm-info" style="margin-top:6px;"><?php echo spot_t('TEXT.NUR_EINSCHALTEN_WENN_DIE_RANG_LOGI'); ?> <b><?php echo spot_t('TEXT.NICHT'); ?></b> <?php echo spot_t('TEXT.IN_LOXONE_GEBAUT_IST_SONST_BERSCHR'); ?> <span class="sm-mono"><?php echo spot_t('TEXT.RANK'); ?></span> <?php echo spot_t('TEXT.AUS_SCHRITT_2'); ?></div>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label><?php echo spot_t('TEXT.ENDPUNKT_DES_MARSTEK_PLUGINS'); ?></label>
        <input data-role="none" type="text" name="marstek_url" value="<?= sp_e($sp_cfg['marstek_url']) ?>" placeholder="<?= sp_e($sp_ownurl) ?>">
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
            <input data-role="none" type="checkbox" name="marstek_neg" <?= !empty($sp_cfg['marstek_neg']) ? 'checked' : '' ?><?php echo spot_t('TEXT.BEI_NEGATIVEM_PREIS_IMMER_LADEN'); ?>
        </label>
    </div>
</div>

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
<?php
$sp_umw = spot_umwelt();
if ($sp_cfg['pv_quelle'] !== '' || $sp_cfg['soc_url'] !== '') { ?>
<div class="sm-alert <?= (!empty($sp_umw['pv_meldung']) || !empty($sp_umw['soc_meldung'])) ? 'sm-err' : 'sm-info' ?>">
  <?= sprintf(sp_e(spot_t('PLAN.STAND')),
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
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($sp_notify['audio']) ? 'checked' : '' ?><?php echo spot_t('TEXT.AUDIOAUSGABE_AKTIV'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($sp_notify['push']) ? 'checked' : '' ?><?php echo spot_t('TEXT.PUSH_NACHRICHT_AKTIV'); ?>
    </label>
    <div class="sm-small"><?php echo spot_t('TEXT.BEIDES_AN_ANSAGE_PUSH_NUR_EINES_AN'); ?> <span class="sm-mono"><?php echo spot_t('TEXT.ANN_1'); ?></span> <?php echo spot_t('TEXT.ANLEITUNG_SCHRITT_4'); ?></div>
</div>
<div class="sm-small"><b><?php echo spot_t('TEXT.STUNDEN_AUSWHLEN'); ?></b><?php echo spot_t('TEXT.ZU_DENEN_DIE_PREISANSAGE_KOMMEN_SO'); ?></div>
<div class="sm-hours">
<?php for ($sp_h = 0; $sp_h < 24; $sp_h++) { ?>
    <label><input data-role="none" type="checkbox" name="hours[]" value="<?= $sp_h ?>" <?= in_array($sp_h, $sp_hoursel, true) ? 'checked' : '' ?>> <?= sprintf('%02d', $sp_h) ?> <?php echo spot_t('TEXT.UHR_3'); ?></label>
<?php } ?>
</div>
<div style="margin-top:6px;">
    <button data-role="none" type="button" class="sm-btn" style="margin-top:4px;padding:6px 14px;font-size:0.85em;background:#607d8b;" onclick="spHours(1)"><?php echo spot_t('TEXT.ALLE'); ?></button>
    <button data-role="none" type="button" class="sm-btn" style="margin-top:4px;padding:6px 14px;font-size:0.85em;background:#607d8b;" onclick="spHours(0)"><?php echo spot_t('TEXT.KEINE'); ?></button>
    <button data-role="none" type="button" class="sm-btn" style="margin-top:4px;padding:6px 14px;font-size:0.85em;background:#607d8b;" onclick="spHours(2)"><?php echo spot_t('TEXT.NUR_TAGSBER_721'); ?></button>
</div>
<div style="margin-top:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="only_cheap" <?= !empty($sp_notify['only_cheap']) ? 'checked' : '' ?><?php echo spot_t('TEXT.NUR_MELDEN_WENN_DER_PREIS_UNTER_DE'); ?>
    </label><br>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="neg_always" <?= !empty($sp_notify['negative']) ? 'checked' : '' ?><?php echo spot_t('TEXT.BEI_NEGATIVEM_BRSENPREIS_IMMER_MEL'); ?>
    </label><br>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_tomorrow" <?= !empty($sp_notify['tomorrow']) ? 'checked' : '' ?><?php echo spot_t('TEXT.EINMAL_TGLICH_MELDEN_SOBALD_DIE_PR'); ?>
    </label>
</div>

<h2><?php echo spot_t('TEXT.SPRACHAUSGABE'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo spot_t('TEXT.AUDIO_AUSGABE'); ?></label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="spTtsMode()">
            <option value="musicserver"<?= $sp_tts['mode'] === 'musicserver' ? ' selected' : '' ?><?php echo spot_t('TEXT.LOXONE_MUSIC_SERVER_KLASSISCH'); ?></option>
            <option value="ms4h"<?= $sp_tts['mode'] === 'ms4h' ? ' selected' : '' ?><?php echo spot_t('TEXT.AUDIOSERVER4HOME_MUSICSERVER4HOME'); ?></option>
            <option value="audioserver"<?= $sp_tts['mode'] === 'audioserver' ? ' selected' : '' ?><?php echo spot_t('TEXT.ORIGINAL_LOXONE_AUDIOSERVER_VIA_LO'); ?></option>
            <option value="custom"<?= $sp_tts['mode'] === 'custom' ? ' selected' : '' ?><?php echo spot_t('TEXT.EIGENE_URL_VORLAGE'); ?></option>
        </select>
    </div>
    <div>
        <label><?php echo spot_t('TEXT.IP_DES_AUDIO_SERVERS'); ?></label>
        <input data-role="none" type="text" name="tts_ip" value="<?= sp_e($sp_tts['ip']) ?>" placeholder="z. B. 192.168.1.20">
    </div>
    <div>
        <label><?php echo spot_t('TEXT.PORT'); ?></label>
        <input data-role="none" type="number" name="tts_port" value="<?= (int) $sp_tts['port'] ?>" min="1" max="65535">
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?php echo spot_t('TEXT.ZONEN'); ?></label>
        <input data-role="none" type="text" name="tts_zones" value="<?= sp_e($sp_tts['zones']) ?>" placeholder="z. B. 2,4,6">
        <div class="sm-small"><?php echo spot_t('TEXT.ZONENNUMMERN_MIT_KOMMA_Z_B'); ?> <span class="sm-mono">2,4,6</span><?php echo spot_t('TEXT.DIE_LAUTSTRKE_KOMMT_AUS_DEM_FELD_D'); ?> <span class="sm-mono"><?php echo spot_t('TEXT.ZONE_LAUTSTRKE'); ?></span> <?php echo spot_t('TEXT.Z_B'); ?> <span class="sm-mono">2~25,4~40</span><?php echo spot_t('TEXT.LEERZEICHEN_NACH_DEM_KOMMA_SIND_ER'); ?> <span class="sm-mono">2,4,6</span> und <span class="sm-mono">2, 4, 6</span> <?php echo spot_t('TEXT.FUNKTIONIEREN_BEIDE'); ?></div>
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

<h2><?php echo spot_t('TEXT.MQTT_OPTIONAL'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($sp_cfg['mqtt_enabled']) ? 'checked' : '' ?><?php echo spot_t('TEXT.PREISE_PER_MQTT_VERFFENTLICHEN'); ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label><?php echo spot_t('TEXT.TOPIC_PRFIX'); ?></label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= sp_e($sp_cfg['mqtt_topic']) ?>" placeholder="spot_awattar">
        <div class="sm-small"><?php echo spot_t('TEXT.NUTZT_DAS'); ?> <b><?php echo spot_t('TEXT.LOXBERRY_MQTT_GATEWAY'); ?></b><?php echo spot_t('TEXT.VERFFENTLICHT_BEI_AUML_NDERUNG_UND'); ?> <b><?php echo spot_t('TEXT.ALLES_WAS_AUCH_DER_HTTP_ENDPUNKT_L'); ?></b><?php echo spot_t('TEXT.SODASS_DIE_LOXONE_KONFIGURATION_GA'); ?><br>
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
        <?php echo spot_t('TEXT.DAS_MELDEFENSTER'); ?> <span class="sm-mono">/ann</span> <?php echo spot_t('TEXT.WIRD_SOFORT_VERFFENTLICHT_WENN_ES_'); ?></div>
    </div>
</div>

<button data-role="none" class="sm-btn" type="submit"><?php echo spot_t('TEXT.SPEICHERN'); ?></button>
</form>
<form action="index.php" method="post" style="margin-top:8px;">
    <input data-role="none" type="hidden" name="fetchnow" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn" type="submit" style="background:#607d8b;margin-top:0;"><?php echo spot_t('TEXT.JETZT_ABRUFEN'); ?></button>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-pane<?php echo $sp_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">
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
  <input data-role="none" type="hidden" name="vorlage" value="1">
  <button data-role="none" class="sm-btn" type="submit" style="background:#546e7a;"><?php echo spot_t('REGEL.K_VORLAGE'); ?></button>
</form>

<h2><?php echo spot_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC'); ?></h2>
<p><?php echo spot_t('TEXT.DER_MINISERVER_FRAGT_DAS_PLUGIN_AL'); ?> <b><?php echo spot_t('TEXT.ENDPREISE'); ?></b>
<?php echo spot_t('TEXT.INKL_NETZENTGELTE_ABGABEN_UND_UMSA'); ?> <b><?php echo spot_t('TEXT.ANSAGE'); ?></b> <?php echo spot_t('TEXT.SPRICHT_DAS_PLUGIN_SELBST_DEN'); ?> <b><?php echo spot_t('TEXT.PUSH_2'); ?></b> <?php echo spot_t('TEXT.VERSCHICKT_DER_MINISERVER'); ?></p>

<div class="sm-step"><b><?php echo spot_t('TEXT.SCHRITT_1_VIRTUELLER_HTTP_EINGANG_'); ?></b> <?php echo spot_t('TEXT.ABFRAGE_ALLE_300_S'); ?>
<table class="sm-tbl">
<tr><th><?php echo spot_t('TEXT.EIGENSCHAFT'); ?></th><th><?php echo spot_t('TEXT.WERT'); ?></th></tr>
<tr><td>URL</td><td><span class="sm-mono">http://<?= $sp_host ?><?php echo spot_t('TEXT.PLUGINS'); ?><?= sp_e($sp_plugin) ?><?php echo spot_t('TEXT.SPOT_PHP'); ?></span></td></tr>
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
</table>
<span class="sm-small"><?php echo spot_t('TEXT.ALLE_PREISE_SIND'); ?> <b><?php echo spot_t('TEXT.CT_KWH_ALS_ENDPREIS'); ?></b><?php echo spot_t('TEXT.WER_DIE_ALTEN_EUR_WERTE_GEWOHNT_IS'); ?> <span class="sm-mono">&lt;v.1&gt; ct</span> <?php echo spot_t('TEXT.STELLEN'); ?></span>
</div>

<div class="sm-step"><b><?php echo spot_t('TEXT.SCHRITT_3_KACHELN_FR_DIE_APP'); ?></b><br>
<?php echo spot_t('TEXT.CUR_NEXT_HAVG_MINP_MAXP_ALS_ANALOG'); ?> <span class="sm-mono">&lt;v.1&gt; ct</span><?php echo spot_t('TEXT.MINH_MAXH_WINH_MIT'); ?> <span class="sm-mono"><?php echo spot_t('TEXT.V_0_UHR'); ?></span><?php echo spot_t('TEXT.RANK_OHNE_EINHEIT_VISUALISIERUNG_F'); ?>
</div>

<div class="sm-step"><b><?php echo spot_t('TEXT.SCHRITT_4_KOMPLETTE_BAUSTEIN_LISTE'); ?></b><br>
<b><?php echo spot_t('TEXT.4A_STUNDENANSAGE_PUSH'); ?></b> <?php echo spot_t('TEXT.DIE_ANSAGE_SELBST_SPRICHT_DAS_PLUG'); ?>
<table class="sm-tbl">
<tr><th><?php echo spot_t('TEXT.BAUSTEIN'); ?></th><th><?php echo spot_t('TEXT.NAME'); ?></th><th><?php echo spot_t('TEXT.EINSTELLUNG'); ?></th><th><?php echo spot_t('TEXT.EINGNGE'); ?></th></tr>
<tr><td><?php echo spot_t('TEXT.SCHWELLWERTSCHALTER_S1'); ?></td><td><?php echo spot_t('TEXT.MELDEFENSTER_AKTIV'); ?></td><td><?php echo spot_t('TEXT.EIN_0_5_AUS_0_4'); ?></td><td><?php echo spot_t('TEXT.ANN_2'); ?></td></tr>
<tr><td><?php echo spot_t('TEXT.SCHWELLWERTSCHALTER_S2'); ?></td><td><?php echo spot_t('TEXT.PUSH_FREIGEGEBEN'); ?></td><td><?php echo spot_t('TEXT.EIN'); ?> 0,5 / <?php echo spot_t('TEXT.AUS'); ?> 0,4</td><td><?php echo spot_t('TEXT.PUSH_3'); ?></td></tr>
<tr><td><?php echo spot_t('TEXT.UND_U1'); ?></td><td><?php echo spot_t('TEXT.PREIS_PUSH_JETZT'); ?></td><td></td><td><?php echo spot_t('TEXT.S1_S2'); ?></td></tr>
<tr><td><?php echo spot_t('TEXT.ODER_O1'); ?></td><td><?php echo spot_t('TEXT.PUSH_SAMMLER'); ?></td><td><?php echo spot_t('TEXT.EINZIGE_QUELLE_DES_BENACHRICHTIGUN'); ?></td><td>U1</td></tr>
<tr><td><?php echo spot_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN'); ?></td><td><?php echo spot_t('TEXT.PUSH_AKTUELLER_STROMPREIS'); ?></td><td><?php echo spot_t('TEXT.TEXT_Z_B_STROMPREIS_JETZT_V1_1_CT_'); ?></td><td><?php echo spot_t('TEXT.O1'); ?></td></tr>
<tr><td><?php echo spot_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN_2'); ?></td><td><?php echo spot_t('TEXT.TEST_PUSH'); ?></td><td><?php echo spot_t('TEXT.EIGENER_BAUSTEIN_NUR_FR_DEN_TEST'); ?></td><td><?php echo spot_t('TEXT.SCHWELLWERTSCHALTER_AN_PTEST_EIN_0'); ?></td></tr>
</table>
<b><?php echo spot_t('TEXT.4B_GNSTIG_TEUER_SCHALTUNG_FR_GROE_'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo spot_t('TEXT.SP_BAUSTEIN'); ?></th><th><?php echo spot_t('TEXT.SP_NAME'); ?></th><th><?php echo spot_t('TEXT.SP_EINSTELLUNG'); ?></th><th><?php echo spot_t('TEXT.SP_EINGAENGE'); ?></th></tr>
<tr><td><?php echo spot_t('TEXT.SCHWELLWERTSCHALTER_S3'); ?></td><td><?php echo spot_t('TEXT.STROM_IST_GNSTIG'); ?></td><td><?php echo spot_t('TEXT.INVERTIERT_EIN_BEI'); ?> <b><?php echo spot_t('TEXT.UNTERSCHREITEN'); ?></b> <?php echo spot_t('TEXT.EIN_1_5_AUS_1_6_AN_LEVEL_ODER_DIRE'); ?></td><td><?php echo spot_t('TEXT.LEVEL_BZW_CUR'); ?></td></tr>
<tr><td><?php echo spot_t('TEXT.SCHWELLWERTSCHALTER_S4'); ?></td><td><?php echo spot_t('TEXT.STROM_IST_TEUER'); ?></td><td><?php echo spot_t('TEXT.EIN_2_5_AUS_2_4_AN_LEVEL'); ?></td><td><?php echo spot_t('TEXT.LEVEL_2'); ?></td></tr>
<tr><td><?php echo spot_t('TEXT.SCHWELLWERTSCHALTER_S5'); ?></td><td><?php echo spot_t('TEXT.BRSENPREIS_NEGATIV_2'); ?></td><td><?php echo spot_t('TEXT.EIN'); ?> 0,5 / <?php echo spot_t('TEXT.AUS'); ?> 0,4</td><td><?php echo spot_t('TEXT.NEG_2'); ?></td></tr>
<tr><td><?php echo spot_t('TEXT.ODER_O2'); ?></td><td><?php echo spot_t('TEXT.FREIGABE_GROE_VERBRAUCHER'); ?></td><td><?php echo spot_t('TEXT.AUF_FREIGABE_EINGANG_VON_WALLBOX_W'); ?></td><td>S3 | S5</td></tr>
<tr><td><?php echo spot_t('TEXT.UND_U2'); ?></td><td><?php echo spot_t('TEXT.SPERRE_BEI_HOCHPREIS'); ?></td><td><?php echo spot_t('TEXT.Z_B_HEIZSTAB_BOILER_SPERREN'); ?></td><td><?php echo spot_t('TEXT.S4_EIGENE_FREIGABE'); ?></td></tr>
</table>
<b><?php echo spot_t('TEXT.4C_GNSTIGSTES_FENSTER_NUTZEN'); ?></b> <?php echo spot_t('TEXT.WASCHMASCHINE_SPLMASCHINE_E_AUTO'); ?>
<table class="sm-tbl">
<tr><th><?php echo spot_t('TEXT.SP_BAUSTEIN'); ?></th><th><?php echo spot_t('TEXT.SP_NAME'); ?></th><th><?php echo spot_t('TEXT.SP_EINSTELLUNG'); ?></th><th><?php echo spot_t('TEXT.SP_EINGAENGE'); ?></th></tr>
<tr><td><?php echo spot_t('TEXT.SCHWELLWERTSCHALTER_S6'); ?></td><td><?php echo spot_t('TEXT.GNSTIGSTES_FENSTER_LUFT'); ?></td><td><?php echo spot_t('TEXT.INVERTIERT_EIN_BEI_UNTERSCHREITEN_'); ?></td><td><?php echo spot_t('TEXT.WININ'); ?></td></tr>
<tr><td><?php echo spot_t('TEXT.UND_U3'); ?></td><td><?php echo spot_t('TEXT.START_FREIGABE_GERT'); ?></td><td><?php echo spot_t('TEXT.SCHALTSTECKDOSE_GERTE_STARTBEFEHL'); ?></td><td><?php echo spot_t('TEXT.S6_TASTER_START_BEI_GNSTIGEM_STROM'); ?></td></tr>
<tr><td><?php echo spot_t('TEXT.STATUSBAUSTEIN'); ?></td><td><?php echo spot_t('TEXT.HINWEIS_KACHEL'); ?></td><td><?php echo spot_t('TEXT.TEXT_GNSTIGSTES_FENSTER_AB_V1_0_UH'); ?></td><td><?php echo spot_t('TEXT.I1_WINH_I2_WINCT'); ?></td></tr>
</table>
<b><?php echo spot_t('TEXT.4D_ANSAGE_PREISE_FR_MORGEN_SIND_DA'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo spot_t('TEXT.SP_BAUSTEIN'); ?></th><th><?php echo spot_t('TEXT.SP_NAME'); ?></th><th><?php echo spot_t('TEXT.SP_EINSTELLUNG'); ?></th><th><?php echo spot_t('TEXT.SP_EINGAENGE'); ?></th></tr>
<tr><td><?php echo spot_t('TEXT.SCHWELLWERTSCHALTER_S7'); ?></td><td><?php echo spot_t('TEXT.PREISE_FR_MORGEN_VORHANDEN'); ?></td><td><?php echo spot_t('TEXT.EIN'); ?> 0,5 / <?php echo spot_t('TEXT.AUS'); ?> 0,4</td><td><?php echo spot_t('TEXT.OK'); ?></td></tr>
<tr><td><?php echo spot_t('TEXT.IMPULSGEBER_BEI_UHRZEIT'); ?></td><td><?php echo spot_t('TEXT.IMPULS_20_00_TAGESVORSCHAU'); ?></td><td><?php echo spot_t('TEXT.20_00_UHR'); ?></td><td></td></tr>
<tr><td><?php echo spot_t('TEXT.UND_U4_STATUSBAUSTEIN'); ?></td><td><?php echo spot_t('TEXT.PUSH_MORGEN_GNSTIGSTE_STUNDE'); ?></td><td><?php echo spot_t('TEXT.TEXT_MORGEN_AM_GNSTIGSTEN_UM_V1_0_'); ?></td><td><?php echo spot_t('TEXT.U4_IMPULS_S7_STATUS_I1MINH_I2MINP_'); ?></td></tr>
</table>
<b><?php echo spot_t('TEXT.4E_CO_8322_OPTIMIERTES_SCHALTEN'); ?></b> <?php echo spot_t('TEXT.UNABHNGIG_VOM_PREIS'); ?>
<table class="sm-tbl">
<tr><th><?php echo spot_t('TEXT.SP_BAUSTEIN'); ?></th><th><?php echo spot_t('TEXT.SP_NAME'); ?></th><th><?php echo spot_t('TEXT.SP_EINSTELLUNG'); ?></th><th><?php echo spot_t('TEXT.SP_EINGAENGE'); ?></th></tr>
<tr><td><?php echo spot_t('TEXT.SCHWELLWERTSCHALTER_S8'); ?></td><td><?php echo spot_t('TEXT.OUML_KOSTROM_ZEIT'); ?></td><td><?php echo spot_t('TEXT.EIN'); ?> 0,5 / <?php echo spot_t('TEXT.AUS'); ?> 0,4</td><td><?php echo spot_t('TEXT.CO2CLEAN'); ?></td></tr>
<tr><td><?php echo spot_t('TEXT.ODER_O3'); ?></td><td><?php echo spot_t('TEXT.FREIGABE_SAUBER_ODER_GNSTIG'); ?></td><td><?php echo spot_t('TEXT.Z_B_WARMWASSER_NACHHEIZUNG_SPEICHE'); ?></td><td>S8 | S3</td></tr>
<tr><td><?php echo spot_t('TEXT.STATUSBAUSTEIN'); ?></td><td><?php echo spot_t('TEXT.KACHEL_STROMMIX'); ?></td><td><?php echo spot_t('TEXT.TEXT_V1_0_G_CO2_KWH_SAUBERSTE_STUN'); ?></td><td><?php echo spot_t('TEXT.I1_CO2_I2_CO2MINH'); ?></td></tr>
</table>
<b><?php echo spot_t('TEXT.4F_TARIFVERGLEICH_ALS_MONATSBERICH'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo spot_t('TEXT.SP_BAUSTEIN'); ?></th><th><?php echo spot_t('TEXT.SP_NAME'); ?></th><th><?php echo spot_t('TEXT.SP_EINSTELLUNG'); ?></th><th><?php echo spot_t('TEXT.SP_EINGAENGE'); ?></th></tr>
<tr><td><?php echo spot_t('TEXT.ANALOGSPEICHER_STATUSBAUSTEIN'); ?></td><td><?php echo spot_t('TEXT.MONATSBERICHT_TARIFVERGLEICH'); ?></td><td><?php echo spot_t('TEXT.TEXT_DYNAMISCH_V1_1_CT_GEGEN_FEST_'); ?></td><td><?php echo spot_t('TEXT.I1_DYNM_I2_FIX_I3_DIFFM'); ?></td></tr>
<tr><td><?php echo spot_t('TEXT.SCHWELLWERTSCHALTER_S9'); ?></td><td><?php echo spot_t('TEXT.DYNAMISCH_WRE_GNSTIGER'); ?></td><td><?php echo spot_t('TEXT.EIN_0_5_AUS_0_4_AN_DIFFM'); ?></td><td><?php echo spot_t('TEXT.PUSH_TARIFWECHSEL_PRFEN'); ?></td></tr>
</table>
<span class="sm-small"><?php echo spot_t('TEXT.DAS_PLUGIN_SENDET_DENSELBEN_BERICH'); ?></span>
<br><br><b><?php echo spot_t('TEXT.PRAXIS_ERFAHRUNGEN_ZUM_BENACHRICHT'); ?></b> <?php echo spot_t('TEXT.ERSPART_LANGE_FEHLERSUCHE'); ?><br>
<?php echo spot_t('TEXT.ER_SENDET_NUR_BEI_EINER_01_FLANKE_'); ?><br>
<?php echo spot_t('TEXT.FR_DEN_TEST_PTEST_EINEN_EIGENEN_BE'); ?><br>
<?php echo spot_t('TEXT.INVERTIERTE_SCHWELLWERTSCHALTER_EI'); ?>
</div>

<div class="sm-step"><b><?php echo spot_t('TEXT.SCHRITT_5_MQTT_ALTERNATIVE_JSON'); ?></b><br>
<?php echo spot_t('TEXT.ALLE_WERTE_GIBT_ES_AUCH_BER_DAS_LO'); ?>
<span class="sm-mono"><?= sp_e($sp_cfg['mqtt_topic']) ?>/...</span> <?php echo spot_t('TEXT.UND_ALS_JSON_FR_DRITTSOFTWARE_INKL'); ?> <b><?php echo spot_t('TEXT.ALLER_STUNDENWERTE'); ?></b> <?php echo spot_t('TEXT.FR_EIGENE_DIAGRAMME'); ?>
<span class="sm-mono">http://<?= $sp_host ?>/plugins/<?= sp_e($sp_plugin) ?><?php echo spot_t('TEXT.SPOT_PHP_JSON_1'); ?></span>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-pane<?php echo $sp_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">
<h2><?php echo spot_t('TEXT.TEST'); ?></h2>

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
<?php if ($sp_budget > 0) { ?>
<p class="sm-small"><?php echo spot_t('PLAN.FAHRPLAN_BUDGET'); ?></p>
<?php } } ?>

<h3 class="sm-h3"><?php echo spot_t('PLAN.H_SELBSTTEST'); ?></h3>
<p class="sm-small"><?php echo spot_t('PLAN.SELBSTTEST_TEXT'); ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo spot_t('PLAN.LEGENDE_TECHNIK'); ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="tab" value="test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="plantest" value="1"><?php echo spot_t('PLAN.K_SELBSTTEST'); ?></button>
  </form>
</div>
<?php if (!empty($sp_plantest)) { ?>
<div class="sm-pre"><?php echo sp_e($sp_plantest); ?></div>
<?php } ?>

<h3 class="sm-h3"><?php echo spot_t('REGEL.H_SELBSTTEST'); ?></h3>
<p class="sm-small"><?php echo spot_t('REGEL.SELBSTTEST_TEXT'); ?></p>
<?php
/* Die Vorlage und die Ausgabe muessen dieselben Feldnamen kennen. Steht in
 * der Vorlage ein Suchmuster, das die Zeile nicht liefert, bleibt der
 * virtuelle Eingang in Loxone stumm - ohne Fehlermeldung. Deshalb wird hier
 * die Zeile wirklich erzeugt und gegen spot_felder() gehalten. */
$sp_pruef = array();
if (function_exists('spot_felder') && function_exists('spot_zeile')) {
    $sp_zeile = spot_zeile(spot_state(), $sp_cfg);
    foreach (spot_felder() as $sp_fn => $sp_fd) {
        if (strpos($sp_zeile, ';' . $sp_fn . '=') === false
            && strpos($sp_zeile, "\n" . $sp_fn . '=') === false) {
            $sp_pruef[] = $sp_fn;
        }
    }
}
$sp_xmlok = 1;
if (function_exists('spot_vorlage')) {
    $sp_v = spot_vorlage();
    $sp_alt2 = libxml_use_internal_errors(true);
    $sp_xmlok = simplexml_load_string($sp_v[1]) !== false ? 1 : 0;
    libxml_clear_errors();
    libxml_use_internal_errors($sp_alt2);
}
?>
<div class="sm-alert <?= ($sp_pruef || !$sp_xmlok) ? 'sm-warn' : 'sm-info' ?>">
<?php if ($sp_pruef) {
    echo sprintf(spot_t('REGEL.SELBSTTEST_FEHL'), sp_e(implode(', ', $sp_pruef)));
} elseif (!$sp_xmlok) {
    echo spot_t('REGEL.SELBSTTEST_XML');
} else {
    echo sprintf(spot_t('REGEL.SELBSTTEST_OK'), count(spot_felder()));
} ?>
</div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo spot_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo spot_t('LEGENDE.TECHNIK'); ?></span>
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
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?php echo spot_t('TEXT.TOKEN_SETZEN'); ?></button>
</form>
<?php } else { ?>
<div class="sm-alert sm-ok"><?php echo spot_t('TEXT.TOKEN_AKTIV'); ?></div>
<form method="post" action="index.php" style="display:inline">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?php echo spot_t('TEXT.TOKEN_ERNEUERN'); ?></button>
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="token_weg" value="1"><?php echo spot_t('TEXT.TOKEN_ENTFERNEN'); ?></button>
</form>
<?php } ?>
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
<table class="sm-tbl"><tr><th><?php echo spot_t('TEXT.MONAT'); ?></th><th><?php echo spot_t('TEXT.TAGE'); ?></th><th>kWh</th><th><?php echo spot_t('TEXT.DYNAMISCH_GEWICHTET'); ?></th><th><?php echo spot_t('TEXT.DYNAMISCH_EINFACH'); ?></th><th><?php echo spot_t('TEXT.FEST'); ?></th><th><?php echo spot_t('TEXT.VORTEIL'); ?></th><th><?php echo spot_t('TEXT.EURO'); ?></th></tr>
<?php foreach ($sp_mc as $sp_m) { ?>
<tr><td><?= sp_e(substr($sp_m['monat'], 4, 2) . '/' . substr($sp_m['monat'], 0, 4)) ?></td>
<td><?= (int) $sp_m['tage'] ?></td>
<td><?= sp_n($sp_m['kwh'], 1) ?><?= $sp_m['quelle'] === 'monat' ? '' : '<span class="sm-small" title="aus dem Jahresverbrauch abgeleitet"> *</span>' ?></td>
<td><b><?= sp_n($sp_m['dynp'], 2) ?> ct</b></td><td><?= sp_n($sp_m['dyn'], 2) ?> ct</td>
<td><?= sp_n($sp_m['fix'], 2) ?> ct</td>
<td style="color:<?= $sp_m['diff'] >= 0 ? '#2e7d32' : '#c62828' ?>;"><b><?= ($sp_m['diff'] >= 0 ? '+' : '') . sp_n($sp_m['diff'], 2) ?> ct</b></td>
<td style="color:<?= $sp_m['euro'] >= 0 ? '#2e7d32' : '#c62828' ?>;"><?= ($sp_m['euro'] >= 0 ? '+' : '') . sp_n($sp_m['euro'], 2) ?> <?php echo spot_t('TEXT.TEXT_6'); ?></td></tr>
<?php } ?></table>
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
<table class="sm-tbl"><tr><th><?php echo spot_t('TEXT.TAG'); ?></th><th><?php echo spot_t('TEXT.SCHNITT'); ?></th><th><?php echo spot_t('TEXT.GEWICHTET'); ?></th><th><?php echo spot_t('TEXT.MINIMUM'); ?></th><th><?php echo spot_t('TEXT.MAXIMUM'); ?></th><th>CO&#8322;</th></tr>
<?php foreach (array_reverse($sp_hist) as $sp_r) { ?>
<tr><td><?= sp_e(substr($sp_r[0], 6, 2) . '.' . substr($sp_r[0], 4, 2) . '.' . substr($sp_r[0], 0, 4)) ?></td>
<td><?= sp_n($sp_r[1], 2) ?> ct</td><td><?= $sp_r[4] > 0 ? sp_n($sp_r[4], 2) . ' ct' : '&ndash;' ?></td>
<td><?= sp_n($sp_r[2], 2) ?> ct</td><td><?= sp_n($sp_r[3], 2) ?> ct</td>
<td><?= $sp_r[5] > 0 ? (int) $sp_r[5] . ' g' : '&ndash;' ?></td></tr>
<?php } ?></table>
<?php } ?>
</div>

<!-- ================= Reiter: Kostenvergleich ================= -->
<div class="sm-pane<?php echo $sp_tab === 'tab-costs' ? ' sm-active' : ''; ?>" id="tab-costs">
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
<div class="sm-pane<?php echo $sp_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
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
    <button data-role="none" class="sm-btn" type="submit" style="background:#c62828;"><?php echo spot_t('TEXT.LOG_LEEREN'); ?></button>
</form>
</div>

</div>
<script>
function spTtsMode() {
    var m = document.getElementById('tts_mode').value;
    document.getElementById('tts_audioserver_hint').style.display = (m === 'audioserver') ? 'block' : 'none';
    document.getElementById('tts_template_row').style.display = (m === 'ms4h' || m === 'custom') ? 'block' : 'none';
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
    if (gepflegt) {
        box.innerHTML = 'Summe der Monatswerte: <b>' + Math.round(sum).toLocaleString('de-DE') +
            ' kWh</b> &mdash; dieser Wert wird beim Speichern als Jahresverbrauch &uuml;bernommen.';
        jahr.value = Math.round(sum);
        jahr.readOnly = true;
        jahr.style.background = '#f0f0f0';
        hint.innerHTML = 'Wird aus den Monatswerten unten berechnet.';
    } else {
        box.innerHTML = 'Summe der Monatswerte: <b>noch keine Werte gepflegt</b>';
        jahr.readOnly = false;
        jahr.style.background = '';
        hint.innerHTML = 'Wird verwendet, solange unten keine Monatswerte gepflegt sind.';
    }
}
function spHours(mode) {
    document.querySelectorAll('input[name="hours[]"]').forEach(function (c) {
        var h = parseInt(c.value, 10);
        c.checked = (mode === 1) ? true : (mode === 0 ? false : (h >= 7 && h <= 21));
    });
}
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-pane').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { activate(t.dataset.pane); }); });
    activate(<?= json_encode($sp_tab) ?>);
    spTtsMode();
})();
</script>
<?php
if ($sp_frame) {
    LBWeb::lbfooter();
}
