<?php
/**
 * Spotpreis aWATTar - gemeinsame Bibliothek
 *
 * Holt die stuendlichen Boersenpreise (EPEX SPOT) ueber die offene aWATTar-API
 * (DE oder AT), rechnet sie mit den konfigurierten Preisbestandteilen auf den
 * ENDPREIS hoch (Netzentgelte + staatliche Abgaben + Umsatzsteuer) und liefert:
 *   - Loxone-Textzeile (abwaertskompatibel zum klassischen spotzeit.php)
 *   - guenstigste/teuerste Stunde heute+morgen, Rang der aktuellen Stunde,
 *     Preisniveau, guenstigstes zusammenhaengendes X-Stunden-Fenster
 *   - JSON-Zustand und MQTT ueber das LoxBerry MQTT Gateway
 *   - stuendliche Ansage (TTS) und Push-Freigabe, je Stunde einzeln schaltbar
 *
 * Keine persoenlichen Daten im Code - alles kommt aus der lokalen Konfiguration.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');

/** Anzahl der Schaltregeln. Vier decken Wallbox, Speicher, Warmwasser und
 *  Waermepumpe ab - mehr macht die Oberflaeche unuebersichtlich. */
define('SPOT_REGELN', 4);

/** Vorgabe einer Schaltregel. */
function spot_regel_vorgabe() {
    return array(
        'aktiv' => 0,
        'name' => '',
        'art' => 'fenster',   // fenster | stunden | schwelle | mittel
        'n' => 3,             // Anzahl Stunden (fenster/stunden)
        'von' => 0,           // Zeitfenster von (Stunde, einschliesslich)
        'bis' => 0,           // Zeitfenster bis (Stunde, ausschliesslich); von==bis = ganzer Tag
        'horizont' => 24,     // nur die naechsten X Stunden betrachten
        'schwelle' => 20.0,   // ct/kWh Endpreis (art=schwelle)
        'prozent' => 20,      // % unter dem Tagesmittel (art=mittel)
        'neg' => 1,           // bei negativem Boersenpreis immer einschalten
    );
}

function spot_paths() {
    $lbhomedir = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
    $plugindir = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
    if ($lbhomedir && is_dir($lbhomedir . '/config/plugins/' . $plugindir) === false) {
        $plugindir = 'spotpreis';
    }
    if ($lbhomedir) {
        return array(
            'config' => $lbhomedir . '/config/plugins/' . $plugindir . '/spot.json',
            'backup' => $lbhomedir . '/config/plugins/' . $plugindir . '.backup.json',
            'log' => $lbhomedir . '/log/plugins/' . $plugindir . '/spot.log',
            'data' => $lbhomedir . '/data/plugins/' . $plugindir,
            'tmp' => '/tmp/spotpreis',
            'lbhome' => $lbhomedir,
        );
    }
    return array(
        'config' => dirname(dirname(__DIR__)) . '/config/spot.json',
        'backup' => dirname(dirname(__DIR__)) . '/config/spot.backup.json',
        'log' => sys_get_temp_dir() . '/spotpreis/spot.log',
        'data' => sys_get_temp_dir() . '/spotpreis/data',
        'tmp' => sys_get_temp_dir() . '/spotpreis',
        'lbhome' => '',
    );
}

function spot_config() {
    $p = spot_paths();
    // Selbstheilung: fehlende/leere Konfiguration aus Sicherung wiederherstellen
    if ((!is_file($p['config']) || trim((string) @file_get_contents($p['config'])) === '' || trim((string) @file_get_contents($p['config'])) === '{}') && is_file($p['backup'])) {
        @mkdir(dirname($p['config']), 0775, true);
        @copy($p['backup'], $p['config']);
    }
    $cfg = is_file($p['config']) ? (json_decode((string) file_get_contents($p['config']), true) ?: array()) : array();
    if (!is_array($cfg)) {
        $cfg = array();
    }
    $cfg += array(
        'market' => 'de',            // de oder at
        // Preisbestandteile in ct/kWh (netto). Voreinstellung: Netzgebiet einer
        // deutschen Grossstadt 2026 - bitte mit der eigenen Rechnung abgleichen.
        'netz' => 6.47,              // Netzentgelt Arbeitspreis (Grundpreis separat!)
        'steuer' => 2.05,            // Stromsteuer
        'konzession' => 2.39,        // Konzessionsabgabe (Gemeinde ueber 500.000 EW)
        'umlagen' => 2.945,          // KWKG 0,446 + Offshore 0,941 + Par. 19 StromNEV 1,558
        'aufschlag' => 0.0,          // Anbieter-Aufschlag auf den Boersenpreis
        'grundpreis' => 5.27,        // EUR/Monat (Netz-Grundpreis + Messstellenbetrieb)
        'vat' => 19.0,               // Umsatzsteuer in % (DE 19, AT 20)
        'cheap' => 20.0,             // Schwelle "guenstig" in ct/kWh (Endpreis)
        'expensive' => 35.0,         // Schwelle "teuer" in ct/kWh (Endpreis)
        'window' => 3,               // Laenge des gesuchten guenstigsten Fensters (h)
        // Zweiter Preissatz: steuerbare Verbrauchseinrichtung nach Par. 14a EnWG
        // (eigener Zaehlpunkt, Modul 1) - z. B. Waermepumpe oder Wallbox
        'wp_enabled' => 0,
        'wp_name' => "W\u{00e4}rmepumpe",
        'wp_netz' => 3.43,           // Netzentgelt steuerbare Waermepumpe (Wallbox: 4.28)
        'wp_konzession' => 0.61,     // Konzessionsabgabe Schwachlast/Sondervertrag
        // CO2-Intensitaet (Fraunhofer ISE Energy-Charts, ohne Konto)
        'co2_enabled' => 1,
        'co2_clean' => 200,          // Schwelle "sauber" in g CO2/kWh
        // Vergleich fester Tarif <-> dynamischer Tarif
        'fixed_price' => 30.90,      // eigener fester Arbeitspreis in ct/kWh (brutto)
        'fix_grund' => 12.90,        // Grundpreis des festen Tarifs in EUR/Monat
        'fix_sofortbonus' => 0.0,    // einmaliger Sofortbonus in EUR
        'fix_neubonus' => 0.0,       // Neukundenbonus in EUR
        'fix_neubonus_pct' => 0.0,   // ODER Neukundenbonus in % des Jahresbetrags
        'fix_rabatt' => 0.0,         // laufender Rabatt auf den Rechnungsbetrag in %
        'consumption' => 3500,       // Jahresverbrauch kWh (Summe der Monate, falls gepflegt)
        'months' => array(),         // Netzbezug je Monat in kWh (12 Werte, 0 = nicht gepflegt)
        'shift_kwh' => 3.0,          // taeglich verschiebbare Menge in kWh
        // Optionale Kopplung mit dem Marstek-Plugin (Standard AUS)
        'marstek_enabled' => 0,
        'marstek_url' => '',         // leer = automatisch (eigene LoxBerry-IP)
        'marstek_hours' => 4,        // in den X guenstigsten Stunden laden
        'marstek_power' => 2500,     // Ladeleistung in W
        'marstek_neg' => 1,          // bei negativem Preis immer laden
        // Schaltregeln (ab 1.1.0): je Regel EIN fertiges 0/1-Signal fuer Loxone.
        // Bis 1.0.3 lieferte das Plugin nur Zahlen - Startstunde, Stunden bis
        // dahin, Durchschnittspreis. Daraus "jetzt laden" zu machen war Arbeit
        // im Miniserver. Siehe spot_regel_werte().
        'regeln' => array(),
        // Stundenprofil: aus | absolut | relativ | beides
        //   absolut  PH00..PH23 heute, PM00..PM23 morgen -> Spot Price
        //            Optimizer im Modus "Absolut" (Eingaenge 00:00 bis 23:00)
        //   relativ  PR00..PR23 ab der laufenden Stunde   -> Modus "Relativ"
        //            (Eingaenge +0 bis +23)
        // Nicht beides als Vorgabe: jeder Wert ist ein virtueller Eingang im
        // Miniserver, und 72 davon legt man nicht versehentlich an.
        'profil_ein' => 'absolut',
        'mqtt_enabled' => 0,
        'mqtt_topic' => 'spot_awattar',
        'notify' => array(),
        'tts' => array(),
    );
    // Alte Konfigurationen trugen hier 0/1 - auf die neuen Namen heben.
    if ($cfg['profil_ein'] === 1 || $cfg['profil_ein'] === '1' || $cfg['profil_ein'] === true) {
        $cfg['profil_ein'] = 'absolut';
    } elseif ($cfg['profil_ein'] === 0 || $cfg['profil_ein'] === '0' || $cfg['profil_ein'] === false) {
        $cfg['profil_ein'] = 'aus';
    }
    if (!in_array($cfg['profil_ein'], array('aus', 'absolut', 'relativ', 'beides'), true)) {
        $cfg['profil_ein'] = 'absolut';
    }
    if (!is_array($cfg['regeln'])) { $cfg['regeln'] = array(); }
    for ($i = 0; $i < SPOT_REGELN; $i++) {
        $r = isset($cfg['regeln'][$i]) && is_array($cfg['regeln'][$i]) ? $cfg['regeln'][$i] : array();
        $r += spot_regel_vorgabe();
        $r['aktiv'] = empty($r['aktiv']) ? 0 : 1;
        $r['neg'] = empty($r['neg']) ? 0 : 1;
        $r['name'] = trim((string) $r['name']);
        $r['art'] = in_array($r['art'], array('fenster', 'stunden', 'schwelle', 'mittel'), true)
                  ? $r['art'] : 'fenster';
        $r['n'] = max(1, min(12, (int) $r['n']));
        $r['von'] = max(0, min(23, (int) $r['von']));
        $r['bis'] = max(0, min(23, (int) $r['bis']));
        $r['horizont'] = max(1, min(48, (int) $r['horizont']));
        $r['schwelle'] = (float) $r['schwelle'];
        $r['prozent'] = max(0, min(90, (int) $r['prozent']));
        $cfg['regeln'][$i] = $r;
    }
    if (!is_array($cfg['notify'])) { $cfg['notify'] = array(); }
    if (!is_array($cfg['tts'])) { $cfg['tts'] = array(); }
    $cfg['notify'] += array(
        'audio' => 0,
        'push' => 0,
        'hours' => array(),          // Liste der Stunden 0-23 mit Ansage/Push
        'only_cheap' => 0,           // nur melden, wenn Preis unter "guenstig"-Schwelle
        'negative' => 1,             // zusaetzlich immer bei negativem Boersenpreis
        'tomorrow' => 0,             // Meldung, sobald die Preise fuer morgen da sind
    );
    if (!is_array($cfg['notify']['hours'])) { $cfg['notify']['hours'] = array(); }
    if (!is_array($cfg['months'])) { $cfg['months'] = array(); }
    for ($i = 0; $i < 12; $i++) {
        $cfg['months'][$i] = isset($cfg['months'][$i]) ? max(0, (float) $cfg['months'][$i]) : 0.0;
    }
    $cfg['tts'] += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091,
                         'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');
    return $cfg;
}

