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

/* Der Fahrplaner. Er liegt als eigene Datei daneben, weil dasselbe
 * Rechenwerk auch im Octopus-Plugin steckt - byteweise gleich. Naeheres im
 * Kopf von planer.php. */
require_once __DIR__ . '/planer.php';

/** Anzahl der Schaltregeln. Vier decken Wallbox, Speicher, Warmwasser und
 *  Waermepumpe ab - mehr macht die Oberflaeche unuebersichtlich. */
define('SPOT_REGELN', 4);

/**
 * Vorgabe einer Schaltregel.
 *
 * Die Felder des Fahrplaners (Rang, Leistung, Energie, Frist, Sperren)
 * kommen aus plan_regel_vorgabe() dazu. Ihre Vorgaben sind so gewaehlt,
 * dass sich fuer eine bestehende Regel nichts aendert.
 */

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function spot_regel_vorgabe() {
    return array_merge(array(
        'aktiv' => 0,
        'name' => '',
        /* fenster | stunden | schwelle | mittel
         *
         * Die Art 'scheiben' aus planer.php 1.1.0 fehlt hier mit Absicht:
         * sie nimmt die N guenstigsten EINZELNEN Zeitscheiben ohne
         * Stundenraster. Dieses Plugin rechnet in Stunden - eine Scheibe
         * IST eine Stunde -, damit liefert sie Scheibe fuer Scheibe
         * dasselbe wie 'stunden'. Nachgemessen mit n = 1, 2 und 3: dieselbe
         * Trefferliste. Zwei Namen fuer dieselbe Sache waeren schlimmer als
         * einer. Bekommt aWATTar eines Tages Viertelstundenpreise, gehoert
         * sie hierher - dann unterscheiden sie sich. */
        'art' => 'fenster',
        'n' => 3,             // Anzahl Stunden (fenster/stunden)
        'von' => 0,           // Zeitfenster von (Stunde, einschliesslich)
        'bis' => 0,           // Zeitfenster bis (Stunde, ausschliesslich); von==bis = ganzer Tag
        'horizont' => 24,     // nur die naechsten X Stunden betrachten
        'schwelle' => 20.0,   // ct/kWh Endpreis (art=schwelle)
        'prozent' => 20,      // % unter dem Tagesmittel (art=mittel)
        'neg' => 1,           // bei negativem Boersenpreis immer einschalten
    ), plan_regel_vorgabe());
}

