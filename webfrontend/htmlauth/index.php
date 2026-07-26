<?php
/**
 * Spotpreis aWATTar - Admin-Oberflaeche (v1.0.1)
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
$sp_tab = preg_match('/^tab-(settings|loxone|costs|test|log)$/', (string) (isset($_POST['activetab']) ? $_POST['activetab'] : '')) ? $_POST['activetab'] : 'tab-settings';

// ---------- Protokoll leeren ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($sp_logfile), 0775, true);
    @file_put_contents($sp_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n");
    $sp_tab = 'tab-log';
}

// ---------- Jetzt abrufen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetchnow']) && function_exists('spot_state')) {
    $sp_s = spot_state(true);
    $sp_note = $sp_s['ok'] ? ('Marktdaten abgerufen: heute ' . $sp_s['heute']['n'] . ' Stunden, morgen ' . ($sp_s['tomorrow_ok'] ? $sp_s['morgen']['n'] . ' Stunden' : 'noch nicht veroeffentlicht'))
                           : 'Abruf FEHLGESCHLAGEN - Internetverbindung/Markt pruefen (Protokoll beachten).';
}

// ---------- Speichern ----------
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
    $sp_new['marstek_url'] = trim((string) (isset($_POST['marstek_url']) ? $_POST['marstek_url'] : ''));
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
    if (!is_dir($sp_cfgdir)) {
        @mkdir($sp_cfgdir, 0775, true);
    }
    $sp_json = json_encode($sp_new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($sp_cfgfile, $sp_json) !== false) {
        $sp_saved = true;
        @copy($sp_cfgfile, $sp_bkfile);
        @unlink('/tmp/spotpreis/state.json'); // Preise mit neuen Aufschlaegen neu rechnen
    } else {
        $sp_err = 'Konfiguration konnte nicht gespeichert werden: ' . $sp_cfgfile;
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
    'marstek_enabled' => 0, 'marstek_url' => '',
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
    $sp_loglines = array_slice(array_reverse(file($sp_logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()), 0, 300);
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
        return '<div class="sp-small">Noch keine Preisdaten &mdash; im Reiter Test &bdquo;Jetzt abrufen&ldquo; klicken.</div>';
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
        $svg .= '<text x="' . round($mid + 4, 1) . '" y="' . ($y0 + 12) . '" font-size="9" fill="#999">morgen</text>';
    }
    $svg .= '<text x="' . $x0 . '" y="' . ($h - 3) . '" font-size="9" fill="#999">Stunde &middot; Endpreis in ct/kWh &middot; orange = aktuelle Stunde, blau = negativer B&#246;rsenpreis</text>';
    return $svg . '</svg>';
}

$sp_mon = function_exists('spot_months') ? spot_months() : array('use' => 0, 'kwh' => array_fill(0, 12, 0.0), 'summe' => 0);
$sp_ownurl = function_exists('spot_marstek_default_url') ? spot_marstek_default_url() : 'http://127.0.0.1/plugins/marstekvenus/marstek.php';
$sp_frame = class_exists('LBWeb', false);
if ($sp_frame) {
    LBWeb::lbheader('Spotpreis aWATTar', 'https://wiki.loxberry.de/', '');
}
$sp_host = sp_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');
$sp_addon = (float) $sp_cfg['netz'] + (float) $sp_cfg['steuer'] + (float) $sp_cfg['konzession'] + (float) $sp_cfg['umlagen'] + (float) $sp_cfg['aufschlag'];
?>
<style>
.sp-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sp-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sp-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sp-wrap input[type=text], .sp-wrap input[type=number], .sp-wrap select, .sp-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sp-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sp-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sp-row > div { flex: 1; min-width: 150px; }
.sp-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sp-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sp-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sp-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sp-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sp-warn { background: #fff8e1; border: 1px solid #ffe082; }
.sp-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sp-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sp-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sp-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; text-shadow: none !important; }
.sp-tab.sp-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sp-pane { display: none; padding-top: 4px; }
.sp-pane.sp-active { display: block; }
.sp-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sp-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sp-tbl { border-collapse: collapse; margin: 8px 0; }
.sp-tbl th, .sp-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.sp-tbl th { background: #f0f0f0; }
.sp-hours { display: flex; flex-wrap: wrap; gap: 4px; margin: 6px 0; }
.sp-hours label { display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; background: #f5f5f5; border: 1px solid #ddd; border-radius: 6px; padding: 5px 9px; margin: 0; font-weight: 500; font-size: 0.85em; width: 95px; box-sizing: border-box; }
.sp-hours label:hover { background: #eef7e4; border-color: #6dac20; }
.sp-months { display: flex; flex-wrap: wrap; gap: 8px; margin: 6px 0; }
.sp-months > div { width: 108px; }
.sp-months label { margin: 0 0 2px; font-size: 0.8em; font-weight: 600; color: #555; white-space: nowrap; min-height: 0; }
.sp-months input { padding: 6px 8px; font-size: 0.9em; text-align: right; }
/* Beschriftungen einer Zeile auf gleiche Hoehe bringen, damit die Eingabefelder
   waagrecht fluchten - auch wenn ein Text zweizeilig umbricht */