function spot_tmpdir() {
    $p = spot_paths();
    if (!is_dir($p['tmp'])) {
        @mkdir($p['tmp'], 0775, true);
    }
    return $p['tmp'];
}

function spot_datadir() {
    $p = spot_paths();
    if (!is_dir($p['data'])) {
        @mkdir($p['data'], 0775, true);
    }
    return $p['data'];
}

/* ---------------- Protokoll ---------------- */

function spot_log($msg) {
    $p = spot_paths();
    $f = $p['log'];
    $dir = dirname($f);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_file($f) && filesize($f) > 512000) { // Rotation: letzte 200 Zeilen behalten
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($f, implode("\n", $tail) . "\n");
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function spot_log_if_changed($key, $line) {
    $f = spot_tmpdir() . '/last_' . $key . '.txt';
    $prev = is_file($f) ? (string) file_get_contents($f) : '';
    if ($line !== $prev) {
        spot_log($key . ': ' . $line);
        @file_put_contents($f, $line);
    }
}

/* ---------------- Preisrechnung ---------------- */

/** Aufschlaege netto in EUR/kWh. */
function spot_addon_net() {
    $c = spot_config();
    return ((float) $c['netz'] + (float) $c['steuer'] + (float) $c['konzession']
          + (float) $c['umlagen'] + (float) $c['aufschlag']) / 100.0;
}

/** Aufschlaege netto in EUR/kWh fuer den zweiten Preissatz (Par. 14a). */
function spot_addon_net_wp() {
    $c = spot_config();
    return ((float) $c['wp_netz'] + (float) $c['steuer'] + (float) $c['wp_konzession']
          + (float) $c['umlagen'] + (float) $c['aufschlag']) / 100.0;
}

/** Boersenpreis (EUR/kWh netto) -> Endpreis (EUR/kWh brutto). */
function spot_endprice($boerse_net) {
    $c = spot_config();
    $vat = 1 + max(0, (float) $c['vat']) / 100.0;
    return round(($boerse_net + spot_addon_net()) * $vat, 5);
}

/** Boersenpreis -> Endpreis fuer den Par.-14a-Preissatz (Waermepumpe/Wallbox). */
function spot_endprice_wp($boerse_net) {
    $c = spot_config();
    $vat = 1 + max(0, (float) $c['vat']) / 100.0;
    return round(($boerse_net + spot_addon_net_wp()) * $vat, 5);
}

/**
 * Vereinfachtes Haushalts-Lastprofil (H0-aehnlich), Summe ca. 24.
 * Dient nur der GEWICHTUNG beim Vergleich fester/dynamischer Tarif -
 * ohne echte Verbrauchsdaten waere ein simpler Mittelwert zu optimistisch.
 */
function spot_profile() {
    return array(0.55, 0.50, 0.45, 0.45, 0.50, 0.60, 0.85, 1.15, 1.25, 1.20, 1.15, 1.20,
                 1.30, 1.20, 1.05, 1.00, 1.05, 1.25, 1.45, 1.50, 1.40, 1.20, 0.95, 0.70);
}

/* ---------------- Marktdaten (aWATTar) ---------------- */

/** Stundenpreise eines Tages: [ts => boersenpreis EUR/kWh netto] oder null. */
function spot_day($startTs, $force = false) {
    $cfg = spot_config();
    $tld = $cfg['market'] === 'at' ? 'at' : 'de';
    $cache = spot_datadir() . '/markt_' . $tld . '_' . date('Ymd', $startTs) . '.json';
    $start = $startTs * 1000;
    $end = $start + 24 * 3600 * 1000;
    $js = false;
    if (!$force && is_file($cache) && time() - filemtime($cache) < 900) {
        $js = file_get_contents($cache);
    } else {
        $url = "https://api.awattar.$tld/v1/marketdata?start=$start&end=$end";
        $ctx = stream_context_create(array('http' => array('timeout' => 15, 'user_agent' => 'LoxBerry Spotpreis')));
        $neu = @file_get_contents($url, false, $ctx);
        if ($neu !== false && strpos($neu, 'marketprice') !== false) {
            file_put_contents($cache, $neu);
            $js = $neu;
        } elseif (is_file($cache)) {
            $js = file_get_contents($cache);
        }
    }
    $d = @json_decode((string) $js, true);
    if (!isset($d['data']) || count($d['data']) < 20) {
        return null;
    }
    $out = array();
    foreach ($d['data'] as $row) {
        $ts = (int) ($row['start_timestamp'] / 1000);
        $out[$ts] = round($row['marketprice'] / 1000, 6); // EUR/MWh -> EUR/kWh (netto, Boerse)
    }
    ksort($out);
    // Alte Cache-Dateien aufraeumen
    if (rand(0, 40) === 0) {
        foreach (glob(spot_datadir() . '/markt_*.json') ?: array() as $old) {
            if (time() - (int) filemtime($old) > 10 * 86400) {
                @unlink($old);
            }
        }
    }
    return $out;
}

/** Kennzahlen eines Tages (Endpreise in ct/kWh). */
function spot_daystats($prices) {
    if (!$prices) {
        return null;
    }
    $min = null; $max = null; $sum = 0; $n = 0; $hours = array();
    foreach ($prices as $ts => $bp) {
        $h = (int) date('G', $ts);
        $ct = round(spot_endprice($bp) * 100, 3);
        $hours[$h] = array('ct' => $ct, 'boerse' => round($bp * 100, 3), 'ts' => $ts);
        $sum += $ct; $n++;
        if ($min === null || $ct < $min[1]) { $min = array($h, $ct); }
        if ($max === null || $ct > $max[1]) { $max = array($h, $ct); }
    }
    ksort($hours);
    return array('minh' => $min[0], 'minp' => $min[1], 'maxh' => $max[0], 'maxp' => $max[1],
                 'avg' => round($sum / max(1, $n), 3), 'n' => $n, 'hours' => $hours);
}

/** Guenstigstes zusammenhaengendes Fenster ab jetzt (Laenge $len Stunden). */
function spot_window($all, $len) {
    $len = max(1, min(12, (int) $len));
    $now = time(); $hstart = $now - ($now % 3600);
    $list = array();
    foreach ($all as $ts => $ct) {
        if ($ts >= $hstart) {
            $list[$ts] = $ct;
        }
    }
    ksort($list);
    $ks = array_keys($list);
    $best = null;
    for ($i = 0; $i + $len <= count($ks); $i++) {
        // nur zusammenhaengende Stunden
        if ($ks[$i + $len - 1] - $ks[$i] !== ($len - 1) * 3600) {
            continue;
        }
        $s = 0;
        for ($j = 0; $j < $len; $j++) {
            $s += $list[$ks[$i + $j]];
        }
        $avg = $s / $len;
        if ($best === null || $avg < $best[1]) {
            $best = array($ks[$i], round($avg, 3));
        }
    }
    if ($best === null) {
        return array('h' => -1, 'ct' => 0, 'in' => -1);
    }
    return array('h' => (int) date('G', $best[0]), 'ct' => $best[1], 'in' => (int) round(($best[0] - $hstart) / 3600));
}

/* ==================================================================
 * Schaltregeln - fertige 0/1-Signale statt Zahlen
 *
 * Bis 1.0.3 lieferte das Plugin WINH, WININ und WINCT: Startstunde,
 * Stunden bis dahin, Durchschnittspreis. Alles Zahlen. Wer daraus
 * "jetzt laden" machen wollte, baute im Miniserver eine Kaskade aus
 * Vergleichern und Zeitbausteinen - und genau daran scheitern die
 * meisten. Eine Schaltregel beantwortet die Frage hier und gibt eine
 * Eins oder eine Null aus. In Loxone bleibt ein digitaler Eingang.
 *
 * Vier Arten:
 *   fenster   die N guenstigsten Stunden AM STUECK   (Wallbox, Waschmaschine)
 *   stunden   die N guenstigsten Einzelstunden       (Speicher, Warmwasser)
 *   schwelle  Preis unter einem festen Wert          (Heizstab)
 *   mittel    Preis X % unter dem Tagesmittel        (mitlaufend)
 *
 * Jede Regel kennt zusaetzlich ein Zeitfenster (z. B. 22 bis 6 Uhr) und
 * einen Horizont (nur die naechsten X Stunden ansehen). Erst damit laesst
 * sich "die 3 guenstigsten Stunden zwischen 22 und 6 Uhr" beantworten -
 * die alte Fensterrechnung sah immer alle verbleibenden Stunden an.
 * ================================================================== */

/** Liegt die Stunde $h im Zeitfenster? von == bis bedeutet: ganzer Tag. */
function spot_in_zeitfenster($h, $von, $bis) {
    $h = (int) $h; $von = (int) $von; $bis = (int) $bis;
    if ($von === $bis) { return true; }
    if ($von < $bis) { return $h >= $von && $h < $bis; }
    return $h >= $von || $h < $bis;   // ueber Mitternacht, z. B. 22 bis 6
}

/** Die Stunden, die fuer eine Regel ueberhaupt in Frage kommen. ts => ct. */
function spot_regel_kandidaten($r, $all, $hstart) {
    $ende = $hstart + max(1, (int) $r['horizont']) * 3600;
    $out = array();
    foreach ($all as $ts => $ct) {
        if ($ts < $hstart || $ts >= $ende) { continue; }
        if (!spot_in_zeitfenster((int) date('G', $ts), $r['von'], $r['bis'])) { continue; }
        $out[$ts] = $ct;
    }
    ksort($out);
    return $out;
}

/**
 * Eine Regel auswerten.
 * Rueckgabe: aktiv (0/1), in (Stunden bis zum naechsten Treffer, -1 = keiner),
 * rest (verbleibende Trefferstunden ab jetzt), ct (Schnitt der Treffer),
 * start (Startstunde des naechsten Treffers, -1 = keiner), grund.
 */
function spot_regel_werte($r, $all, $st) {
    $leer = array('aktiv' => 0, 'in' => -1, 'rest' => 0, 'ct' => 0.0, 'start' => -1, 'grund' => 'aus');
    if (empty($r['aktiv'])) {
        return $leer;
    }
    $hstart = (int) $st['hstart'];
    $kand = spot_regel_kandidaten($r, $all, $hstart);
    $treffer = array();

    if ($r['art'] === 'fenster') {
        // Guenstigstes zusammenhaengendes Fenster. Zusammenhaengend heisst:
        // luekenlos in der Zeit - ueber eine fehlende Stunde hinweg wird nicht
        // geklebt, sonst stuende die Wallbox mittendrin still.
        $ks = array_keys($kand);
        $len = min(max(1, (int) $r['n']), count($ks));
        $best = null;
        for ($i = 0; $len > 0 && $i + $len <= count($ks); $i++) {
            if ($ks[$i + $len - 1] - $ks[$i] !== ($len - 1) * 3600) { continue; }
            $s = 0;
            for ($j = 0; $j < $len; $j++) { $s += $kand[$ks[$i + $j]]; }
            if ($best === null || $s / $len < $best[1]) { $best = array($i, $s / $len); }
        }
        if ($best !== null) {
            for ($j = 0; $j < $len; $j++) { $treffer[] = $ks[$best[0] + $j]; }
        }
    } elseif ($r['art'] === 'stunden') {
        // Die N guenstigsten Einzelstunden - sie duerfen ueber den Tag
        // verstreut liegen.
        $sortiert = $kand;
        asort($sortiert);
        $treffer = array_slice(array_keys($sortiert), 0, max(1, (int) $r['n']));
        sort($treffer);
    } else {
        // schwelle: fester Wert. mittel: Abstand zum Tagesmittel.
        if ($r['art'] === 'schwelle') {
            $grenze = (float) $r['schwelle'];
        } else {
            $mittel = (float) $st['heute']['avg'];
            if ($mittel <= 0 && $kand) { $mittel = array_sum($kand) / count($kand); }
            $grenze = round($mittel * (1 - max(0, min(90, (int) $r['prozent'])) / 100), 3);
        }
        foreach ($kand as $ts => $ct) {
            if ($ct <= $grenze) { $treffer[] = $ts; }
        }
    }

    $erg = $leer;
    if ($treffer) {
        $erg['ct'] = round(array_sum(array_intersect_key($kand, array_flip($treffer))) / count($treffer), 3);
        $erg['aktiv'] = in_array($hstart, $treffer, true) ? 1 : 0;
        foreach ($treffer as $ts) {
            if ($ts >= $hstart) {
                $erg['start'] = (int) date('G', $ts);
                $erg['in'] = (int) round(($ts - $hstart) / 3600);
                break;
            }
        }
        if ($erg['aktiv']) {
            // Wie lange laeuft es noch am Stueck? Eine Luecke beendet die Zaehlung.
            $rest = 0;
            for ($ts = $hstart; in_array($ts, $treffer, true); $ts += 3600) { $rest++; }
            $erg['rest'] = $rest;
        }
        $erg['grund'] = $erg['aktiv'] ? $r['art'] : 'wartet';
    }

    // Negativer Boersenpreis sticht - wer dann nicht laedt, verschenkt Geld.
    if (!empty($r['neg']) && !empty($st['neg'])) {
        $erg['aktiv'] = 1;
        $erg['in'] = 0;
        $erg['rest'] = max(1, (int) $erg['rest']);
        $erg['grund'] = 'negativ';
    }
    return $erg;
}

/** Alle Regeln auswerten. Rueckgabe: Liste mit Name, Art und Werten. */
function spot_regeln($all, $st) {
    $cfg = spot_config();
    $out = array();
    foreach ($cfg['regeln'] as $i => $r) {
        $w = spot_regel_werte($r, $all, $st);
        $w['nr'] = $i + 1;
        $w['name'] = $r['name'] !== '' ? $r['name'] : ('Regel ' . ($i + 1));
        $w['art'] = $r['art'];
        $w['ein'] = empty($r['aktiv']) ? 0 : 1;
        $out[] = $w;
    }
    return $out;
}

/** Kompletter Zustand (Cache 5 min). */
function spot_state($force = false) {
    $cfg = spot_config();
    $cache = spot_tmpdir() . '/state.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 300) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c) && isset($c['hstart']) && $c['hstart'] === (time() - time() % 3600)) {
            return $c;
        }
    }
    $ph = spot_day(strtotime('today 00:00'), $force);
    $pm = spot_day(strtotime('tomorrow 00:00'), $force);
    $sh = spot_daystats($ph);
    $sm = spot_daystats($pm);
    $now = time(); $hstart = $now - ($now % 3600);
    // Endpreise aller bekannten Stunden (heute + morgen)
    $all = array();
    foreach (array($ph, $pm) as $set) {
        if (!$set) { continue; }
        foreach ($set as $ts => $bp) {
            $all[$ts] = round(spot_endprice($bp) * 100, 3);
        }
    }
    ksort($all);
    $cur = isset($all[$hstart]) ? $all[$hstart] : 0;
    $curb = ($ph && isset($ph[$hstart])) ? round($ph[$hstart] * 100, 3) : (($pm && isset($pm[$hstart])) ? round($pm[$hstart] * 100, 3) : 0);
    $next = isset($all[$hstart + 3600]) ? $all[$hstart + 3600] : 0;
    // Rang der aktuellen Stunde in den naechsten 24 h
    $win = array();
    foreach ($all as $ts => $ct) {
        if ($ts >= $hstart && $ts < $hstart + 24 * 3600) {
            $win[$ts] = $ct;
        }
    }
    $vals = array_values($win);
    sort($vals);
    $rank = 1;
    foreach ($vals as $v) {
        if ($v < $cur) { $rank++; }
    }
    // Preisniveau relativ zu den Schwellen
    $level = 2; // 1=guenstig 2=normal 3=teuer
    if ($cur <= (float) $cfg['cheap']) { $level = 1; }
    if ($cur >= (float) $cfg['expensive']) { $level = 3; }
    $st = array(
        'ok' => $sh ? 1 : 0,
        'tomorrow_ok' => $sm ? 1 : 0,
        'market' => $cfg['market'],
        'hstart' => $hstart,
        'stunde' => (int) date('G'),
        'cur' => $cur,
        'cur_boerse' => $curb,
        'next' => $next,
        'neg' => $curb < 0 ? 1 : 0,
        'rank' => $rank,
        'rankd' => count($vals) ? count($vals) + 1 - $rank : 99,
        'n' => count($vals),
        'level' => $level,
        'heute' => $sh ? $sh : array('minh' => 0, 'minp' => 0, 'maxh' => 0, 'maxp' => 0, 'avg' => 0, 'n' => 0, 'hours' => array()),
        'morgen' => $sm ? $sm : array('minh' => 0, 'minp' => 0, 'maxh' => 0, 'maxp' => 0, 'avg' => 0, 'n' => 0, 'hours' => array()),
        'fenster' => spot_window($all, $cfg['window']),
        'fenster_len' => (int) $cfg['window'],
        'addon' => round(spot_addon_net() * 100, 3),
        'vat' => (float) $cfg['vat'],
        'ts' => time(),
    );
    // Zweiter Preissatz (Par. 14a: Waermepumpe/Wallbox mit eigenem Zaehlpunkt)
    $st['wp_on'] = empty($cfg['wp_enabled']) ? 0 : 1;
    $st['wp_name'] = (string) $cfg['wp_name'];
    $st['wp_addon'] = round(spot_addon_net_wp() * 100, 3);
    $st['wp_cur'] = $st['ok'] ? round(spot_endprice_wp($curb / 100) * 100, 3) : 0;
    $st['wp_next'] = ($st['ok'] && isset($all[$hstart + 3600]))
        ? round(spot_endprice_wp((($all[$hstart + 3600] / 100) / (1 + $st['vat'] / 100)) - spot_addon_net()) * 100, 3) : 0;
    // CO2-Intensitaet
    $co2 = spot_co2();
    $st['co2'] = $co2['now'];
    $st['co2_ok'] = $co2['ok'];
    $st['co2_min'] = $co2['min'];
    $st['co2_minh'] = $co2['minh'];
    $st['co2_avg'] = $co2['avg'];
    $st['co2_clean'] = ($co2['ok'] && $co2['now'] > 0 && $co2['now'] <= (float) $cfg['co2_clean']) ? 1 : 0;
    // Tarifvergleich (laufender Monat) und Verschiebe-Potenzial
    $mc = spot_month_compare(1);
    $cur_m = $mc ? reset($mc) : null;
    $st['fix'] = (float) $cfg['fixed_price'];
    $st['dyn_monat'] = $cur_m ? $cur_m['dynp'] : 0;
    $st['diff_monat'] = $cur_m ? $cur_m['diff'] : 0;
    $st['euro_monat'] = $cur_m ? $cur_m['euro'] : 0;
    $sh = spot_shift_saving(7);
    $st['shift_ct'] = $sh['ct'];
    $st['shift_euro'] = $sh['euro'];
    $st['shift_jahr'] = $sh['euro_jahr'];
    // Stundenprofil als flache Listen - so kommt es ohne JSON in den
    // Miniserver (PH00..PH23 heute, PM00..PM23 morgen).
    $st['profil_heute'] = array();
    $st['profil_morgen'] = array();
    for ($h = 0; $h < 24; $h++) {
        $st['profil_heute'][$h] = isset($st['heute']['hours'][$h]['ct'])
            ? round((float) $st['heute']['hours'][$h]['ct'], 3) : 0.0;
        $st['profil_morgen'][$h] = isset($st['morgen']['hours'][$h]['ct'])
            ? round((float) $st['morgen']['hours'][$h]['ct'], 3) : 0.0;
    }
    // Rollend ab der laufenden Stunde - fuer den Modus "Relativ" des Spot
    // Price Optimizer (Eingaenge +0 bis +23).
    $st['profil_relativ'] = array();
    for ($h = 0; $h < 24; $h++) {
        $st['profil_relativ'][$h] = isset($all[$hstart + $h * 3600])
            ? round((float) $all[$hstart + $h * 3600], 3) : 0.0;
    }
    // Schaltregeln zuletzt: sie brauchen neg, hstart und das Tagesmittel.
    $st['regeln'] = spot_regeln($all, $st);
    file_put_contents($cache, json_encode($st));
    spot_log_if_changed('zustand', 'cur=' . $st['cur'] . ' ct rank=' . $st['rank'] . ' level=' . $st['level'] . ' morgen_ok=' . $st['tomorrow_ok']);
    return $st;
}