function spot_paths() {
    $lbhomedir = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
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

function spot_vorgaben()
{
    /* Herausgezogen aus spot_config(): die Vorgaben stehen weiterhin an
     * EINER Stelle, jetzt aber an einer abrufbaren. Die Sicherung
     * braucht die Schluesselliste, um Fremdes zu erkennen - ohne sie
     * koennte sie nur alles durchwinken. */
    return array(
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
    'token' => '',               // leer = Endpunkt ohne Token erreichbar
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
    // Fahrplaner (ab 1.2.0): Leistungsbudget und PV-Gutschrift.
    // Vorgabe 0 heisst jeweils "aus" - wer nichts einstellt, bekommt
    // das Verhalten der Fassung davor.
    'pv_quelle' => '',           // '' | forecast_solar | objekt | liste
    'pv_url' => '',
    'pv_pfad' => '',
    'pv_zeitfeld' => '',
    'pv_wertfeld' => '',
    'pv_einheit' => 'wh',        // wh | w | kw
    'soc_url' => '',
    'soc_pfad' => '',
    /* Eigener Lastgang (ab 1.2.13, ab Werk AUS).
     *
     * Der Tarifvergleich rechnet bis hierher mit einem eingebauten
     * Haushalts-Lastprofil - einer Modellrechnung. Wer seinen wirklichen
     * stuendlichen Verbrauch liefern kann (Smartmeter-Plugin, eigenes
     * Skript, Wechselrichter), bekommt daraus eine Messung statt eines
     * Modells: der gewichtete Tagesschnitt entsteht dann aus dem, was
     * wirklich verbraucht wurde, nicht aus dem, was ein Durchschnitts-
     * haushalt verbraucht haette.
     *
     * Gleiche Bauform wie die PV-Prognose darueber, damit niemand ein
     * zweites Format lernen muss. Leer heisst aus - und dann bleibt alles
     * genau so, wie es in 1.2.12 war. */
    'last_quelle' => '',         // '' | objekt | liste
    'last_url' => '',
    'last_pfad' => '',
    'last_zeitfeld' => '',
    'last_wertfeld' => '',
    'last_einheit' => 'kwh',     // kwh | wh | w | kw
    /* Hysterese (planer.php 1.1.0): ein begonnener Block laeuft bis zu
     * seinem Ende, auch wenn die neue Preisreihe inzwischen eine billigere
     * Stunde kennt. Ab Werk AN - ohne sie kann ein Geraet mitten im
     * Betrieb abschalten, und das will niemand absichtlich. */
    'hysterese' => 1,
) + plan_global_vorgabe();
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
    $cfg += spot_vorgaben();
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
        // Felder des Fahrplaners. Hier wird gekappt, nicht abgewiesen: das
        // Abweisen macht die Oberflaeche beim Speichern, und was schon in der
        // Datei steht, soll das Plugin nicht zum Absturz bringen.
        $r['rang'] = max(1, min(99, (int) $r['rang']));
        $r['leistung'] = max(0.0, min(100.0, (float) $r['leistung']));
        $r['energie'] = max(0.0, min(500.0, (float) $r['energie']));
        $r['frist'] = (int) $r['frist'];
        if ($r['frist'] < 0 || $r['frist'] > 23) { $r['frist'] = -1; }
        $r['pv_sperre'] = max(0.0, min(500.0, (float) $r['pv_sperre']));
        $r['soc_min'] = max(0, min(100, (int) $r['soc_min']));
        $r['soc_max'] = max(0, min(100, (int) $r['soc_max']));
        /* Taktschutz (planer.php 1.1.0). Beide in Minuten, 0 = aus. Bei
         * Stundenpreisen ist eine Mindestlaufzeit unter 60 Minuten
         * wirkungslos - das steht in der Hilfe, nicht in einer Schranke:
         * abweisen waere bevormundend, und 0 heisst ohnehin aus. */
        $r['min_lauf'] = max(0, min(720, (int) $r['min_lauf']));
        $r['min_pause'] = max(0, min(720, (int) $r['min_pause']));
        $cfg['regeln'][$i] = $r;
    }
    // Fahrplaner, global
    $cfg['budget_kw'] = max(0.0, min(200.0, (float) $cfg['budget_kw']));
    $cfg['pv_bonus'] = max(0.0, min(100.0, (float) $cfg['pv_bonus']));
    $cfg['pv_schwelle'] = max(1, min(100000, (int) $cfg['pv_schwelle']));
    /* Zweites, zeitlich begrenztes Budget (Paragraf 14a EnWG) und die
     * Hysterese - beide aus planer.php 1.1.0. */
    $cfg['budget2_kw'] = max(0.0, min(200.0, (float) $cfg['budget2_kw']));
    $cfg['budget2_von'] = max(0, min(23, (int) $cfg['budget2_von']));
    $cfg['budget2_bis'] = max(0, min(23, (int) $cfg['budget2_bis']));
    $cfg['hysterese'] = empty($cfg['hysterese']) ? 0 : 1;
    if (!in_array($cfg['pv_quelle'], array('', 'forecast_solar', 'objekt', 'liste'), true)) {
        $cfg['pv_quelle'] = '';
    }
    if (!in_array($cfg['pv_einheit'], array('wh', 'w', 'kw'), true)) {
        $cfg['pv_einheit'] = 'wh';
    }
    if (!in_array($cfg['last_quelle'], array('', 'objekt', 'liste'), true)) {
        $cfg['last_quelle'] = '';
    }
    if (!in_array($cfg['last_einheit'], array('kwh', 'wh', 'w', 'kw'), true)) {
        $cfg['last_einheit'] = 'kwh';
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

/**
 * Merker, die einen Neustart ueberstehen muessen.
 *
 * spot_tmpdir() zeigt auf /tmp/spotpreis - und /tmp ist auf dem LoxBerry
 * fluechtig. Fuer Merker, die nur ein paar Minuten gelten (ptest, said_,
 * mqtt_sig), ist das genau richtig: nach einem Neustart soll wieder von vorn
 * begonnen werden.
 *
 * Fuer "das ist heute/diesen Monat schon geschehen" ist es falsch. Der
 * Merker "Preise fuer morgen sind veroeffentlicht" lag bis 1.1.1 in /tmp:
 * ein Neustart nach 14 Uhr, und die Ansage samt Pushnachricht kam ein
 * zweites Mal. Dasselbe gilt fuer den Monatsbericht - ein Merker in /tmp
 * wuerde nach einem Neustart am Monatsersten einen zweiten Bericht
 * ausloesen.
 *
 * data/plugins/<ordner> ueberlebt Neustart UND Plugin-Update.
 */
function spot_merker($name) {
    return spot_datadir() . '/marke_' . preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
}

/**
 * Ist der Merker gesetzt? Wenn nicht, wird er gesetzt und true zurueckgegeben.
 * Alles in einem Schritt, damit zwei gleichzeitige Laeufe nicht beide
 * "noch nicht geschehen" sehen.
 */
function spot_merker_setzen($name) {
    $f = spot_merker($name);
    // 'x' schlaegt fehl, wenn die Datei schon da ist - unteilbar im Dateisystem.
    $fh = @fopen($f, 'x');
    if ($fh === false) {
        return false;
    }
    fwrite($fh, date('c') . "\n");
    fclose($fh);
    return true;
}

/* ==================================================================
 * Lebenszeichen
 *
 * Ein virtueller Eingang behaelt seinen letzten Wert. Stirbt der
 * Minutencron - Update, fehlende Bibliothek, PHP-Fehler -, dann steht in
 * Loxone weiter der Preis vom Ausfallzeitpunkt, und in der App sieht alles
 * normal aus. Das ist keine fehlende Auskunft, sondern eine Falschaussage,
 * und sie sieht aus wie eine richtige. Ihre Schaltregeln laufen dann nach
 * einem eingefrorenen Fahrplan weiter.
 *
 * Zwei Werte beantworten das, und sie beantworten Verschiedenes:
 *
 *   TS    Zeitstempel des letzten Laufs in Unix-Sekunden. Ueber MQTT gibt
 *         es kein "Alter", nur einen Zeitstempel - der Miniserver rechnet
 *         selbst: Alter = (Loxone-Zeit + 1230768000) - TS.
 *   LAUF  ein Zaehler, der bei 999 umlaeuft. Er beantwortet, was der
 *         Zeitstempel nicht kann: ein Raspberry ohne Echtzeituhr springt
 *         beim ersten Zeitabgleich, und ein Alter kann danach negativ oder
 *         stundenlang sein, obwohl alles laeuft. Eine umlaufende Zahl nicht.
 *
 * Der Zaehler liegt im Datenordner, nicht in /tmp: er soll einen Neustart
 * ueberstehen, sonst faengt er staendig bei 0 an und ein "haengt" ist von
 * einem "neu gestartet" nicht zu unterscheiden.
 * ================================================================== */

/** Zaehlerstand lesen, ohne ihn zu veraendern. */
function spot_lauf_stand() {
    $f = spot_datadir() . '/laufzaehler';
    return is_file($f) ? ((int) trim((string) @file_get_contents($f)) % 1000) : 0;
}

/** Zaehler eine Stelle weiterdrehen und den neuen Stand zurueckgeben. */
function spot_lauf_weiter() {
    $neu = (spot_lauf_stand() + 1) % 1000;
    spot_write_atomic(spot_datadir() . '/laufzaehler', (string) $neu);
    return $neu;
}

/**
 * Wie es um die Konfigurationsdatei steht - fuer die Selbstpruefung.
 *
 * Jeder Zustand, den der Code erzeugen kann, braucht seinen eigenen Satz.
 * spot_config() heilt eine fehlende oder leere Datei still aus der
 * Zweitschrift; das ist richtig, darf aber nicht unsichtbar bleiben.
 *
 * Rueckgabe: 'ok' | 'vorgabe' | 'zweitschrift' | 'kaputt'
 */
function spot_konfig_lage() {
    $p = spot_paths();
    if (!is_file($p['config'])) {
        return is_file($p['backup']) ? 'zweitschrift' : 'vorgabe';
    }
    $roh = trim((string) @file_get_contents($p['config']));
    if ($roh === '' || $roh === '{}') {
        return is_file($p['backup']) ? 'zweitschrift' : 'vorgabe';
    }
    $d = json_decode($roh, true);
    if (!is_array($d)) {
        return 'kaputt';
    }
    return 'ok';
}

/** Alte Merker eines Musters wegraeumen, ausser dem aktuellen. */
function spot_merker_aufraeumen($muster, $behalten) {
    foreach ((array) glob(spot_datadir() . '/marke_' . $muster) as $f) {
        if (basename($f) !== 'marke_' . $behalten) {
            @unlink($f);
        }
    }
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

/**
 * Eine Zeile nur protokollieren, wenn sie sich geaendert hat.
 *
 * Die Merkdatei wird ueber temp + rename geschrieben. Der Schaden waere hier
 * gering - ein halb geschriebener Merker fuehrt zu einer doppelten
 * Protokollzeile, nicht zu einem falschen Messwert. Es kostet aber nichts,
 * und ein Muster, das im Plugin an einer Stelle gilt und an der anderen
 * nicht, laedt zum Nachahmen der falschen Haelfte ein.
 */
/**
 * Die letzten $anzahl Zeilen des Protokolls, neueste zuerst.
 *
 * Bis 1.1.1 las die Oberflaeche das ganze Protokoll mit file() ein und warf
 * den groessten Teil wieder weg. Nachgemessen an einer Datei kurz vor der
 * Rotationsgrenze (512 kB, 6384 Zeilen, 300 gewuenscht), PHP 7.4 und 8.1:
 *
 *   file() + array_reverse   0,3 ms   Spitze 1445 kB
 *   exec("tail -n 300")      1,9 ms   Spitze   72 kB
 *   rueckwaerts mit fseek    0,04 ms  Spitze  123 kB
 *
 * Der Hinweis auf den Speicher war berechtigt. Der vorgeschlagene Weg ueber
 * tail ist aber der langsamste der drei: ein Prozessstart kostet mehr, als
 * das Einlesen je gespart hat. Rueckwaerts lesen ist in beidem besser und
 * braucht keine Shell.
 */
function spot_log_ende($datei, $anzahl = 300, $block = 8192) {
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

function spot_log_if_changed($key, $line) {
    $f = spot_tmpdir() . '/last_' . preg_replace('/[^A-Za-z0-9_]/', '', (string) $key) . '.txt';
    $prev = is_file($f) ? (string) file_get_contents($f) : '';
    if ($line === $prev) {
        return;
    }
    spot_log($key . ': ' . $line);
    $tmp = $f . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $line) !== false) {
        if (!@rename($tmp, $f)) {
            @unlink($tmp);
        }
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
            spot_write_atomic($cache, $neu);
            $js = $neu;
        } elseif (is_file($cache)) {
            $js = file_get_contents($cache);
        }
    }
    $d = @json_decode((string) $js, true);
    if (!isset($d['data']) || count($d['data']) < 20) {
        return null;
    }
    /* AUFLOESUNG DER ANTWORT - gemessen, nicht angenommen.
     *
     * Am 27.08.2026 liefert api.awattar.de/v1/marketdata 24 Datensaetze mit
     * 60-Minuten-Intervallen in Eur/MWh. Der deutsche Day-Ahead-Handel geht
     * aber schrittweise auf Viertelstunden ueber; kaeme das hier an, haette
     * der bisherige Code von je vier Viertelstunden drei lautlos verworfen,
     * weil er nach der Stundenzahl schluesselt. Ein Tag saehe danach voellig
     * normal aus und traege drei Viertel falscher Preise.
     *
     * Deshalb wird die Schrittweite GEMESSEN und alles, was feiner als eine
     * Stunde ist, zum Stundenmittel zusammengefasst - das ist die richtige
     * Umrechnung, nicht eine Auswahl. Die erkannte Schrittweite wird
     * vermerkt; der Reiter Test zeigt sie an, damit ein Wechsel auffaellt,
     * statt still zu wirken. */
    $roh = array();
    $schritt = 3600;
    foreach ($d['data'] as $row) {
        if (!isset($row['start_timestamp']) || !isset($row['marketprice'])) { continue; }
        $ts = (int) ($row['start_timestamp'] / 1000);
        $roh[$ts] = round($row['marketprice'] / 1000, 6); // EUR/MWh -> EUR/kWh (netto, Boerse)
        if (isset($row['end_timestamp'])) {
            $s = (int) (($row['end_timestamp'] - $row['start_timestamp']) / 1000);
            if ($s > 0 && $s < $schritt) { $schritt = $s; }
        }
    }
    ksort($roh);
    if ($schritt >= 3600) {
        $out = $roh;
    } else {
        $summe = array(); $zahl = array();
        foreach ($roh as $ts => $p) {
            $stunde = $ts - ($ts % 3600);
            if (!isset($summe[$stunde])) { $summe[$stunde] = 0.0; $zahl[$stunde] = 0; }
            $summe[$stunde] += $p; $zahl[$stunde]++;
        }
        $out = array();
        foreach ($summe as $stunde => $s) {
            $out[$stunde] = round($s / max(1, $zahl[$stunde]), 6);
        }
        ksort($out);
        spot_log_if_changed('aufloesung', 'aWATTar liefert ' . $schritt . '-Sekunden-Werte ('
            . count($roh) . ' Datensaetze) - zu ' . count($out) . ' Stundenmitteln zusammengefasst.');
    }
    @file_put_contents(spot_tmpdir() . '/aufloesung', (int) $schritt);
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

/**
 * Kennzahlen eines Tages (Endpreise in ct/kWh).
 *
 * ZWEI TAGE IM JAHR HAT EIN TAG NICHT 24 STUNDEN, und beide waren bis 1.2.12
 * still falsch. Der Schluessel von $hours ist die Stundenzahl, gemessen mit
 * date('G'):
 *
 *   Ende der Sommerzeit (25.10.2026): 25 Preise, zwei davon auf Stunde 2.
 *     Bis 1.2.12 gewann der zweite und der erste verschwand lautlos -
 *     gemessen: 25 Werte hinein, 24 heraus. Jetzt gilt der ERSTE, der
 *     zweite wird in 'doppelt' vermerkt statt verworfen.
 *
 *   Beginn der Sommerzeit (28.03.2027): 23 Preise, Stunde 2 fehlt ganz.
 *     Bis 1.2.12 stand in PH02 daraufhin 0.000 - und 0 ct sieht fuer jeden
 *     Optimierer wie die guenstigste Stunde des Tages aus. Das ist die
 *     schlimmste Art Fehler: eine Zahl, die richtig aussieht und in Loxone
 *     eine Schaltung ausloest. Eine Stunde, die es auf der Uhr nicht gibt,
 *     darf nie gewaehlt werden - sie bekommt deshalb den TAGESHOECHSTPREIS
 *     (siehe spot_state(), profil_heute/profil_morgen).
 *
 * Der Tagesschnitt rechnet ueber ALLE gelieferten Stunden, auch die 25.
 */
function spot_daystats($prices) {
    if (!$prices) {
        return null;
    }
    $min = null; $max = null; $sum = 0; $n = 0; $hours = array(); $doppelt = array();
    foreach ($prices as $ts => $bp) {
        $h = (int) date('G', $ts);
        $ct = round(spot_endprice($bp) * 100, 3);
        if (isset($hours[$h])) {
            // Zeitumstellung: dieselbe Stundenzahl ein zweites Mal. Der
            // erste Wert bleibt stehen; verworfen wird nichts.
            $doppelt[] = array('h' => $h, 'ct' => $ct, 'ts' => $ts);
        } else {
            $hours[$h] = array('ct' => $ct, 'boerse' => round($bp * 100, 3), 'ts' => $ts);
        }
        $sum += $ct; $n++;
        if ($min === null || $ct < $min[1]) { $min = array($h, $ct); }
        if ($max === null || $ct > $max[1]) { $max = array($h, $ct); }
    }
    ksort($hours);
    // Welche Stundenzahlen der Tag gar nicht hergibt - fuer das Profil und
    // fuer die Selbstpruefung, die es sagen soll statt es zu verstecken.
    $luecken = array();
    for ($h = 0; $h < 24; $h++) {
        if (!isset($hours[$h])) { $luecken[] = $h; }
    }
    return array('minh' => $min[0], 'minp' => $min[1], 'maxh' => $max[0], 'maxp' => $max[1],
                 'avg' => round($sum / max(1, $n), 3), 'n' => $n, 'hours' => $hours,
                 'doppelt' => $doppelt, 'luecken' => $luecken);
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

/* ==================================================================
 * Fremde Auskuenfte fuer den Fahrplaner
 *
 * PV-Prognose und Speicherstand kommen von irgendwo her - von
 * forecast.solar, von einem anderen LoxBerry-Plugin, von einem eigenen
 * Skript. Das Plugin holt sie und reicht sie an den Planer weiter; das
 * AUSWERTEN steckt in planer.php und ist dort ohne Netz durchgeprueft.
 *
 * Zwischengespeichert wird 15 Minuten. Eine Prognose aendert sich nicht
 * schneller, und ein Fremddienst, den jedes Plugin im Minutentakt fragt,
 * sperrt irgendwann aus.
 * ================================================================== */

function spot_umwelt($force = false) {
    $cfg = spot_config();
    $leer = array('pv' => null, 'pv_summe' => null, 'soc' => null,
                  'pv_meldung' => '', 'soc_meldung' => '', 'ts' => 0);
    $cache = spot_tmpdir() . '/umwelt.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 900) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c)) { return $c + $leer; }
    }
    $erg = $leer;
    $erg['ts'] = time();
    $jetzt = time() - (time() % 3600);

    if ($cfg['pv_quelle'] !== '' && trim((string) $cfg['pv_url']) !== '') {
        $roh = spot_holen($cfg['pv_url']);
        if ($roh === null) {
            $erg['pv_meldung'] = 'NICHT_ERREICHBAR';
        } else {
            list($pv, $m) = plan_pv_lesen($roh, $cfg['pv_quelle'], $cfg['pv_pfad'],
                $cfg['pv_zeitfeld'], $cfg['pv_wertfeld'], $cfg['pv_einheit'], 3600);
            $erg['pv_meldung'] = $m;
            if ($pv) {
                $erg['pv'] = $pv;
                $erg['pv_summe'] = plan_pv_summe($pv, $jetzt, 24);
            }
        }
    }

    if (trim((string) $cfg['soc_url']) !== '') {
        $roh = spot_holen($cfg['soc_url']);
        if ($roh === null) {
            $erg['soc_meldung'] = 'NICHT_ERREICHBAR';
        } else {
            list($soc, $m) = plan_soc_lesen($roh, $cfg['soc_pfad']);
            $erg['soc_meldung'] = $m;
            $erg['soc'] = $soc;
        }
    }

    spot_write_json_atomic($cache, $erg);
    return $erg;
}

/* ==================================================================
 * Eigener Lastgang - aus einem Modell wird eine Messung
 *
 * Der Tarifvergleich gewichtet den Tagesschnitt bis 1.2.12 mit einem
 * eingebauten Haushaltsprofil. Das ist eine Annahme ueber einen
 * Durchschnittshaushalt und liegt bei jedem Haus mit Waermepumpe,
 * Wallbox oder PV daneben - und zwar in beide Richtungen, je nachdem,
 * wann verbraucht wird.
 *
 * Wer stuendliche Verbrauchswerte liefern kann, bekommt statt dessen die
 * Wahrheit: jede Stunde mit ihrem WIRKLICHEN Verbrauch gegen den Preis
 * DERSELBEN Stunde. Das ist die Zahl, nach der man einen Tarif wechselt.
 *
 * Bewusst dieselbe Bauform wie die PV-Prognose - dieselben Feldnamen,
 * dasselbe Auswerten ueber plan_pv_lesen(). Die Einheit kwh gibt es dort
 * nicht (die Prognose rechnet in Wh); sie wird hier davor umgerechnet,
 * weil ein Verbrauch nun einmal in kWh angegeben wird.
 *
 * Rueckgabe: array('werte' => [ts => Wh], 'meldung' => '', 'ts' => Zeit).
 * Leere Werte plus Meldung heisst: es wurde nichts gemessen. Dann rechnet
 * der Vergleich wie bisher mit dem Profil weiter - und sagt das auch.
 * ================================================================== */
function spot_lastgang($force = false)
{
    $cfg = spot_config();
    $leer = array('werte' => array(), 'meldung' => '', 'ts' => 0);
    if ($cfg['last_quelle'] === '' || trim((string) $cfg['last_url']) === '') {
        return $leer;
    }
    $cache = spot_tmpdir() . '/lastgang.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 900) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c) && isset($c['werte'])) { return $c + $leer; }
    }
    $erg = $leer;
    $erg['ts'] = time();
    $roh = spot_holen($cfg['last_url']);
    if ($roh === null) {
        $erg['meldung'] = 'NICHT_ERREICHBAR';
        spot_write_json_atomic($cache, $erg);
        return $erg;
    }
    // kwh kennt plan_nach_wh() nicht - also in wh umrechnen, nicht raten.
    $einheit = $cfg['last_einheit'] === 'kwh' ? 'wh' : $cfg['last_einheit'];
    $faktor = $cfg['last_einheit'] === 'kwh' ? 1000.0 : 1.0;
    list($werte, $meldung) = plan_pv_lesen($roh, $cfg['last_quelle'], $cfg['last_pfad'],
        $cfg['last_zeitfeld'], $cfg['last_wertfeld'], $einheit, 3600);
    $erg['meldung'] = $meldung;
    if ($werte) {
        foreach ($werte as $ts => $wh) {
            // Ein negativer Verbrauch ist keiner. Einspeisung gehoert nicht
            // in eine Bezugsgewichtung - sie wird abgewiesen, nicht gedreht.
            $w = (float) $wh * $faktor;
            if ($w > 0) { $erg['werte'][$ts] = $w; }
        }
    }
    spot_write_json_atomic($cache, $erg);
    return $erg;
}

/**
 * Den Sperrgrund als Zahl - Loxone rechnet mit Zahlen, nicht mit Woertern.
 * 0 frei, 1 PV-Prognose, 2 Speicher zu leer, 3 Speicher zu voll.
 */
function spot_sperre_zahl($grund) {
    if ($grund === 'pv') { return 1; }
    if ($grund === 'soc_min') { return 2; }
    if ($grund === 'soc_max') { return 3; }
    return 0;
}

/** Eine JSON-Adresse holen. Rueckgabe: Feld oder null. */
function spot_holen($url) {
    $url = trim((string) $url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) { return null; }
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 12, 'user_agent' => 'LoxBerry Spotpreis', 'ignore_errors' => true)));
    $r = @file_get_contents($url, false, $ctx);
    if ($r === false) { return null; }
    $d = json_decode($r, true);
    return is_array($d) ? $d : null;
}