.sp-row > div > label:not([style]) { min-height: 2.6em; display: flex; align-items: flex-end; }
.sp-wrap .sp-btn, .sp-wrap a.sp-btn, .sp-wrap button { text-shadow: none !important; box-shadow: none !important; }
.sp-wrap a.sp-btn, .sp-wrap a.sp-btn:visited, .sp-wrap a.sp-btn:hover { color: #fff !important; text-decoration: none; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Standard aller Plugins) --- */
.sp-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; text-shadow: none !important; }
.sp-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sp-knopfreihe form { margin: 0; display: flex; }
.sp-knopfreihe .sp-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.sp-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sp-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sp-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sp-btn.sp-b-lesen   { background: #6dac20; }
.sp-btn.sp-b-technik { background: #546e7a; }
.sp-btn.sp-b-aktion  { background: #e0620d; }
.sp-punkt.sp-b-lesen   { background: #6dac20; }
.sp-punkt.sp-b-technik { background: #546e7a; }
.sp-punkt.sp-b-aktion  { background: #e0620d; }
</style>
<div class="sp-wrap">

<?php if ($sp_saved) { ?><div class="sp-alert sp-ok"><b>Konfiguration gespeichert</b> (inkl. Sicherungskopie f&uuml;r Updates).</div><?php } ?>
<?php if ($sp_note !== '') { ?><div class="sp-alert sp-ok"><?= sp_e($sp_note) ?></div><?php } ?>
<?php if ($sp_err !== '') { ?><div class="sp-alert sp-err"><b>Fehler:</b> <?= sp_e($sp_err) ?></div><?php } ?>

<?php if (!empty($sp_st)) { ?>
<div class="sp-alert sp-info">
<?php if ($sp_st['ok']) { ?>
<b>Jetzt (<?= (int) $sp_st['stunde'] ?> Uhr): <?= sp_n($sp_st['cur'], 2) ?> ct/kWh</b>
(davon B&ouml;rse <?= sp_n($sp_st['cur_boerse'], 2) ?> ct) &middot;
n&auml;chste Stunde <?= sp_n($sp_st['next'], 2) ?> ct &middot;
Rang <?= (int) $sp_st['rank'] ?> von <?= (int) $sp_st['n'] ?> &middot;
Niveau <?= $sp_st['level'] == 1 ? '<b>g&uuml;nstig</b>' : ($sp_st['level'] == 3 ? '<b>teuer</b>' : 'normal') ?>
<?= $sp_st['neg'] ? ' &middot; <b>B&ouml;rsenpreis negativ!</b>' : '' ?><br>
Heute: Min <?= sp_n($sp_st['heute']['minp'], 2) ?> ct um <?= (int) $sp_st['heute']['minh'] ?> Uhr &middot;
Max <?= sp_n($sp_st['heute']['maxp'], 2) ?> ct um <?= (int) $sp_st['heute']['maxh'] ?> Uhr &middot;
Schnitt <?= sp_n($sp_st['heute']['avg'], 2) ?> ct
<?php if ($sp_st['tomorrow_ok']) { ?><br>Morgen: Min <?= sp_n($sp_st['morgen']['minp'], 2) ?> ct um <?= (int) $sp_st['morgen']['minh'] ?> Uhr &middot;
Max <?= sp_n($sp_st['morgen']['maxp'], 2) ?> ct um <?= (int) $sp_st['morgen']['maxh'] ?> Uhr &middot;
Schnitt <?= sp_n($sp_st['morgen']['avg'], 2) ?> ct<?php } else { ?><br>Morgen: noch nicht ver&ouml;ffentlicht (kommt b&ouml;rsent&auml;glich ab ca. 14 Uhr)<?php } ?>
<?php if ($sp_st['fenster']['in'] >= 0) { ?><br>G&uuml;nstigstes <?= (int) $sp_st['fenster_len'] ?>-Stunden-Fenster: ab <?= (int) $sp_st['fenster']['h'] ?> Uhr
(<?= $sp_st['fenster']['in'] == 0 ? 'jetzt' : 'in ' . (int) $sp_st['fenster']['in'] . ' h' ?>), Schnitt <?= sp_n($sp_st['fenster']['ct'], 2) ?> ct<?php } ?>
<?php if (!empty($sp_st['wp_on'])) { ?><br><?= sp_e($sp_st['wp_name']) ?> (&sect;&nbsp;14a): <b><?= sp_n($sp_st['wp_cur'], 2) ?> ct/kWh</b> &middot; n&auml;chste Stunde <?= sp_n($sp_st['wp_next'], 2) ?> ct<?php } ?>
<?php if (!empty($sp_st['co2_ok'])) { ?><br>CO&#8322;-Intensit&auml;t: <b><?= (int) $sp_st['co2'] ?> g/kWh</b><?= !empty($sp_st['co2_clean']) ? ' <b>(sauber)</b> ' : ' ' ?>
&middot; sauberste Stunde <?= (int) $sp_st['co2_minh'] ?> Uhr mit <?= (int) $sp_st['co2_min'] ?> g &middot; Schnitt <?= (int) $sp_st['co2_avg'] ?> g<?php } ?>
<br>Tarifvergleich laufender Monat: dynamisch (gewichtet) <b><?= sp_n($sp_st['dyn_monat'], 2) ?> ct</b> gegen fest <b><?= sp_n($sp_st['fix'], 2) ?> ct</b>
&rarr; <?= $sp_st['diff_monat'] >= 0 ? 'dynamisch w&auml;re g&uuml;nstiger um ' : '<b>fester Tarif ist g&uuml;nstiger um</b> ' ?>
<?= sp_n(abs($sp_st['diff_monat']), 2) ?> ct/kWh (<?= sp_n(abs($sp_st['euro_monat']), 2) ?> &euro;)
<br>Verschiebe-Potenzial (7 Tage): <?= sp_n($sp_st['shift_ct'], 2) ?> ct/kWh Spanne &rarr; rund <b><?= sp_n($sp_st['shift_jahr'], 2) ?> &euro; im Jahr</b>
<div style="margin-top:8px;"><?= sp_chart($sp_st) ?></div>
<?php } else { ?>
<b>Noch keine Preisdaten geladen.</b> Bitte unten die Preisbestandteile pr&uuml;fen, speichern und im Reiter Test &bdquo;Jetzt abrufen&ldquo; klicken.
<?php } ?>
</div>
<?php } ?>

<div class="sp-tabs">
    <div class="sp-tab" data-pane="tab-settings">Einstellungen</div>
    <div class="sp-tab" data-pane="tab-loxone">Einbindung in Loxone</div>
    <div class="sp-tab" data-pane="tab-costs">Kostenvergleich</div>
    <div class="sp-tab" data-pane="tab-test">Test</div>
    <div class="sp-tab" data-pane="tab-log">Logdateien</div>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sp-pane" id="tab-settings">
<form method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>Markt</h2>
<div class="sp-row">
    <div>
        <label>Preiszone / API</label>
        <select data-role="none" name="market" id="market" onchange="spMarket()">
            <option value="de"<?= $sp_cfg['market'] === 'de' ? ' selected' : '' ?>>Deutschland (api.awattar.de)</option>
            <option value="at"<?= $sp_cfg['market'] === 'at' ? ' selected' : '' ?>>&Ouml;sterreich (api.awattar.at)</option>
        </select>
        <div class="sp-small">Quelle: offene aWATTar-API (EPEX SPOT Day-Ahead, st&uuml;ndlich). Kein Konto n&ouml;tig.</div>
    </div>
</div>

<h2>Preiszusammensetzung (Endpreis)</h2>
<div class="sp-small" style="margin-bottom:8px;">Alle Angaben in <b>ct/kWh netto</b>. Endpreis =
(B&ouml;rsenpreis + Summe der Aufschl&auml;ge) &times; Umsatzsteuer. Die Werte stehen auf den
Richtwerten f&uuml;r 2026 &mdash; bitte mit der eigenen Stromrechnung abgleichen.</div>
<div class="sp-row">
    <div>
        <label>Netzentgelte</label>
        <input data-role="none" type="text" name="netz" value="<?= sp_e($sp_cfg['netz']) ?>" placeholder="9.0">
        <div class="sp-small">Inkl. Messstellenbetrieb; stark regional (typisch 7&ndash;12).</div>
    </div>
    <div>
        <label>Stromsteuer</label>
        <input data-role="none" type="text" name="steuer" value="<?= sp_e($sp_cfg['steuer']) ?>" placeholder="2.05">
        <div class="sp-small">DE: 2,05 &middot; AT (Elektrizit&auml;tsabgabe): 1,50.</div>
    </div>
    <div>
        <label>Konzessionsabgabe</label>
        <input data-role="none" type="text" name="konzession" value="<?= sp_e($sp_cfg['konzession']) ?>" placeholder="1.32">
        <div class="sp-small">Nach Gemeindegr&ouml;&szlig;e: bis 25.000 EW <b>1,32</b> &middot; bis 100.000 <b>1,59</b> &middot; bis 500.000 <b>1,99</b> &middot; &uuml;ber 500.000 <b>2,39</b>.</div>
    </div>
</div>
<div class="sp-row">
    <div>
        <label>Umlagen</label>
        <input data-role="none" type="text" name="umlagen" value="<?= sp_e($sp_cfg['umlagen']) ?>" placeholder="2.945">
        <div class="sp-small">2026: KWKG 0,446 + Offshore-Netzumlage 0,941 + &sect;&nbsp;19 StromNEV 1,558 = <b>2,945</b>.</div>
    </div>
    <div>
        <label>Anbieter-Aufschlag</label>
        <input data-role="none" type="text" name="aufschlag" value="<?= sp_e($sp_cfg['aufschlag']) ?>" placeholder="0.0">
        <div class="sp-small">Marge/Arbeitspreis-Aufschlag des dynamischen Tarifs (oft 1&ndash;2).</div>
    </div>
    <div>
        <label>Umsatzsteuer (%)</label>
        <input data-role="none" type="text" name="vat" id="vat" value="<?= sp_e($sp_cfg['vat']) ?>" placeholder="19">
        <div class="sp-small">Deutschland 19, &Ouml;sterreich 20.</div>
    </div>
    <div>
        <label>Grundpreis (EUR/Monat)</label>
        <input data-role="none" type="text" name="grundpreis" value="<?= sp_e($sp_cfg['grundpreis']) ?>" placeholder="0">
        <div class="sp-small">Netz-Grundpreis + Messstellenbetrieb. Nur informativ (geht nicht in den ct-Preis ein).</div>
    </div>
</div>
<div class="sp-alert sp-ok" style="margin-top:10px;">Aktuelle Summe der Aufschl&auml;ge: <b><?= sp_n($sp_addon, 3) ?> ct/kWh netto</b>
&rarr; bei einem B&ouml;rsenpreis von 8,00 ct ergibt das <b><?= sp_n((8 + $sp_addon) * (1 + (float) $sp_cfg['vat'] / 100), 2) ?> ct/kWh</b> Endpreis.</div>

<h2>Zweiter Preissatz: steuerbare Verbrauchseinrichtung (&sect;&nbsp;14a EnWG)</h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="wp_enabled" <?= !empty($sp_cfg['wp_enabled']) ? 'checked' : '' ?>> Zweiten Preissatz berechnen und ausgeben
</label>
<div class="sp-small">F&uuml;r W&auml;rmepumpe oder Wallbox mit <b>eigenem Z&auml;hlpunkt</b> nach &sect;&nbsp;14a EnWG (Modul 1):
reduziertes Netzentgelt und Schwachlast-Konzessionsabgabe. Liefert eine zweite Preisreihe
(<span class="sp-mono">WPCUR</span>/<span class="sp-mono">WPNEXT</span>) &mdash; ideal, um W&auml;rmepumpe und Haushalt getrennt zu rechnen.</div>
<div class="sp-row" style="margin-top:6px;">
    <div>
        <label>Bezeichnung</label>
        <input data-role="none" type="text" name="wp_name" value="<?= sp_e($sp_cfg['wp_name']) ?>" placeholder="Wärmepumpe">
    </div>
    <div>
        <label>Netzentgelt &sect;&nbsp;14a (ct/kWh)</label>
        <input data-role="none" type="text" name="wp_netz" value="<?= sp_e($sp_cfg['wp_netz']) ?>" placeholder="3.43">
        <div class="sp-small">Beispiel-Netzgebiet 2026: steuerbare W&auml;rmepumpe <b>3,43</b> &middot; Speicherheizung 1,71 &middot;
        Elektromobilit&auml;t/sonstige 4,28 &middot; Modul 2 (pauschal) 2,59 &middot; Modul 3 HT 7,14 / NT 2,59.</div>
    </div>
    <div>
        <label>Konzessionsabgabe (ct/kWh)</label>
        <input data-role="none" type="text" name="wp_konzession" value="<?= sp_e($sp_cfg['wp_konzession']) ?>" placeholder="0.61">
        <div class="sp-small">Schwachlast-H&ouml;chstbetrag nach &sect;&nbsp;2 Abs.&nbsp;2 KAV: <b>0,61</b>.</div>
    </div>
</div>

<h2>CO&#8322;-Intensit&auml;t des Strommixes</h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="co2_enabled" <?= !empty($sp_cfg['co2_enabled']) ? 'checked' : '' ?>> CO&#8322;-Werte abrufen (Fraunhofer ISE Energy-Charts, kostenlos, ohne Konto)
</label>
<div class="sp-row" style="margin-top:6px;">
    <div>
        <label>Schwelle &bdquo;sauber&ldquo; (g CO&#8322;/kWh)</label>
        <input data-role="none" type="text" name="co2_clean" value="<?= sp_e($sp_cfg['co2_clean']) ?>" placeholder="200">
        <div class="sp-small">Darunter meldet das Plugin <span class="sp-mono">CO2CLEAN=1</span> &mdash; f&uuml;r &bdquo;jetzt ist &Ouml;kostrom-Zeit&ldquo;.
        Typisch: unter 150 sehr sauber, &uuml;ber 400 sehr fossil. Inklusive Prognose f&uuml;r die n&auml;chsten Stunden.</div>
    </div>
</div>

<h2>Vergleich fester Tarif gegen dynamischen Tarif</h2>
<div class="sp-row">
    <div>
        <label>Mein fester Arbeitspreis (ct/kWh brutto)</label>
        <input data-role="none" type="text" name="fixed_price" value="<?= sp_e($sp_cfg['fixed_price']) ?>" placeholder="30.90">
        <div class="sp-small">Arbeitspreis des aktuellen Tarifs &mdash; damit rechnet der Monatsvergleich.</div>
    </div>
    <div>
        <label>Grundpreis fester Tarif (&euro;/Monat)</label>
        <input data-role="none" type="text" name="fix_grund" value="<?= sp_e($sp_cfg['fix_grund']) ?>" placeholder="12.90">
        <div class="sp-small">Grundgeb&uuml;hr des Liefervertrags (meist inkl. Netz-Grundpreis und Messstellenbetrieb).</div>
    </div>
    <div>
        <label>Jahresverbrauch (kWh)</label>
        <input data-role="none" type="text" name="consumption" id="consumption" value="<?= (int) $sp_cfg['consumption'] ?>" placeholder="3500"<?= $sp_mon['use'] ? ' readonly style="background:#f0f0f0;"' : '' ?>>
        <div class="sp-small" id="consumption_hint"><?= $sp_mon['use']
            ? 'Wird aus den Monatswerten unten berechnet.'
            : 'Wird verwendet, solange unten keine Monatswerte gepflegt sind.' ?></div>
    </div>
    <div>
        <label>T&auml;glich verschiebbare Menge (kWh)</label>
        <input data-role="none" type="text" name="shift_kwh" value="<?= sp_e($sp_cfg['shift_kwh']) ?>" placeholder="3">
        <div class="sp-small">Wasch-/Sp&uuml;lmaschine, Warmwasser, E-Auto &hellip; Basis f&uuml;r das Verschiebe-Potenzial.</div>
    </div>
</div>

<div class="sp-small" style="margin-top:10px;"><b>Boni und Rabatte des festen Tarifs</b> &mdash; nur so l&auml;sst sich der
tats&auml;chlich gezahlte Betrag vergleichen (viele Anbieter locken mit Einmalzahlungen, andere mit einem laufenden
Rabatt auf die Rechnung):</div>
<div class="sp-row">
    <div>
        <label>Sofortbonus (&euro;, einmalig)</label>
        <input data-role="none" type="text" name="fix_sofortbonus" value="<?= sp_e($sp_cfg['fix_sofortbonus']) ?>" placeholder="0">
        <div class="sp-small">Wird meist nach wenigen Wochen ausgezahlt.</div>
    </div>
    <div>
        <label>Neukundenbonus (&euro;)</label>
        <input data-role="none" type="text" name="fix_neubonus" value="<?= sp_e($sp_cfg['fix_neubonus']) ?>" placeholder="0">
        <div class="sp-small">Fester Betrag nach dem ersten Lieferjahr.</div>
    </div>
    <div>
        <label>&hellip; oder Neukundenbonus (%)</label>
        <input data-role="none" type="text" name="fix_neubonus_pct" value="<?= sp_e($sp_cfg['fix_neubonus_pct']) ?>" placeholder="0">
        <div class="sp-small">Prozent vom Jahresbetrag (Arbeitspreis + Grundpreis). Beide Felder werden addiert &mdash; nur eines ausf&uuml;llen.</div>
    </div>
    <div>
        <label>Abschlagsrabatt auf Rechnungsbetrag (%)</label>
        <input data-role="none" type="text" name="fix_rabatt" value="<?= sp_e($sp_cfg['fix_rabatt']) ?>" placeholder="0">
        <div class="sp-small">Laufender Rabatt, gilt <b>dauerhaft</b> (nicht nur im ersten Jahr) &mdash; z.&nbsp;B. 27&nbsp;%.</div>
    </div>
</div>

<div class="sp-small" style="margin-top:10px;"><b>Netzbezug je Monat (kWh)</b> &mdash; optional, aber deutlich genauer:
Mit PV-Anlage ist der Zukauf im Sommer klein und im Winter gro&szlig; &mdash; genau dann, wenn der B&ouml;rsenstrom teuer ist.
Wer hier die Werte der letzten Jahresabrechnung eintr&auml;gt, bekommt einen realistischen Euro-Vergleich statt einer
Gleichverteilung. Felder leer lassen = es wird mit dem Jahresverbrauch oben gerechnet.</div>
<div class="sp-months">
<?php $sp_mnames = array('Januar', 'Februar', 'M&auml;rz', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember');
for ($sp_i = 0; $sp_i < 12; $sp_i++) { ?>
    <div>
        <label><?= $sp_mnames[$sp_i] ?></label>
        <input data-role="none" type="text" class="sp-mkwh" name="months[]" value="<?= $sp_mon['kwh'][$sp_i] > 0 ? sp_e(rtrim(rtrim(number_format($sp_mon['kwh'][$sp_i], 1, '.', ''), '0'), '.')) : '' ?>" placeholder="&ndash;" oninput="spSum()">
    </div>
<?php } ?>
</div>
<div class="sp-alert sp-ok" id="sp_msum" style="margin-top:6px;">Summe der Monatswerte: <b><?= $sp_mon['use'] ? sp_n($sp_mon['summe'], 0) . ' kWh' : 'noch keine Werte gepflegt' ?></b><?= $sp_mon['use'] ? ' &mdash; dieser Wert wird als Jahresverbrauch gespeichert.' : '' ?></div>
<div class="sp-small">Der Monatsvergleich wird <b>lastprofil-gewichtet</b> gerechnet (Haushalts-Profil): ein einfacher
Mittelwert w&uuml;rde den dynamischen Tarif sch&ouml;nrechnen, weil der Verbrauch abends liegt, wenn der Strom teurer ist.
Ergebnis erscheint im Reiter <b>Test</b>, im Protokoll und am Monatsersten als Ansage/Push.</div>

<h2>Kopplung mit dem Marstek-Speicher-Plugin (optional)</h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="marstek_enabled" <?= !empty($sp_cfg['marstek_enabled']) ? 'checked' : '' ?>> Speicher in den g&uuml;nstigsten Stunden automatisch laden
</label>
<div class="sp-alert sp-info" style="margin-top:6px;">Nur einschalten, wenn die Rang-Logik <b>nicht</b> in Loxone gebaut ist &mdash;
sonst &uuml;berschreiben sich beide Sollwerte gegenseitig. Wer die Loxone-Logik behalten will, l&auml;sst das hier aus
(Standard) und nutzt einfach <span class="sp-mono">RANK</span> aus Schritt 2.</div>
<div class="sp-row" style="margin-top:6px;">
    <div>
        <label>Endpunkt des Marstek-Plugins</label>
        <input data-role="none" type="text" name="marstek_url" value="<?= sp_e($sp_cfg['marstek_url']) ?>" placeholder="<?= sp_e($sp_ownurl) ?>">
        <div class="sp-small">Leer lassen = automatisch <span class="sp-mono"><?= sp_e($sp_ownurl) ?></span> (eigene LoxBerry-Adresse).</div>
    </div>
    <div>
        <label>In den X g&uuml;nstigsten Stunden laden</label>
        <input data-role="none" type="number" name="marstek_hours" value="<?= (int) $sp_cfg['marstek_hours'] ?>" min="1" max="12">
    </div>
    <div>
        <label>Ladeleistung (W)</label>
        <input data-role="none" type="number" name="marstek_power" value="<?= (int) $sp_cfg['marstek_power'] ?>" min="100" max="10000">
    </div>
    <div>
        <label style="min-height:2.6em;display:flex;align-items:flex-end;">&nbsp;</label>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
            <input data-role="none" type="checkbox" name="marstek_neg" <?= !empty($sp_cfg['marstek_neg']) ? 'checked' : '' ?>> Bei negativem Preis immer laden
        </label>
    </div>
</div>

<h2>Schwellen und Fenster</h2>
<div class="sp-row">
    <div>
        <label>Schwelle &bdquo;g&uuml;nstig&ldquo; (ct/kWh Endpreis)</label>
        <input data-role="none" type="text" name="cheap" value="<?= sp_e($sp_cfg['cheap']) ?>" placeholder="20">
        <div class="sp-small">Darunter meldet das Plugin Niveau 1 (LEVEL=1).</div>
    </div>
    <div>
        <label>Schwelle &bdquo;teuer&ldquo; (ct/kWh Endpreis)</label>
        <input data-role="none" type="text" name="expensive" value="<?= sp_e($sp_cfg['expensive']) ?>" placeholder="35">
        <div class="sp-small">Dar&uuml;ber Niveau 3 (LEVEL=3) &mdash; ideal zum Sperren gro&szlig;er Verbraucher.</div>
    </div>
    <div>
        <label>L&auml;nge des g&uuml;nstigsten Fensters (h)</label>
        <input data-role="none" type="number" name="window" value="<?= (int) $sp_cfg['window'] ?>" min="1" max="12">
        <div class="sp-small">Z.&nbsp;B. 3 f&uuml;r Waschmaschine/Sp&uuml;lmaschine, 4&ndash;6 f&uuml;r E-Auto.</div>
    </div>
</div>

<h2>Ansage und Push je Stunde</h2>
<div style="margin-bottom:6px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($sp_notify['audio']) ? 'checked' : '' ?>> Audioausgabe aktiv
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($sp_notify['push']) ? 'checked' : '' ?>> Push-Nachricht aktiv
    </label>
    <div class="sp-small">Beides an = Ansage + Push. Nur eines an = nur diese Ausgabe. Beides aus = keine Meldung.
    Die Ansage spricht das Plugin selbst; den Push verschickt der Miniserver &uuml;ber <span class="sp-mono">ANN=1</span> (Anleitung Schritt 4).</div>
</div>
<div class="sp-small"><b>Stunden ausw&auml;hlen</b>, zu denen die Preisansage kommen soll (jeweils zur vollen Stunde):</div>
<div class="sp-hours">
<?php for ($sp_h = 0; $sp_h < 24; $sp_h++) { ?>
    <label><input data-role="none" type="checkbox" name="hours[]" value="<?= $sp_h ?>" <?= in_array($sp_h, $sp_hoursel, true) ? 'checked' : '' ?>> <?= sprintf('%02d', $sp_h) ?> Uhr</label>
<?php } ?>
</div>
<div style="margin-top:6px;">
    <button data-role="none" type="button" class="sp-btn" style="margin-top:4px;padding:6px 14px;font-size:0.85em;background:#607d8b;" onclick="spHours(1)">Alle</button>
    <button data-role="none" type="button" class="sp-btn" style="margin-top:4px;padding:6px 14px;font-size:0.85em;background:#607d8b;" onclick="spHours(0)">Keine</button>
    <button data-role="none" type="button" class="sp-btn" style="margin-top:4px;padding:6px 14px;font-size:0.85em;background:#607d8b;" onclick="spHours(2)">Nur tags&uuml;ber (7&ndash;21)</button>
</div>
<div style="margin-top:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="only_cheap" <?= !empty($sp_notify['only_cheap']) ? 'checked' : '' ?>> Nur melden, wenn der Preis unter der &bdquo;g&uuml;nstig&ldquo;-Schwelle liegt
    </label><br>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="neg_always" <?= !empty($sp_notify['negative']) ? 'checked' : '' ?>> Bei negativem B&ouml;rsenpreis IMMER melden (auch in nicht gew&auml;hlten Stunden)
    </label><br>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_tomorrow" <?= !empty($sp_notify['tomorrow']) ? 'checked' : '' ?>> Einmal t&auml;glich melden, sobald die Preise f&uuml;r morgen ver&ouml;ffentlicht sind (ca. 14 Uhr)
    </label>
</div>

<h2>Sprachausgabe</h2>
<div class="sp-row">
    <div>
        <label>Audio-Ausgabe</label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="spTtsMode()">
            <option value="musicserver"<?= $sp_tts['mode'] === 'musicserver' ? ' selected' : '' ?>>Loxone Music Server (klassisch)</option>
            <option value="ms4h"<?= $sp_tts['mode'] === 'ms4h' ? ' selected' : '' ?>>Audioserver4Home / MusicServer4Home</option>
            <option value="audioserver"<?= $sp_tts['mode'] === 'audioserver' ? ' selected' : '' ?>>Original Loxone Audioserver (via Loxone Config)</option>
            <option value="custom"<?= $sp_tts['mode'] === 'custom' ? ' selected' : '' ?>>Eigene URL-Vorlage</option>
        </select>
    </div>
    <div>
        <label>IP des Audio-Servers</label>
        <input data-role="none" type="text" name="tts_ip" value="<?= sp_e($sp_tts['ip']) ?>" placeholder="z. B. 192.168.1.50">
    </div>
    <div>
        <label>Port</label>
        <input data-role="none" type="number" name="tts_port" value="<?= (int) $sp_tts['port'] ?>" min="1" max="65535">
    </div>
</div>
<div class="sp-row">
    <div>
        <label>Zonen</label>
        <input data-role="none" type="text" name="tts_zones" value="<?= sp_e($sp_tts['zones']) ?>" placeholder="z. B. 2,4,6">
        <div class="sp-small">Zonennummern mit Komma (z.&nbsp;B. <span class="sp-mono">2,4,6</span>) &mdash; die Lautst&auml;rke kommt aus dem Feld daneben. Optional je Zone eigene Lautst&auml;rke: <span class="sp-mono">Zone~Lautst&auml;rke</span> (z.&nbsp;B. <span class="sp-mono">2~25,4~40</span>). Leerzeichen nach dem Komma sind erlaubt &mdash; <span class="sp-mono">2,4,6</span> und <span class="sp-mono">2, 4, 6</span> funktionieren beide.</div>
    </div>
    <div>
        <label>Lautst&auml;rke (%)</label>
        <input data-role="none" type="number" name="tts_volume" value="<?= (int) $sp_tts['volume'] ?>" min="1" max="100">
    </div>
    <div>
        <label>Sprache</label>
        <input data-role="none" type="text" name="tts_lang" value="<?= sp_e($sp_tts['lang']) ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label>URL-Vorlage (f&uuml;r Audioserver4Home/MS4H bzw. eigene Ausgabe)</label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= sp_e($sp_tts['template']) ?></textarea>
    <div class="sp-small">Platzhalter: <span class="sp-mono">{ip} {port} {zones} {vol} {lang} {text}</span>. Leer = Standard-Vorlage.</div>
</div>
<div id="tts_audioserver_hint" class="sp-alert sp-info" style="display:none;">
    Der originale Loxone Audioserver bietet <b>keine HTTP-TTS-Schnittstelle</b>. In diesem Modus spricht das Plugin NICHT selbst;
    die Sprachausgabe baut man in Loxone Config: Textgenerator &rarr; TTS-Eingang des Audioplayers, ausgel&ouml;st &uuml;ber
    <span class="sp-mono">ANN=1</span> (Anleitung Schritt 4).
</div>

<h2>MQTT (optional)</h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($sp_cfg['mqtt_enabled']) ? 'checked' : '' ?>> Preise per MQTT ver&ouml;ffentlichen
</label>
<div class="sp-row" style="margin-top:6px;">
    <div>
        <label>Topic-Pr&auml;fix</label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= sp_e($sp_cfg['mqtt_topic']) ?>" placeholder="spot_awattar">
        <div class="sp-small">Nutzt das <b>LoxBerry MQTT Gateway</b>. Ver&ouml;ffentlicht bei &Auml;nderung und mindestens halbst&uuml;ndlich
        &mdash; ab Version 1.0.3 <b>alles, was auch der HTTP-Endpunkt liefert</b>, sodass die Loxone-Konfiguration ganz auf MQTT laufen kann:<br>
        <b>Preise:</b> <span class="sp-mono"><?= sp_e($sp_cfg['mqtt_topic']) ?>/cur</span>, <span class="sp-mono">/cur_boerse</span>,
        <span class="sp-mono">/next</span>, <span class="sp-mono">/rank</span>, <span class="sp-mono">/rankd</span>,
        <span class="sp-mono">/level</span>, <span class="sp-mono">/neg</span>, <span class="sp-mono">/ok</span><br>
        <b>Heute und morgen:</b> <span class="sp-mono">/avg_heute</span>, <span class="sp-mono">/min_heute</span>,
        <span class="sp-mono">/minh_heute</span>, <span class="sp-mono">/max_heute</span>, <span class="sp-mono">/maxh_heute</span>,
        dieselben mit <span class="sp-mono">_morgen</span>, dazu <span class="sp-mono">/morgen_ok</span><br>
        <b>G&uuml;nstigstes Fenster:</b> <span class="sp-mono">/fenster_start</span>, <span class="sp-mono">/fenster_in</span>, <span class="sp-mono">/fenster_ct</span><br>
        <b>CO<sub>2</sub>:</b> <span class="sp-mono">/co2</span>, <span class="sp-mono">/co2_min</span>,
        <span class="sp-mono">/co2_minh</span>, <span class="sp-mono">/co2_clean</span><br>
        <b>Meldesteuerung:</b> <span class="sp-mono">/ann</span>, <span class="sp-mono">/audio</span>,
        <span class="sp-mono">/push</span>, <span class="sp-mono">/ptest</span><br>
        <b>Kostenvergleich:</b> <span class="sp-mono">/fix</span>, <span class="sp-mono">/dyn_monat</span>,
        <span class="sp-mono">/diff_monat</span>, <span class="sp-mono">/euro_monat</span>,
        <span class="sp-mono">/shift_jahr</span>, <span class="sp-mono">/wp_cur</span>, <span class="sp-mono">/wp_next</span><br>
        Das Meldefenster <span class="sp-mono">/ann</span> wird sofort ver&ouml;ffentlicht, wenn es umspringt &mdash;
        es gilt nur die ersten zehn Minuten einer aktivierten Stunde und d&uuml;rfte sonst zu sp&auml;t kommen.</div>
    </div>
</div>

<button data-role="none" class="sp-btn" type="submit">Speichern</button>
</form>
<form method="post" style="margin-top:8px;">
    <input data-role="none" type="hidden" name="fetchnow" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sp-btn" type="submit" style="background:#607d8b;margin-top:0;">Jetzt abrufen</button>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sp-pane" id="tab-loxone">
<h2>Einbindung in Loxone &mdash; Schritt f&uuml;r Schritt</h2>
<p>Der Miniserver fragt das Plugin alle 5 Minuten ab und bekommt fertig berechnete <b>Endpreise</b>
(inkl. Netzentgelte, Abgaben und Umsatzsteuer) &mdash; dazu G&uuml;nstigst-/Teuerst-Stunden f&uuml;r heute und morgen,
den Rang der aktuellen Stunde, ein Preisniveau und das g&uuml;nstigste zusammenh&auml;ngende Zeitfenster.
Die <b>Ansage</b> spricht das Plugin selbst; den <b>Push</b> verschickt der Miniserver.</p>

<div class="sp-step"><b>Schritt 1: Virtueller HTTP-Eingang &bdquo;Spotpreis&ldquo;</b> (Abfrage alle 300 s)
<table class="sp-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>URL</td><td><span class="sp-mono">http://<?= $sp_host ?>/plugins/<?= sp_e($sp_plugin) ?>/spot.php</span></td></tr>
<tr><td>Abfragezyklus</td><td>300 Sekunden</td></tr>
</table>
</div>

<div class="sp-step"><b>Schritt 2: Befehlserkennungen</b> (je ein &bdquo;Virtueller HTTP-Eingang Befehl&ldquo;;
<span class="sp-mono">\i...\i</span> = Suchtext, <span class="sp-mono">\v</span> = Zahl dahinter)
<table class="sp-tbl">
<tr><th>Befehlserkennung</th><th>Bedeutung</th><th>Einheit</th></tr>
<tr><td><span class="sp-mono">\iCUR=\i\v</span></td><td>Endpreis der AKTUELLEN Stunde</td><td>ct/kWh</td></tr>
<tr><td><span class="sp-mono">\iNEXT=\i\v</span></td><td>Endpreis der n&auml;chsten Stunde</td><td>ct/kWh</td></tr>
<tr><td><span class="sp-mono">\iCURB=\i\v</span></td><td>reiner B&ouml;rsenanteil der aktuellen Stunde</td><td>ct/kWh</td></tr>
<tr><td><span class="sp-mono">\iNEG=\i\v</span></td><td>1 = B&ouml;rsenpreis negativ</td><td>&mdash;</td></tr>
<tr><td><span class="sp-mono">\iRANK=\i\v</span></td><td>Rang der aktuellen Stunde in den n&auml;chsten 24 h (1 = g&uuml;nstigste)</td><td>&mdash;</td></tr>
<tr><td><span class="sp-mono">\iRANKD=\i\v</span></td><td>Rang absteigend (1 = teuerste)</td><td>&mdash;</td></tr>
<tr><td><span class="sp-mono">\iLEVEL=\i\v</span></td><td>1 = g&uuml;nstig, 2 = normal, 3 = teuer (Schwellen aus den Einstellungen)</td><td>&mdash;</td></tr>
<tr><td><span class="sp-mono">\iHMINP=\i\v</span> / <span class="sp-mono">\iHMINH=\i\v</span></td><td>g&uuml;nstigster Preis HEUTE / dessen Stunde</td><td>ct/kWh, 0&ndash;23</td></tr>
<tr><td><span class="sp-mono">\iHMAXP=\i\v</span> / <span class="sp-mono">\iHMAXH=\i\v</span></td><td>teuerster Preis heute / dessen Stunde</td><td>ct/kWh, 0&ndash;23</td></tr>
<tr><td><span class="sp-mono">\iHAVG=\i\v</span></td><td>Tagesdurchschnitt heute</td><td>ct/kWh</td></tr>
<tr><td><span class="sp-mono">\iMINP=\i\v</span> / <span class="sp-mono">\iMINH=\i\v</span></td><td>g&uuml;nstigster Preis MORGEN / dessen Stunde</td><td>ct/kWh, 0&ndash;23</td></tr>
<tr><td><span class="sp-mono">\iMAXP=\i\v</span> / <span class="sp-mono">\iMAXH=\i\v</span></td><td>teuerster Preis morgen / dessen Stunde</td><td>ct/kWh, 0&ndash;23</td></tr>
<tr><td><span class="sp-mono">\iAVG=\i\v</span> / <span class="sp-mono">\iOK=\i\v</span></td><td>Durchschnitt morgen / 1 = Preise f&uuml;r morgen liegen vor</td><td>&mdash;</td></tr>
<tr><td><span class="sp-mono">\iWINH=\i\v</span></td><td>Startstunde des g&uuml;nstigsten X-Stunden-Fensters</td><td>0&ndash;23</td></tr>
<tr><td><span class="sp-mono">\iWININ=\i\v</span></td><td>beginnt in ... Stunden (0 = l&auml;uft gerade)</td><td>h</td></tr>
<tr><td><span class="sp-mono">\iWINCT=\i\v</span></td><td>Durchschnittspreis in diesem Fenster</td><td>ct/kWh</td></tr>
<tr><td><span class="sp-mono">\iANN=\i\v</span></td><td>1 = Meldefenster (erste 10 min einer aktivierten Stunde) &mdash; Ausl&ouml;ser f&uuml;r den Push</td><td>&mdash;</td></tr>
<tr><td><span class="sp-mono">\iPUSH=\i\v</span> / <span class="sp-mono">\iAUDIO=\i\v</span></td><td>Freigaben aus der Plugin-Konfiguration</td><td>&mdash;</td></tr>
<tr><td><span class="sp-mono">\iPTEST=\i\v</span></td><td>1 = Test-Pushnachricht angefordert (Reiter Test, 5 min aktiv)</td><td>&mdash;</td></tr>
<tr><td><span class="sp-mono">\iCO2=\i\v</span></td><td>CO&#8322;-Intensit&auml;t des Strommixes JETZT</td><td>g/kWh</td></tr>
<tr><td><span class="sp-mono">\iCO2MIN=\i\v</span> / <span class="sp-mono">\iCO2MINH=\i\v</span></td><td>sauberste Stunde der n&auml;chsten 24 h / deren Uhrzeit</td><td>g/kWh, 0&ndash;23</td></tr>
<tr><td><span class="sp-mono">\iCO2CLEAN=\i\v</span></td><td>1 = unter der Sauber-Schwelle (&bdquo;&Ouml;kostrom-Zeit&ldquo;)</td><td>&mdash;</td></tr>
<tr><td><span class="sp-mono">\iWPCUR=\i\v</span> / <span class="sp-mono">\iWPNEXT=\i\v</span></td><td>Preis nach &sect;&nbsp;14a (W&auml;rmepumpe/Wallbox) jetzt / n&auml;chste Stunde</td><td>ct/kWh</td></tr>
<tr><td><span class="sp-mono">\iFIX=\i\v</span> / <span class="sp-mono">\iDYNM=\i\v</span></td><td>eigener Festpreis / dynamischer Monatsschnitt (gewichtet)</td><td>ct/kWh</td></tr>
<tr><td><span class="sp-mono">\iDIFFM=\i\v</span> / <span class="sp-mono">\iEUROM=\i\v</span></td><td>Vorteil dynamisch (positiv) bzw. fest (negativ) im laufenden Monat</td><td>ct/kWh, EUR</td></tr>
<tr><td><span class="sp-mono">\iSHIFTJ=\i\v</span></td><td>Ersparnis-Potenzial pro Jahr durch verschobenen Verbrauch</td><td>EUR</td></tr>
</table>
<span class="sp-small">Alle Preise sind <b>ct/kWh als Endpreis</b>. Wer die alten EUR-Werte gewohnt ist:
Einheit in der Kachel auf <span class="sp-mono">&lt;v.1&gt; ct</span> stellen.</span>
</div>

<div class="sp-step"><b>Schritt 3: Kacheln f&uuml;r die App</b><br>
CUR, NEXT, HAVG, MINP/MAXP als Analogeing&auml;nge mit Einheit <span class="sp-mono">&lt;v.1&gt; ct</span>;
MINH/MAXH/WINH mit <span class="sp-mono">&lt;v.0&gt; Uhr</span>; RANK ohne Einheit. Visualisierung freigeben,
Raum/Kategorie zuordnen. Sch&ouml;n: einen Statusbaustein bauen, der aus LEVEL die Texte
&bdquo;Strom ist gerade g&uuml;nstig / normal / teuer&ldquo; anzeigt.
</div>

<div class="sp-step"><b>Schritt 4: Komplette Baustein-Liste zum 1:1-Nachbauen</b><br>
<b>4a) Stundenansage-Push</b> (die Ansage selbst spricht das Plugin)
<table class="sp-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Schwellwertschalter S1</td><td>Meldefenster aktiv</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; ANN</td></tr>
<tr><td>Schwellwertschalter S2</td><td>Push freigegeben</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; PUSH</td></tr>
<tr><td>UND U1</td><td>Preis-Push jetzt</td><td></td><td>S1 &amp; S2</td></tr>
<tr><td>ODER O1</td><td>Push-Sammler</td><td>einzige Quelle des Benachrichtigungs-Bausteins!</td><td>U1</td></tr>
<tr><td>Benachrichtigungs-Baustein</td><td>Push &bdquo;Aktueller Strompreis&ldquo;</td><td>Text z. B. &bdquo;Strompreis jetzt &lt;v1.1&gt; ct/kWh&ldquo; (Statusbaustein davor h&auml;ngen, um CUR einzublenden)</td><td>&larr; O1</td></tr>
<tr><td>Benachrichtigungs-Baustein 2</td><td>Test-Push</td><td>eigener Baustein NUR f&uuml;r den Test</td><td>&larr; Schwellwertschalter an PTEST (Ein 0,5/Aus 0,4)</td></tr>
</table>
<b>4b) G&uuml;nstig-/Teuer-Schaltung f&uuml;r gro&szlig;e Verbraucher</b>
<table class="sp-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Schwellwertschalter S3</td><td>Strom ist g&uuml;nstig</td><td>invertiert: Ein bei <b>Unterschreiten</b> (Ein 1,5 / Aus 1,6 an LEVEL) oder direkt an CUR mit der eigenen ct-Schwelle</td><td>&larr; LEVEL bzw. CUR</td></tr>
<tr><td>Schwellwertschalter S4</td><td>Strom ist teuer</td><td>Ein 2,5 / Aus 2,4 an LEVEL</td><td>&larr; LEVEL</td></tr>
<tr><td>Schwellwertschalter S5</td><td>B&ouml;rsenpreis negativ</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; NEG</td></tr>
<tr><td>ODER O2</td><td>Freigabe gro&szlig;e Verbraucher</td><td>&rarr; auf Freigabe-Eingang von Wallbox, W&auml;rmepumpe, Boiler, Speicher-Netzladen</td><td>S3 | S5</td></tr>
<tr><td>UND U2</td><td>Sperre bei Hochpreis</td><td>&rarr; z. B. Heizstab/Boiler sperren</td><td>S4 &amp; (eigene Freigabe)</td></tr>
</table>
<b>4c) G&uuml;nstigstes Fenster nutzen</b> (Waschmaschine, Sp&uuml;lmaschine, E-Auto)
<table class="sp-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Schwellwertschalter S6</td><td>G&uuml;nstigstes Fenster l&auml;uft</td><td>invertiert: Ein bei Unterschreiten von 0,5 (WININ = 0)</td><td>&larr; WININ</td></tr>
<tr><td>UND U3</td><td>Start-Freigabe Ger&auml;t</td><td>&rarr; Schaltsteckdose / Ger&auml;te-Startbefehl</td><td>S6 &amp; Taster &bdquo;Start bei g&uuml;nstigem Strom&ldquo;</td></tr>
<tr><td>Statusbaustein</td><td>Hinweis-Kachel</td><td>Text: &bdquo;G&uuml;nstigstes Fenster ab &lt;v1.0&gt; Uhr (&lt;v2.1&gt; ct)&ldquo;</td><td>I1 &larr; WINH, I2 &larr; WINCT</td></tr>
</table>
<b>4d) Ansage &bdquo;Preise f&uuml;r morgen sind da&ldquo; und Tagesplanung</b>
<table class="sp-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Schwellwertschalter S7</td><td>Preise f&uuml;r morgen vorhanden</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; OK</td></tr>
<tr><td>Impulsgeber bei Uhrzeit</td><td>Impuls 20:00 Tagesvorschau</td><td>20:00 Uhr</td><td></td></tr>
<tr><td>UND U4 + Statusbaustein</td><td>Push &bdquo;Morgen g&uuml;nstigste Stunde&ldquo;</td><td>Text: &bdquo;Morgen am g&uuml;nstigsten um &lt;v1.0&gt; Uhr (&lt;v2.1&gt; ct), am teuersten um &lt;v3.0&gt; Uhr&ldquo;</td><td>U4: Impuls &amp; S7; Status: I1&larr;MINH, I2&larr;MINP, I3&larr;MAXH</td></tr>
</table>
<b>4e) CO&#8322;-optimiertes Schalten</b> (unabh&auml;ngig vom Preis)
<table class="sp-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Schwellwertschalter S8</td><td>&Ouml;kostrom-Zeit</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; CO2CLEAN</td></tr>
<tr><td>ODER O3</td><td>Freigabe &bdquo;sauber ODER g&uuml;nstig&ldquo;</td><td>&rarr; z. B. Warmwasser-Nachheizung, Speicher-Netzladen</td><td>S8 | S3</td></tr>
<tr><td>Statusbaustein</td><td>Kachel &bdquo;Strommix&ldquo;</td><td>Text: &bdquo;&lt;v1.0&gt; g CO2/kWh &mdash; sauberste Stunde um &lt;v2.0&gt; Uhr&ldquo;</td><td>I1 &larr; CO2, I2 &larr; CO2MINH</td></tr>
</table>
<b>4f) Tarifvergleich als Monatsbericht</b>
<table class="sp-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Analogspeicher + Statusbaustein</td><td>Monatsbericht Tarifvergleich</td><td>Text: &bdquo;Dynamisch &lt;v1.1&gt; ct gegen fest &lt;v2.1&gt; ct &mdash; Vorteil &lt;v3.1&gt; ct/kWh&ldquo;; Trigger: Impuls am Monatsersten 8:05</td><td>I1 &larr; DYNM, I2 &larr; FIX, I3 &larr; DIFFM</td></tr>
<tr><td>Schwellwertschalter S9</td><td>Dynamisch w&auml;re g&uuml;nstiger</td><td>Ein 0,5 / Aus 0,4 an DIFFM</td><td>&rarr; Push &bdquo;Tarifwechsel pr&uuml;fen&ldquo;</td></tr>
</table>
<span class="sp-small">Das Plugin sendet denselben Bericht am Monatsersten auch selbst als Ansage (wenn Audio aktiv) und schreibt ihn ins Protokoll.</span>
<br><br><b>Praxis-Erfahrungen zum Benachrichtigungs-Baustein</b> (erspart lange Fehlersuche):<br>
&bull; Er sendet NUR bei einer 0&rarr;1-Flanke. NIEMALS mehrere Quellen direkt an den Eingang legen &mdash;
eine dauerhaft aktive Quelle verschluckt alle weiteren Ausl&ouml;ser. Immer erst im ODER-Baustein sammeln.<br>
&bull; F&uuml;r den Test (PTEST) einen EIGENEN Benachrichtigungs-Baustein verwenden.<br>
&bull; Invertierte Schwellwertschalter (Ein-Wert kleiner als Aus-Wert = Ein bei Unterschreiten) funktionieren zuverl&auml;ssig.
</div>

<div class="sp-step"><b>Schritt 5: MQTT-Alternative + JSON</b><br>
Alle Werte gibt es auch &uuml;ber das LoxBerry MQTT Gateway (Reiter Einstellungen &rarr; MQTT) unter
<span class="sp-mono"><?= sp_e($sp_cfg['mqtt_topic']) ?>/...</span> &mdash; und als JSON f&uuml;r Drittsoftware
(inkl. <b>aller Stundenwerte</b> f&uuml;r eigene Diagramme):
<span class="sp-mono">http://<?= $sp_host ?>/plugins/<?= sp_e($sp_plugin) ?>/spot.php?json=1</span>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sp-pane" id="tab-test">
<h2>Test</h2>
<div class="sp-legende">
<span><i class="sp-punkt sp-b-lesen"></i> Ansehen &mdash; fragt nur ab, ver&auml;ndert nichts</span>
<span><i class="sp-punkt sp-b-technik"></i> Technische Auskunft &mdash; f&uuml;r die Fehlersuche</span>
<span><i class="sp-punkt sp-b-aktion"></i> L&ouml;st etwas aus &mdash; sendet oder ver&auml;ndert</span>
</div>

<h3 class="sp-h3">Ansehen</h3>
<div class="sp-knopfreihe">
<a class="sp-btn sp-b-lesen"  href="/plugins/<?= sp_e($sp_plugin) ?>/spot.php" target="_blank">Loxone-Zeile abrufen</a>
<a class="sp-btn sp-b-lesen"  href="/plugins/<?= sp_e($sp_plugin) ?>/spot.php?json=1" target="_blank">JSON-Ansicht</a>
</div>

<h3 class="sp-h3">Technische Auskunft</h3>
<div class="sp-knopfreihe">
<a class="sp-btn sp-b-technik"  href="/plugins/<?= sp_e($sp_plugin) ?>/spot.php?debug=1" target="_blank">Debug (alle Stundenpreise)</a>
<a class="sp-btn sp-b-technik"  href="/plugins/<?= sp_e($sp_plugin) ?>/spot.php?refresh=1&amp;debug=1" target="_blank">Neu abrufen + Debug</a>
</div>

<h3 class="sp-h3">L&ouml;st etwas aus</h3>
<div class="sp-knopfreihe">
<a class="sp-btn sp-b-aktion"  href="/plugins/<?= sp_e($sp_plugin) ?>/spot.php?say=1" target="_blank">Test-Ansage (aktueller Preis)</a>
<a class="sp-btn sp-b-aktion"  href="/plugins/<?= sp_e($sp_plugin) ?>/spot.php?saytomorrow=1" target="_blank">Test-Ansage (Preise morgen)</a>
<a class="sp-btn sp-b-aktion"  href="/plugins/<?= sp_e($sp_plugin) ?>/spot.php?ptest=1" target="_blank">Test-Pushnachricht</a>
</div>


<div class="sp-small">
&bull; <b>Loxone-Zeile</b> zeigt genau das, was der Miniserver bekommt.<br>
&bull; <b>Debug</b> listet alle Stundenpreise heute und morgen &mdash; jeweils Endpreis und B&ouml;rsenanteil.<br>
&bull; <b>Test-Ansage</b> spricht sofort in den konfigurierten Zonen.<br>
&bull; <b>Test-Pushnachricht</b> setzt <span class="sp-mono">PTEST=1</span> f&uuml;r 5 Minuten &mdash; der Push kommt &uuml;ber den
Test-Benachrichtigungsbaustein in Loxone (Schritt 4a) innerhalb des 300-s-Abfragetakts.<br>
&bull; Die Tabellen unten f&uuml;llen sich mit jedem Tag, den das Plugin l&auml;uft (Tageswerte werden um 23:50 gesichert).
</div>
<?php $sp_mc = function_exists('spot_month_compare') ? spot_month_compare(12) : array(); if ($sp_mc) { ?>
<h2>Monatsvergleich der Arbeitspreise</h2>
<div class="sp-small" style="margin-bottom:6px;">Dynamisch = lastprofil-gewichteter Monatsschnitt der Endpreise.
&bdquo;Vorteil&ldquo; positiv = der dynamische Tarif w&auml;re g&uuml;nstiger gewesen. Die Euro-Spalte rechnet mit
<?= $sp_mon['use'] ? '<b>den gepflegten Monatsmengen</b>' : (int) $sp_cfg['consumption'] . ' kWh Jahresverbrauch (gleichm&auml;&szlig;ig verteilt)' ?>
&mdash; die Spalte &bdquo;kWh&ldquo; zeigt die dabei angesetzte Menge f&uuml;r die bereits erfassten Tage.
Die Tabelle f&uuml;llt sich mit jedem erfassten Tag.</div>
<table class="sp-tbl"><tr><th>Monat</th><th>Tage</th><th>kWh</th><th>dynamisch (gewichtet)</th><th>dynamisch (einfach)</th><th>fest</th><th>Vorteil</th><th>Euro</th></tr>
<?php foreach ($sp_mc as $sp_m) { ?>
<tr><td><?= sp_e(substr($sp_m['monat'], 4, 2) . '/' . substr($sp_m['monat'], 0, 4)) ?></td>
<td><?= (int) $sp_m['tage'] ?></td>
<td><?= sp_n($sp_m['kwh'], 1) ?><?= $sp_m['quelle'] === 'monat' ? '' : '<span class="sp-small" title="aus dem Jahresverbrauch abgeleitet"> *</span>' ?></td>
<td><b><?= sp_n($sp_m['dynp'], 2) ?> ct</b></td><td><?= sp_n($sp_m['dyn'], 2) ?> ct</td>
<td><?= sp_n($sp_m['fix'], 2) ?> ct</td>
<td style="color:<?= $sp_m['diff'] >= 0 ? '#2e7d32' : '#c62828' ?>;"><b><?= ($sp_m['diff'] >= 0 ? '+' : '') . sp_n($sp_m['diff'], 2) ?> ct</b></td>
<td style="color:<?= $sp_m['euro'] >= 0 ? '#2e7d32' : '#c62828' ?>;"><?= ($sp_m['euro'] >= 0 ? '+' : '') . sp_n($sp_m['euro'], 2) ?> &euro;</td></tr>
<?php } ?></table>
<div class="sp-small">* Menge aus dem Jahresverbrauch abgeleitet &mdash; f&uuml;r diesen Monat ist im Reiter Einstellungen kein eigener Wert gepflegt.</div>
<?php } ?>
<?php $sp_sh = function_exists('spot_shift_saving') ? spot_shift_saving(7) : array(); if (!empty($sp_sh['tage'])) { ?>
<h2>Ersparnis durch verschobenen Verbrauch</h2>
<div class="sp-alert sp-ok">Mittlere Spanne zwischen Tagesschnitt und g&uuml;nstigster Stunde der letzten
<?= (int) $sp_sh['tage'] ?> Tage: <b><?= sp_n($sp_sh['ct'], 2) ?> ct/kWh</b>.
Wer t&auml;glich <?= sp_n($sp_sh['kwh'], 1) ?> kWh in die g&uuml;nstigste Zeit verschiebt, spart daraus rechnerisch
<b><?= sp_n($sp_sh['euro'], 2) ?> &euro;</b> in diesen <?= (int) $sp_sh['tage'] ?> Tagen &mdash; hochgerechnet
<b><?= sp_n($sp_sh['euro_jahr'], 2) ?> &euro; im Jahr</b> (zus&auml;tzlich zum reinen Tarifvergleich oben).</div>
<?php } ?>
<?php $sp_hist = function_exists('spot_history_read') ? spot_history_read(14) : array(); if ($sp_hist) { ?>
<h2>Tageswerte der letzten Tage</h2>
<table class="sp-tbl"><tr><th>Tag</th><th>Schnitt</th><th>gewichtet</th><th>Minimum</th><th>Maximum</th><th>CO&#8322;</th></tr>
<?php foreach (array_reverse($sp_hist) as $sp_r) { ?>
<tr><td><?= sp_e(substr($sp_r[0], 6, 2) . '.' . substr($sp_r[0], 4, 2) . '.' . substr($sp_r[0], 0, 4)) ?></td>
<td><?= sp_n($sp_r[1], 2) ?> ct</td><td><?= $sp_r[4] > 0 ? sp_n($sp_r[4], 2) . ' ct' : '&ndash;' ?></td>
<td><?= sp_n($sp_r[2], 2) ?> ct</td><td><?= sp_n($sp_r[3], 2) ?> ct</td>
<td><?= $sp_r[5] > 0 ? (int) $sp_r[5] . ' g' : '&ndash;' ?></td></tr>
<?php } ?></table>
<?php } ?>
</div>

<!-- ================= Reiter: Kostenvergleich ================= -->
<div class="sp-pane" id="tab-costs">
<?php $sp_cc = function_exists('spot_cost_compare') ? spot_cost_compare() : null; if ($sp_cc) { ?>
<h2>Kostenvergleich auf ein Jahr hochgerechnet</h2>
<div class="sp-small" style="margin-bottom:6px;">Beide Tarife mit allen Bestandteilen: Arbeitspreis, Grundpreis,
Rabatt und Boni. Grundlage: <b><?= sp_n($sp_cc['kwh'], 0) ?> kWh</b> Jahresverbrauch
<?= $sp_mon['use'] ? '(aus den Monatswerten)' : '(Jahreswert, gleichm&auml;&szlig;ig verteilt)' ?>,
Preisniveau aus <?= (int) $sp_cc['monate_gemessen'] ?> Monat(en) eigener Aufzeichnung
<?= $sp_cc['monate_gemessen'] < 12 ? '&mdash; f&uuml;r die &uuml;brigen Monate mit dem bisherigen Schnitt von ' . sp_n($sp_cc['schnitt'], 2) . ' ct/kWh' : '' ?>.
Je l&auml;nger das Plugin l&auml;uft, desto belastbarer wird die Zahl.</div>
<table class="sp-tbl" style="width:100%;">
<tr><th>Position</th><th style="text-align:right;">fester Tarif</th><th style="text-align:right;">dynamischer Tarif</th></tr>
<tr><td>Arbeitspreis (Jahr)</td><td style="text-align:right;"><?= sp_n($sp_cc['fix_arbeit'], 2) ?> &euro;</td><td style="text-align:right;"><?= sp_n($sp_cc['dyn_arbeit'], 2) ?> &euro;</td></tr>
<tr><td>Grundpreis (12 Monate)</td><td style="text-align:right;"><?= sp_n($sp_cc['fix_grund'], 2) ?> &euro;</td><td style="text-align:right;"><?= sp_n($sp_cc['dyn_grund'], 2) ?> &euro;</td></tr>
<tr><td>Zwischensumme</td><td style="text-align:right;"><b><?= sp_n($sp_cc['fix_zwischen'], 2) ?> &euro;</b></td><td style="text-align:right;"><b><?= sp_n($sp_cc['dyn_jahr'], 2) ?> &euro;</b></td></tr>
<?php if ($sp_cc['rabatt'] > 0) { ?>
<tr><td>Abschlagsrabatt (<?= sp_n($sp_cc['rabatt_pct'], 1) ?> %)</td><td style="text-align:right;color:#2e7d32;">&minus; <?= sp_n($sp_cc['rabatt'], 2) ?> &euro;</td><td style="text-align:right;">&ndash;</td></tr>
<?php } ?>
<?php if ($sp_cc['boni'] > 0) { ?>
<tr><td>Boni (nur erstes Jahr)</td><td style="text-align:right;color:#2e7d32;">&minus; <?= sp_n($sp_cc['boni'], 2) ?> &euro;</td><td style="text-align:right;">&ndash;</td></tr>
<?php } ?>
<tr style="background:#f5f5f5;"><td><b>Kosten erstes Jahr</b></td><td style="text-align:right;"><b><?= sp_n($sp_cc['fix_jahr1'], 2) ?> &euro;</b><br><span class="sp-small"><?= sp_n($sp_cc['fix_monat1'], 2) ?> &euro;/Monat</span></td>
<td style="text-align:right;"><b><?= sp_n($sp_cc['dyn_jahr'], 2) ?> &euro;</b><br><span class="sp-small"><?= sp_n($sp_cc['dyn_monat'], 2) ?> &euro;/Monat</span></td></tr>
<tr style="background:#f5f5f5;"><td><b>Kosten Folgejahr</b> (ohne Boni)</td><td style="text-align:right;"><b><?= sp_n($sp_cc['fix_folge'], 2) ?> &euro;</b><br><span class="sp-small"><?= sp_n($sp_cc['fix_monatf'], 2) ?> &euro;/Monat</span></td>
<td style="text-align:right;"><b><?= sp_n($sp_cc['dyn_jahr'], 2) ?> &euro;</b><br><span class="sp-small"><?= sp_n($sp_cc['dyn_monat'], 2) ?> &euro;/Monat</span></td></tr>
</table>
<div class="sp-alert <?= $sp_cc['vorteilf'] >= 0 ? 'sp-ok' : 'sp-warn' ?>">
<b>Erstes Jahr:</b> <?= $sp_cc['vorteil1'] >= 0
    ? 'Der dynamische Tarif w&auml;re um <b>' . sp_n(abs($sp_cc['vorteil1']), 2) . ' &euro;</b> g&uuml;nstiger gewesen.'
    : 'Der feste Tarif ist um <b>' . sp_n(abs($sp_cc['vorteil1']), 2) . ' &euro;</b> g&uuml;nstiger &mdash; die Boni machen den Unterschied.' ?><br>
<b>Folgejahr:</b> <?= $sp_cc['vorteilf'] >= 0
    ? 'Der dynamische Tarif w&auml;re um <b>' . sp_n(abs($sp_cc['vorteilf']), 2) . ' &euro;</b> g&uuml;nstiger.'
    : 'Der feste Tarif bleibt um <b>' . sp_n(abs($sp_cc['vorteilf']), 2) . ' &euro;</b> g&uuml;nstiger.' ?>
<div class="sp-small" style="margin-top:4px;">Das Folgejahr ist die ehrlichere Zahl: Sofort- und Neukundenboni gibt es nur einmal,
der Abschlagsrabatt dagegen dauerhaft. Nicht ber&uuml;cksichtigt sind Boni des dynamischen Anbieters und die Ersparnis
durch verschobenen Verbrauch (Reiter <b>Test</b>) &mdash; beides w&uuml;rde den dynamischen Tarif zus&auml;tzlich verbessern.</div>
</div>
<?php } ?>
<?php if (!$sp_cc) { ?>
<h2>Kostenvergleich auf ein Jahr hochgerechnet</h2>
<div class="sp-alert sp-info">Noch keine Auswertung m&ouml;glich &mdash; das Plugin sichert die Tageswerte jeweils um
23:50&nbsp;Uhr. Nach dem ersten vollst&auml;ndigen Tag erscheint hier der Vergleich, und mit jedem weiteren Monat
wird er belastbarer. Pr&uuml;fen Sie im Reiter <b>Einstellungen</b>, ob fester Arbeitspreis, Grundpreis und
Jahres- bzw. Monatsverbrauch eingetragen sind.</div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sp-pane" id="tab-log">
<h2>Logdatei</h2>
<div class="sp-small" style="margin-bottom:8px;">Protokolliert werden Preis&auml;nderungen (Strukturwechsel, kein Zahlenspam), Ansagen, Ver&ouml;ffentlichung der Folgetagspreise und Fehler. Neueste Eintr&auml;ge oben (max. 300 angezeigt).<br>Datei: <span class="sp-mono"><?= sp_e($sp_logfile) ?></span></div>
<?php if ($sp_loglines) { ?>
<div class="sp-log"><?= sp_e(implode("\n", $sp_loglines)) ?></div>
<?php } else { ?>
<div class="sp-alert sp-info">Noch keine Protokoll-Eintr&auml;ge vorhanden.</div>
<?php } ?>
<form method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sp-btn" type="submit" style="background:#c62828;">Log leeren</button>
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
    document.querySelectorAll('.sp-mkwh').forEach(function (i) {
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
    var tabs = document.querySelectorAll('.sp-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sp-active', t.dataset.pane === id); });
        document.querySelectorAll('.sp-pane').forEach(function (p) { p.classList.toggle('sp-active', p.id === id); });
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