/* ---------------- CO2-Intensitaet (Fraunhofer ISE Energy-Charts) ---------------- */

/**
 * Stuendliche CO2-Intensitaet des Strommixes in g CO2-Aequivalent je kWh.
 * Quelle: https://api.energy-charts.info/co2eq (frei, ohne Konto, inkl. Prognose).
 * Rueckgabe: array('now'=>g, 'min'=>g, 'minh'=>Stunde, 'max'=>g, 'maxh'=>Stunde,
 *                  'avg'=>g, 'hours'=>[Stunde=>g], 'ok'=>0/1)
 */
function spot_co2($force = false) {
    $cfg = spot_config();
    $off = array('ok' => 0, 'now' => 0, 'min' => 0, 'minh' => -1, 'max' => 0, 'maxh' => -1, 'avg' => 0, 'hours' => array());
    if (empty($cfg['co2_enabled'])) {
        return $off;
    }
    $cache = spot_tmpdir() . '/co2.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 1800) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c) && isset($c['ok'])) {
            return $c;
        }
    }
    $land = $cfg['market'] === 'at' ? 'at' : 'de';
    $ctx = stream_context_create(array('http' => array('timeout' => 15, 'user_agent' => 'LoxBerry Spotpreis')));
    $js = @file_get_contents('https://api.energy-charts.info/co2eq?country=' . $land, false, $ctx);
    $d = @json_decode((string) $js, true);
    if (!isset($d['unix_seconds']) || !is_array($d['unix_seconds'])) {
        if (is_file($cache)) {
            $c = json_decode((string) file_get_contents($cache), true);
            if (is_array($c)) {
                return $c;
            }
        }
        spot_log_if_changed('co2', 'Abruf fehlgeschlagen (api.energy-charts.info)');
        return $off;
    }
    // Messwerte und Prognose zu Stundenmittelwerten zusammenfassen
    $buckets = array();
    foreach ($d['unix_seconds'] as $i => $ts) {
        $v = isset($d['co2eq'][$i]) ? $d['co2eq'][$i] : null;
        if ($v === null && isset($d['co2eq_forecast'][$i])) {
            $v = $d['co2eq_forecast'][$i];
        }
        if ($v === null) {
            continue;
        }
        $hts = ((int) $ts) - (((int) $ts) % 3600);
        if (!isset($buckets[$hts])) {
            $buckets[$hts] = array(0, 0);
        }
        $buckets[$hts][0] += (float) $v;
        $buckets[$hts][1]++;
    }
    ksort($buckets);
    $now = time(); $hstart = $now - ($now % 3600);
    $hours = array(); $min = null; $max = null; $sum = 0; $n = 0; $cur = 0;
    foreach ($buckets as $hts => $b) {
        $g = round($b[0] / max(1, $b[1]));
        if ($hts === $hstart) {
            $cur = $g;
        }
        if ($hts < $hstart || $hts >= $hstart + 24 * 3600) {
            continue; // Fenster: naechste 24 h
        }
        $h = (int) date('G', $hts);
        $hours[$h] = $g;
        $sum += $g; $n++;
        if ($min === null || $g < $min[1]) { $min = array($h, $g); }
        if ($max === null || $g > $max[1]) { $max = array($h, $g); }
    }
    if (!$n) {
        return $off;
    }
    $out = array('ok' => 1, 'now' => $cur, 'min' => $min[1], 'minh' => $min[0],
                 'max' => $max[1], 'maxh' => $max[0], 'avg' => round($sum / $n), 'hours' => $hours, 'ts' => time());
    file_put_contents($cache, json_encode($out));
    spot_log_if_changed('co2', 'jetzt ' . $out['now'] . ' g/kWh, sauberste Stunde ' . $out['minh'] . ' Uhr mit ' . $out['min'] . ' g');
    return $out;
}