/**
 * Alle Regeln auswerten - seit 1.2.0 ueber den gemeinsamen Fahrplaner.
 *
 * Bis 1.1.2 rechnete jede Regel fuer sich (spot_regel_werte, steht
 * unveraendert darueber und wird noch vom Reiter Test benutzt, um die alte
 * und die neue Rechnung nebeneinander zu zeigen). Der Planer bringt drei
 * Dinge dazu, die eine einzelne Regel nicht wissen kann: die Frist, das
 * gemeinsame Leistungsbudget und die PV-Prognose.
 */

/* ==================================================================
 * Hysterese: was laeuft, laeuft zu Ende
 *
 * Der Planer bekommt bei jedem Lauf eine frische Preisreihe. Ohne
 * Gedaechtnis kann er deshalb bei jedem Abruf zu einem anderen Ergebnis
 * kommen - und die Wallbox schaltet mitten im Ladevorgang ab, weil in
 * drei Stunden eine Stunde billiger geworden ist.
 *
 * Gemerkt wird nur EINE Zahl je Regel: bis wann der begonnene Block
 * laeuft. Sie wird gesetzt, wenn ein Block ANFAENGT, und nicht mehr
 * angefasst, bis er vorbei ist. Damit kann sie sich nicht selbst
 * verlaengern - das waere eine Regel, die nie wieder ausgeht.
 *
 * Die Ablage liegt in /tmp und uebersteht einen Neustart nicht. Das ist
 * richtig so: nach einem Neustart laeuft ohnehin nichts mehr, und ein
 * Gedaechtnis an einen Block, den niemand mehr faehrt, waere falsch.
 *
 * Wortgleich mit dem Baustein im Spotpreis-Octopus-Plugin, nur mit dem
 * Kuerzel dieser Linie - dieselbe Ueberlegung soll nicht zweimal
 * verschieden aussehen.
 * ================================================================== */

/** array(Regelindex => bis_ts). Abgelaufene Eintraege fallen weg. */
function spot_laufend_lesen() {
    $cfg = spot_config();
    if (empty($cfg['hysterese'])) { return array(); }
    $f = spot_tmpdir() . '/laufend.json';
    if (!is_file($f)) { return array(); }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d)) { return array(); }
    $jetzt = time();
    $out = array();
    foreach ($d as $i => $bis) {
        if (is_array($bis)) { continue; }
        $bis = (int) $bis;
        // Harte Obergrenze: kein Block laeuft laenger als 24 Stunden.
        if ($bis > $jetzt && $bis <= $jetzt + 86400) { $out[(int) $i] = $bis; }
    }
    return $out;
}

/**
 * Nach der Rechnung fortschreiben.
 *
 * Drei Faelle je Regel:
 *   laeuft und war noch nicht vermerkt  -> Ende eintragen
 *   laeuft und war vermerkt             -> unveraendert stehen lassen
 *   laeuft nicht                        -> Eintrag entfernen
 */
function spot_laufend_fortschreiben($regeln, $jetzt) {
    $cfg = spot_config();
    $f = spot_tmpdir() . '/laufend.json';
    if (empty($cfg['hysterese'])) {
        /* is_file() VOR unlink(). Das @ genuegt nicht, wenn ein eigener
         * Fehlerbehandler gesetzt ist - der wird unabhaengig von
         * error_reporting gerufen, und "No such file or directory" steht
         * dann als Befund im Protokoll, obwohl nichts fehlt. */
        if (is_file($f)) { @unlink($f); }
        return;
    }
    $alt = spot_laufend_lesen();
    $neu = array();
    foreach ((array) $regeln as $r) {
        if (!is_array($r) || empty($r['aktiv'])) { continue; }
        $i = (int) $r['nr'] - 1;
        if (isset($alt[$i])) { $neu[$i] = $alt[$i]; continue; }
        $rest = isset($r['rest']) ? (int) $r['rest'] : 0;
        if ($rest > 0) { $neu[$i] = (int) $jetzt + $rest * 60; }
    }
    @file_put_contents($f, json_encode($neu));
}

function spot_regeln($all, $st) {
    $cfg = spot_config();
    $umwelt = spot_umwelt();
    $fp = plan_rechnen($all, 3600, (int) $st['hstart'], $cfg['regeln'], array(
        'pv'       => isset($umwelt['pv']) ? $umwelt['pv'] : null,
        'pv_summe' => isset($umwelt['pv_summe']) ? $umwelt['pv_summe'] : null,
        'soc'      => isset($umwelt['soc']) ? $umwelt['soc'] : null,
        'neg'      => !empty($st['neg']) ? 1 : 0,
        /* Das Tagesmittel nur uebergeben, wenn es eines GIBT. 0.0 waere ein
         * Wert und kein Nichtwissen - und bei negativen Preisen ist der
         * Unterschied entscheidend (planer.php 1.1.0). */
        'mittel'   => (!empty($st['ok']) && !empty($st['heute']['n']))
                      ? (float) $st['heute']['avg'] : null,
        'laufend'  => spot_laufend_lesen(),
    ), array(
        'budget_kw'   => $cfg['budget_kw'],
        'pv_bonus'    => $cfg['pv_bonus'],
        'pv_schwelle' => $cfg['pv_schwelle'],
        'budget2_kw'  => $cfg['budget2_kw'],
        'budget2_von' => $cfg['budget2_von'],
        'budget2_bis' => $cfg['budget2_bis'],
    ));

    $out = array();
    foreach ($fp as $i => $w) {
        $r = isset($cfg['regeln'][$i]) ? $cfg['regeln'][$i] : array();
        // 'in' und 'rest' kommen aus dem Planer in MINUTEN. Dieses Plugin
        // rechnet seit jeher in Stunden, und daran haengen die virtuellen
        // Eingaenge im Miniserver - also hier zurueckrechnen.
        $w['in'] = $w['in'] < 0 ? -1 : (int) round($w['in'] / 60);
        $w['rest'] = (int) round($w['rest'] / 60);
        $w['name'] = (isset($r['name']) && $r['name'] !== '') ? $r['name'] : ('Regel ' . ($i + 1));
        $w['art'] = isset($r['art']) ? $r['art'] : 'fenster';
        $w['ein'] = empty($r['aktiv']) ? 0 : 1;
        unset($w['slots']);   // die Liste selbst braucht Loxone nicht
        $out[] = $w;
    }
    return $out;
}

/**
 * Der Fahrplan MIT den Zeitscheiben - nur fuer die Anzeige.
 *
 * spot_regeln() wirft die Scheibenliste weg, weil Loxone sie nicht braucht.
 * Die Oberflaeche braucht sie sehr wohl: erst daran sieht man, wann welche
 * Regel laeuft und wie viel Leistung gleichzeitig verplant ist.
 *
 * Bewusst ein zweiter Aufruf und kein Zwischenspeicher: die Rechnung ist
 * reine Arithmetik ueber hoechstens 48 Werte, und ein zweiter Cache waere
 * eine zweite Stelle, die veralten kann.
 *
 * Rueckgabe: array('plan'=>..., 'belegung'=>ts=>kW, 'slotlen'=>Sekunden,
 *                  'preise'=>ts=>ct)
 */