/* ---------------- Tarifvergleich fest <-> dynamisch ---------------- */

/**
 * Monatsvergleich aus der Tages-Historie:
 * - dyn_simple : ungewichteter Mittelwert aller Stundenpreise
 * - dyn_prof   : mit Haushalts-Lastprofil gewichtet (realistischer)
 * - fix        : eingestellter Festpreis
 * Rueckgabe je Monat: array('monat', 'tage', 'dyn', 'dynp', 'fix', 'diff', 'euro')
 */
function spot_month_compare($months = 12) {
    $cfg = spot_config();
    $fix = (float) $cfg['fixed_price'];
    $mon = spot_months();
    $agg = array();
    foreach (spot_history_read(400) as $r) {
        $m = substr($r[0], 0, 6);
        if (!isset($agg[$m])) {
            $agg[$m] = array('n' => 0, 'sum' => 0, 'sump' => 0);
        }
        $agg[$m]['n']++;
        $agg[$m]['sum'] += $r[1];
        $agg[$m]['sump'] += (isset($r[4]) && $r[4] > 0) ? $r[4] : $r[1];
    }
    $out = array();
    foreach ($agg as $m => $a) {
        $dyn = round($a['sum'] / max(1, $a['n']), 3);
        $dynp = round($a['sump'] / max(1, $a['n']), 3);
        $diff = round($fix - $dynp, 3); // positiv = dynamisch waere guenstiger gewesen
        // Verbrauchsmenge: gepflegter Monatswert, sonst Jahresverbrauch/365
        $mi = ((int) substr($m, 4, 2)) - 1;
        $tage_mon = (int) date('t', strtotime(substr($m, 0, 4) . '-' . substr($m, 4, 2) . '-01'));
        $kwh_tag = ($mon['use'] && !empty($mon['kwh'][$mi]))
            ? $mon['kwh'][$mi] / max(1, $tage_mon)
            : max(0.1, (float) $cfg['consumption']) / 365.0;
        $out[$m] = array(
            'monat' => $m, 'tage' => $a['n'], 'dyn' => $dyn, 'dynp' => $dynp, 'fix' => $fix,
            'diff' => $diff, 'euro' => round($diff / 100 * $kwh_tag * $a['n'], 2),
            'kwh' => round($kwh_tag * $a['n'], 1), 'quelle' => ($mon['use'] && !empty($mon['kwh'][$mi])) ? 'monat' : 'jahr',
        );
    }
    krsort($out);
    return array_slice($out, 0, max(1, (int) $months), true);
}

/**
 * Monatsverbraeuche: Rueckgabe array('use'=>0/1, 'kwh'=>[12 Werte], 'summe'=>kWh).
 * 'use' = 1, sobald mindestens ein Monat gepflegt ist - dann rechnet der
 * Tarifvergleich monatsgenau (wichtig bei PV: Sommer wenig, Winter viel Zukauf).
 */
function spot_months() {
    $cfg = spot_config();
    $kwh = array();
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $v = isset($cfg['months'][$i]) ? max(0, (float) $cfg['months'][$i]) : 0.0;
        $kwh[$i] = $v;
        $sum += $v;
    }
    return array('use' => $sum > 0 ? 1 : 0, 'kwh' => $kwh, 'summe' => round($sum, 1));
}

/**
 * VOLLKOSTEN-VERGLEICH auf ein Jahr hochgerechnet:
 * fester Tarif (Arbeitspreis + Grundpreis, abzueglich Rabatt und Boni) gegen
 * dynamischen Tarif (Spot-Endpreise + Grundpreis der Preiszusammensetzung).
 *
 * Preisquelle je Monat: gepflegte Historie (lastprofil-gewichtet), sonst der
 * Mittelwert aller erfassten Tage. Ohne jede Historie wird der heutige
 * Tagesschnitt genommen - dann ist das Ergebnis nur eine grobe Momentaufnahme.
 */
function spot_cost_compare() {
    $cfg = spot_config();
    $mon = spot_months();
    $kwh_jahr = $mon['use'] ? $mon['summe'] : max(0, (float) $cfg['consumption']);
    // Preisniveau je Monat aus der Historie
    $mpreis = array(); $alle = array();
    foreach (spot_history_read(400) as $r) {
        $mi = ((int) substr($r[0], 4, 2)) - 1;
        $p = (isset($r[4]) && $r[4] > 0) ? $r[4] : $r[1];
        if (!isset($mpreis[$mi])) {
            $mpreis[$mi] = array(0, 0);
        }
        $mpreis[$mi][0] += $p;
        $mpreis[$mi][1]++;
        $alle[] = $p;
    }
    $schnitt = $alle ? array_sum($alle) / count($alle) : 0;
    if ($schnitt <= 0) {
        $st = spot_state();
        $schnitt = $st['ok'] ? (float) $st['heute']['avg'] : 0;
    }
    $tage_im_monat = array(31, 28.25, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
    $dyn_arbeit = 0; $gemessen = 0;
    for ($i = 0; $i < 12; $i++) {
        $kwh_m = $mon['use'] ? $mon['kwh'][$i] : $kwh_jahr * $tage_im_monat[$i] / 365.25;
        $p = isset($mpreis[$i]) && $mpreis[$i][1] > 0 ? $mpreis[$i][0] / $mpreis[$i][1] : $schnitt;
        if (isset($mpreis[$i])) {
            $gemessen++;
        }
        $dyn_arbeit += $kwh_m * $p / 100;
    }
    $dyn_grund = max(0, (float) $cfg['grundpreis']) * 12;
    $dyn_jahr = $dyn_arbeit + $dyn_grund;

    $fix_arbeit = $kwh_jahr * max(0, (float) $cfg['fixed_price']) / 100;
    $fix_grund = max(0, (float) $cfg['fix_grund']) * 12;
    $fix_zwischen = $fix_arbeit + $fix_grund;
    $rabatt_pct = max(0, min(100, (float) $cfg['fix_rabatt']));
    $rabatt = $fix_zwischen * $rabatt_pct / 100;
    $fix_nach_rabatt = $fix_zwischen - $rabatt;
    $boni = max(0, (float) $cfg['fix_sofortbonus']) + max(0, (float) $cfg['fix_neubonus'])
          + $fix_zwischen * max(0, min(100, (float) $cfg['fix_neubonus_pct'])) / 100;
    $fix_jahr1 = $fix_nach_rabatt - $boni;

    return array(
        'kwh' => round($kwh_jahr, 1),
        'monate_gemessen' => $gemessen,
        'schnitt' => round($schnitt, 3),
        'dyn_arbeit' => round($dyn_arbeit, 2),
        'dyn_grund' => round($dyn_grund, 2),
        'dyn_jahr' => round($dyn_jahr, 2),
        'dyn_monat' => round($dyn_jahr / 12, 2),
        'fix_arbeit' => round($fix_arbeit, 2),
        'fix_grund' => round($fix_grund, 2),
        'fix_zwischen' => round($fix_zwischen, 2),
        'rabatt_pct' => $rabatt_pct,
        'rabatt' => round($rabatt, 2),
        'boni' => round($boni, 2),
        'fix_jahr1' => round($fix_jahr1, 2),
        'fix_folge' => round($fix_nach_rabatt, 2),
        'fix_monat1' => round($fix_jahr1 / 12, 2),
        'fix_monatf' => round($fix_nach_rabatt / 12, 2),
        // positiv = dynamischer Tarif waere guenstiger
        'vorteil1' => round($fix_jahr1 - $dyn_jahr, 2),
        'vorteilf' => round($fix_nach_rabatt - $dyn_jahr, 2),
    );
}

/**
 * Ersparnis-Potenzial durch VERSCHOBENEN VERBRAUCH (letzte 7 Tage):
 * Was haette es gebracht, taeglich X kWh aus dem Tagesdurchschnitt in das
 * guenstigste Fenster zu verschieben? (Ohne echte Verbrauchsdaten eine
 * Abschaetzung - genau das, was man vor dem Tarifwechsel wissen will.)
 */
function spot_shift_saving($days = 7) {
    $cfg = spot_config();
    $kwh = max(0, (float) $cfg['shift_kwh']);
    $rows = spot_history_read(max(1, (int) $days));
    $sum = 0; $n = 0;
    foreach ($rows as $r) {
        $sum += max(0, $r[1] - $r[2]); // Tagesschnitt minus Tagesminimum
        $n++;
    }
    if (!$n) {
        return array('tage' => 0, 'ct' => 0, 'euro' => 0, 'euro_jahr' => 0, 'kwh' => $kwh);
    }
    $ct = round($sum / $n, 3);                       // mittlere Spanne je kWh
    $euro = round($ct * $kwh * $n / 100, 2);         // Ersparnis im Zeitraum
    return array('tage' => $n, 'ct' => $ct, 'euro' => $euro,
                 'euro_jahr' => round($ct * $kwh * 365 / 100, 2), 'kwh' => $kwh);
}

/* ---------------- Eigene LoxBerry-Adresse ermitteln ---------------- */

/**
 * IP-Adresse dieses LoxBerry bestimmen: bevorzugt die Adresse, unter der die
 * Weboberflaeche gerade aufgerufen wird, sonst die Netzwerkadresse des Hosts.
 * Rueckgabe z. B. "192.168.1.14" (Fallback: 127.0.0.1).
 */
function spot_own_ip() {
    $cand = array();
    if (!empty($_SERVER['SERVER_ADDR'])) {
        $cand[] = $_SERVER['SERVER_ADDR'];
    }
    if (!empty($_SERVER['HTTP_HOST'])) {
        $h = preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']);
        if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $h)) {
            $cand[] = $h;
        }
    }
    // Ohne Web-Kontext (Cron): Adresse ueber eine Test-Verbindung bzw. den Hostnamen
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($s) {
        if (@socket_connect($s, '8.8.8.8', 53)) {
            $addr = '';
            $port = 0;
            if (@socket_getsockname($s, $addr, $port) && $addr !== '') {
                $cand[] = $addr;
            }
        }
        socket_close($s);
    }
    $hn = @gethostbyname(@gethostname());
    if ($hn && preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $hn)) {
        $cand[] = $hn;
    }
    foreach ($cand as $ip) {
        if ($ip !== '' && strpos($ip, '127.') !== 0) {
            return $ip;
        }
    }
    return '127.0.0.1';
}

/** Vollstaendige Standard-URL zum Marstek-Plugin auf diesem LoxBerry. */
function spot_marstek_default_url() {
    return 'http://' . spot_own_ip() . '/plugins/marstekvenus/marstek.php';
}

/* ---------------- Optionale Kopplung: Marstek-Speicher laden ---------------- */

/**
 * Schickt dem Marstek-Plugin einen Ladebefehl, wenn die aktuelle Stunde zu den
 * X guenstigsten der naechsten 24 h gehoert (oder der Preis negativ ist).
 * STANDARD AUS - gedacht als Alternative fuer alle, die die Rang-Logik NICHT
 * in Loxone bauen wollen. Laeuft die Loxone-Logik parallel, bitte ausgeschaltet
 * lassen, sonst ueberschreiben sich beide Sollwerte gegenseitig.
 */
function spot_marstek_control($st = null) {
    $cfg = spot_config();
    if (empty($cfg['marstek_enabled'])) {
        return;
    }
    if ($st === null) {
        $st = spot_state();
    }
    if (!$st['ok']) {
        return;
    }
    $laden = ($st['rank'] <= max(1, (int) $cfg['marstek_hours']))
          || (!empty($cfg['marstek_neg']) && $st['neg']);
    $p = $laden ? max(0, (int) $cfg['marstek_power']) : 0;
    $url = trim((string) $cfg['marstek_url']);
    if ($url === '') {
        $url = spot_marstek_default_url(); // leer = automatisch eigene LoxBerry-IP
    }
    $url .= (strpos($url, '?') === false ? '?' : '&') . 'p=' . $p . '&t=240';
    $ctx = stream_context_create(array('http' => array('timeout' => 8)));
    $r = @file_get_contents($url, false, $ctx);
    spot_log_if_changed('marstek', ($laden ? 'laden mit ' . $p . ' W' : 'kein Spot-Laden')
        . ' (Rang ' . $st['rank'] . ', neg=' . $st['neg'] . ') -> ' . ($r !== false ? trim((string) $r) : 'FEHLER'));
}

/* ---------------- MQTT (LoxBerry MQTT Gateway, UDP-Relay) ---------------- */