function spot_fahrplan($st = null) {
    $cfg = spot_config();
    if ($st === null) { $st = spot_state(); }
    $all = array();
    $heute = spot_day(strtotime('today 00:00'));
    $morgen = spot_day(strtotime('tomorrow 00:00'));
    foreach (array($heute, $morgen) as $tag) {
        if (is_array($tag)) {
            foreach ($tag as $ts => $netto) { $all[$ts] = spot_endprice($netto); }
        }
    }
    ksort($all);
    $umwelt = spot_umwelt();
    $plan = plan_rechnen($all, 3600, (int) $st['hstart'], $cfg['regeln'], array(
        'pv'       => isset($umwelt['pv']) ? $umwelt['pv'] : null,
        'pv_summe' => isset($umwelt['pv_summe']) ? $umwelt['pv_summe'] : null,
        'soc'      => isset($umwelt['soc']) ? $umwelt['soc'] : null,
        'neg'      => !empty($st['neg']) ? 1 : 0,
        /* Das Tagesmittel nur uebergeben, wenn es eines GIBT. 0.0 waere ein
         * Wert und kein Nichtwissen - und bei negativen Preisen ist der
         * Unterschied entscheidend (planer.php 1.1.0). */
        'mittel'   => (!empty($st['ok']) && !empty($st['heute']['n']))
                      ? (float) $st['heute']['avg'] : null,
        'laufend'  => spot_laufend_lesen(),
    ), array(
        'budget_kw'   => $cfg['budget_kw'],
        'pv_bonus'    => $cfg['pv_bonus'],
        'pv_schwelle' => $cfg['pv_schwelle'],
        'budget2_kw'  => $cfg['budget2_kw'],
        'budget2_von' => $cfg['budget2_von'],
        'budget2_bis' => $cfg['budget2_bis'],
    ));
    foreach ($plan as $i => $p) {
        $plan[$i]['name'] = (isset($cfg['regeln'][$i]['name']) && $cfg['regeln'][$i]['name'] !== '')
            ? $cfg['regeln'][$i]['name'] : ('Regel ' . ($i + 1));
    }
    return array('plan' => $plan, 'belegung' => plan_belegung($plan),
                 'slotlen' => 3600, 'preise' => $all);
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
    /* Fehlt eine Stunde, ist die Frage nicht "welche Zahl passt am besten",
     * sondern "welche Zahl richtet keinen Schaden an". Eine 0 waere die
     * schlechteste: sie sieht fuer den Spot Price Optimizer wie die
     * guenstigste Stunde des Tages aus und wuerde eine Schaltung ausloesen,
     * die es nicht geben darf. Deshalb der TAGESHOECHSTPREIS - damit wird
     * die Stunde nie gewaehlt.
     *
     * Zwei Faelle fuehren hierher, und nur der erste ist selten:
     *   - der 28.03.2027 und jeder Beginn der Sommerzeit: Stunde 2 gibt es
     *     auf der Uhr nicht.
     *   - die Preise fuer morgen sind noch nicht veroeffentlicht (vor etwa
     *     14 Uhr der Normalfall): dann ist das ganze Feld leer. Auch hier
     *     darf keine 0 stehen, sonst sehen alle 24 Stunden von morgen wie
     *     Geschenke aus. Ohne eigenen Hoechstpreis gilt der von heute; ob
     *     die Werte ueberhaupt schon gelten, sagt OK.
     *
     * Ohne jeden Preis - erster Start, aWATTar nicht erreichbar - bleibt es
     * bei 0. Dann steht aber auch HOK auf 0, und die Anleitung sagt, dass
     * ohne HOK kein Wert dieser Zeile gilt. */
    $ph_ersatz = ($sh && isset($sh['maxp'])) ? round((float) $sh['maxp'], 3) : 0.0;
    $pm_ersatz = ($sm && isset($sm['maxp'])) ? round((float) $sm['maxp'], 3) : $ph_ersatz;
    for ($h = 0; $h < 24; $h++) {
        $st['profil_heute'][$h] = isset($st['heute']['hours'][$h]['ct'])
            ? round((float) $st['heute']['hours'][$h]['ct'], 3) : $ph_ersatz;
        $st['profil_morgen'][$h] = isset($st['morgen']['hours'][$h]['ct'])
            ? round((float) $st['morgen']['hours'][$h]['ct'], 3) : $pm_ersatz;
    }
    // Was der Tag an Stunden nicht hergibt - die Selbstpruefung sagt es an.
    $st['luecken_heute'] = ($sh && isset($sh['luecken'])) ? $sh['luecken'] : array();
    $st['doppelt_heute'] = ($sh && isset($sh['doppelt'])) ? count($sh['doppelt']) : 0;
    // Rollend ab der laufenden Stunde - fuer den Modus "Relativ" des Spot
    // Price Optimizer (Eingaenge +0 bis +23).
    $st['profil_relativ'] = array();
    for ($h = 0; $h < 24; $h++) {
        $st['profil_relativ'][$h] = isset($all[$hstart + $h * 3600])
            ? round((float) $all[$hstart + $h * 3600], 3) : 0.0;
    }
    /* Fremde Auskuenfte vor den Regeln - der Planer braucht sie.
     * Ein Fehlschlag hier macht den Zustand nicht ungueltig: ohne Prognose
     * plant der Planer wie vorher, nur ohne Gutschrift. */
    $umwelt = spot_umwelt();
    $st['pv_summe'] = isset($umwelt['pv_summe']) ? $umwelt['pv_summe'] : null;
    $st['soc'] = isset($umwelt['soc']) ? $umwelt['soc'] : null;
    $st['pv_meldung'] = isset($umwelt['pv_meldung']) ? $umwelt['pv_meldung'] : '';
    $st['soc_meldung'] = isset($umwelt['soc_meldung']) ? $umwelt['soc_meldung'] : '';

    // Schaltregeln zuletzt: sie brauchen neg, hstart und das Tagesmittel.
    $st['regeln'] = spot_regeln($all, $st);

    /* Verplante Leistung in der laufenden Stunde - die eine Zahl, an der
     * sich ablesen laesst, ob das Budget greift. */
    $st['planlast'] = 0.0;
    foreach ($st['regeln'] as $r) {
        if (!empty($r['aktiv'])) { $st['planlast'] += (float) $r['leistung']; }
    }
    $st['planlast'] = round($st['planlast'], 2);
    /* Summe dessen, was das Warten bringt - je Regel gerechnet, hier
     * zusammengezaehlt. Die eine Zahl, an der sich ablesen laesst, ob der
     * ganze Fahrplaner sich lohnt. */
    $st['spart_eur'] = 0.0;
    foreach ($st['regeln'] as $r) {
        $st['spart_eur'] += isset($r['spart_eur']) ? (float) $r['spart_eur'] : 0.0;
    }
    $st['spart_eur'] = round($st['spart_eur'], 2);
    /* Die Hysterese fortschreiben - ERST nach der Rechnung, damit der
     * naechste Lauf sie vorfindet. */
    spot_laufend_fortschreiben($st['regeln'], (int) $st['hstart']);
    spot_write_json_atomic($cache, $st);
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
    spot_write_json_atomic($cache, $out);
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
            $agg[$m] = array('n' => 0, 'sum' => 0, 'sump' => 0, 'gem' => 0, 'kwh' => 0.0);
        }
        $agg[$m]['n']++;
        $agg[$m]['sum'] += $r[1];
        $agg[$m]['sump'] += (isset($r[4]) && $r[4] > 0) ? $r[4] : $r[1];
        // Tage mit eigenem Lastgang zaehlen - siehe spot_history_add().
        if (!empty($r[6])) { $agg[$m]['gem']++; $agg[$m]['kwh'] += (float) $r[7]; }
    }
    $out = array();
    foreach ($agg as $m => $a) {
        $dyn = round($a['sum'] / max(1, $a['n']), 3);
        $dynp = round($a['sump'] / max(1, $a['n']), 3);
        $diff = round($fix - $dynp, 3); // positiv = dynamisch waere guenstiger gewesen
        $mi = ((int) substr($m, 4, 2)) - 1;
        $tage_mon = (int) date('t', strtotime(substr($m, 0, 4) . '-' . substr($m, 4, 2) . '-01'));
        /* Die Verbrauchsmenge in drei Stufen, von gemessen zu geraten:
         *   1. eigener Lastgang - aber nur, wenn er den GANZEN Monat deckt.
         *      Zehn gemessene von dreissig Tagen ergaeben sonst einen
         *      Monatsverbrauch von einem Drittel, und der Euro-Betrag
         *      darunter waere um zwei Drittel zu klein.
         *   2. gepflegter Monatswert aus der Oberflaeche
         *   3. Jahresverbrauch durch 365
         * Welche Stufe es war, steht in 'quelle' - und die Tabelle zeigt es
         * an, statt eine Genauigkeit zu behaupten. */
        if ($a['gem'] >= $a['n'] && $a['kwh'] > 0) {
            $kwh_zeitraum = $a['kwh'];
            $quelle = 'lastgang';
        } elseif ($mon['use'] && !empty($mon['kwh'][$mi])) {
            $kwh_zeitraum = $mon['kwh'][$mi] / max(1, $tage_mon) * $a['n'];
            $quelle = 'monat';
        } else {
            $kwh_zeitraum = max(0.1, (float) $cfg['consumption']) / 365.0 * $a['n'];
            $quelle = 'jahr';
        }
        $out[$m] = array(
            'monat' => $m, 'tage' => $a['n'], 'dyn' => $dyn, 'dynp' => $dynp, 'fix' => $fix,
            'diff' => $diff, 'euro' => round($diff / 100 * $kwh_zeitraum, 2),
            'kwh' => round($kwh_zeitraum, 1), 'quelle' => $quelle,
            'gemessen' => $a['gem'],
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
 * Rueckgabe z. B. "192.168.1.10" (Fallback: 127.0.0.1).
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
    /* Ohne Web-Kontext (Cron): Adresse ueber eine Test-Verbindung.
     *
     * GEPRUEFT WIRD, OB ES socket_create() UEBERHAUPT GIBT. Die Erweiterung
     * 'sockets' ist auf einem LoxBerry nicht garantiert geladen, und ein
     * Aufruf ohne sie ist kein Fehler zur Laufzeit, sondern ein Fatal error:
     * die GANZE Seite bleibt weiss, nicht nur diese Zeile. Aufgefallen am
     * 10.08.2026 in einem PHP ohne die Erweiterung.
     *
     * Der Rueckfallweg ueber Datenstroeme kann dasselbe: eine UDP-"Verbindung"
     * verschickt kein Paket, sie legt nur die Route fest - und daraus liest
     * stream_socket_get_name() die eigene Adresse. */
    if (function_exists('socket_create')) {
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
    } else {
        $nr = 0; $txt = '';
        $st = @stream_socket_client('udp://8.8.8.8:53', $nr, $txt, 1);
        if ($st) {
            $name = @stream_socket_get_name($st, false);
            fclose($st);
            $ip = preg_replace('/:\d+$/', '', (string) $name);
            if ($ip !== '' && preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip)) {
                $cand[] = $ip;
            }
        }
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

/**
 * Die Konfiguration schreiben - unteilbar, mit Sicherungskopie.
 *
 * Anmerkung zu einer Beanstandung: es hiess, das temp+rename-Muster sei bei
 * den Konfigurations-JSONs bereits vorbildlich umgesetzt und fehle nur bei
 * den kleinen Merkdateien. Das war umgekehrt - bis 1.1.1 schrieb die
 * Oberflaeche die spot.json mit einem einfachen file_put_contents, also
 * kuerzen und neu fuellen. Ein Abbruch mittendrin hinterlaesst eine halbe
 * Datei. Aufgefangen haette es die Selbstheilung in spot_config() (leere
 * oder unvollstaendige Konfiguration wird aus der Sicherungskopie geholt) -
 * aber sich auf die Reparatur zu verlassen, statt den Schaden zu vermeiden,
 * ist die falsche Reihenfolge.
 *
 * rename() ist innerhalb desselben Dateisystems unteilbar: wer liest, sieht
 * entweder die alte oder die neue Datei, nie einen Zwischenstand.
 */
/**
 * Eine Datei unteilbar schreiben - dasselbe Muster wie spot_config_save(),
 * aber fuer die Zwischenspeicher.
 *
 * WARUM AUCH DIE ZWISCHENSPEICHER: An state.json haengen zwei Schreiber (der
 * Minutencron und spot.php, wenn der Zwischenspeicher abgelaufen ist) und ein
 * Leser, der bei jedem Abruf des Miniservers vorbeikommt. Ein einfaches
 * file_put_contents kuerzt die Datei zuerst auf null.
 *
 * Falsche Werte bekommt Loxone dadurch nicht - spot_state() prueft die
 * gelesene Struktur und rechnet bei Bruch neu. Aber genau das ist der Schaden:
 * Aus einem Lesevorgang aus dem Zwischenspeicher wird eine vollstaendige
 * Neuberechnung, im schlechtesten Fall mit einem Abruf bei aWATTar - waehrend
 * der Miniserver auf seine Antwort wartet.
 *
 * $daten wird hier kodiert und nicht als fertiger Text erwartet: json_encode
 * liefert bei ungueltigem UTF-8 false, und file_put_contents($f, false)
 * schreibt klaglos eine leere Datei. Der Zwischenspeicher waere dann dauerhaft
 * unbrauchbar, ohne dass etwas auffiele - jede Abfrage rechnete neu.
 */
function spot_write_json_atomic($datei, $daten) {
    $json = json_encode($daten);
    if ($json === false) {
        return false;
    }
    return spot_write_atomic($datei, $json);
}

function spot_write_atomic($datei, $inhalt) {
    if ($inhalt === false || $inhalt === null) {
        return false;
    }
    $inhalt = (string) $inhalt;
    $dir = dirname($datei);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $datei . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);
    if (@file_put_contents($tmp, $inhalt) !== strlen($inhalt)) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $datei)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function spot_config_save($cfg) {
    $p = spot_paths();
    $dir = dirname($p['config']);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false - dann darf nichts
    // geschrieben werden, sonst stuende eine leere Konfiguration da.
    if ($json === false) {
        return false;
    }
    $tmp = $p['config'] . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json) === false) {
        return false;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $p['config'])) {
        @unlink($tmp);
        return false;
    }
    @copy($p['config'], $p['backup']);
    // Zwischenspeicher verwerfen: die Preise werden mit den neuen
    // Aufschlaegen neu gerechnet.
    @unlink(spot_tmpdir() . '/state.json');
    return true;
}

/** Ein einzelner Wert aus der Konfiguration, mit Vorgabe. */
function spot_cfg_wert($schluessel, $vorgabe = '') {
    $c = spot_config();
    return isset($c[$schluessel]) ? $c[$schluessel] : $vorgabe;
}

/* ==================================================================
 * Formularmerkmal gegen fremde Absender (Wachposten)
 *
 * Das ist etwas ANDERES als der Aktionstoken. Der Aktionstoken schuetzt
 * den unangemeldeten Endpunkt und gehoert in die Sicherungsdatei; dieses
 * Merkmal hier lebt eine Sitzung, schuetzt die angemeldete Oberflaeche
 * gegen Formulare fremder Herkunft - und hat in einer Datei nichts zu
 * suchen. Wer beide verwechselt, macht aus der Umzugshilfe ein Leck.
 *
 * Der Wachposten steht EINMAL am Kopf der index.php und wirkt auf ALLE
 * Zweige, ohne dass einer davon davon wissen muss. Ein "$post = false"
 * je Zweig wirkt nur, wenn wirklich jeder daran haengt - und einen
 * vergisst man.
 * ================================================================== */

/**
 * Das Formularmerkmal dieser Anlage.
 *
 * EINE QUELLE, und das ist die Datei im Datenordner. Die PHP-Sitzung wird
 * nur als Zwischenspeicher benutzt und bekommt DENSELBEN Wert.
 *
 * Warum nicht die Sitzung als Quelle: sie laesst sich nicht immer starten.
 * Auf diesem Pruefstand ist genau das aufgetreten - session_start() gelang
 * bei einem Aufruf und beim naechsten nicht, und damit zeigte die Seite ein
 * Merkmal aus der Sitzung, waehrend der Wachposten gegen das aus der Datei
 * verglich. Ergebnis: ein Speichervorgang, der mit einer Fehlermeldung
 * abgewiesen wird, die niemand zuordnen kann - und zwar nicht immer,
 * sondern manchmal. Zwei Quellen fuer EIN Geheimnis laufen auseinander;
 * das ist dieselbe Klasse wie zwei Stellen, die denselben Namen bilden.
 *
 * Was das Merkmal leistet und was nicht: eine fremde Seite kann es nicht
 * lesen und deshalb kein gueltiges Formular bauen - das ist sein Zweck. Es
 * wechselt nicht mit der Anmeldung, ist also schwaecher als ein echtes
 * Sitzungsmerkmal. Fuer eine Oberflaeche, die ohnehin hinter der Anmeldung
 * des LoxBerry liegt, ist das der richtige Tausch: ein Schutz, der den
 * Anwender gelegentlich aussperrt, wird abgeschaltet und schuetzt dann gar
 * nichts.
 */
function spot_formtoken() {
    static $wert = null;
    if ($wert !== null) {
        return $wert;
    }
    $f = spot_datadir() . '/formtoken';
    $gelesen = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    if (strlen($gelesen) < 16) {
        $gelesen = spot_token_erzeugen(32);
        spot_write_atomic($f, $gelesen);
        // Rechte unmittelbar nach dem Anlegen: die Datei traegt ein
        // Geheimnis und geht niemanden ausser dem Webserver etwas an.
        @chmod($f, 0600);
    }
    $wert = $gelesen;
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['spot_fmt'] = $wert;
    }
    return $wert;
}