function spot_mqtt_publish($st = null) {
    $cfg = spot_config();
    if (empty($cfg['mqtt_enabled'])) {
        return;
    }
    $p = spot_paths();
    if ($p['lbhome'] === '') {
        return;
    }
    if ($st === null) {
        $st = spot_state();
    }
    $gen = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    $udpport = 0;
    if (isset($gen['Mqtt']['Udpinport'])) { $udpport = (int) $gen['Mqtt']['Udpinport']; }
    if (!$udpport && isset($gen['mqtt']['udpinport'])) { $udpport = (int) $gen['mqtt']['udpinport']; }
    if (!$udpport) {
        return;
    }
    $prefix = trim((string) $cfg['mqtt_topic']) !== '' ? trim((string) $cfg['mqtt_topic']) : 'spot_awattar';
    $msgs = array(
        'ok' => $st['ok'], 'cur' => $st['cur'], 'cur_boerse' => $st['cur_boerse'], 'next' => $st['next'],
        'neg' => $st['neg'], 'rank' => $st['rank'], 'rankd' => $st['rankd'], 'level' => $st['level'],
        'avg_heute' => $st['heute']['avg'], 'min_heute' => $st['heute']['minp'], 'minh_heute' => $st['heute']['minh'],
        'max_heute' => $st['heute']['maxp'], 'maxh_heute' => $st['heute']['maxh'],
        'morgen_ok' => $st['tomorrow_ok'], 'avg_morgen' => $st['morgen']['avg'],
        'min_morgen' => $st['morgen']['minp'], 'minh_morgen' => $st['morgen']['minh'],
        'max_morgen' => $st['morgen']['maxp'], 'maxh_morgen' => $st['morgen']['maxh'],
        'fenster_start' => $st['fenster']['h'], 'fenster_in' => $st['fenster']['in'], 'fenster_ct' => $st['fenster']['ct'],
        'co2' => $st['co2'], 'co2_min' => $st['co2_min'], 'co2_minh' => $st['co2_minh'], 'co2_clean' => $st['co2_clean'],
        'wp_cur' => $st['wp_cur'], 'wp_next' => $st['wp_next'],
        'fix' => $st['fix'], 'dyn_monat' => $st['dyn_monat'],
        'diff_monat' => $st['diff_monat'], 'euro_monat' => $st['euro_monat'], 'shift_jahr' => $st['shift_jahr'],
        // Meldesteuerung - bisher nur ueber den HTTP-Endpunkt erreichbar
        'ann' => spot_ann_active($st),
        'audio' => empty($cfg['notify']['audio']) ? 0 : 1,
        'push' => empty($cfg['notify']['push']) ? 0 : 1,
        'ptest' => spot_ptest_active(),
    );
    // Schaltregeln: je Regel ein eigener Themenzweig. 'aktiv' ist das, woran
    // in Loxone ein digitaler Eingang haengt - alles andere ist Beiwerk.
    foreach ((array) (isset($st['regeln']) ? $st['regeln'] : array()) as $r) {
        $z = 'regel/' . (int) $r['nr'] . '/';
        $msgs[$z . 'aktiv'] = (int) $r['aktiv'];
        $msgs[$z . 'in'] = (int) $r['in'];
        $msgs[$z . 'rest'] = (int) $r['rest'];
        $msgs[$z . 'ct'] = $r['ct'];
        $msgs[$z . 'ein'] = (int) $r['ein'];
    }
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        return;
    }
    foreach ($msgs as $k => $v) {
        $msg = 'publish ' . $prefix . '/' . $k . ' ' . $v;
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $udpport);
    }
    socket_close($s);
}

/* ==================================================================
 * Loxone-Vorlage (XML fuer den Import in Loxone Config)
 *
 * Das Plugin liefert weit ueber hundert Werte. Die von Hand als virtuelle
 * Eingaenge anzulegen ist eine Stunde stumpfe Arbeit mit vielen
 * Gelegenheiten fuer Tippfehler. Hausstandard: ein Knopf, eine Datei.
 *
 * spot_felder() ist die EINE Quelle - aus ihr entstehen die Vorlage UND
 * die Pruefung im Reiter Test, die nachsieht, ob jedes hier genannte Feld
 * auch wirklich in der Zeile von spot.php steht. Ohne diese Pruefung
 * laufen Tabelle und Ausgabe beim naechsten neuen Wert auseinander, und
 * der virtuelle Eingang bleibt stumm - ohne Fehlermeldung.
 *
 * min/max sind die Grenzen des FERTIGEN Wertes: gerechnet wird im Plugin,
 * nicht in Loxone. Deshalb bleiben SourceVal/DestVal 1:1.
 * ================================================================== */

/**
 * Die komplette Ausgabe fuer den Miniserver als Zeichenkette.
 *
 * Bewusst eine Funktion und nicht direkt in spot.php: so kann die
 * Selbstpruefung im Reiter Test dieselbe Zeile erzeugen und gegen
 * spot_felder() halten, ohne spot.php einzubinden - ein include haette
 * dort ein header() nach der Ausgabe ausgeloest.
 */
function spot_zeile($st, $cfg) {
    $o = sprintf("SPOT;OK=%d;MINH=%d;MINP=%.3f;MAXH=%d;MAXP=%.3f;AVG=%.3f;HOK=%d;HMINH=%d;HMINP=%.3f;HMAXH=%d;HMAXP=%.3f;HAVG=%.3f;CUR=%.3f;CURB=%.3f;NEXT=%.3f;NEG=%d;RANK=%d;RANKD=%d;LEVEL=%d;WINH=%d;WININ=%d;WINCT=%.3f;ANN=%d;AUDIO=%d;PUSH=%d;PTEST=%d;CO2=%d;CO2MIN=%d;CO2MINH=%d;CO2CLEAN=%d;WPCUR=%.3f;WPNEXT=%.3f;FIX=%.3f;DYNM=%.3f;DIFFM=%.3f;EUROM=%.2f;SHIFTJ=%.2f\n",
        $st['tomorrow_ok'], $st['morgen']['minh'], $st['morgen']['minp'], $st['morgen']['maxh'], $st['morgen']['maxp'], $st['morgen']['avg'],
        $st['ok'], $st['heute']['minh'], $st['heute']['minp'], $st['heute']['maxh'], $st['heute']['maxp'], $st['heute']['avg'],
        $st['cur'], $st['cur_boerse'], $st['next'], $st['neg'], $st['rank'], $st['rankd'], $st['level'],
        $st['fenster']['h'], $st['fenster']['in'], $st['fenster']['ct'],
        spot_ann_active($st),
        empty($cfg['notify']['audio']) ? 0 : 1,
        empty($cfg['notify']['push']) ? 0 : 1,
        spot_ptest_active(),
        $st['co2'], $st['co2_min'], $st['co2_minh'], $st['co2_clean'],
        $st['wp_cur'], $st['wp_next'],
        $st['fix'], $st['dyn_monat'], $st['diff_monat'], $st['euro_monat'], $st['shift_jahr']);

    // Schaltregeln als EIGENE Zeile hinter der bisherigen. Die
    // Befehlserkennung in Loxone sucht Textstellen, nicht Zeilen -
    // bestehende Eingaenge merken davon nichts.
    $teile = array();
    foreach ((array) (isset($st['regeln']) ? $st['regeln'] : array()) as $r) {
        $n = (int) $r['nr'];
        $teile[] = sprintf('R%d=%d;R%dIN=%d;R%dREST=%d;R%dCT=%.3f',
            $n, $r['aktiv'], $n, $r['in'], $n, $r['rest'], $n, $r['ct']);
    }
    $o .= 'REGEL;' . implode(';', $teile) . "\n";

    // Stundenprofil: PH00..PH23 heute, PM00..PM23 morgen, Endpreis in ct/kWh.
    $modus = (string) $cfg['profil_ein'];
    if ($modus === 'absolut' || $modus === 'beides') {
        $ph = array();
        $pm = array();
        for ($h = 0; $h < 24; $h++) {
            $ph[] = sprintf('PH%02d=%.3f', $h, isset($st['profil_heute'][$h]) ? $st['profil_heute'][$h] : 0);
            $pm[] = sprintf('PM%02d=%.3f', $h, isset($st['profil_morgen'][$h]) ? $st['profil_morgen'][$h] : 0);
        }
        $o .= 'PROFIL;' . implode(';', $ph) . ';' . implode(';', $pm) . "\n";
    }
    if ($modus === 'relativ' || $modus === 'beides') {
        $pr = array();
        for ($h = 0; $h < 24; $h++) {
            $pr[] = sprintf('PR%02d=%.3f', $h, isset($st['profil_relativ'][$h]) ? $st['profil_relativ'][$h] : 0);
        }
        $o .= 'PROFILR;' . implode(';', $pr) . "\n";
    }
    return $o;
}