/** Das versteckte Feld fuer ein Formular. */
function spot_fmt() {
    return '<input data-role="none" type="hidden" name="fmt" value="'
        . htmlspecialchars(spot_formtoken(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Traegt die Anfrage das Merkmal?
 *
 * Gelesen wird aus $_POST, nie aus $_REQUEST: sonst liesse sich das
 * Merkmal ueber die Adresszeile mitschicken, und genau das soll es
 * verhindern. Verglichen wird mit hash_equals - und vorher auf is_string
 * geprueft, weil ?fmt[]=x sonst eine "Array to string conversion" wirft.
 */
function spot_formtoken_ok() {
    if (!isset($_POST['fmt']) || !is_string($_POST['fmt'])) {
        return false;
    }
    $soll = spot_formtoken();
    // hash_equals('','') ist true - ein leeres Soll darf nie durchgehen.
    return $soll !== '' && hash_equals($soll, (string) $_POST['fmt']);
}

/**
 * Zufallstoken fuer den unangemeldeten Endpunkt.
 * Ohne mehrdeutige Zeichen (0/O, 1/l), weil man es abtippt.
 */
function spot_token_erzeugen($laenge = 24) {
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

/**
 * Ist das eine Adresse, die dieses Plugin abrufen darf?
 *
 * file_get_contents() kennt nicht nur http. Nachgemessen mit PHP 7.4 und
 * 8.1, jeweils mit einem http-Kontext (der fuer andere Wrapper einfach
 * ignoriert wird):
 *
 *   file:///pfad/datei          -> Datei wird GELESEN
 *   php://filter/...resource=   -> Datei wird GELESEN (base64)
 *   expect://id                 -> nichts (Erweiterung nicht vorhanden)
 *   ftp://...                   -> nichts
 *
 * Die Antwort landet im Protokoll, und das Protokoll zeigt die Oberflaeche
 * an. Wer die Adresse setzen kann, konnte damit beliebige fuer den
 * Webserver lesbare Dateien in das Protokoll holen.
 *
 * Zur Einordnung: eine Codeausfuehrung ist das NICHT - file_get_contents
 * fuehrt nichts aus. Es ist ein Lesezugriff und ein Aufruf an beliebige
 * Rechner und Ports (SSRF). Dafuer braucht es allerdings bereits Zugang zur
 * angemeldeten Plugin-Oberflaeche.
 *
 * Erlaubt sind deshalb nur http und https.
 */
function spot_url_ok($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return false;
    }
    if (!preg_match('#^https?://#i', $url)) {
        return false;
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
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
    } elseif (!spot_url_ok($url)) {
        // Zweite Schranke hinter der Oberflaeche: eine Adresse, die aus einer
        // aelteren Fassung oder von Hand in der spot.json steht, wird hier
        // ebenfalls abgewiesen - und zwar benannt, nicht stillschweigend.
        spot_log_if_changed('marstek', 'Adresse abgewiesen (nur http/https erlaubt): ' . $url);
        return;
    }
    $url .= (strpos($url, '?') === false ? '?' : '&') . 'p=' . $p . '&t=240';
    $ctx = stream_context_create(array('http' => array('timeout' => 8)));
    $r = @file_get_contents($url, false, $ctx);
    spot_log_if_changed('marstek', ($laden ? 'laden mit ' . $p . ' W' : 'kein Spot-Laden')
        . ' (Rang ' . $st['rank'] . ', neg=' . $st['neg'] . ') -> ' . ($r !== false ? trim((string) $r) : 'FEHLER'));
}

/* ---------------- MQTT (LoxBerry MQTT Gateway, UDP-Relay) ---------------- */

/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung des Betriebssystems, einem Geraetenamen oder der Ausgabe
 * eines Systembefehls - zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen. Ein Tabulator schadet ebenso, weil
 * Leerzeichen Thema und Wert trennt.
 */
/* ==================================================================
 * Zustand und FASSUNG des LoxBerry-MQTT-Gateways
 *
 * Das Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
 * Es gibt es in zwei Fassungen, und sie verlangen vom Anwender
 * ENTGEGENGESETZTES:
 *
 *   V1 (Vorgabe)  Das Abo wird von Hand eingetragen. Ohne den Eintrag
 *                 kommt am Miniserver nichts an - die haeufigste
 *                 Fehlerursache ueberhaupt.
 *   V2            Es ist NICHTS einzutragen. Das Gateway erkennt die
 *                 Themengruppe selbst; in den Abonnements werden nur noch
 *                 die gewuenschten Datenpunkte angehakt. Auf der
 *                 Abonnement-Seite schaltet der Kern die Eingabeknoepfe
 *                 ausdruecklich ab, sobald die Fassung 2 ist.
 *
 * Bis 1.2.12 hat dieses Plugin die Fassung nicht gelesen - und den Satz
 * ueberhaupt nicht gesagt. Wer MQTT einschaltete, bekam die Themenliste
 * und sonst nichts; unter V1 kam damit am Miniserver kein einziger Wert an,
 * ohne dass irgendwo stand, warum.
 *
 * Belegt am LoxBerry-Kern, webfrontend/htmlauth/system/mqtt-gateway.cgi:
 *     $gatewayversion = $generaljson->{Mqtt}->{Gatewayversion} // 1;
 *     $template->param("FORM_DISABLE_BUTTONS", 1) if $gatewayversion == 2;
 *
 * NICHT selbst gemessen ist, dass V2 die Themen von selbst erkennt. Das
 * steht in der Oberflaeche eines fremden Plugins (mschlenstedt,
 * LoxBerry-Plugin-MGiSMART, Schluessel TOPIC_HINT) und passt zu den
 * abgeschalteten Knoepfen - es bleibt aber eine Sekundaerquelle.
 *
 * Rueckgabe: null, wenn general.json nicht lesbar ist. Sonst ein Feld mit
 * autostart (bool) und fassung (int). fassung 0 heisst NICHT LESBAR und
 * wird ausdruecklich nicht auf 1 vorbelegt: "unbekannt" und "Fassung 1"
 * sind verschiedene Aussagen, und die Oberflaeche behandelt sie
 * verschieden - bei 0 stehen BEIDE Saetze da.
 * ================================================================== */
function spot_mqtt_gateway_info()
{
    $p = spot_paths();
    if ($p['lbhome'] === '') {
        return null;
    }
    $d = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    if (!is_array($d) || !isset($d['Mqtt']) || !is_array($d['Mqtt'])) {
        return null;
    }
    $auto = isset($d['Mqtt']['Gatewayautostart']) ? $d['Mqtt']['Gatewayautostart'] : '';
    return array(
        'autostart' => in_array((string) $auto, array('1', 'true'), true),
        'fassung'   => isset($d['Mqtt']['Gatewayversion']) ? (int) $d['Mqtt']['Gatewayversion'] : 0,
    );
}

/* HIER STAND DER HELFER "spot_mqtt_gateway_autostart" - mit 1.2.14
 * entfernt. Der Name steht hier bewusst OHNE Klammern: ein Suchmuster, das
 * die Aufrufform zaehlt, schlaegt sonst auf diesen Erklaertext an und
 * meldet einen Aufruf, den es nicht gibt.
 *
 * Der Hausstandard fuehrt den Helfer, und im Vorbild MGiSmart wird er auch
 * benutzt: dessen beide Aufrufstellen brauchen nur den Autostart. In diesem
 * Plugin liegt es anders. Gemessen an allen drei Stellen, die den
 * Gateway-Zustand ueberhaupt brauchen:
 *
 *   MQTT-Reiter          autostart UND fassung
 *   Reiter Loxone, Schritt 6   fassung UND autostart
 *   Selbstpruefung PRUEF.MQTT  autostart UND fassung
 *
 * Ein Helfer, der nur den Autostart liefert, haette an keiner der drei
 * etwas gespart - er haette einen zweiten Aufruf erzwungen oder die halbe
 * Auskunft geliefert. Er stand seit dem Zusammenlegen in 1.2.13 unbenutzt
 * da; gefunden hat ihn tote_helfer.py.
 *
 * Kein Werkzeug haengt am Namen (nachgesehen), und der Aufruf war nie
 * veroeffentlicht - es geht also nichts kaputt. Wer ihn wieder braucht,
 * findet ihn in MGiSmart 1.1.2, mg_lib.php.
 */

function spot_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

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
        // Lebenszeichen - siehe den Block ueber spot_lauf_stand().
        'status/ts' => isset($st['ts']) ? (int) $st['ts'] : time(),
        'status/zaehler' => spot_lauf_stand(),
        'status/ok' => (int) $st['ok'],
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
        $msgs[$z . 'verdraengt'] = isset($r['verdraengt']) ? (int) $r['verdraengt'] : 0;
        $msgs[$z . 'sperre'] = spot_sperre_zahl(isset($r['gesperrt']) ? $r['gesperrt'] : '');
        $msgs[$z . 'rang'] = isset($r['rang']) ? (int) $r['rang'] : 50;
        // planer.php 1.1.0: was fehlt, und was das Warten bringt.
        $msgs[$z . 'fehlt'] = isset($r['fehlt']) ? (int) $r['fehlt'] : 0;
        $msgs[$z . 'spart'] = isset($r['spart_ct']) ? (float) $r['spart_ct'] : 0.0;
        $msgs[$z . 'spart_eur'] = isset($r['spart_eur']) ? (float) $r['spart_eur'] : 0.0;
    }
    // Fahrplaner, global
    $msgs['plan/budget'] = (float) $cfg['budget_kw'];
    $msgs['plan/budget2'] = (float) $cfg['budget2_kw'];
    $msgs['plan/spart'] = isset($st['spart_eur']) ? (float) $st['spart_eur'] : 0.0;
    $msgs['plan/last'] = isset($st['planlast']) ? (float) $st['planlast'] : 0.0;
    if (isset($st['pv_summe']) && $st['pv_summe'] !== null) {
        $msgs['plan/pv_prognose'] = (float) $st['pv_summe'];
    }
    if (isset($st['soc']) && $st['soc'] !== null) {
        $msgs['plan/soc'] = (float) $st['soc'];
    }
    spot_mqtt_senden($prefix, $udpport, $msgs);
}

/**
 * NUR das Lebenszeichen - drei Themen, sonst nichts.
 *
 * Der Cron veroeffentlicht den vollen Satz nur bei Aenderung (und
 * mindestens halbstuendlich); das ist richtig, sonst stuende der Broker
 * voll mit identischen Werten. Der Zeitstempel muss aber bei JEDEM
 * Durchgang hinausgehen, auch wenn sich sonst nichts geruehrt hat - genau
 * darin besteht seine Aufgabe. Deshalb geht er am Doppelt-senden-Filter
 * vorbei, und deshalb ist das hier eine eigene Funktion.
 */
function spot_mqtt_lebenszeichen($st = null) {
    $cfg = spot_config();
    if (empty($cfg['mqtt_enabled'])) {
        return;
    }
    $p = spot_paths();
    if ($p['lbhome'] === '') {
        return;
    }
    $gen = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    $udpport = isset($gen['Mqtt']['Udpinport']) ? (int) $gen['Mqtt']['Udpinport'] : 0;
    if (!$udpport && isset($gen['mqtt']['udpinport'])) { $udpport = (int) $gen['mqtt']['udpinport']; }
    if (!$udpport) {
        return;
    }
    $prefix = trim((string) $cfg['mqtt_topic']) !== '' ? trim((string) $cfg['mqtt_topic']) : 'spot_awattar';
    spot_mqtt_senden($prefix, $udpport, array(
        'status/ts' => ($st !== null && isset($st['ts'])) ? (int) $st['ts'] : time(),
        'status/zaehler' => spot_lauf_stand(),
        'status/ok' => ($st !== null && isset($st['ok'])) ? (int) $st['ok'] : 0,
    ));
}

/**
 * Die Themen wirklich absetzen - ueber das UDP-Relais des Gateways.
 *
 * Herausgezogen, damit der volle Satz und das Lebenszeichen denselben Weg
 * gehen. Zwei Kopien desselben Sendecodes laufen auseinander, und dann
 * traegt ein Weg Werte, die der andere nicht hat.
 *
 * Die Erweiterung 'sockets' ist auf einem LoxBerry nicht garantiert
 * geladen. Ohne die Pruefung stirbt der Cron-Lauf mit einem Fatal error,
 * und in der Logdatei steht nichts, was darauf hinweist - deshalb der
 * Rueckfallweg ueber Datenstroeme.
 */
function spot_mqtt_senden($prefix, $udpport, $msgs) {
    $udpport = (int) $udpport;
    if ($udpport < 1 || $udpport > 65535 || !$msgs) {
        return;
    }
    if (function_exists('socket_create')) {
        $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$s) {
            return;
        }
        foreach ($msgs as $k => $v) {
            $msg = 'publish ' . $prefix . '/' . $k . ' ' . spot_mqtt_wert_saeubern($v);
            @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $udpport);
        }
        socket_close($s);
        return;
    }
    $nr = 0; $txt = '';
    $strom = @stream_socket_client('udp://127.0.0.1:' . $udpport, $nr, $txt, 2);
    if (!$strom) {
        return;
    }
    foreach ($msgs as $k => $v) {
        @fwrite($strom, 'publish ' . $prefix . '/' . $k . ' ' . spot_mqtt_wert_saeubern($v));
    }
    fclose($strom);
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
    /* TS und LAUF stehen VORN, unmittelbar hinter OK. Sie sind das
     * Lebenszeichen: ohne sie kann der Miniserver einen toten Cron nicht
     * von ruhigen Preisen unterscheiden. Angehaengt, nicht eingeschoben -
     * die Befehlserkennung in Loxone sucht Textstellen, bestehende
     * Eingaenge merken von den beiden neuen Feldern nichts. */
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

    /* Lebenszeichen als eigene Zeile. TS ist der Zeitpunkt, zu dem der
     * Zustand zuletzt wirklich gerechnet wurde - nicht der Zeitpunkt dieses
     * Abrufs. Genau das ist der Sinn: fragt der Miniserver alle 300 s und
     * der Cron ist seit zwei Stunden tot, dann steht hier ein zwei Stunden
     * alter TS, waehrend jede andere Zahl der Zeile unveraendert plausibel
     * aussieht. */
    $o .= sprintf("LEBEN;TS=%d;LAUF=%d\n",
        isset($st['ts']) ? (int) $st['ts'] : time(),
        spot_lauf_stand());

    // Schaltregeln als EIGENE Zeile hinter der bisherigen. Die
    // Befehlserkennung in Loxone sucht Textstellen, nicht Zeilen -
    // bestehende Eingaenge merken davon nichts.
    $teile = array();
    foreach ((array) (isset($st['regeln']) ? $st['regeln'] : array()) as $r) {
        $n = (int) $r['nr'];
        $teile[] = sprintf('R%d=%d;R%dIN=%d;R%dREST=%d;R%dCT=%.3f;R%dVERD=%d;R%dSPERRE=%d',
            $n, $r['aktiv'], $n, $r['in'], $n, $r['rest'], $n, $r['ct'],
            $n, isset($r['verdraengt']) ? (int) $r['verdraengt'] : 0,
            $n, spot_sperre_zahl(isset($r['gesperrt']) ? $r['gesperrt'] : ''));
    }
    $o .= 'REGEL;' . implode(';', $teile) . "\n";

    /* Der Fahrplaner als eigene Zeile. Auch hier gilt: die Befehlserkennung
     * in Loxone sucht Textstellen, nicht Zeilen - bestehende Eingaenge
     * merken von der neuen Zeile nichts. */
    $o .= sprintf("PLAN;PVSUM=%.3f;SOC=%d;BUDGET=%.2f;PLANLAST=%.2f\n",
        isset($st['pv_summe']) && $st['pv_summe'] !== null ? (float) $st['pv_summe'] : 0.0,
        isset($st['soc']) && $st['soc'] !== null ? (int) round($st['soc']) : -1,
        (float) $cfg['budget_kw'],
        isset($st['planlast']) ? (float) $st['planlast'] : 0.0);

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
        /* Lebenszeichen. TS ist eine Unix-Sekundenzahl und damit gross -
         * MaxVal muss reichen, sonst kappt Loxone den Wert. 2147483647 ist
         * das Ende der 32-Bit-Zeitrechnung (2038) und die groesste Zahl,
         * die Config an dieser Stelle sinnvoll fuehrt.
         * In Loxone: Alter in Sekunden = (Zeit-Baustein + 1230768000) - TS. */
        'TS'      => array(1, 0, 2147483647, 's', 'Zeitpunkt des letzten Laufs (Unix-Sekunden)'),
        'LAUF'    => array(1, 0, 999, '', 'Laufzaehler, laeuft bei 999 um - wechselt er nicht mehr, steht der Cron'),
    );
    // Schaltregeln - der Grund fuer die ganze Uebung: R<n> ist digital.
    for ($i = 1; $i <= SPOT_REGELN; $i++) {
        $f['R' . $i]          = array(0, 0, 1, '', 'Schaltregel ' . $i . ': jetzt einschalten');
        $f['R' . $i . 'IN']   = array(1, -1, 48, 'h', 'Schaltregel ' . $i . ': Stunden bis zum naechsten Fenster');
        $f['R' . $i . 'REST'] = array(1, 0, 48, 'h', 'Schaltregel ' . $i . ': verbleibende Stunden');
        $f['R' . $i . 'CT']   = array(1, -100, 200, 'ct/kWh', 'Schaltregel ' . $i . ': Schnitt im Fenster');
        // Fahrplaner ab 1.2.0. VERD und SPERRE beantworten die Frage, die
        // sonst im Dunkeln bleibt: warum laeuft es gerade NICHT?
        $f['R' . $i . 'VERD']   = array(1, 0, 96, '',
            'Schaltregel ' . $i . ': Stunden, die eine hoeher gereihte Regel weggenommen hat');
        $f['R' . $i . 'SPERRE'] = array(1, 0, 3, '',
            'Schaltregel ' . $i . ': 0 frei, 1 PV-Prognose, 2 Speicher zu leer, 3 Speicher zu voll');
    }
    // Fahrplaner, global
    $f['PVSUM'] = array(1, 0, 1000, 'kWh', 'PV-Prognose der naechsten 24 Stunden');
    $f['SOC']   = array(1, -1, 100, '%', 'Speicherstand, -1 = keine Auskunft');
    $f['BUDGET'] = array(1, 0, 200, 'kW', 'Eingestelltes Leistungsbudget, 0 = keines');
    $f['PLANLAST'] = array(1, 0, 200, 'kW', 'Verplante Leistung in der laufenden Stunde');
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
    $o .= '<VirtualInHttp HintText="" ';
    $o .= 'Title="' . spot_x($kopf['title']) . '" ';
    $o .= 'Comment="' . spot_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . spot_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . spot_x(isset($kopf['polling']) ? $kopf['polling'] : '300') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf; // wie Original-Export aus Loxone Config 17.1
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
        $o .= 'MaxVal="' . (int) $c['max'] . '" ';
        $o .= 'Unit="' . spot_x(isset($c['unit']) ? $c['unit'] : '<v>') . '" ';
        $o .= 'HintText=""';
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
            'unit' => ($einheit !== '' ? '<v.1> ' . $einheit : '<v.1>'),
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
    if ($mode === 'musicserver' && (string) $tts['ip'] === '') {
        return '';   // ohne IP laesst sich die Music-Server-Adresse nicht bauen
    }

    /* Zonenliste EINMAL fuer alle Modi normalisieren. Vorher wurde nur im
     * Modus musicserver je Zone getrimmt; in den Vorlagen-Modi ging die
     * Eingabe roh in {zones} - aus "2, 4, 6" wurde eine Adresse mit
     * Leerzeichen. */
    $zl = array();
    foreach (explode(',', (string) $tts['zones']) as $z) {
        $z = trim($z);
        if ($z !== '') { $zl[] = $z; }
    }
    $tts['zones'] = implode(',', $zl);
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
    /* Die IP wird nur verlangt, wenn die Vorlage sie auch verwendet.
     * Vorher stand die Pruefung unbedingt am Anfang - eine eigene Vorlage
     * ohne {ip} war damit unbenutzbar (AWM-1.2.0-Fund, hier nachgezogen). */
    if ((string) $tts['ip'] === '' && strpos($tpl, '{ip}') !== false) {
        return '';
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

/**
 * Zahl fuer die Ansage aufbereiten: 24.3 -> "24,3" (de) bzw. "24.3" (en).
 *
 * Das Dezimalzeichen entscheidet darueber, was die Sprachausgabe vorliest.
 * Ein englisches TTS liest "24,3" als "twenty-four, three" - zwei Zahlen
 * statt einer.
 */
function spot_num($v, $dec = 1) {
    $s = number_format((float) $v, $dec, '.', '');
    return spot_sprache() === 'de' ? str_replace('.', ',', $s) : $s;
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
    /* Die Texte kommen aus den Sprachdateien, Abschnitt [ANSAGE].
     *
     * Bis 1.1.1 standen sie hier fest in Deutsch - auf einem englisch
     * eingestellten LoxBerry sprach das Plugin trotzdem Deutsch. Und weil
     * die Quelltextdatei ohne Umlaute auskommen sollte, wurden am Ende
     * neun Woerter per str_replace zurueckverwandelt; wer einen Satz
     * aenderte, musste an diese Liste denken. In den Sprachdateien stehen
     * die Umlaute unmittelbar. */
    if ($st['neg']) {
        $t = sprintf(spot_t('ANSAGE.NEGATIV'), spot_num($st['cur'], 1));
    } else {
        $t = sprintf(spot_t('ANSAGE.PREIS'), spot_num($st['cur'], 1));
        if ($st['level'] === 1) {
            $t .= spot_t('ANSAGE.GUENSTIG');
        } elseif ($st['level'] === 3) {
            $t .= spot_t('ANSAGE.TEUER');
        }
    }
    if ($st['fenster']['in'] === 0) {
        $t .= sprintf(spot_t('ANSAGE.FENSTER_JETZT'), (int) $st['fenster_len']);
    } elseif ($st['fenster']['in'] > 0) {
        $t .= sprintf(spot_t('ANSAGE.FENSTER_SPAETER'), (int) $st['fenster']['h']);
    }
    if (!empty($st['co2_ok']) && !empty($st['co2_clean'])) {
        $t .= sprintf(spot_t('ANSAGE.CO2'), (int) $st['co2']);
    }
    return $t;
}

/** Ansagetext, sobald die Preise fuer morgen veroeffentlicht sind. */
function spot_tomorrow_text($st = null) {
    if ($st === null) {
        $st = spot_state();
    }
    if (!$st['tomorrow_ok']) {
        return '';
    }
    return sprintf(spot_t('ANSAGE.MORGEN'),
        (int) $st['morgen']['minh'], spot_num($st['morgen']['minp'], 1),
        (int) $st['morgen']['maxh'], spot_num($st['morgen']['maxp'], 1),
        spot_num($st['morgen']['avg'], 1));
}

/**
 * Ansagetext fuer den Monatsbericht.
 *
 * Steht hier und nicht in bin/cron.php, damit der Text an einer Stelle
 * gepflegt wird und sich der Bericht auch von Hand pruefen laesst.
 * $vm ist ein Eintrag aus spot_month_compare().
 */
function spot_month_text($vm) {
    if (!is_array($vm)) {
        return '';
    }
    $t = sprintf(spot_t('ANSAGE.MONAT'), spot_num($vm['dynp'], 1), spot_num($vm['fix'], 1));
    $t .= sprintf(spot_t($vm['diff'] >= 0 ? 'ANSAGE.MONAT_DYN' : 'ANSAGE.MONAT_FIX'),
        spot_num(abs($vm['diff']), 1));
    return $t;
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
    //
    // Der Merker liegt seit 1.1.2 im Datenordner, nicht mehr in /tmp: dort
    // war er nach einem Neustart fort, und die Ansage samt Pushnachricht kam
    // am selben Tag ein zweites Mal.
    if (!empty($cfg['notify']['tomorrow']) && $st['tomorrow_ok']) {
        if (spot_merker_setzen('tomorrow_' . date('Ymd'))) {
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
    spot_merker_aufraeumen('tomorrow_*', 'tomorrow_' . date('Ymd'));
    // Merker frueherer Monatsberichte: den des laufenden Monats behalten.
    spot_merker_aufraeumen('monatsbericht_*', 'monatsbericht_' . date('Ym'));
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
    /* Gewichteter Tagesschnitt.
     *
     * Erste Wahl ist der EIGENE Lastgang: jede Stunde mit dem, was
     * wirklich verbraucht wurde. Nur wenn er fehlt oder den Tag nicht
     * abdeckt, gilt das eingebaute Haushaltsprofil - und die Zeile
     * schreibt mit, welches von beiden es war. Ohne diese Spalte waeren
     * gemessene und gerechnete Monate hinterher nicht auseinanderzuhalten,
     * und der Vergleich wuerde eine Genauigkeit behaupten, die er nicht
     * hat.
     *
     * Verlangt werden mindestens 20 der 24 Stunden. Ein Lastgang mit drei
     * Werten wuerde einen Tagesschnitt aus drei Stunden erzeugen und dabei
     * aussehen wie eine Messung. */
    $prof = spot_profile();
    $lg = spot_lastgang();
    $treffer = array();
    foreach ($st['heute']['hours'] as $h => $row) {
        $ts = isset($row['ts']) ? (int) $row['ts'] : 0;
        if ($ts && isset($lg['werte'][$ts]) && $lg['werte'][$ts] > 0) {
            $treffer[$h] = (float) $lg['werte'][$ts];
        }
    }
    $gemessen = count($treffer) >= 20 ? 1 : 0;
    $ws = 0; $w = 0;
    foreach ($st['heute']['hours'] as $h => $row) {
        $g = $gemessen
            ? (isset($treffer[$h]) ? $treffer[$h] : 0.0)
            : (isset($prof[(int) $h]) ? $prof[(int) $h] : 1.0);
        $ws += $row['ct'] * $g;
        $w += $g;
    }
    $avgw = $w > 0 ? round($ws / $w, 3) : $st['heute']['avg'];
    $lines[] = $day . ';' . $st['heute']['avg'] . ';' . $st['heute']['minp'] . ';' . $st['heute']['maxp']
             . ';' . $avgw . ';' . (int) (isset($st['co2_avg']) ? $st['co2_avg'] : 0)
             . ';' . $gemessen . ';' . ($gemessen ? round(array_sum($treffer) / 1000.0, 3) : 0);
    if (count($lines) > 400) {
        $lines = array_slice($lines, -400);
    }
    @file_put_contents($f, implode("\n", $lines) . "\n");
    spot_log('Tageswerte gesichert: Schnitt ' . $st['heute']['avg'] . ' ct (gewichtet ' . $avgw
        . ' ct, ' . ($gemessen ? 'eigener Lastgang, ' . count($treffer) . ' Stunden'
                               : 'Haushaltsprofil')
        . '), Min ' . $st['heute']['minp'] . ', Max ' . $st['heute']['maxp'] . ' ct');
}

/**
 * Tages-Statistik lesen:
 * [[Ymd, avg, min, max, avg_gewichtet, co2, gemessen, kwh], ...]
 *
 * Die beiden letzten Spalten gibt es erst seit 1.2.13. Aeltere Zeilen haben
 * sie nicht - sie zaehlen als "nicht gemessen", nicht als 0 kWh Verbrauch.
 * Der Aktualisierungsfall ist hier der Normalfall: jede bestehende Anlage
 * hat eine history.csv mit sechs Spalten.
 */
function spot_history_read($days = 30) {
    $f = spot_datadir() . '/history.csv';
    $out = array();
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
            $c = explode(';', $l);
            if (count($c) >= 4) {
                $out[] = array($c[0], (float) $c[1], (float) $c[2], (float) $c[3],
                               isset($c[4]) ? (float) $c[4] : 0.0, isset($c[5]) ? (int) $c[5] : 0,
                               isset($c[6]) ? (int) $c[6] : 0, isset($c[7]) ? (float) $c[7] : 0.0);
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
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
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


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Der wichtigste Punkt: eine halb gueltige Datei ueberschreibt GAR NICHTS.
 * Wer eine Sicherung zurueckspielt, will entweder den ganzen Stand oder
 * gar keinen - eine zur Haelfte uebernommene Konfiguration ist schlimmer
 * als die alte, und man sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function spot_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(spot_t('TEXT.SICH_KEIN_JSON')), 0);
    }
    $neu = spot_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        /* Der lesbare Kopf wird UEBERGANGEN, nicht beanstandet.
         *
         * Ohne diese drei Zeilen weist die Funktion genau die Datei ab, die
         * spot_sicherung_schreiben() zwei Bildschirmzeilen weiter oben
         * erzeugt hat - der Kopf traegt Schluessel, die es in den Vorgaben
         * nicht gibt und nie geben soll. Wer der Sicherungsdatei etwas
         * hinzufuegt, das keine Einstellung ist, ergaenzt im selben Zug die
         * Leseseite. */
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(spot_t('TEXT.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = spot_t('TEXT.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}

/**
 * Die Sicherungsdatei bauen.
 *
 * VOLLSTAENDIG, aus den Vorgaben heraus: geschrieben werden ALLE Schluessel,
 * nicht nur die abweichenden. Ein Schluessel, der in der Sicherung fehlt,
 * kaeme beim Zurueckspielen aus der Vorgabe - und das ist genau dann falsch,
 * wenn jemand ihn bewusst auf den heutigen Vorgabewert gesetzt hat und sich
 * die Vorgabe spaeter aendert.
 *
 * DER AKTIONSTOKEN IST DABEI. Ohne ihn stuenden nach dem Zurueckspielen alle
 * Felder richtig, und der Miniserver kaeme trotzdem nicht mehr an das
 * Plugin - die Datei waere wertlos. Wer ihn weglaesst, hat kein
 * Sicherheitsmerkmal eingebaut, sondern die Funktion halbiert.
 *
 * Damit traegt die Datei ein Geheimnis. Der Text am Knopf sagt das, und die
 * Datei sagt es in ihrem eigenen Kopf noch einmal - wer sie in einem Jahr
 * wiederfindet, sieht ohne Nachfragen, was er in der Hand haelt.
 *
 * Das FORMULARMERKMAL gehoert ausdruecklich NICHT hinein (siehe
 * spot_formtoken()); es steht auch in keiner Vorgabe und kann deshalb gar
 * nicht hineingeraten.
 *
 * Rueckgabe: array(Dateiname, Inhalt) - oder array('', '') bei ungueltigem
 * UTF-8, denn json_encode gibt dann false zurueck und ein leerer Download
 * saehe wie eine gelungene Sicherung aus.
 */
function spot_sicherung_schreiben()
{
    $cfg = spot_config();
    // Vollstaendig: jeder Vorgabeschluessel steht in der Datei.
    $daten = array(
        '_hinweis' => 'Spotpreis aWATTar - gesicherte Einstellungen.'
                    . ' ENTHAELT DEN AKTIONSTOKEN DES ENDPUNKTS -'
                    . ' wie ein Passwort behandeln, nicht in ein Forum haengen'
                    . ' und nicht an einen Fehlerbericht heften.',
        '_stand'   => date('Y-m-d H:i:s'),
        '_fassung' => spot_fassung(),
    );
    foreach (spot_vorgaben() as $k => $v) {
        $daten[$k] = isset($cfg[$k]) ? $cfg[$k] : $v;
    }
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        return array('', '');
    }
    return array('spotpreis_einstellungen_' . date('Ymd_His') . '.json', $js);
}

/**
 * Die Fassung aus der plugin.cfg.
 *
 * parse_ini_file() scheitert an dieser Datei (die Kommentare enthalten
 * Klammern und Doppelpunkte), deshalb zeilenweise. Faellt sie aus, bleibt
 * die Zeichenkette leer - eine erfundene Nummer waere schlimmer als keine.
 */
function spot_fassung()
{
    $p = spot_paths();
    foreach (array(
        $p['lbhome'] . '/config/plugins/' . basename(dirname($p['config'])) . '/plugin.cfg',
        dirname(dirname(dirname(__DIR__))) . '/plugin.cfg',
        dirname(dirname(__DIR__)) . '/plugin.cfg',
    ) as $kandidat) {
        if ($kandidat === '' || !is_file($kandidat)) { continue; }
        foreach (file($kandidat, FILE_IGNORE_NEW_LINES) ?: array() as $z) {
            if (preg_match('/^\s*VERSION\s*=\s*([0-9][0-9.]*)\s*$/', $z, $m)) {
                return $m[1];
            }
        }
    }
    return '';
}

/**
 * Die Tages-Statistik als CSV zum Herunterladen.
 *
 * Der Tarifvergleich behauptet Betraege in Euro. Wer sie nachrechnen will,
 * braucht die Zahlen, aus denen sie entstanden sind - sonst muss er dem
 * Plugin glauben. Die Datei liegt ohnehin da; sie bekommt nur eine
 * Kopfzeile und einen Knopf.
 *
 * Semikolon als Trenner und Komma als Dezimalzeichen: so oeffnet ein
 * deutsches Tabellenprogramm die Datei ohne Importdialog.
 */
function spot_history_csv()
{
    $zeilen = array('Datum;Schnitt ct/kWh;Minimum ct/kWh;Maximum ct/kWh;gewichtet ct/kWh;'
                  . 'CO2 g/kWh;Gewichtung;Verbrauch kWh');
    foreach (spot_history_read(400) as $r) {
        $zeilen[] = implode(';', array(
            substr($r[0], 6, 2) . '.' . substr($r[0], 4, 2) . '.' . substr($r[0], 0, 4),
            str_replace('.', ',', (string) $r[1]),
            str_replace('.', ',', (string) $r[2]),
            str_replace('.', ',', (string) $r[3]),
            $r[4] > 0 ? str_replace('.', ',', (string) $r[4]) : '',
            $r[5] > 0 ? (string) $r[5] : '',
            // Klartext statt 0/1: die Datei liest ein Mensch, nicht das Plugin.
            !empty($r[6]) ? 'eigener Lastgang' : 'Haushaltsprofil',
            !empty($r[7]) ? str_replace('.', ',', (string) $r[7]) : '',
        ));
    }
    return array('spotpreis_verlauf_' . date('Ymd') . '.csv',
                 implode("\r\n", $zeilen) . "\r\n");
}

/* ==================================================================
 * Selbstpruefung - beantwortet OHNE Loxone, ob die Einrichtung traegt
 *
 * Jede Zeile hat DREI Ausgaenge, nicht zwei:
 *   1 Haken   - gemessen und in Ordnung
 *   0 Kreuz   - gemessen und nicht in Ordnung
 *   2 Strich  - NICHT FESTSTELLBAR
 *
 * Der Strich ist ausdruecklich kein Haken. "Ich konnte es nicht messen"
 * darf nicht aussehen wie "in Ordnung" - eine Zusammenfassung, die besser
 * aussieht als ihr schlechtester Punkt, ist schlimmer als keine.
 *
 * Und jede Zeile, die ueber eine MENGE urteilt, prueft zuerst, ob die Menge
 * ueberhaupt gefuellt ist. Ueber einen Cron, der noch nie gelaufen ist,
 * wird kein Herzschlag beurteilt.
 * ================================================================== */

/**
 * Ruft den eigenen Endpunkt WIRKLICH auf.
 *
 * Das ist die einzige Zeile, die die getrennten Baeume findet: im Archiv
 * liegen html/ und htmlauth/ nebeneinander, auf dem installierten LoxBerry
 * in verschiedenen Baeumen. Eine Leseprufung sieht das nie.
 *
 * Zwischengespeichert (300 s), sonst ruft sich der Webserver bei jedem
 * Klick auf den Reiter selbst auf. Und mit kurzer Frist: unter dem
 * eingebauten PHP-Server (php -S) ist der Prozess einlaeufig und kann eine
 * Anfrage an sich selbst gar nicht bedienen - das ergibt einen STRICH, kein
 * Kreuz, denn es sagt nichts ueber das Plugin.
 *
 * Rueckgabe: array(0|1|2, Klartext)
 */
function spot_endpunkt_probe($force = false)
{
    $cache = spot_tmpdir() . '/endpunkt_probe.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 300) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c) && isset($c[0])) { return array((int) $c[0], (string) $c[1]); }
    }
    $p = spot_paths();
    $ordner = basename(dirname($p['config']));
    $port = 80;
    if ($p['lbhome'] !== '') {
        $g = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
        if (isset($g['Webserver']['Port'])) { $port = (int) $g['Webserver']['Port']; }
    }
    if ($port < 1 || $port > 65535) { $port = 80; }
    $tok = (string) spot_cfg_wert('token', '');
    $url = 'http://127.0.0.1:' . $port . '/plugins/' . rawurlencode($ordner) . '/spot.php'
         . ($tok !== '' ? '?token=' . rawurlencode($tok) : '');
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 5, 'user_agent' => 'LoxBerry Spotpreis Selbsttest', 'ignore_errors' => true)));
    $r = @file_get_contents($url, false, $ctx);
    if ($r === false) {
        $erg = array(2, 'ENDPUNKT_UNKLAR');
    } elseif (strpos($r, 'GRUND=TOKEN') !== false) {
        $erg = array(0, 'ENDPUNKT_TOKEN');
    } elseif (strpos($r, 'SPOT;OK=') === 0 && strpos($r, ';CUR=') !== false) {
        $erg = array(1, 'ENDPUNKT_OK');
    } else {
        $erg = array(0, 'ENDPUNKT_FALSCH');
    }
    spot_write_json_atomic($cache, $erg);
    return $erg;
}