/** Alle Felder der Loxone-Zeile: name => array(analog, min, max, einheit, text). */
function spot_felder() {
    $f = array(
        'OK'      => array(0, 0, 1, '', 'Preise fuer morgen liegen vor'),
        'HOK'     => array(0, 0, 1, '', 'Preise fuer heute liegen vor'),
        'CUR'     => array(1, -100, 200, 'ct/kWh', 'Endpreis der laufenden Stunde'),
        'CURB'    => array(1, -100, 200, 'ct/kWh', 'Reiner Boersenanteil der laufenden Stunde'),
        'NEXT'    => array(1, -100, 200, 'ct/kWh', 'Endpreis der naechsten Stunde'),
        'NEG'     => array(0, 0, 1, '', 'Boersenpreis ist negativ'),
        'RANK'    => array(1, 1, 48, '', 'Rang der laufenden Stunde (1 = guenstigste)'),
        'RANKD'   => array(1, 1, 48, '', 'Rang von hinten (1 = teuerste)'),
        'LEVEL'   => array(1, 1, 3, '', 'Preisniveau: 1 guenstig, 2 normal, 3 teuer'),
        'MINH'    => array(1, 0, 23, 'h', 'Guenstigste Stunde morgen'),
        'MINP'    => array(1, -100, 200, 'ct/kWh', 'Preis der guenstigsten Stunde morgen'),
        'MAXH'    => array(1, 0, 23, 'h', 'Teuerste Stunde morgen'),
        'MAXP'    => array(1, -100, 200, 'ct/kWh', 'Preis der teuersten Stunde morgen'),
        'AVG'     => array(1, -100, 200, 'ct/kWh', 'Tagesmittel morgen'),
        'HMINH'   => array(1, 0, 23, 'h', 'Guenstigste Stunde heute'),
        'HMINP'   => array(1, -100, 200, 'ct/kWh', 'Preis der guenstigsten Stunde heute'),
        'HMAXH'   => array(1, 0, 23, 'h', 'Teuerste Stunde heute'),
        'HMAXP'   => array(1, -100, 200, 'ct/kWh', 'Preis der teuersten Stunde heute'),
        'HAVG'    => array(1, -100, 200, 'ct/kWh', 'Tagesmittel heute'),
        'WINH'    => array(1, -1, 23, 'h', 'Startstunde des guenstigsten Fensters'),
        'WININ'   => array(1, -1, 48, 'h', 'Stunden bis zu diesem Fenster'),
        'WINCT'   => array(1, -100, 200, 'ct/kWh', 'Schnitt in diesem Fenster'),
        'CO2'     => array(1, 0, 1000, 'g/kWh', 'CO2-Intensitaet jetzt'),
        'CO2MIN'  => array(1, 0, 1000, 'g/kWh', 'Sauberste Stunde'),
        'CO2MINH' => array(1, -1, 23, 'h', 'Stunde der saubersten Stunde'),
        'CO2CLEAN' => array(0, 0, 1, '', 'Strommix gilt als sauber'),
        'WPCUR'   => array(1, -100, 200, 'ct/kWh', 'Par.-14a-Preissatz jetzt'),
        'WPNEXT'  => array(1, -100, 200, 'ct/kWh', 'Par.-14a-Preissatz naechste Stunde'),
        'FIX'     => array(1, 0, 200, 'ct/kWh', 'Fester Vergleichstarif'),
        'DYNM'    => array(1, -100, 200, 'ct/kWh', 'Dynamischer Preis laufender Monat'),
        'DIFFM'   => array(1, -200, 200, 'ct/kWh', 'Unterschied dynamisch zu fest'),
        'EUROM'   => array(1, -10000, 10000, 'EUR', 'Unterschied in Euro im laufenden Monat'),
        'SHIFTJ'  => array(1, 0, 10000, 'EUR', 'Verschiebe-Potenzial im Jahr'),
        'ANN'     => array(0, 0, 1, '', 'Meldefenster offen'),
        'AUDIO'   => array(0, 0, 1, '', 'Ansage freigegeben'),
        'PUSH'    => array(0, 0, 1, '', 'Push freigegeben'),
        'PTEST'   => array(0, 0, 1, '', 'Test-Pushnachricht angefordert'),
    );
    // Schaltregeln - der Grund fuer die ganze Uebung: R<n> ist digital.
    for ($i = 1; $i <= SPOT_REGELN; $i++) {
        $f['R' . $i]          = array(0, 0, 1, '', 'Schaltregel ' . $i . ': jetzt einschalten');
        $f['R' . $i . 'IN']   = array(1, -1, 48, 'h', 'Schaltregel ' . $i . ': Stunden bis zum naechsten Fenster');
        $f['R' . $i . 'REST'] = array(1, 0, 48, 'h', 'Schaltregel ' . $i . ': verbleibende Stunden');
        $f['R' . $i . 'CT']   = array(1, -100, 200, 'ct/kWh', 'Schaltregel ' . $i . ': Schnitt im Fenster');
    }
    $cfg = spot_config();
    $modus = (string) $cfg['profil_ein'];
    if ($modus === 'absolut' || $modus === 'beides') {
        for ($h = 0; $h < 24; $h++) {
            $f[sprintf('PH%02d', $h)] = array(1, -100, 200, 'ct/kWh', sprintf('Endpreis heute %02d Uhr - Spot Price Optimizer, Modus Absolut, Eingang %02d:00', $h, $h));
            $f[sprintf('PM%02d', $h)] = array(1, -100, 200, 'ct/kWh', sprintf('Endpreis morgen %02d Uhr', $h));
        }
    }
    if ($modus === 'relativ' || $modus === 'beides') {
        for ($h = 0; $h < 24; $h++) {
            $f[sprintf('PR%02d', $h)] = array(1, -100, 200, 'ct/kWh', sprintf('Endpreis in %d Stunden - Spot Price Optimizer, Modus Relativ, Eingang +%d', $h, $h));
        }
    }
    return $f;
}

/**
 * Geprüfter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 * CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 * Uebernommen aus LoxBerry-Plugin-APC-UPS, nur das Kuerzel getauscht.
 */