/**
 * Die Zeilen der Selbstpruefung.
 * Rueckgabe: [['schluessel'=>Sprachschluessel, 'ok'=>0|1|2, 'text'=>Klartext], ...]
 */
function spot_selbsttest($endpunkt_pruefen = false)
{
    $z = array();
    $add = function ($schluessel, $ok, $text) use (&$z) {
        $z[] = array('schluessel' => $schluessel, 'ok' => (int) $ok, 'text' => (string) $text);
    };
    $cfg = spot_config();
    $st = spot_state();

    /* Die URSACHE gehoert VOR die Wirkung. Steht die Konfiguration nicht,
     * erklaert das jede leere Zahl darunter - wer die Reihenfolge umdreht,
     * schickt den Leser in die falsche Ecke. */
    $lage = spot_konfig_lage();
    $add('PRUEF.KONFIG', $lage === 'kaputt' ? 0 : ($lage === 'ok' ? 1 : 2),
        spot_t('PRUEFTEXT.KONFIG_' . strtoupper($lage)));

    // Marktdaten. Ohne sie ist jede Zahl darunter eine Null, und das hat
    // dann nichts mit der Einrichtung zu tun.
    $add('PRUEF.PREISE', !empty($st['ok']) ? 1 : 0,
        !empty($st['ok'])
            ? sprintf(spot_t('PRUEFTEXT.PREISE_OK'), (int) $st['heute']['n'],
                      !empty($st['tomorrow_ok']) ? (int) $st['morgen']['n'] : 0)
            : spot_t('PRUEFTEXT.PREISE_FEHLT'));

    /* Lebenszeichen. Erst pruefen, ob der Cron ueberhaupt schon einmal
     * gelaufen ist - ueber eine leere Menge wird nicht geurteilt. */
    $tsdatei = spot_tmpdir() . '/state.json';
    if (!is_file($tsdatei)) {
        $add('PRUEF.LEBEN', 2, spot_t('PRUEFTEXT.LEBEN_NIE'));
    } else {
        $alter = time() - (int) filemtime($tsdatei);
        // Der Cron laeuft jede Minute, der Zustand wird alle 5 Minuten neu
        // gerechnet. 15 Minuten sind deutlich darueber und schlagen nicht
        // bei einem einzelnen verpassten Lauf an.
        $add('PRUEF.LEBEN', $alter <= 900 ? 1 : 0,
            sprintf(spot_t($alter <= 900 ? 'PRUEFTEXT.LEBEN_OK' : 'PRUEFTEXT.LEBEN_ALT'),
                    (int) round($alter / 60), spot_lauf_stand()));
    }

    /* Der eigene Cron-Eintrag. LoxBerry legt die Datei aus cron/cron.01min
     * beim Installieren unter <home>/system/cron/cron.01min/<ordner> ab.
     * Fehlt sie, laeuft nie etwas - und das sieht in Loxone aus wie ruhige
     * Preise, nicht wie ein Defekt. */
    $p = spot_paths();
    $ordner = basename(dirname($p['config']));
    if ($p['lbhome'] === '') {
        $add('PRUEF.CRON', 2, spot_t('PRUEFTEXT.CRON_UNKLAR'));
    } else {
        $cronverz = $p['lbhome'] . '/system/cron/cron.01min';
        if (!is_dir($cronverz)) {
            $add('PRUEF.CRON', 2, spot_t('PRUEFTEXT.CRON_UNKLAR'));
        } else {
            $da = is_file($cronverz . '/' . $ordner);
            $add('PRUEF.CRON', $da ? 1 : 0,
                sprintf(spot_t($da ? 'PRUEFTEXT.CRON_OK' : 'PRUEFTEXT.CRON_FEHLT'),
                        $cronverz . '/' . $ordner));
        }
    }

    // Der eigene Endpunkt - der teure Punkt, deshalb nur auf Verlangen.
    if ($endpunkt_pruefen) {
        list($eok, $etext) = spot_endpunkt_probe();
        $add('PRUEF.ENDPUNKT', $eok, spot_t('PRUEFTEXT.' . $etext));
    } else {
        $add('PRUEF.ENDPUNKT', 2, spot_t('PRUEFTEXT.ENDPUNKT_UNGEPRUEFT'));
    }

    /* MQTT. Drei verschiedene Aussagen, und sie duerfen nicht vermischt
     * werden: MQTT aus, Gateway nicht lesbar, Gateway lesbar. */
    if (empty($cfg['mqtt_enabled'])) {
        $add('PRUEF.MQTT', 2, spot_t('PRUEFTEXT.MQTT_AUS'));
    } else {
        $gw = spot_mqtt_gateway_info();
        if ($gw === null) {
            $add('PRUEF.MQTT', 2, spot_t('PRUEFTEXT.MQTT_UNKLAR'));
        } else {
            $fassung = (int) $gw['fassung'];
            $add('PRUEF.MQTT', $gw['autostart'] ? 1 : 0,
                sprintf(spot_t($gw['autostart'] ? 'PRUEFTEXT.MQTT_OK' : 'PRUEFTEXT.MQTT_AUTOSTART'),
                        $fassung > 0 ? (string) $fassung : spot_t('PRUEFTEXT.FASSUNG_UNBEKANNT')));
        }
    }

    /* Tragen HTTP-Weg und MQTT-Weg dieselben Werte?
     *
     * Die Tabelle im Reiter MQTT ist die Anleitung. Laeuft sie gegen den
     * Sendecode aus, legt jemand einen virtuellen Eingang auf ein Thema an,
     * das es nicht gibt - und der bleibt stumm, ohne Fehlermeldung. */
    $zeile = spot_zeile($st, $cfg);
    $fehlt = array();
    foreach (spot_felder() as $fn => $fd) {
        if (strpos($zeile, ';' . $fn . '=') === false
            && strpos($zeile, "\n" . $fn . '=') === false) {
            $fehlt[] = $fn;
        }
    }
    $add('PRUEF.FELDER', $fehlt ? 0 : 1,
        $fehlt ? sprintf(spot_t('PRUEFTEXT.FELDER_FEHLT'), implode(', ', array_slice($fehlt, 0, 8)))
               : sprintf(spot_t('PRUEFTEXT.FELDER_OK'), count(spot_felder())));

    // Ist die Loxone-Vorlage wohlgeformt? Eine kaputte Vorlage merkt der
    // Anwender sonst erst in Loxone Config - und sucht den Fehler bei sich.
    if (!function_exists('spot_vorlage')) {
        $add('PRUEF.VORLAGE', 2, spot_t('PRUEFTEXT.VORLAGE_UNKLAR'));
    } else {
        $v = spot_vorlage();
        $alt = libxml_use_internal_errors(true);
        $ok = simplexml_load_string($v[1]) !== false;
        libxml_clear_errors();
        libxml_use_internal_errors($alt);
        $add('PRUEF.VORLAGE', $ok ? 1 : 0,
            $ok ? sprintf(spot_t('PRUEFTEXT.VORLAGE_OK'), strlen($v[1]))
                : spot_t('PRUEFTEXT.VORLAGE_KAPUTT'));
    }

    /* Zeitumstellung. Zwei Tage im Jahr hat ein Tag nicht 24 Stunden; das
     * ist kein Fehler, muss aber dastehen, weil sonst jemand die Luecke im
     * Stundenprofil fuer einen Defekt haelt. */
    $luecken = isset($st['luecken_heute']) ? (array) $st['luecken_heute'] : array();
    $doppelt = isset($st['doppelt_heute']) ? (int) $st['doppelt_heute'] : 0;
    if (!$luecken && !$doppelt) {
        $add('PRUEF.STUNDEN', 1, sprintf(spot_t('PRUEFTEXT.STUNDEN_OK'), (int) $st['heute']['n']));
    } else {
        $add('PRUEF.STUNDEN', 2,
            sprintf(spot_t('PRUEFTEXT.STUNDEN_UMSTELLUNG'), (int) $st['heute']['n'],
                    $luecken ? implode(', ', $luecken) : '-', $doppelt));
    }

    // Aufloesung der Marktdaten - ein Wechsel auf Viertelstunden soll
    // auffallen, statt still zu wirken.
    $af = spot_tmpdir() . '/aufloesung';
    if (!is_file($af)) {
        $add('PRUEF.AUFLOESUNG', 2, spot_t('PRUEFTEXT.AUFLOESUNG_UNKLAR'));
    } else {
        $s = (int) trim((string) @file_get_contents($af));
        $add('PRUEF.AUFLOESUNG', $s === 3600 ? 1 : 2,
            sprintf(spot_t($s === 3600 ? 'PRUEFTEXT.AUFLOESUNG_OK' : 'PRUEFTEXT.AUFLOESUNG_FEIN'),
                    (int) round($s / 60)));
    }

    /* Eigener Lastgang. Ab Werk aus - und "aus" ist ein Strich, kein Kreuz:
     * wer ihn nicht eingerichtet hat, hat nichts falsch gemacht. */
    if ($cfg['last_quelle'] === '' || trim((string) $cfg['last_url']) === '') {
        $add('PRUEF.LASTGANG', 2, spot_t('PRUEFTEXT.LASTGANG_AUS'));
    } else {
        $lg = spot_lastgang();
        if ($lg['meldung'] !== '') {
            $add('PRUEF.LASTGANG', 0,
                sprintf(spot_t('PRUEFTEXT.LASTGANG_FEHLER'),
                        spot_t('PLANMELD.' . $lg['meldung'])));
        } else {
            // Ueber eine leere Menge wird nicht geurteilt - aber eine leere
            // Menge bei eingeschalteter Quelle IST ein Befund.
            $n = count($lg['werte']);
            $heute = 0;
            foreach ($st['heute']['hours'] as $row) {
                if (isset($row['ts']) && isset($lg['werte'][(int) $row['ts']])) { $heute++; }
            }
            $add('PRUEF.LASTGANG', $heute >= 20 ? 1 : 0,
                sprintf(spot_t($heute >= 20 ? 'PRUEFTEXT.LASTGANG_OK' : 'PRUEFTEXT.LASTGANG_LUECKIG'),
                        $heute, $n));
        }
    }

    /* Zeilen, die die eigene Datei lesen, melden die Zahl der angesehenen
     * Stellen. Eine Null ist dann kein "in Ordnung", sondern der Hinweis,
     * dass nichts gemessen wurde. */
    $oberflaeche = spot_oberflaeche_datei();
    if ($oberflaeche === '') {
        $add('PRUEF.FORMULARE', 2, spot_t('PRUEFTEXT.OBERFLAECHE_UNKLAR'));
        $add('PRUEF.REITER', 2, spot_t('PRUEFTEXT.OBERFLAECHE_UNKLAR'));
    } else {
        $q = (string) @file_get_contents($oberflaeche);
        // Formulare, die etwas absenden, gegen die Zahl der Merkmale.
        $formulare = preg_match_all('/<form\b[^>]*method=["\']post["\']/i', $q);
        $merkmale = substr_count($q, 'spot_fmt()');
        if ($formulare === 0) {
            $add('PRUEF.FORMULARE', 2, spot_t('PRUEFTEXT.FORMULARE_KEINE'));
        } else {
            $add('PRUEF.FORMULARE', $merkmale >= $formulare ? 1 : 0,
                sprintf(spot_t($merkmale >= $formulare ? 'PRUEFTEXT.FORMULARE_OK' : 'PRUEFTEXT.FORMULARE_FEHLT'),
                        $merkmale, $formulare));
        }
        // Reiterleiste, Flaechen und Positivliste - drei Stellen, die
        // auseinanderlaufen koennen, ohne dass es eine Fehlermeldung gibt.
        $ids = array();
        if (preg_match('/\$sp_reiter_ids\s*=\s*array\(([^)]*)\)/', $q, $m)) {
            preg_match_all("/'([a-z0-9_]+)'/", $m[1], $t);
            $ids = $t[1];
        }
        $flaechen = preg_match_all('/id="tab-([a-z0-9_]+)"/', $q, $f) ? $f[1] : array();
        /* DREI Stellen, nicht zwei: die Positivliste, die Flaechen UND die
         * ausgeschriebene Leiste. Die Leiste steht seit 1.2.13 ausgeschrieben
         * da, damit hausstandard_pruefen.py sie sieht - und genau deshalb
         * kann sie jetzt auch von den anderen beiden abweichen. Wer sie
         * ausschreibt, ohne sie nachrechnen zu lassen, hat den Fehler nur
         * verschoben. */
        $leiste = preg_match_all('/data-pane="tab-([a-z0-9_]+)"/', $q, $l) ? $l[1] : array();
        $fehlend = array_merge(array_diff($ids, $flaechen), array_diff($ids, $leiste));
        $ueberzaehlig = array_merge(array_diff($flaechen, $ids), array_diff($leiste, $ids));
        if (!$ids) {
            $add('PRUEF.REITER', 2, spot_t('PRUEFTEXT.REITER_UNKLAR'));
        } else {
            $add('PRUEF.REITER', (!$fehlend && !$ueberzaehlig) ? 1 : 0,
                (!$fehlend && !$ueberzaehlig)
                    ? sprintf(spot_t('PRUEFTEXT.REITER_OK'), count($ids))
                    : sprintf(spot_t('PRUEFTEXT.REITER_FEHLT'),
                              $fehlend ? implode(', ', array_unique($fehlend)) : '-',
                              $ueberzaehlig ? implode(', ', array_unique($ueberzaehlig)) : '-'));
        }
    }
    return $z;
}

/**
 * Wo die Oberflaechendatei liegt - installiert und im ausgepackten Archiv.
 * Leerstring, wenn sie nicht zu finden ist; dann gibt es einen STRICH,
 * keinen Haken (eine Pruefung ohne Fundstellen ist ein blinder Fleck).
 */
function spot_oberflaeche_datei()
{
    $p = spot_paths();
    $ordner = basename(dirname($p['config']));
    foreach (array(
        $p['lbhome'] . '/webfrontend/htmlauth/plugins/' . $ordner . '/index.php',
        dirname(dirname(__DIR__)) . '/htmlauth/index.php',
    ) as $k) {
        if ($k !== '' && strpos($k, '/index.php') !== false && is_file($k)) {
            return $k;
        }
    }
    return '';
}