function spot_xml_virtual_in_http($kopf, $cmds) {
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . spot_x($kopf['title']) . '" ';
    $o .= 'Comment="' . spot_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . spot_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . spot_x(isset($kopf['polling']) ? $kopf['polling'] : '300') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . spot_x($c['title']) . '" ';
        $o .= 'Comment="' . spot_x($c['comment']) . '" ';
        $o .= 'Check="' . spot_x($c['check']) . '" ';
        $o .= 'Signed="' . ($c['min'] < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . ($c['analog'] ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . (int) $c['min'] . '" ';
        $o .= 'MaxVal="' . (int) $c['max'] . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

function spot_x($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt) */
function spot_vorlage() {
    $p = spot_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    $ordner = basename(dirname(__DIR__, 2));
    if ($p['lbhome'] !== '') {
        $ordner = getenv('LBPPLUGINDIR') ?: 'spotpreis';
    }
    $st = spot_state();
    $cmds = array();
    foreach (spot_felder() as $name => $d) {
        list($analog, $min, $max, $einheit, $text) = $d;
        // Die Namen der Regeln aus der Konfiguration einsetzen - ein Eingang
        // "Wallbox" ist beim Verdrahten mehr wert als "Schaltregel 1".
        if (preg_match('/^R([0-9]+)/', $name, $m)) {
            $i = (int) $m[1] - 1;
            if (isset($st['regeln'][$i]) && $st['regeln'][$i]['name'] !== '') {
                $text = str_replace('Schaltregel ' . ($i + 1), $st['regeln'][$i]['name'], $text);
            }
        }
        $cmds[] = array(
            'title' => 'SPOT_' . $name,
            'comment' => $text . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            'check' => '\i' . $name . '=\i\v',
            'analog' => $analog, 'min' => $min, 'max' => $max,
        );
    }
    return array('VI_spotpreis.xml', spot_xml_virtual_in_http(array(
        'title' => 'Spotpreis aWATTar',
        'address' => 'http://' . $host . '/plugins/' . $ordner . '/spot.php',
        'polling' => '300',
        'comment' => 'Erzeugt vom LoxBerry-Plugin Spotpreis aWATTar (' . date('d.m.Y') . '). '
                   . 'Loxone Config legt beim Import neu an und ueberschreibt nichts - '
                   . 'zweimal eingelesen ergibt doppelte Bausteine.',
    ), $cmds));
}

/* ---------------- Ansage (TTS) - identisch zu Abfahrtsassistent/Abfuhrkalender ---------------- */

/** TTS-URL fuer die konfigurierte Ausgabe bauen. Fuer mode=audioserver: null. */
function spot_tts_url($text) {
    $cfg = spot_config();
    $tts = $cfg['tts'];
    $mode = $tts['mode'];
    if ($mode === 'audioserver') {
        return null; // Original Loxone Audioserver: TTS nur ueber Loxone Config (Textgenerator -> TTS-Eingang)
    }
    if ((string) $tts['ip'] === '') {
        return '';
    }
    if ($mode === 'musicserver') {
        // Zonenliste normalisieren: "2,4,6" + Lautstaerke-Feld -> "2~8,4~8,6~8".
        // Explizite Angaben "Zone~Lautstaerke" haben Vorrang.
        $vol = max(1, min(100, (int) $tts['volume']));
        $zones = array();
        foreach (explode(',', (string) $tts['zones']) as $z) {
            $z = trim($z);
            if ($z === '') {
                continue;
            }
            $zones[] = (strpos($z, '~') === false) ? $z . '~' . $vol : $z;
        }
        $zoneStr = $zones ? implode(',', $zones) : '1~' . $vol;
        return 'http://' . $tts['ip'] . ':' . (int) $tts['port'] . '/audio/grouped/tts/' . $zoneStr . '/' . rawurlencode($tts['lang'] . '|' . $text);
    }
    // ms4h (MusicServer4Home / Audioserver4Home) und custom: Vorlage mit Platzhaltern
    $tpl = trim((string) $tts['template']);
    if ($tpl === '') {
        $tpl = 'http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}';
    }
    return str_replace(
        array('{ip}', '{port}', '{zones}', '{vol}', '{lang}', '{text}'),
        array($tts['ip'], (int) $tts['port'], $tts['zones'], (int) $tts['volume'], $tts['lang'], rawurlencode($text)),
        $tpl
    );
}

function spot_say($text) {
    $url = spot_tts_url($text);
    if ($url === null) {
        spot_log('Ansage: Modus "Original Loxone Audioserver" - Sprachausgabe erfolgt ueber Loxone Config (Textgenerator)');
        return false;
    }
    if ($url === '') {
        spot_log('Ansage uebersprungen: keine TTS-IP konfiguriert');
        return false;
    }
    $ctx = stream_context_create(array('http' => array('timeout' => 10)));
    $r = @file_get_contents($url, false, $ctx);
    spot_log('Ansage gesendet: "' . $text . '" -> ' . ($r !== false ? 'OK' : 'FEHLER'));
    return $r !== false;
}

/** Zahl deutsch aussprechen: 24.3 -> "24,3". */
function spot_num($v, $dec = 1) {
    return str_replace('.', ',', number_format((float) $v, $dec, '.', ''));
}

/** Ansagetext fuer die aktuelle Stunde. */
function spot_announce_text($st = null) {
    $cfg = spot_config();
    if ($st === null) {
        $st = spot_state();
    }
    if (!$st['ok']) {
        return '';
    }
    $t = 'Hallo! Der Strompreis betraegt jetzt ' . spot_num($st['cur'], 1) . ' Cent pro Kilowattstunde.';
    if ($st['neg']) {
        $t = 'Hallo! Achtung, der Boersenstrompreis ist gerade negativ. Der Endpreis liegt bei '
           . spot_num($st['cur'], 1) . ' Cent pro Kilowattstunde. Ein guter Zeitpunkt fuer grosse Verbraucher.';
    } elseif ($st['level'] === 1) {
        $t .= ' Das ist guenstig.';
    } elseif ($st['level'] === 3) {
        $t .= ' Das ist teuer.';
    }
    if ($st['fenster']['in'] === 0) {
        $t .= ' Die kommenden ' . (int) $st['fenster_len'] . ' Stunden sind der guenstigste Zeitraum.';
    } elseif ($st['fenster']['in'] > 0) {
        $t .= ' Der guenstigste Zeitraum beginnt um ' . (int) $st['fenster']['h'] . ' Uhr.';
    }
    if (!empty($st['co2_ok']) && !empty($st['co2_clean'])) {
        $t .= ' Der Strom ist gerade besonders sauber, mit ' . (int) $st['co2'] . ' Gramm CO2 je Kilowattstunde.';
    }
    return str_replace(array('betraegt', 'Boersenstrompreis', 'guenstig', 'guenstigste', 'guenstigsten', 'guenstigster', 'teuer', 'grosse', 'Zeitraum'),
        array("betr\u{00e4}gt", "B\u{00f6}rsenstrompreis", "g\u{00fc}nstig", "g\u{00fc}nstigste", "g\u{00fc}nstigsten", "g\u{00fc}nstigster", 'teuer', "gro\u{00df}e", 'Zeitraum'), $t);
}

/** Ansagetext, sobald die Preise fuer morgen veroeffentlicht sind. */
function spot_tomorrow_text($st = null) {
    if ($st === null) {
        $st = spot_state();
    }
    if (!$st['tomorrow_ok']) {
        return '';
    }
    return 'Hallo! Die Strompreise f' . "\u{00fc}" . 'r morgen sind da. Am g' . "\u{00fc}" . 'nstigsten ist es um '
        . (int) $st['morgen']['minh'] . ' Uhr mit ' . spot_num($st['morgen']['minp'], 1) . ' Cent, am teuersten um '
        . (int) $st['morgen']['maxh'] . ' Uhr mit ' . spot_num($st['morgen']['maxp'], 1)
        . ' Cent pro Kilowattstunde. Der Tagesdurchschnitt liegt bei ' . spot_num($st['morgen']['avg'], 1) . ' Cent.';
}

/** Ist fuer die aktuelle Stunde eine Meldung vorgesehen? */
function spot_hour_selected($h = null) {
    $cfg = spot_config();
    if ($h === null) {
        $h = (int) date('G');
    }
    return in_array((int) $h, array_map('intval', (array) $cfg['notify']['hours']), true);
}

/** Meldefenster fuer Loxone: 1 in den ersten 10 Minuten einer aktivierten Stunde. */
function spot_ann_active($st = null) {
    $cfg = spot_config();
    if ($st === null) {
        $st = spot_state();
    }
    if (!$st['ok'] || (int) date('i') >= 10) {
        return 0;
    }
    $sel = spot_hour_selected();
    if (!$sel && !(!empty($cfg['notify']['negative']) && $st['neg'])) {
        return 0;
    }
    if ($sel && !empty($cfg['notify']['only_cheap']) && $st['cur'] > (float) $cfg['cheap'] && !$st['neg']) {
        return 0;
    }
    return 1;
}

/** Test-Push-Merker (5 Minuten nach Klick auf "Test-Pushnachricht"). */
function spot_ptest_active() {
    $f = spot_tmpdir() . '/ptest';
    return (is_file($f) && time() - filemtime($f) < 300) ? 1 : 0;
}

/** Cron: stuendliche Ansage + Meldung "Preise fuer morgen da". */
function spot_announce_check() {
    $cfg = spot_config();
    $st = spot_state();
    // 1) Stuendliche Ansage
    if (!empty($cfg['notify']['audio']) && $st['ok'] && (int) date('i') === 0) {
        $flag = spot_tmpdir() . '/said_' . date('YmdH');
        if (!is_file($flag)) {
            $sel = spot_hour_selected();
            $neg = !empty($cfg['notify']['negative']) && $st['neg'];
            $skip = $sel && !empty($cfg['notify']['only_cheap']) && $st['cur'] > (float) $cfg['cheap'] && !$st['neg'];
            if (($sel || $neg) && !$skip) {
                @file_put_contents($flag, '1');
                $txt = spot_announce_text();
                if ($txt !== '') {
                    spot_say($txt);
                }
            }
        }
    }
    // 2) Preise fuer morgen sind da (boersentaeglich ab ca. 14:00)
    if (!empty($cfg['notify']['tomorrow']) && $st['tomorrow_ok']) {
        $flag = spot_tmpdir() . '/tomorrow_' . date('Ymd');
        if (!is_file($flag)) {
            @file_put_contents($flag, '1');
            if (!empty($cfg['notify']['audio'])) {
                $txt = spot_tomorrow_text($st);
                if ($txt !== '') {
                    spot_say($txt);
                }
            }
            spot_log('Preise fuer morgen veroeffentlicht: min ' . $st['morgen']['minp'] . ' ct um ' . $st['morgen']['minh'] . ' Uhr, max ' . $st['morgen']['maxp'] . ' ct um ' . $st['morgen']['maxh'] . ' Uhr');
        }
    }
    // Alte Merker aufraeumen
    foreach (glob(spot_tmpdir() . '/said_*') ?: array() as $f) {
        if (time() - (int) filemtime($f) > 7200) {
            @unlink($f);
        }
    }
    foreach (glob(spot_tmpdir() . '/tomorrow_*') ?: array() as $f) {
        if (basename($f) !== 'tomorrow_' . date('Ymd')) {
            @unlink($f);
        }
    }
}

/**
 * Tages-Statistik fortschreiben:
 * Ymd ; Schnitt ; Minimum ; Maximum ; lastprofil-gewichteter Schnitt ; CO2-Schnitt
 */
function spot_history_add($st = null) {
    if ($st === null) {
        $st = spot_state();
    }
    if (!$st['ok'] || empty($st['heute']['hours'])) {
        return;
    }
    $f = spot_datadir() . '/history.csv';
    $day = date('Ymd');
    $lines = is_file($f) ? (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()) : array();
    foreach ($lines as $l) {
        if (strpos($l, $day . ';') === 0) {
            return; // heute schon erfasst
        }
    }
    // Gewichteter Tagesschnitt nach Haushalts-Lastprofil
    $prof = spot_profile(); $ws = 0; $w = 0;
    foreach ($st['heute']['hours'] as $h => $row) {
        $g = isset($prof[(int) $h]) ? $prof[(int) $h] : 1.0;
        $ws += $row['ct'] * $g;
        $w += $g;
    }
    $avgw = $w > 0 ? round($ws / $w, 3) : $st['heute']['avg'];
    $lines[] = $day . ';' . $st['heute']['avg'] . ';' . $st['heute']['minp'] . ';' . $st['heute']['maxp']
             . ';' . $avgw . ';' . (int) (isset($st['co2_avg']) ? $st['co2_avg'] : 0);
    if (count($lines) > 400) {
        $lines = array_slice($lines, -400);
    }
    @file_put_contents($f, implode("\n", $lines) . "\n");
    spot_log('Tageswerte gesichert: Schnitt ' . $st['heute']['avg'] . ' ct (gewichtet ' . $avgw
        . ' ct), Min ' . $st['heute']['minp'] . ', Max ' . $st['heute']['maxp'] . ' ct');
}

/** Tages-Statistik lesen: [[Ymd, avg, min, max, avg_gewichtet, co2], ...]. */
function spot_history_read($days = 30) {
    $f = spot_datadir() . '/history.csv';
    $out = array();
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
            $c = explode(';', $l);
            if (count($c) >= 4) {
                $out[] = array($c[0], (float) $c[1], (float) $c[2], (float) $c[3],
                               isset($c[4]) ? (float) $c[4] : 0.0, isset($c[5]) ? (int) $c[5] : 0);
            }
        }
    }
    return array_slice($out, -max(1, (int) $days));
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
 * ================================================================== */

function spot_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
function spot_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/ - der Ordnername ergibt
        // sich aus dem Ablageort dieser Datei.
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . spot_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen.
        // Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}
