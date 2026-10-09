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
/* Die gemeinsame Sprachausgabe (seit 1.2.33, Nr. 36 b). Eigene Datei daneben,
 * byteweise gleich mit der Stammfassung Werkzeuge/gemeinsam/sprachausgabe.php
 * im Arbeitsordner und mit den Abschriften der anderen Linien; Naeheres im
 * Kopf von sprachausgabe.php. Sie tut beim Laden nichts ausser Funktionen
 * anzulegen und antwortet bei direktem Aufruf mit 403. */
require_once __DIR__ . '/sprachausgabe.php';

/** Anzahl der Schaltregeln. Vier decken Wallbox, Speicher, Warmwasser und
 *  Waermepumpe ab - mehr macht die Oberflaeche unuebersichtlich. */
define('SPOT_REGELN', 4);

/** P1 (Durchgang 01.10.2026, Entscheidung Nr. 30 zu Frage 28, wie empfohlen): Rang und
 *  "guenstigste Stunden" gelten nur, wenn mindestens so viele kuenftige
 *  Preisstunden bekannt sind - die laufende mitgezaehlt, hoechstens die naechsten
 *  24. Gemessen (Pruefbericht code, Befund 4): um 20:15 ohne die Preise fuer
 *  morgen kannte das Plugin nur noch 4 Stunden; die teure Abendstunde war "Rang 1
 *  von 4", die Marstek-Kopplung lud mit 2500 W aus dem Netz, und die Schaltregeln
 *  "guenstigste Stunden"/"Fenster" schalteten ein. 12 passt zur Hoechstzahl der
 *  Ladestunden. Eine andere Entscheidung ist diese eine Zeile.
 *  Planer-30 (02.10.2026): die Zahl steht seit planer.php 1.1.8 dort als
 *  PLAN_RANG_MIN_STUNDEN, weil der Planer die Schaltregeln jetzt selbst nach
 *  Nr. 30 rechnet - eine Quelle fuer Rang, Kopplung und Regeln. */
define('SPOT_RANG_MIN_STUNDEN', PLAN_RANG_MIN_STUNDEN);

/**
 * P4 (Pruefbericht code, Befund 5): Betrieb nur aus dem Zwischenspeicher.
 *
 * spot.php schaltet ihn als Erstes ein, neben spot_nur_lesen(). Solange er an
 * ist, ruft keine Funktion dieser Bibliothek eine fremde Adresse ab (aWATTar,
 * energy-charts, PV-Prognose, Speicherstand, Lastgang), und es wird kein
 * Zwischenspeicher geschrieben (state.json, laufend.json, umwelt.json,
 * co2.json, Merkdateien). Gerechnet wird aus dem, was der Minutenlauf abgelegt
 * hat. Bis 1.2.31 rief der unangemeldete Endpunkt bei kaltem Zwischenspeicher
 * selbst ab - gemessen mit einer Gegenstelle, die jeden Verbindungsaufbau
 * verschluckt: 43,9 s bis zur Antwort, und er schrieb dabei die Dateien des
 * Minutenlaufs.
 */
function spot_nur_zwischenspeicher($an = null) {
    static $wert = false;
    if ($an !== null) {
        $wert = (bool) $an;
    }
    return $wert;
}

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
 * config/plugins, data/plugins UND config/system/general.json traegt. Das
 * trifft die uebliche Installation genauso wie eine an einem anderen Ort -
 * und es trifft auch den Fall, dass das Plugin noch als entpacktes Archiv
 * daliegt (dann findet es nichts und gibt einen Leerstring zurueck, was der
 * Aufrufer abfangen muss).
 *
 * general.json ist die entscheidende Bedingung. Bis 1.2.27 genuegten
 * config/plugins und webfrontend - genau diese Ordner hinterlaesst ein
 * Pruefstand auf einem Arbeitsrechner, und am 05.09.2026 hat eine solche
 * Suche dort das Laufwerk selbst als "LoxBerry" erkannt und Daten geloescht
 * (Regeln/06). In WSL gemessen (Pruefung-Spotpreis-aWATTar-1.2.28, Faelle H1
 * und H2): in einem fremden Baum ohne general.json nahm diese Bibliothek den
 * Baum als Wurzel, und bin/cron.php schrieb dort.
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
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

/* Die Wurzel in der Reihenfolge der Hausregel: erst die Umgebung, dann die
 * Suche - und DANACH NICHTS MEHR, kein fest verdrahteter Systempfad (bis
 * 1.2.27 stand einer als Rueckfall in spot_t()). Ein gesetztes LBHOMEDIR gilt
 * mit config/plugins UND data/plugins darunter - general.json wird hier nicht
 * verlangt, damit Attrappen ohne sie (Werkzeuge/lb) weiter tragen. Rueckgabe
 * '' heisst "keine Wurzel"; jeder Aufrufer muss das abfangen. Bauart
 * tb_lbhome() aus Spotpreis-Tibber 0.9.18. */
function spot_lbhome()
{
    $h = getenv('LBHOMEDIR');
    if ($h && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return rtrim($h, '/');
    }
    return lb_wurzel_ermitteln();
}

/* Fuer bin/cron.php: ohne Wurzel, oder aus einem Archiv heraus, das nicht in
 * der gefundenen Wurzel installiert liegt (Archivmodus in spot_paths()),
 * nichts tun - eine Meldung auf stderr, Rueckgabewert 1. Der Aufruf steht
 * VOR allem, was holt, sendet oder schreibt. Bauart
 * tb_keine_wurzel_abbruch() aus Spotpreis-Tibber 0.9.19. */
function spot_keine_wurzel_abbruch($programm)
{
    $p = spot_paths();
    if ($p['lbhome'] !== '') {
        return;
    }
    if ($p['archiv'] !== '') {
        fwrite(STDERR, $programm . ': Diese Datei liegt nicht in der Installation unter '
            . $p['archiv'] . "\n"
            . '(ausgepacktes Archiv oder Pruefordner). Damit nichts in die Anlage kommt,' . "\n"
            . 'wurde nichts geholt, nichts gesendet und in keine Konfiguration, keine' . "\n"
            . 'Daten und kein Protokoll geschrieben.' . "\n"
            . 'Abhilfe: das Programm aus ' . $p['archiv'] . '/bin/plugins/<ordner> aufrufen' . "\n"
            . 'oder LBHOMEDIR und LBPPLUGINDIR ausdruecklich setzen.' . "\n");
        exit(1);
    }
    fwrite(STDERR, $programm . ': Es wurde kein LoxBerry-Wurzelverzeichnis gefunden.' . "\n"
        . '$LBHOMEDIR ist nicht gesetzt, und oberhalb von ' . __DIR__ . ' traegt kein' . "\n"
        . 'Verzeichnis config/plugins, data/plugins und config/system/general.json.' . "\n"
        . 'Es wurde nichts geholt, nichts gesendet und in keine Konfiguration, keine' . "\n"
        . 'Daten und kein Protokoll geschrieben.' . "\n");
    exit(1);
}

function spot_paths() {
    $lbhomedir = spot_lbhome();
    /* Der Ordnername. LBPPLUGINDIR ist die Auskunft von LoxBerry selbst und
     * hat Vorrang; von ihm zaehlt nur der letzte Pfadteil, und die Namen, die
     * nachweislich kein Pluginordner sind, gelten nicht (Bauart VolkswagenID
     * 0.9.24, Spotpreis-Tibber 0.9.19). Sonst der Ablageort dieser Datei; der
     * feste Name 'spotpreis' greift nur, wo der abgeleitete kein Pluginordner
     * sein KANN - aus dem ausgepackten Archiv heraus heisst er 'html'.
     *
     * Bis 1.2.27 fiel die Ermittlung auf 'spotpreis' zurueck, sobald
     * config/plugins/<name> nicht existierte - aus einem Archiv unter einer
     * echten Wurzel war das die Konfiguration der Anlage (Faelle B1, B2). */
    $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    $lbp_gilt = ($lbp !== '' && !in_array($lbp, array('.', '/', 'html', 'bin', 'plugins'), true));
    $plugindir = basename(__DIR__);
    if ($lbp_gilt) {
        $plugindir = $lbp;
    } elseif (in_array($plugindir, array('', '.', '/', 'html', 'bin', 'plugins'), true)) {
        $plugindir = 'spotpreis';
    }
    /* Archivmodus. Die Pfade DER ANLAGE gelten nur, wenn diese Bibliothek
     * dort installiert liegt (<Wurzel>/webfrontend/html/plugins/<ordner>,
     * physisch verglichen) oder der Aufrufer Wurzel UND Ordner ausdruecklich
     * nennt ($LBHOMEDIR und $LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge mit
     * ihrer Attrappe, und so ruft die Deinstallation bin/cron.php). Sonst ist
     * das ein ausgepacktes Archiv oder ein Pruefordner: alles bleibt in dessen
     * eigenem Ordner, und bin/cron.php steigt aus
     * (spot_keine_wurzel_abbruch()).
     *
     * Bis 1.2.27 nahm ein Archiv unterhalb einer echten Wurzel diese Wurzel
     * und den festen Namen 'spotpreis' - Konfiguration, Daten und Protokoll
     * der Anlage; mit $LBHOMEDIR allein, wie es am Geraet in /etc/environment
     * steht, ebenso, und bin/cron.php lief dort los (in WSL gemessen,
     * Pruefung-Spotpreis-aWATTar-1.2.28, Faelle B1, B2, B6, B7). Bauart
     * tb_paths() aus Spotpreis-Tibber 0.9.19. */
    $gefunden = $lbhomedir;
    if ($lbhomedir !== '') {
        $soll = @realpath($lbhomedir . '/webfrontend/html/plugins/' . basename(__DIR__));
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt && $lbhomedir === rtrim((string) getenv('LBHOMEDIR'), '/');
        if (!$installiert && !$ausdruecklich) {
            $lbhomedir = '';
        }
    }
    if ($lbhomedir !== '') {
        return array(
            'config' => $lbhomedir . '/config/plugins/' . $plugindir . '/spot.json',
            'backup' => $lbhomedir . '/config/plugins/' . $plugindir . '.backup.json',
            'log' => $lbhomedir . '/log/plugins/' . $plugindir . '/spot.log',
            'datadir' => $lbhomedir . '/data/plugins/' . $plugindir,
            /* Der Zwischenspeicher traegt den ERMITTELTEN Ordnernamen, nicht
             * einen festen. Bis 1.2.19 stand hier '/tmp/spotpreis'. Zwei
             * Installationen desselben Plugins - etwa eine zweite fuer den
             * oesterreichischen Markt - teilten sich damit state.json,
             * laufend.json und die Sperrdatei des Cron; je Minute lief nur
             * eine von beiden, und beide sahen die Zahlen der anderen.
             * REGELN_2. */
            'tmp' => '/tmp/' . $plugindir,
            'lbhome' => $lbhomedir,
            'plugin' => $plugindir,
            'archiv' => '',
        );
    }
    /* Keine Wurzel (Entwicklung, ausgepacktes Archiv, fremder Baum): alles
     * neben dem Plugin, nie an der Laufwerkswurzel und nie im Ordner einer
     * Installation. Bis 1.2.27 lagen Zwischenspeicher, Daten und Protokoll
     * hier unter sys_get_temp_dir()/spotpreis - auf einem LoxBerry derselbe
     * Ordner /tmp/spotpreis, den die installierte Anlage 'spotpreis' benutzt
     * (in WSL gemessen, Pruefung-Spotpreis-aWATTar-1.2.28, Fall B10). */
    $basis = dirname(dirname(__DIR__));
    return array(
        'config' => $basis . '/config/spot.json',
        'backup' => $basis . '/config/spot.backup.json',
        'log' => $basis . '/log/spot.log',
        'datadir' => $basis . '/data',
        'tmp' => $basis . '/tmp',
        'lbhome' => '',
        'plugin' => $plugindir,
        // Die gefundene Wurzel, wenn diese Datei NICHT darin installiert
        // liegt (Archivmodus) - fuer die Meldung; sonst leer.
        'archiv' => $gefunden,
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
    // P6 (aWATTar-c2b, Durchgang 01.10.2026): beim Speichern beanstanden, wenn
    // der Marstek fremde Schreiber meldet. Ab Werk aus - gemeldet wird immer.
    'marstek_fremd_beanstanden' => 0,
    // Aktionstoken des Marstek-Plugins (Energie-1 C2). Wie ein Kennwort:
    // nie angezeigt, nie in Adresse, Protokoll oder Sicherung.
    'marstek_token' => '',
    'token' => '',               // leer = Endpunkt ohne Token erreichbar
    // Schaltregeln (ab 1.1.0): je Regel EIN fertiges 0/1-Signal fuer Loxone.
    // Bis 1.0.3 lieferte das Plugin nur Zahlen - Startstunde, Stunden bis
    // dahin, Durchschnittspreis. Daraus "jetzt laden" zu machen war Arbeit
    // im Miniserver. Die Schaltregeln nehmen ihm das ab; gerechnet
    // werden sie im Fahrplaner (planer.php).
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

/**
 * Nur-Lese-Betrieb fuer den unangemeldeten Endpunkt (Regeln/05).
 *
 * spot.php schaltet ihn als ERSTES ein. Solange er an ist, legt
 * spot_config() nichts an: kein Ordner, keine Kopie der Zweitschrift, kein
 * Beiseitelegen einer kaputten Datei. Die Zweitschrift wird dann nur
 * GELESEN. Gemessen an 1.2.26 am Pruefstand: Konfigordner geloescht,
 * Zweitschrift da, ein tokenloser Lesezugriff - danach stand spot.json
 * wieder da, und die Antwort kam als HTTP 403, weil die gerade geheilte
 * Datei ein Token trug. Der Aufruf hat sich also selbst ausgesperrt.
 */
function spot_nur_lesen($an = null) {
    static $wert = false;
    if ($an !== null) {
        $wert = (bool) $an;
    }
    return $wert;
}

/**
 * Die Lage der Konfigurationsdatei, wie sie VOR jeder Selbstheilung war.
 *
 * Regeln/05: eine Zeile, die den Zustand meldet, merkt ihn sich, bevor die
 * Selbstheilung ihn beseitigt. Der erste Aufruf von spot_config() heilt;
 * wer danach nachsieht, sieht eine heile Datei und meldet "in Ordnung".
 * Der zuerst festgestellte Zustand wird deshalb fuer die Dauer des
 * Prozesses gehalten und von einem spaeteren nicht ueberschrieben.
 */
function spot_konfig_lage_merken($lage = null) {
    static $erste = null;
    if ($lage !== null && $erste === null) {
        $erste = (string) $lage;
    }
    return $erste;
}

function spot_config($erzeugen = null) {
    $p = spot_paths();
    if ($erzeugen === null) {
        $erzeugen = !spot_nur_lesen();
    }
    spot_konfig_lage_merken(spot_konfig_lage_jetzt());
    /* ---- Selbstheilung ----
     *
     * DREI Faelle, nicht zwei. Bis 1.2.19 kannte diese Stelle nur "fehlt"
     * und "leer". Der dritte - die Datei ist da, aber ihr JSON ist
     * beschaedigt - fiel durch: json_decode gibt null, das ?: array()
     * darunter machte daraus ein leeres Feld, und die Vorgaben fuellten es
     * auf. Die Oberflaeche zeigte danach eine Anlage in Werkseinstellung
     * mit leerem Token, ohne ein Wort darueber. Speicherte der Anwender
     * dann irgendetwas, kopierte spot_config_save() diese Werkseinstellung
     * ueber die Sicherung - die letzte gute Fassung war weg.
     *
     * Jetzt wird die beschaedigte Datei beiseitegelegt und aus der
     * Sicherung zurueckgeholt. Beides steht im Protokoll: eine
     * Selbstheilung, die schweigt, ist von einem Datenverlust nicht zu
     * unterscheiden. */
    $sp_roh = is_file($p['config']) ? (string) @file_get_contents($p['config']) : '';
    $sp_leer = (trim($sp_roh) === '' || trim($sp_roh) === '{}');
    $sp_kaputt = (!$sp_leer && !is_array(json_decode($sp_roh, true)));
    /* C2 (Pruefbericht code, Befund 2) und I1: aus der Zweitschrift wird nur
     * geheilt, wenn sie BRAUCHBAR ist - lesbares JSON-Objekt mit Inhalt - und
     * keine Neuinstallation sie fremd macht (spot_zweitschrift_brauchbar()). */
    $sp_bk = spot_zweitschrift_brauchbar();
    if (!$erzeugen) {
        /* Nur lesen: die Zweitschrift wird im Speicher benutzt, auf der
         * Platte bleibt alles, wie es war. */
        $cfg = $sp_kaputt ? array() : (json_decode($sp_roh, true) ?: array());
        if ((!is_file($p['config']) || $sp_leer || $sp_kaputt) && $sp_bk !== null) {
            $cfg = $sp_bk;
        }
        return spot_config_normalisieren(is_array($cfg) ? $cfg : array());
    }
    if ($sp_kaputt) {
        $sp_weg = $p['config'] . '.kaputt.' . date('YmdHis');
        $sp_weg_ok = @rename($p['config'], $sp_weg);
        /* Die beiseitegelegte Datei traegt, was die kaputte trug - oft noch
         * den Aktionstoken. Sie bekommt die Rechte der Konfiguration (0600,
         * Regeln/05). Bis 1.2.27 behielt sie per rename() die Rechte der
         * kaputten Datei (in WSL gemessen, Pruefung-Spotpreis-aWATTar-1.2.28,
         * Fall N2b: 644). */
        if ($sp_weg_ok) {
            @chmod($sp_weg, 0600);
        }
        if ($sp_weg_ok && function_exists('spot_log')) {
            spot_log('Konfiguration war beschaedigt und wurde beiseitegelegt: '
                . basename($sp_weg));
        }
    }
    if ((!is_file($p['config']) || $sp_leer || $sp_kaputt) && $sp_bk !== null) {
        @mkdir(dirname($p['config']), 0775, true);
        /* Ueber dieselbe Nebendatei wie spot_config_save() (0600 vor dem
         * Inhalt, Laenge geprueft, dann rename) und nur mit dem Inhalt, der
         * oben als brauchbar gelesen wurde. Bis 1.2.31 kopierte copy() die
         * Zweitschrift ungeprueft zurueck - auch eine kaputte, und dann bei
         * JEDEM Aufruf aufs Neue: gemessen nach drei Minutenlaeufen 4 Dateien
         * spot.json.kaputt.* und 35 Zeilenpaare "beiseitegelegt / zurueckgeholt". */
        $sp_bk_roh = (string) @file_get_contents($p['backup']);
        if (spot_geheim_schreiben($p['config'], $sp_bk_roh)) {
            if ($sp_kaputt && function_exists('spot_log')) {
                spot_log('Konfiguration aus der Sicherung zurueckgeholt.');
            }
        }
    } elseif ((!is_file($p['config']) || $sp_leer || $sp_kaputt) && is_file($p['backup'])
              && !spot_frisch_installiert() && function_exists('spot_log_if_changed')) {
        /* Konfiguration UND Zweitschrift unbrauchbar: nichts zurueckholen,
         * EINMAL melden (die Zeile aendert sich nicht, also schreibt
         * spot_log_if_changed() sie nur einmal). Der Reiter Test zeigt es rot. */
        spot_log_if_changed('konfig_zweitschrift', 'Konfiguration fehlt oder war beschaedigt, und auch die '
            . 'Zweitschrift ' . basename($p['backup']) . ' ist unbrauchbar - es gelten die Vorgaben. '
            . 'Bitte die Einstellungen speichern oder eine Sicherung zurueckspielen.');
    }
    $cfg = is_file($p['config']) ? (json_decode((string) file_get_contents($p['config']), true) ?: array()) : array();
    if (!is_array($cfg)) {
        $cfg = array();
    }
    return spot_config_normalisieren($cfg);
}

/**
 * Ist die Zweitschrift brauchbar? Rueckgabe ihr Inhalt (Feld) oder null.
 *
 * C2: lesbares JSON-Objekt mit mindestens einem Schluessel. Eine kaputte wird
 * nie zurueckkopiert. I1 (Entscheidung 1): liegt data/plugins/<ordner>/
 * marke_frisch - postinstall.sh legt sie bei einer NEUINSTALLATION an,
 * spot_config_save() raeumt sie nach dem ersten Speichern ab -, stammt jede
 * Zweitschrift aus einer frueheren Installation und gilt nicht. preinstall.sh
 * legt eine solche ohnehin nach .alt; die Marke deckt den Fall, dass sie sich
 * dort nicht verschieben liess.
 */
function spot_zweitschrift_brauchbar() {
    $p = spot_paths();
    if (!is_file($p['backup']) || spot_frisch_installiert()) {
        return null;
    }
    $z = json_decode((string) @file_get_contents($p['backup']), true);
    return (is_array($z) && $z) ? $z : null;
}

/** I1: Liegt die Marke einer frischen Installation (noch nie gespeichert)? */
function spot_frisch_installiert() {
    $p = spot_paths();
    return is_file($p['datadir'] . '/marke_frisch');
}

/**
 * Vorgaben ergaenzen und jeden Wert in seinen Bereich bringen - fuer beide
 * Wege von spot_config() dieselbe Rechnung.
 */
function spot_config_normalisieren($cfg) {
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
    /* ---- Preisbestandteile und Schwellen kappen ----
     *
     * Aus demselben Grund wie bei den Regelfeldern: was schon in der Datei
     * steht, soll das Plugin nicht zum Absturz bringen. Das Abweisen macht
     * die Oberflaeche beim Speichern.
     *
     * 'vat' ist der wichtigste davon. Es ging bis 1.2.19 an zwei Stellen
     * mit max(0, ..) durch, an einer dritten roh: spot_state() legt es in
     * den Zustand, und WPNEXT teilt durch (1 + vat/100) - bei vat = -100
     * eine Teilung durch Null. Der Weg dahin ist real, denn
     * spot_sicherung_lesen() uebernimmt jeden Wert eines bekannten
     * Schluessels ungeprueft, und die Datei laesst sich von Hand
     * bearbeiten. Gekappt wird HIER, an der einen Stelle, durch die alles
     * Lesen geht - zwei Pruefungen fuer dieselbe Sache laufen auseinander.
     *
     * Die Grenzen sind weit gewaehlt: sie sollen Unsinn abfangen, nicht
     * eine ungewoehnliche, aber richtige Rechnung verbieten. Negative
     * Preisbestandteile gibt es wirklich (Rueckerstattungen), ein
     * negativer Steuersatz nicht. */
    $cfg['vat'] = max(0.0, min(30.0, (float) $cfg['vat']));
    /* DIESELBEN SCHRANKEN WIE DIE OBERFLAECHE (index.php). Stuenden hier
     * andere Zahlen, gaebe es zwei Wahrheiten - genau davor warnt der
     * Kommentar beim zweiten Budget ein Stueck weiter unten. */
    foreach (array('netz' => 50.0, 'steuer' => 20.0, 'konzession' => 20.0,
                   'umlagen' => 20.0, 'grundpreis' => 100.0) as $sp_f => $sp_max) {
        $cfg[$sp_f] = max(0.0, min($sp_max, (float) $cfg[$sp_f]));
    }
    $cfg['aufschlag'] = max(-10.0, min(30.0, (float) $cfg['aufschlag']));
    $cfg['cheap'] = max(0.0, min(200.0, (float) $cfg['cheap']));
    $cfg['expensive'] = max(0.0, min(400.0, (float) $cfg['expensive']));
    $cfg['fixed_price'] = max(0.0, min(200.0, (float) $cfg['fixed_price']));
    $cfg['window'] = max(1, min(12, (int) $cfg['window']));
    $cfg['co2_clean'] = max(0, min(2000, (int) $cfg['co2_clean']));
    /* Marstek-Aktionstoken (Energie-1 C2): nur in der Form, die das
     * Marstek-Plugin selbst annimmt. Was von Hand oder aus einer alten Datei
     * anders dasteht (Liste, Leerzeichen, fremde Zeichen), gilt als "kein
     * Token" - der Reiter Test zeigt das rot, und es geht nie als "Array"
     * hinaus. */
    $cfg['marstek_fremd_beanstanden'] = empty($cfg['marstek_fremd_beanstanden']) ? 0 : 1;
    $sp_mt = is_string($cfg['marstek_token']) ? trim($cfg['marstek_token']) : '';
    $cfg['marstek_token'] = ($sp_mt !== '' && spot_marstek_token_form_ok($sp_mt)) ? $sp_mt : '';
    /* Das MQTT-Thema geht in den Gateway-Befehl "publish <thema> <wert>".
     * Ein Leerzeichen darin verschiebt den Wert. Die Oberflaeche saeubert
     * es beim Speichern (index.php); eine zurueckgespielte Sicherung geht
     * daran vorbei. Dieselbe Regel, an der Stelle, an der es BENUTZT
     * wird. */
    $sp_thema = preg_replace('#[^\w/\-]#', '', (string) $cfg['mqtt_topic']);
    $cfg['mqtt_topic'] = ($sp_thema === '' || $sp_thema === null) ? 'spot_awattar' : $sp_thema;

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
    /* Seit 1.2.34 (Sprachausgabe Stufe 2, Nr. 36 b): Vorgaben des Blocks tts aus der gemeinsamen
     * Sprachausgabe - EINE Vorgabeliste (spot_tts()). Die Ausgabeart ab Werk bleibt musicserver wie bis
     * 1.2.33 (mit leerer IP spricht sie nicht). Neu im Block: alexa_laut (-1 = Ansagelautstaerke von
     * Alexa-NG), sonos_zone und sonos_laut (vom Modul vorgegeben; Sonos4Lox bietet diese Linie nicht an).
     *
     * S1 (Ansage-2/Ansage-3, 01.10.2026): Ein Token in fremder Form gilt als "keines" (es ginge sonst nie
     * als "Array" hinaus), ein Geraet, das kein Text ist, als leer, eine Lautstaerke ausserhalb 0 bis 100
     * als -1 - abgewiesen wird beim Speichern und beim Zurueckspielen (spot_wert_mangel()). */
    $cfg['tts'] = spot_tts($cfg);
    foreach (array('alexa_token', 'google_token') as $sp_k) {
        $cfg['tts'][$sp_k] = spot_sprech_token_ok($cfg['tts'][$sp_k]) ? $cfg['tts'][$sp_k] : '';
    }
    foreach (array('alexa_geraet', 'google_geraet') as $sp_k) {
        if (!is_string($cfg['tts'][$sp_k])) { $cfg['tts'][$sp_k] = ''; }
    }
    foreach (array('alexa_laut', 'google_laut') as $sp_k) {
        $sp_l = $cfg['tts'][$sp_k];
        $cfg['tts'][$sp_k] = (is_int($sp_l) && $sp_l >= 0 && $sp_l <= 100) ? $sp_l : -1;
    }
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
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    return $p['datadir'];
}

/**
 * Merker, die einen Neustart ueberstehen muessen.
 *
 * spot_tmpdir() zeigt auf /tmp/spotpreis - und /tmp ist auf dem LoxBerry
 * fluechtig. Fuer Merker, die nur ein paar Minuten gelten (ptest, said_,
 * mqtt_letzte.json), ist das genau richtig: nach einem Neustart soll wieder von vorn
 * begonnen werden.
 *
 * Fuer "das ist heute/diesen Monat schon geschehen" ist es falsch. Der
 * Merker "Preise fuer morgen sind veroeffentlicht" lag bis 1.1.1 in /tmp:
 * ein Neustart nach 14 Uhr, und die Ansage samt Pushnachricht kam ein
 * zweites Mal. Dasselbe gilt fuer den Monatsbericht - ein Merker in /tmp
 * wuerde nach einem Neustart am Monatsersten einen zweiten Bericht
 * ausloesen.
 *
 * data/plugins/<ordner> ueberlebt den Neustart. Ein Plugin-Update
 * ueberlebt er NICHT von selbst: der Installer loescht den Ordner in
 * jedem Upgrade-Zweig (gemessen an plugininstall.pl, siehe die
 * Fundstellen in preupgrade.sh). Getragen wird der Merker deshalb von
 * preupgrade.sh, das ihn NEBEN den Ordner legt, und von
 * postinstall.sh, das ihn zurueckholt.
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
 * Zeitpunkt des letzten CRON-Laufs, in Unix-Sekunden. 0, wenn der Cron noch
 * nie gelaufen ist.
 *
 * WARUM NICHT $st['ts']: das ist der Zeitpunkt, zu dem der ZUSTAND zuletzt
 * gerechnet wurde - und rechnen laesst ihn auch der Abruf des Miniservers.
 * Bis 1.2.19 ging genau dieser Wert als TS hinaus. Gemessen mit einem
 * kuenstlich zwei Stunden gealterten Zustand und ohne einen einzigen
 * Cron-Lauf dazwischen: TS = jetzt, Alter 0 s. Die Ausfallerkennung, die
 * der Reiter Loxone vorschreibt (Formel Zeit minus TS, Ein bei 900), konnte
 * damit nie anschlagen.
 *
 * Der Laufzaehler wird ausschliesslich von bin/cron.php geschrieben, und
 * dort als ERSTES im Lauf - vor dem Abruf bei aWATTar, vor den Meldungen,
 * vor allem, was scheitern kann. Sein Zeitstempel beantwortet deshalb
 * genau die Frage "laeuft der Cron noch?".
 *
 * clearstatcache, weil derselbe Prozess die Datei kurz zuvor geschrieben
 * haben kann - PHP haelt sonst den alten Stand.
 */
function spot_cron_puls() {
    $f = spot_datadir() . '/laufzaehler';
    clearstatcache(true, $f);
    return is_file($f) ? (int) filemtime($f) : 0;
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
    $erste = spot_konfig_lage_merken();
    return $erste !== null ? $erste : spot_konfig_lage_jetzt();
}

/**
 * Welche Schluessel fehlen in der Datei, und welche stehen darin, die das
 * Plugin nicht kennt? Regeln/05: fehlende werden geschrieben, fremde
 * GENANNT und stehen gelassen. Rueckgabe array(fehlend, fremd) oder null,
 * wenn die Datei nicht lesbar ist.
 */
function spot_konfig_schluessel() {
    $p = spot_paths();
    if (!is_file($p['config'])) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($p['config']), true);
    if (!is_array($d)) {
        return null;
    }
    $vorg = spot_vorgaben();
    $fehlend = array_values(array_diff(array_keys($vorg), array_keys($d)));
    $fremd = array();
    foreach (array_keys($d) as $k) {
        if (!array_key_exists($k, $vorg) && !($k !== '' && $k[0] === '_')) {
            $fremd[] = (string) $k;
        }
    }
    return array($fehlend, $fremd);
}

/**
 * Fehlende Schluessel einmal mit ihrer Vorgabe in die Datei schreiben, mit
 * Protokollzeile (Regeln/05). Nur wenn die Datei heil ist, nie im
 * Nur-Lese-Betrieb, und die Zweitschrift nur dann mit, wenn dabei kein
 * Token verlorengeht. Rueckgabe: Zahl der ergaenzten Schluessel.
 *
 * Gemessen am Geraet (17.09.2026): spot.json vom 26.07.2026 mit 35
 * Schluesseln, die Vorgaben kennen 59. Die fehlenden galten still mit
 * ihrer Vorgabe - ein stiller Vorgabewert ist eine Annahme, keine Auskunft.
 */
function spot_config_vervollstaendigen() {
    if (spot_nur_lesen() || spot_konfig_lage_jetzt() !== 'ok') {
        return 0;
    }
    $sk = spot_konfig_schluessel();
    if ($sk === null || !$sk[0]) {
        return 0;
    }
    $p = spot_paths();
    $cfg = spot_config(true);
    $z = is_file($p['backup']) ? json_decode((string) @file_get_contents($p['backup']), true) : null;
    if (is_array($z) && !empty($z['token']) && (!is_string($cfg['token']) || $cfg['token'] === '')) {
        spot_log('Konfiguration NICHT vervollstaendigt: die Datei hat kein Token, die Zweitschrift schon.');
        return 0;
    }
    if (!spot_config_save($cfg)) {
        return 0;
    }
    spot_log('Konfiguration vervollstaendigt: ' . count($sk[0]) . ' Schluessel mit Vorgabe ergaenzt ('
        . implode(', ', $sk[0]) . ')');
    return count($sk[0]);
}

function spot_konfig_lage_jetzt() {
    $p = spot_paths();
    /* C2: eine Zweitschrift, die nicht zu gebrauchen ist, ist keine
     * "Wiederherstellung" - dann gilt die Lage 'zweit_kaputt' (Kreuz). Eine aus
     * einer frueheren Installation (I1) zaehlt gar nicht. */
    $zweit = 'vorgabe';
    if (is_file($p['backup']) && !spot_frisch_installiert()) {
        $zweit = spot_zweitschrift_brauchbar() !== null ? 'zweitschrift' : 'zweit_kaputt';
    }
    if (!is_file($p['config'])) {
        return $zweit;
    }
    $roh = trim((string) @file_get_contents($p['config']));
    if ($roh === '' || $roh === '{}') {
        return $zweit;
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
    clearstatcache(true, $f);
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
    /* Das Fenster endet am ANFANG DES NAECHSTEN TAGES, nicht 24 Stunden
     * spaeter. Bis 1.2.19 stand hier "+ 24 * 3600 * 1000". Gerechnet an
     * drei Tagen, ohne Netz:
     *     15.06.2026  Tag hat 24 h, im Fenster 24
     *     25.10.2026  Tag hat 25 h, im Fenster 24 - 23:00 fehlt
     *     29.03.2026  Tag hat 23 h, im Fenster 23
     * Am Ende der Sommerzeit wurde also die letzte Stunde des Tages nie
     * abgerufen. Zusammen mit dem Ersatzwert fuer die laufende Stunde ergab
     * das einmal im Jahr eine Stunde mit CUR=0, RANK=1 und LEVEL=1 bei
     * HOK=1 - die Stunde sah fuer den Spot Price Optimizer aus wie die
     * guenstigste des Tages.
     *
     * Ueber die Datumsfunktion und nicht mit +86400: an genau diesen
     * beiden Tagen ist ein Tag nicht 86400 Sekunden lang. Das ist dieselbe
     * Ueberlegung wie in plan_frist_ende() im Fahrplaner. */
    $end = strtotime(date('Y-m-d', $startTs) . ' +1 day 00:00:00') * 1000;
    $js = false;
    if (spot_nur_zwischenspeicher()) {
        // P4: nur lesen, was der Minutenlauf abgelegt hat - gleich welchen Alters.
        $js = is_file($cache) ? (string) @file_get_contents($cache) : false;
    } elseif (!$force && is_file($cache) && time() - filemtime($cache) < 900) {
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
        if (!spot_nur_zwischenspeicher()) {
            spot_log_if_changed('aufloesung', 'aWATTar liefert ' . $schritt . '-Sekunden-Werte ('
                . count($roh) . ' Datensaetze) - zu ' . count($out) . ' Stundenmitteln zusammengefasst.');
        }
    }
    if (spot_nur_zwischenspeicher()) {
        return $out;    // P4: der Endpunkt schreibt nichts
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

/* Die Einzelregel-Rechnung von vor 1.1.2 stand bis 1.2.18 hier:
 * spot_in_zeitfenster(), spot_regel_kandidaten() und spot_regel_werte().
 * Der Kommentar darueber sagte, der Reiter Test zeige damit die alte und
 * die neue Rechnung nebeneinander - gemessen wurde keine der drei je
 * aufgerufen, weder im Code noch in den Sprachdateien. Ein Kommentar, der
 * eine Benutzung behauptet, ist kein Beleg fuer sie. Gerechnet wird seit
 * 1.1.2 ausschliesslich in planer.php, weil nur dort Rangfolge und
 * Leistungsbudget ueber alle Regeln zusammen entschieden werden. */

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
    if (spot_nur_zwischenspeicher()) {
        // P4: der Stand des Minutenlaufs, gleich welchen Alters - oder nichts.
        $c = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
        return is_array($c) ? $c + $leer : $leer;
    }
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
    if (spot_nur_zwischenspeicher()) {
        $c = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
        return (is_array($c) && isset($c['werte'])) ? $c + $leer : $leer;
    }
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
    /* false: ein Lastgang darf negative Werte tragen (Einspeisung); sie werden
     * unten je Wert verworfen. Nicht endliche Werte weist planer.php seit 1.1.8
     * immer ab (WERTE_UNGUELTIG). */
    list($werte, $meldung) = plan_pv_lesen($roh, $cfg['last_quelle'], $cfg['last_pfad'],
        $cfg['last_zeitfeld'], $cfg['last_wertfeld'], $einheit, 3600, false);
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
 * Bis 1.1.2 rechnete jede Regel fuer sich. Diese Rechnung ist in 1.2.19
 * entfallen: sie stand seit 1.1.2 unbenutzt da, und der Kommentar, sie
 * werde vom Reiter Test angezeigt, traf nicht zu. Der Planer bringt drei
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
        /* 'rest' kommt hier in STUNDEN an: der Planer gibt Minuten aus,
         * spot_regeln() rechnet sie oben in Stunden um, und diese Liste
         * ist das Ergebnis dieser Umrechnung. Bis 1.2.18 stand hier * 60;
         * ein Dreistundenblock lief damit 180 Sekunden, und die Hysterese
         * war ab der vierten Minute jeder Stunde wirkungslos. */
        $rest = isset($r['rest']) ? (int) $r['rest'] : 0;
        if ($rest > 0) { $neu[$i] = (int) $jetzt + $rest * 3600; }
    }
    /* UNTEILBAR schreiben. An dieser Datei haengen zwei Schreiber - der
     * Cron jede Minute und jeder Abruf der Oberflaeche. Ein halb
     * geschriebenes JSON liest spot_laufend_lesen() als leer, und die
     * Hysterese vergisst genau in dem Augenblick, in dem sie gebraucht
     * wird, welche Bloecke laufen. spot_write_atomic() steht in dieser
     * Datei und wird ueberall sonst benutzt. */
    spot_write_atomic($f, json_encode($neu));
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
        $w = spot_regel_horizont($w, $r, $st);
        unset($w['slots']);   // die Liste selbst braucht Loxone nicht
        $out[] = $w;
    }
    return $out;
}

/**
 * P1: Eine Regel der Art "stunden" (guenstigste Einzelstunden) oder "fenster"
 * (guenstigstes Fenster) urteilt ueber einen RANG. Sind weniger als
 * SPOT_RANG_MIN_STUNDEN kuenftige Preisstunden bekannt, ist das kein Rang - die
 * Regel geht auf 0, mit dem Grund "horizont". Schwelle und Tagesmittel sind
 * keine Rangfrage und bleiben, wie sie sind.
 * Seit planer.php 1.1.8 (Planer-30) trifft der Planer dieselbe Entscheidung
 * selbst, mit derselben Zahl und demselben Grund - und eine Regel ohne Rang
 * bucht dort auch kein Leistungsbudget mehr. Diese Funktion bleibt als zweite
 * Wache stehen: sie urteilt ueber rang_ok aus spot_state().
 */
function spot_regel_horizont($w, $r, $st) {
    $art = isset($r['art']) ? (string) $r['art'] : 'fenster';
    if (!in_array($art, array('stunden', 'fenster'), true) || empty($r['aktiv'])) {
        return $w;
    }
    if (!isset($st['rang_ok']) || !empty($st['rang_ok'])) {
        return $w;
    }
    $w['aktiv'] = 0;
    $w['grund'] = 'horizont';
    $w['in'] = -1;
    $w['rest'] = 0;
    $w['ct'] = 0.0;
    $w['start'] = -1;
    $w['anzahl'] = 0;
    $w['verdraengt'] = 0;
    $w['slots'] = array();
    return $w;
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
    /* Der Planer rechnet in ct/kWh - so steht es im Kopf von planer.php,
     * und so uebergibt es spot_state(). Bis 1.2.19 reichte diese Funktion
     * den Endpreis in EUR/kWh weiter, also hundertmal zu klein, waehrend
     * das Tagesmittel im selben Aufruf in ct stand.
     *
     * Gemessen an einer Regel der Art 'schwelle' mit Grenze 20,0 ct bei
     * einem Endpreis von rund 26 ct - die Regel darf nicht laufen:
     *     Loxone-Zeile (aus spot_state)    R1=0
     *     Anzeige      (aus spot_fahrplan) aktiv=1, grund=schwelle
     * Dieselbe Regel, derselbe Preis, zwei Antworten. */
    foreach (array($heute, $morgen) as $tag) {
        if (is_array($tag)) {
            foreach ($tag as $ts => $netto) {
                $all[$ts] = round(spot_endprice($netto) * 100, 3);
            }
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
        // P1: dieselbe Entscheidung wie fuer Loxone (spot_regeln()).
        $plan[$i] = spot_regel_horizont($plan[$i],
            isset($cfg['regeln'][$i]) ? $cfg['regeln'][$i] : array(), $st);
    }
    return array('plan' => $plan, 'belegung' => plan_belegung($plan),
                 'slotlen' => 3600, 'preise' => $all);
}

/** Kompletter Zustand (Cache 5 min). */
function spot_state($force = false) {
    $cfg = spot_config();
    $cache = spot_tmpdir() . '/state.json';
    /* P4: der Endpunkt nimmt den Zustand des Minutenlaufs, solange er zur
     * laufenden Stunde gehoert - gleich wie alt (RECHNE in der Zeile nennt das
     * Alter). Gehoert er zu einer frueheren Stunde, wird aus den abgelegten
     * Marktdaten gerechnet, ohne Abruf und ohne etwas zu schreiben. Ein
     * ?refresh=1 erzwingt hier nichts mehr (siehe spot.php). */
    $nur = spot_nur_zwischenspeicher();
    if ($nur) {
        $force = false;
    }
    if (!$force && is_file($cache) && ($nur || time() - filemtime($cache) < 300)) {
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

    /* ---- Ersatzwerte, falls eine Stunde fehlt ----
     *
     * Die Ueberlegung steht ausfuehrlich weiter unten beim Stundenprofil:
     * Fehlt eine Stunde, ist die Frage nicht "welche Zahl passt am besten",
     * sondern "welche Zahl richtet keinen Schaden an". Eine 0 ist die
     * schlechteste - sie sieht fuer den Spot Price Optimizer wie die
     * guenstigste Stunde des Tages aus.
     *
     * Bis 1.2.19 galt das NUR fuer das Stundenprofil. CUR, CURB und NEXT
     * nahmen die 0. Gemessen an einem vollstaendigen Tag, aus dem genau
     * die laufende Stunde entfernt wurde:
     *
     *     Kontrollfall  HOK=1  CUR=46.761  RANK=13  LEVEL=3  WPCUR=49.141
     *     Prueffall     HOK=1  CUR=0.000   RANK=1   LEVEL=1  WPCUR=20.200
     *
     * HOK blieb dabei 1, denn der Tag war ja da - die Anleitung sagt, ohne
     * HOK gelte kein Wert, aber HOK stand auf 1. PH19 war durch den
     * Ersatzwert geschuetzt (49.141), CUR nicht.
     *
     * Die Werte werden deshalb HIER gerechnet, vor ihrer ersten Benutzung,
     * und weiter unten fuer die Profile wiederverwendet. */
    $ph_ersatz = ($sh && isset($sh['maxp'])) ? round((float) $sh['maxp'], 3) : 0.0;
    $pm_ersatz = ($sm && isset($sm['maxp'])) ? round((float) $sm['maxp'], 3) : $ph_ersatz;
    $pr_ersatz = max($ph_ersatz, $pm_ersatz);
    /* Fuer CURB derselbe Gedanke, nur eine Stufe frueher: der BOERSENPREIS
     * der teuersten Stunde. Aus ihm rechnet WPCUR den Preis des zweiten
     * Zaehlpunkts; stuende dort eine 0, waere die Waermepumpe genau in der
     * Stunde ohne Preis am billigsten dran. */
    $b_ersatz = ($sh && isset($sh['maxh'], $sh['hours'][$sh['maxh']]['boerse']))
        ? (float) $sh['hours'][$sh['maxh']]['boerse'] : 0.0;
    if ($sm && isset($sm['maxh'], $sm['hours'][$sm['maxh']]['boerse'])
        && (float) $sm['hours'][$sm['maxh']]['boerse'] > $b_ersatz) {
        $b_ersatz = (float) $sm['hours'][$sm['maxh']]['boerse'];
    }
    /* Ohne jeden Preis - erster Start, aWATTar nicht erreichbar - bleibt es
     * bei 0. Dann steht aber auch HOK auf 0. */
    $cur_fehlt = isset($all[$hstart]) ? 0 : 1;
    $cur = isset($all[$hstart]) ? $all[$hstart] : $pr_ersatz;
    $curb = ($ph && isset($ph[$hstart])) ? round($ph[$hstart] * 100, 3)
        : (($pm && isset($pm[$hstart])) ? round($pm[$hstart] * 100, 3) : $b_ersatz);
    $next = isset($all[$hstart + 3600]) ? $all[$hstart + 3600] : $pr_ersatz;
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
    /* P1: ein Rang braucht einen gedeckten Horizont (SPOT_RANG_MIN_STUNDEN).
     * Sonst ist RANK/RANKD -1 ("keine Aussage") und n 0; wie viele Stunden
     * bekannt sind, steht in n_bekannt. */
    $n_bekannt = count($vals);
    $rang_ok = ($n_bekannt >= SPOT_RANG_MIN_STUNDEN) ? 1 : 0;
    /* Preisniveau relativ zu den Schwellen.
     *
     * OHNE GUELTIGE PREISE IST ES NICHT BEKANNT. Bis 1.2.23 wurde es
     * ohne jede Wache gerechnet: bei fehlenden Preisen ist $cur = 0,
     * und 0 <= cheap ergibt Niveau 1 - 'guenstig', obwohl gar kein
     * Preis vorliegt. -1 heisst 'nicht bekannt'; die Ansage haengt
     * bereits an === 1 bzw. === 3 und sagt dann nichts Zusaetzliches.
     * Anlass: derselbe Befund in der Schwesterlinie Octopus, am
     * Geraet gemessen am 13.09.2026. */
    $level = $sh ? 2 : -1;
    if ($sh && $cur <= (float) $cfg['cheap']) { $level = 1; }
    if ($sh && $cur >= (float) $cfg['expensive']) { $level = 3; }
    $st = array(
        'ok' => $sh ? 1 : 0,
        'tomorrow_ok' => $sm ? 1 : 0,
        'market' => $cfg['market'],
        'hstart' => $hstart,
        'stunde' => (int) date('G'),
        /* Sagt AN, dass fuer die laufende Stunde ein Ersatzwert steht.
         * Eine stille Ersetzung waere nur die naechste Fassung desselben
         * Fehlers: der Miniserver saehe eine teure Stunde und wuesste
         * nicht, dass sie in Wahrheit fehlt. Geht als CURX in die Zeile. */
        'cur_fehlt' => $cur_fehlt,
        'cur' => $cur,
        'cur_boerse' => $curb,
        'next' => $next,
        'neg' => $curb < 0 ? 1 : 0,
        /* EIN RANG OHNE PREISE IST KEIN RANG.
         *
         * $rank faengt bei 1 an und wird je guenstigerem Wert erhoeht;
         * bei leerer Liste bleibt er 1 - und 1 heisst laut spot_felder()
         * 'guenstigste'. rankd hatte fuer diesen Fall schon einen
         * Ersatzwert (99), rank nicht. Beide tragen jetzt -1; die 99
         * war nirgends beschrieben und deshalb keine Zusage.
         *
         * Gegengeprueft, dass der Ersatzwert nichts ausloest:
         * spot_marstek_control() kehrt bei !$st['ok'] vorher um, und
         * die Ansage haengt an === 1 bzw. === 3. */
        'rank' => $rang_ok ? $rank : -1,
        'rankd' => $rang_ok ? count($vals) + 1 - $rank : -1,
        'n' => $rang_ok ? count($vals) : 0,
        'n_bekannt' => $n_bekannt,
        'rang_ok' => $rang_ok,
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
    /* EIGENE Variable: $sh traegt ab Zeile 1179 die Tagesstatistik und
     * wird 27 Zeilen weiter unten noch gebraucht (Ersatzwert, Luecken,
     * doppelte Stunden). Bis 1.2.18 hiess die Verschiebungsrechnung
     * ebenfalls $sh - danach hatte 'maxp' keinen Wert mehr, der
     * Ersatzwert war immer 0.000, und genau die 0, vor der der
     * Kommentar unten warnt, ging an den Miniserver. */
    $shift = spot_shift_saving(7);
    $st['shift_ct'] = $shift['ct'];
    $st['shift_euro'] = $shift['euro'];
    $st['shift_jahr'] = $shift['euro_jahr'];
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
    // $ph_ersatz und $pm_ersatz stehen schon oben - sie werden dort fuer
    // CUR, CURB und NEXT gebraucht und sind hier dieselben.
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
    /* Derselbe Ersatzwert wie oben, aus demselben Grund: die rollende
     * Sicht reicht 24 Stunden voraus, die Preise fuer morgen kommen
     * aber erst gegen 14 Uhr. Jeden Vormittag steht deshalb fuer einen
     * Teil der Eingaenge kein Preis bereit. Eine 0 sieht dort genauso
     * aus wie die guenstigste Stunde des Tages. Genommen wird der
     * hoehere der beiden Tageshoechstpreise - so wird die Stunde nie
     * gewaehlt. Ohne jeden Preis bleibt es bei 0, und dann steht auch
     * HOK auf 0. */
    // $pr_ersatz steht ebenfalls schon oben.
    for ($h = 0; $h < 24; $h++) {
        $st['profil_relativ'][$h] = isset($all[$hstart + $h * 3600])
            ? round((float) $all[$hstart + $h * 3600], 3) : $pr_ersatz;
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
    /* P4: aus dem Endpunkt heraus nichts fortschreiben - die Hysterese und den
     * Zwischenspeicher fuehrt allein der Minutenlauf (und die Oberflaeche). */
    if ($nur) {
        return $st;
    }
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
    /* P3 (Pruefbericht mqtt, B5): der Zwischenspeicher gilt hoechstens eine
     * Stunde, und seine Werte werden fuer die LAUFENDE Stunde neu gebildet
     * (spot_co2_aus_speicher()). Bis 1.2.31 lieferte er nach einem
     * gescheiterten Abruf beliebig lange den Stand von vor Stunden - gemessen:
     * um 20:10 "CO2=180;CO2MINH=15;CO2CLEAN=1" aus einem Abruf von 14:10, und
     * auch ein frischer behielt ueber den Stundenwechsel das "jetzt" der
     * Vorstunde. Ohne gueltigen Stand gilt die sichere Richtung $off
     * (CO2CLEAN=0, CO2MINH=-1). */
    $c = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
    $gilt = spot_co2_aus_speicher($c);
    if (!$force && $gilt !== null && time() - (int) @filemtime($cache) < 1800) {
        return $gilt;
    }
    if (spot_nur_zwischenspeicher()) {
        return $gilt !== null ? $gilt : $off;     // P4: kein Abruf aus dem Endpunkt
    }
    $land = $cfg['market'] === 'at' ? 'at' : 'de';
    $ctx = stream_context_create(array('http' => array('timeout' => 15, 'user_agent' => 'LoxBerry Spotpreis')));
    $js = @file_get_contents('https://api.energy-charts.info/co2eq?country=' . $land, false, $ctx);
    $d = @json_decode((string) $js, true);
    if (!isset($d['unix_seconds']) || !is_array($d['unix_seconds'])) {
        if ($gilt !== null) {
            return $gilt;
        }
        spot_log_if_changed('co2', 'Abruf fehlgeschlagen (api.energy-charts.info)'
            . (is_array($c) ? ' - der letzte Stand ist aelter als eine Stunde und gilt nicht mehr' : ''));
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
                 'max' => $max[1], 'maxh' => $max[0], 'avg' => round($sum / $n), 'hours' => $hours, 'ts' => time(),
                 'hstart' => $hstart);
    spot_write_json_atomic($cache, $out);
    spot_log_if_changed('co2', 'jetzt ' . $out['now'] . ' g/kWh, sauberste Stunde ' . $out['minh'] . ' Uhr mit ' . $out['min'] . ' g');
    return $out;
}

/**
 * P3: Einen abgelegten CO2-Stand fuer die LAUFENDE Stunde nehmen - oder null.
 *
 * Null heisst "gilt nicht": kein lesbarer Stand, aelter als eine Stunde (ts),
 * oder fuer die laufende Stunde liegt kein Wert vor. Ist seit dem Abruf eine
 * Stunde angebrochen, werden jetzt, Minimum, Maximum und Schnitt aus den
 * Stundenwerten ab der laufenden Stunde neu gebildet; die abgelaufene faellt
 * heraus. Die Stundenwerte liegen nach Stundenzahl (date('G')) und decken die
 * 24 Stunden ab der Stunde des Abrufs.
 */
function spot_co2_aus_speicher($c) {
    if (!is_array($c) || empty($c['ok']) || !isset($c['hours']) || !is_array($c['hours'])) {
        return null;
    }
    $ts = isset($c['ts']) ? (int) $c['ts'] : 0;
    $jetzt = time();
    if ($ts <= 0 || $jetzt - $ts > 3600 || $ts > $jetzt + 300) {
        return null;
    }
    $hstart = $jetzt - ($jetzt % 3600);
    $c_h = isset($c['hstart']) ? (int) $c['hstart'] : ($ts - ($ts % 3600));
    if ($c_h === $hstart) {
        return $c;
    }
    $hours = array(); $min = null; $max = null; $sum = 0; $n = 0; $cur = null;
    for ($k = 0; $k < 24; $k++) {
        $hts = $c_h + $k * 3600;
        if ($hts < $hstart) {
            continue;
        }
        $h = (int) date('G', $hts);
        if (!isset($c['hours'][$h]) || !is_numeric($c['hours'][$h])) {
            continue;
        }
        $g = (float) $c['hours'][$h];
        if ($hts === $hstart) {
            $cur = $g;
        }
        $hours[$h] = $g;
        $sum += $g; $n++;
        if ($min === null || $g < $min[1]) { $min = array($h, $g); }
        if ($max === null || $g > $max[1]) { $max = array($h, $g); }
    }
    if ($cur === null || !$n) {
        return null;
    }
    return array('ok' => 1, 'now' => $cur, 'min' => $min[1], 'minh' => $min[0], 'max' => $max[1],
                 'maxh' => $max[0], 'avg' => round($sum / $n), 'hours' => $hours, 'ts' => $ts, 'hstart' => $hstart);
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
    /* C1 (Pruefbericht code, Befund 1): erst die Konfiguration ueber eine
     * Nebendatei (spot_geheim_schreiben(): 0600 vor dem Inhalt, geschriebene
     * Laenge gegen strlen() geprueft, dann rename), dann ZURUECKLESEN, und erst
     * wenn sie wortgleich und gueltiges JSON ist, die Zweitschrift - auf
     * demselben Weg. Bis 1.2.31 galt jede Rueckgabe von fwrite ausser false als Erfolg: mit einer
     * Dateigroessengrenze von 1 kB (wie bei voller Platte) meldete die
     * Funktion true, und spot.json UND Zweitschrift waren abgeschnitten -
     * Token und alle Einstellungen weg. */
    if (!spot_geheim_schreiben($p['config'], $json)) {
        return false;
    }
    clearstatcache(true, $p['config']);
    $sp_zurueck = @file_get_contents($p['config']);
    if ($sp_zurueck !== $json || !is_array(json_decode((string) $sp_zurueck, true))) {
        spot_log('Konfiguration geschrieben, aber nicht wortgleich zurueckgelesen - die Zweitschrift bleibt, wie sie war.');
        return false;
    }
    /* Die Zweitschrift bekommt DIESELBEN RECHTE wie das Original - sie
     * enthaelt dasselbe Geheimnis (REGELN_2). */
    if (!spot_geheim_schreiben($p['backup'], $json)) {
        spot_log('Die Zweitschrift ' . basename($p['backup']) . ' liess sich nicht schreiben - die Konfiguration '
            . 'selbst ist gespeichert; die alte Zweitschrift bleibt liegen.');
    } elseif (spot_frisch_installiert()) {
        /* I1: ab jetzt gibt es eine Zweitschrift DIESER Installation. */
        @unlink(spot_paths()['datadir'] . '/marke_frisch');
    }
    // Zwischenspeicher verwerfen: die Preise werden mit den neuen
    // Aufschlaegen neu gerechnet.
    @unlink(spot_tmpdir() . '/state.json');
    return true;
}

/**
 * Eine Datei mit Geheimnis unteilbar schreiben (C1): Nebendatei mit PID und
 * Zufallszahl, 0600 VOR dem Inhalt, geschriebene Laenge gegen strlen()
 * geprueft, dann rename(). Eine kurze Schreibung (volle Platte) laesst die
 * alte Datei stehen. Rueckgabe true nur nach dem rename().
 */
function spot_geheim_schreiben($datei, $inhalt) {
    $inhalt = (string) $inhalt;
    $tmp = $datei . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);
    $fh = @fopen($tmp, 'x');
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, 0600);
    $n = @fwrite($fh, $inhalt);
    $ok = ($n === strlen($inhalt)) && @fflush($fh);
    @fclose($fh);
    clearstatcache(true, $tmp);
    if (!$ok || @filesize($tmp) !== strlen($inhalt)) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $datei)) {
        @unlink($tmp);
        return false;
    }
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

/* ---------------- Optionale Kopplung: Marstek-Speicher laden ----------------
 *
 * ENERGIE-1 TEIL C2 (Entscheidung Nr. 25, 01.10.2026). Bis 1.2.29 galt:
 *
 *   1. Die Kopplung schickte ihren Sollwert OHNE Token. Der Marstek-Endpunkt
 *      weist ?p= ohne Token mit HTTP 403 ab (marstek.php, Token-Pruefung vor
 *      dem Passiv-Sollwert). Die Kopplung war damit still wirkungslos - im
 *      Protokoll stand nur "-> FEHLER", weil file_get_contents() bei 403
 *      false liefert und den Code verschluckt.
 *   2. In jeder Nicht-Guenstig-Stunde ging jede Minute p=0 hinaus. Mit Token
 *      haette das den Ladesollwert aus Loxone im Minutentakt ueberschrieben.
 *
 * Jetzt:
 *   - Das Aktionstoken des Marstek steht im eigenen Feld marstek_token und
 *     geht als &token= nur in die Anfrage - nie ins Protokoll, nie in den
 *     Reiter Test, nie in die Sicherung, nie in die Adresse im Formular.
 *   - Gesendet wird NUR, wenn die Kopplung laden will (Rang <= X oder
 *     negativer Preis). Ausserhalb davon gibt sie nichts vor; den zuletzt
 *     gesetzten Ladesollwert beendet der Watchdog des Marstek (t=240) von
 *     selbst, oder Loxone setzt seinen eigenen. Loxone bleibt die eine Hand.
 *   - Jedes Senden wird mit HTTP-Code und Antwort festgehalten
 *     (marstek_ergebnis.json im Zwischenspeicher, Protokollzeile bei jeder
 *     Aenderung) und im Reiter Test gezeigt. Ein 403 ist rot, nie still.
 *
 * Was die Kopplung NICHT weiss: ob gerade PV-Ueberschuss ansteht. Das
 * Plugin hat keinen Zaehlerwert (die PV-Prognose ist eine Prognose, kein
 * Ueberschuss). Wer Netzladen nur ohne Ueberschuss will, baut das Spot-Laden
 * in Loxone, wo der Zaehler liegt, und laesst die Kopplung aus.
 * ------------------------------------------------------------------------- */

/** Hat das Endpunkt-Token die Form der eigenen Erzeugung (spot_token_erzeugen,
 *  24 Zeichen)? Zugelassen sind 8 bis 64 Zeichen aus Buchstaben, Ziffern,
 *  Punkt, Bindestrich und Unterstrich - eine Liste oder "Array" nie (Klasse 12). */
function spot_endpunkt_token_form_ok($t) {
    /* C5 (Pruefbericht oberflaeche, Befund 9): verankert mit \z. "$" liess ein
     * angehaengtes Zeilenende durch - eine Sicherung mit "abcdefghjk\n" wurde
     * angenommen, und der Endpunkt nahm ?token=abcdefghjk%0A an. */
    return is_string($t) && preg_match('/^[A-Za-z0-9_.\-]{8,64}\z/', $t) === 1;
}

/** Hat ein Marstek-Aktionstoken die Form, die das Marstek-Plugin annimmt?
 *  Dieselbe Regel wie dort (marstek_lib.php, Pruefung 'aktionstoken'). */
function spot_marstek_token_form_ok($t) {
    return is_string($t) && preg_match('/^[A-Za-z0-9_.\-]{1,64}\z/', $t) === 1;   // C5: \z
}

/** Die Teile der Anfrage einer Adresse, ohne jeden token-Parameter.
 *  Rueckgabe array(Adresse ohne Token, ob einer darin stand). */
function spot_marstek_url_teilen($url) {
    $url = (string) $url;
    $frage = strpos($url, '?');
    if ($frage === false) {
        return array($url, false);
    }
    $anker = '';
    $raute = strpos($url, '#', $frage);
    if ($raute !== false) {
        $anker = substr($url, $raute);
        $url = substr($url, 0, $raute);
    }
    $behalten = array();
    $hatte = false;
    foreach (explode('&', substr($url, $frage + 1)) as $teil) {
        if ($teil === '') {
            continue;
        }
        $k = strtolower(rawurldecode((string) strstr($teil . '=', '=', true)));
        if ($k === 'token' || strpos($k, 'token[') === 0) {
            $hatte = true;
            continue;
        }
        $behalten[] = $teil;
    }
    return array(substr($url, 0, $frage) . ($behalten ? '?' . implode('&', $behalten) : '') . $anker, $hatte);
}

/** Traegt eine Adresse einen token-Parameter? Er gehoert ins eigene Feld. */
function spot_marstek_url_hat_token($url) {
    $t = spot_marstek_url_teilen($url);
    return $t[1];
}

/** Dieselbe Adresse ohne token-Parameter (fuer Protokoll und Sicherung). */
function spot_marstek_url_ohne_token($url) {
    $t = spot_marstek_url_teilen($url);
    return $t[0];
}

/**
 * Den Marstek-Endpunkt aufrufen. Rueckgabe array(HTTP-Code, Rumpf); Code 0
 * heisst "keine Verbindung". Der Code kommt aus stream_get_meta_data() und
 * dort aus der LETZTEN Statuszeile - die vordefinierte Kopfzeilen-Variable
 * von PHP meldet 8.5 als ueberholt (Bauform oc_http_strom(), Octopus).
 * Umleitungen werden NICHT verfolgt: das Token ginge sonst an die Adresse,
 * die die Umleitung nennt.
 */
function spot_marstek_rufen($url, $timeout = 8) {
    $ctx = stream_context_create(array('http' => array(
        'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0,
        'user_agent' => 'LoxBerry Spotpreis')));
    $fh = @fopen($url, 'rb', false, $ctx);
    if ($fh === false) {
        return array(0, '');
    }
    $meta = @stream_get_meta_data($fh);
    $rumpf = @stream_get_contents($fh, 65536);
    @fclose($fh);
    $kopf = (is_array($meta) && isset($meta['wrapper_data']) && is_array($meta['wrapper_data']))
        ? $meta['wrapper_data'] : array();
    $code = 0;
    foreach ($kopf as $z) {
        if (is_string($z) && preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) {
            $code = (int) $m[1];
        }
    }
    return array($code, $rumpf === false ? '' : (string) $rumpf);
}

/** Die Antwort fuer Protokoll und Reiter Test: eine Zeile, nur druckbares
 *  ASCII, hoechstens 160 Zeichen, und das Token - sollte eine Gegenstelle es
 *  je zurueckgeben - durch *** ersetzt. */
function spot_marstek_antwort_kurz($rumpf, $tok) {
    $s = (string) $rumpf;
    if ($tok !== '') {
        $s = str_replace(array($tok, rawurlencode($tok)), '***', $s);
    }
    $s = trim((string) preg_replace('/ {2,}/', ' ', (string) preg_replace('/[^\x20-\x7E]+/', ' ', $s)));
    if (strlen($s) > 160) {
        $s = substr($s, 0, 160) . '...';
    }
    return $s;
}

/** Wo das Ergebnis des letzten Sendens liegt (Zwischenspeicher; nach einem
 *  Neustart beginnt es von vorn). */
function spot_marstek_ergebnis_datei() {
    return spot_tmpdir() . '/marstek_ergebnis.json';
}

/** Das Ergebnis des letzten Sendens - oder null, wenn seit dem Neustart
 *  noch nichts gesendet wurde. */
function spot_marstek_ergebnis_lesen() {
    $f = spot_marstek_ergebnis_datei();
    if (!is_file($f)) {
        return null;
    }
    $e = json_decode((string) @file_get_contents($f), true);
    if (!is_array($e) || !isset($e['ts'])) {
        return null;
    }
    return $e + array('p' => 0, 'code' => 0, 'ok' => 0, 'antwort' => '', 'folge' => 0, 'schreiber' => -1);
}

/**
 * Schickt dem Marstek-Plugin einen Ladebefehl, wenn die aktuelle Stunde zu den
 * X guenstigsten der naechsten 24 h gehoert (oder der Preis negativ ist) - und
 * SONST NICHTS. STANDARD AUS - gedacht als Alternative fuer alle, die das
 * Spot-Laden NICHT in Loxone bauen wollen. Fuehrt Loxone den Marstek, ist die
 * Kopplung eine zweite Hand: in den guenstigen Stunden ueberschreiben sich
 * beide Sollwerte. Dann bitte ausgeschaltet lassen.
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
    /* P1: ohne gedeckten Horizont kein Rang - und damit kein Spot-Laden, auch
     * nicht bei negativem Preis (Entscheidung Nr. 30: "sonst laedt die Kopplung nicht"). */
    if (isset($st['rang_ok']) && !$st['rang_ok']) {
        spot_log_if_changed('marstek', 'kein Spot-Laden: nur ' . (int) $st['n_bekannt']
            . ' kuenftige Preisstunden bekannt (mindestens ' . SPOT_RANG_MIN_STUNDEN
            . ') - ohne sie gibt es keinen Rang -> nichts gesendet');
        return;
    }
    $laden = ($st['rank'] <= max(1, (int) $cfg['marstek_hours']))
          || (!empty($cfg['marstek_neg']) && $st['neg']);
    $grund = '(Rang ' . $st['rank'] . ', neg=' . $st['neg'] . ')';
    $url = trim((string) $cfg['marstek_url']);
    if ($url === '') {
        $url = spot_marstek_default_url(); // leer = automatisch eigene LoxBerry-IP
    } elseif (!spot_url_ok($url)) {
        // Zweite Schranke hinter der Oberflaeche: eine Adresse, die aus einer
        // aelteren Fassung oder von Hand in der spot.json steht, wird hier
        // ebenfalls abgewiesen - und zwar benannt, nicht stillschweigend.
        // Ein Token darin kommt nicht mit ins Protokoll.
        spot_log_if_changed('marstek', 'Adresse abgewiesen (nur http/https erlaubt): '
            . spot_marstek_url_ohne_token($url));
        return;
    }
    if (!$laden) {
        /* Ausserhalb der guenstigen Stunden geht NICHTS hinaus. Bis 1.2.29
         * stand hier p=0 jede Minute - mit Token haette das den Sollwert aus
         * Loxone im Minutentakt ueberschrieben (Energie-1, Konfliktfall K3). */
        spot_log_if_changed('marstek', 'kein Spot-Laden ' . $grund
            . ' -> nichts gesendet; Loxone fuehrt den Speicher (ein zuletzt gesetzter'
            . ' Ladesollwert endet spaetestens nach 240 s)');
        return;
    }
    $p = max(0, (int) $cfg['marstek_power']);
    $tok = (string) $cfg['marstek_token'];
    /* &von=awattar: die Kennung fuer die Schreiber-Wache des Marstek
     * (Energie-1 C1). Aeltere Marstek-Fassungen ignorieren den Parameter. */
    $ziel = $url . (strpos($url, '?') === false ? '?' : '&') . 'p=' . $p . '&t=240&von=awattar';
    if ($tok !== '') {
        $ziel .= '&token=' . rawurlencode($tok);
    }
    list($code, $rumpf) = spot_marstek_rufen($ziel, 8);
    $kurz = spot_marstek_antwort_kurz($rumpf, $tok);
    $ok = ($code === 200 && strpos(ltrim($rumpf), 'SET;OK=1') === 0);
    $alt = spot_marstek_ergebnis_lesen();
    $folge = $ok ? 0 : (($alt !== null && empty($alt['ok'])) ? (int) $alt['folge'] + 1 : 1);
    /* P6 (aWATTar-c2b): die Schreiber-Wache des Marstek (ab 1.1.19) haengt
     * ;SCHREIBER=n an, sobald in ihrem Fenster mehr als ein Schreiber war. -1
     * heisst "nicht gemeldet" - eine aeltere Fassung oder nur dieser eine. */
    $schreiber = preg_match('/;SCHREIBER=([0-9]{1,3})(;|\s|$)/', (string) $rumpf, $sm) ? (int) $sm[1] : -1;
    spot_write_json_atomic(spot_marstek_ergebnis_datei(), array(
        'ts' => time(), 'p' => $p, 'code' => $code, 'ok' => $ok ? 1 : 0,
        'antwort' => $kurz, 'folge' => $folge, 'schreiber' => $schreiber));
    $zeile = 'laden mit ' . $p . ' W ' . $grund . ' -> '
        . ($code > 0 ? 'HTTP ' . $code : 'keine Verbindung');
    if ($ok) {
        // UNVERAENDERT=1 (Befehlsbremse des Marstek) wechselt von Minute zu
        // Minute; es gehoert nicht in die Zeile, sonst schriebe sie jede Minute.
        $zeile .= ', angenommen (' . str_replace(';UNVERAENDERT=1', '', $kurz) . ')';
    } else {
        $zeile .= ', NICHT angenommen' . ($kurz !== '' ? ': ' . $kurz : '');
        if ($code === 403) {
            $zeile .= ' - das Marstek-Token fehlt oder stimmt nicht (Einstellungen, Kopplung mit dem Marstek)';
        }
    }
    spot_log_if_changed('marstek', $zeile);
}

/**
 * Die Zeile der Selbstpruefung fuer die Kopplung. Rueckgabe array(0|1|2, Text).
 *
 * Ein Haken nur, wenn etwas GEMESSEN wurde: der Selbsttest des Marstek
 * (?selftest=1, nur auf Knopfdruck; er prueft nur das Token und schaltet
 * nichts) oder ein angenommener Sollwert. Sonst ein Strich. Kein Token, ein
 * Token in der Adresse oder ein abgewiesener Sollwert sind ein Kreuz.
 */
function spot_marstek_pruefen($probe = false) {
    $cfg = spot_config();
    $url = trim((string) $cfg['marstek_url']);
    if ($url === '') {
        $url = spot_marstek_default_url();
    } elseif (!spot_url_ok($url)) {
        return array(0, spot_t('PRUEFTEXT.MARSTEK_ADRESSE'));
    }
    $tok = (string) $cfg['marstek_token'];
    $url_tok = spot_marstek_url_hat_token($url);
    $teile = array();
    $note = 1;
    $gemessen = false;
    if ($tok === '' && !$url_tok) {
        $teile[] = spot_t('PRUEFTEXT.MARSTEK_KEIN_TOKEN');
        $note = 0;
    }
    if ($url_tok) {
        $teile[] = spot_t('PRUEFTEXT.MARSTEK_TOKEN_IN_ADRESSE');
        $note = 0;
    }
    if ($probe) {
        $ziel = $url . (strpos($url, '?') === false ? '?' : '&') . 'selftest=1'
              . ($tok !== '' ? '&token=' . rawurlencode($tok) : '');
        list($code, $rumpf) = spot_marstek_rufen($ziel, 5);
        $kurz = spot_marstek_antwort_kurz($rumpf, $tok);
        if ($code === 200 && strpos(ltrim($rumpf), 'SELFTEST;OK=1') === 0) {
            $teile[] = sprintf(spot_t('PRUEFTEXT.MARSTEK_PROBE_OK'), $code);
            $gemessen = true;
        } elseif ($code === 403) {
            $teile[] = sprintf(spot_t('PRUEFTEXT.MARSTEK_PROBE_TOKEN'), $code, $kurz);
            $note = 0;
        } elseif ($code === 0) {
            $teile[] = spot_t('PRUEFTEXT.MARSTEK_PROBE_WEG');
        } else {
            $teile[] = sprintf(spot_t('PRUEFTEXT.MARSTEK_PROBE_FALSCH'), $code, $kurz);
            $note = 0;
        }
    } else {
        $teile[] = spot_t('PRUEFTEXT.MARSTEK_PROBE_KNOPF');
    }
    $e = spot_marstek_ergebnis_lesen();
    if ($e === null) {
        $teile[] = spot_t('PRUEFTEXT.MARSTEK_NIE');
    } elseif (!empty($e['ok'])) {
        $teile[] = sprintf(spot_t('PRUEFTEXT.MARSTEK_LETZT_OK'), date('d.m. H:i', (int) $e['ts']),
                           (int) $e['p'], (int) $e['code'], (string) $e['antwort']);
        $gemessen = true;
    } else {
        $teile[] = sprintf(spot_t('PRUEFTEXT.MARSTEK_LETZT_FEHL'), date('d.m. H:i', (int) $e['ts']),
                           (int) $e['p'], (int) $e['code'], (string) $e['antwort'], (int) $e['folge']);
        $note = 0;
    }
    /* P6: fremde Schreiber am Marstek melden (Entscheidung Nr. 25: melden ab
     * Werk an). Ein Kreuz: zwei Haende auf einem Speicher ist der Konflikt,
     * vor dem der Kasten in den Einstellungen warnt. */
    if ($e !== null && (int) $e['schreiber'] >= 2) {
        $teile[] = sprintf(spot_t('PRUEFTEXT.MARSTEK_SCHREIBER'), (int) $e['schreiber']);
        $note = 0;
    }
    /* P1: warum die Kopplung gerade nichts sendet. */
    $sp_st = spot_state();
    if (isset($sp_st['rang_ok']) && !$sp_st['rang_ok'] && !empty($sp_st['ok'])) {
        $teile[] = sprintf(spot_t('PRUEFTEXT.MARSTEK_HORIZONT'), (int) $sp_st['n_bekannt'], SPOT_RANG_MIN_STUNDEN);
    }
    if ($note === 1 && !$gemessen) {
        $note = 2;
    }
    return array($note, implode(' ', $teile));
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

/**
 * Die Themen und Werte, die ueber MQTT hinausgehen - als EINE Quelle.
 *
 * Bis 1.2.23 entstanden sie mitten in spot_mqtt_publish(). Die
 * Pruefzeile im Reiter Test, die die Retain-Tabelle dagegen haelt,
 * haette dann eine zweite, abgeschriebene Liste gebraucht - und zwei
 * Listen laufen auseinander. Der Rumpf ist woertlich derselbe.
 */
function spot_mqtt_themen($st = null) {
    $cfg = spot_config();
    if ($st === null) {
        $st = spot_state();
    }
    $msgs = array(
        'ok' => $st['ok'], 'cur' => $st['cur'],
        /* O9 (Pruefbericht mqtt, B6): das Gegenstueck zu CURX der Loxone-Zeile -
         * 1, wenn fuer die laufende Stunde ein Ersatzwert steht. Fluechtig. */
        'curx' => isset($st['cur_fehlt']) ? (int) $st['cur_fehlt'] : 0,
        'cur_boerse' => $st['cur_boerse'], 'next' => $st['next'],
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
        /* Lebenszeichen - siehe den Block ueber spot_lauf_stand() und
         * spot_cron_puls(). ts ist der CRON, rechne das Rechnen. */
        'status/ts' => spot_cron_puls(),
        'status/rechne' => isset($st['ts']) ? (int) $st['ts'] : time(),
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
    return $msgs;
}

/**
 * M2 (Pruefbericht mqtt, B3): das Themenpraefix an EINER Stelle - fuer Senden,
 * Lebenszeichen, Altlast und Deinstallation. Bis 1.2.31 kuerzte nur die
 * Deinstallation einen Schraegstrich am Rand ("haus/spot/" -> "haus/spot"),
 * gesendet wurde aber nach "haus/spot//fix": die Deinstallation leerte andere
 * Themen als die, die im Broker standen. Gilt jetzt ueberall wie beim Senden
 * (nur Leerraum am Rand weg) - auf einer bestehenden Anlage aendert sich damit
 * kein gesendetes Thema. Ein Praefix mit / am Rand nimmt das Formular nicht mehr
 * an, und "Einstellungen sichern" warnt davor (X-3).
 */
function spot_mqtt_praefix($cfg = null) {
    if ($cfg === null) {
        $cfg = spot_config();
    }
    $p = (isset($cfg['mqtt_topic']) && is_string($cfg['mqtt_topic'])) ? trim($cfg['mqtt_topic']) : '';
    return $p !== '' ? $p : 'spot_awattar';
}

/** P2: die Themen, die bei einem Ausfall (ok=0) hinausgehen - nur das Signal. */
function spot_mqtt_ausfall_themen(array $msgs) {
    $aus = array();
    foreach ($msgs as $k => $v) {
        if ($k === 'ok' || preg_match('#^regel/[0-9]+/aktiv$#', (string) $k)) {
            $aus[$k] = $v;
        }
    }
    return $aus;
}

/**
 * O9 (Pruefbericht mqtt, B6): jedes Thema, das hinausgeht, mit seiner
 * Bedeutung - EINE Liste fuer die Tabelle im Reiter MQTT und fuer die
 * Pruefzeile PRUEF.MQTT_LISTE, die sie in beide Richtungen gegen
 * spot_mqtt_themen() haelt. Der Text steht in der Sprachdatei (Abschnitt
 * MQTTTHEMA, Schluessel aus spot_mqtt_text_schluessel()). Bis 1.2.31 nannte die
 * Oberflaeche 35 von 89 Themen, und keine Pruefung merkte es.
 * Wert: 1 = nur, wenn die Quelle etwas liefert (PV-Prognose, Speicherstand).
 */
function spot_mqtt_beschreibung() {
    $l = array();
    foreach (array('OK', 'CUR', 'CURX', 'CUR_BOERSE', 'NEXT', 'NEG', 'RANK', 'RANKD', 'LEVEL',
                   'AVG_HEUTE', 'MIN_HEUTE', 'MINH_HEUTE', 'MAX_HEUTE', 'MAXH_HEUTE', 'MORGEN_OK',
                   'AVG_MORGEN', 'MIN_MORGEN', 'MINH_MORGEN', 'MAX_MORGEN', 'MAXH_MORGEN',
                   'FENSTER_START', 'FENSTER_IN', 'FENSTER_CT', 'CO2', 'CO2_MIN', 'CO2_MINH', 'CO2_CLEAN',
                   'WP_CUR', 'WP_NEXT', 'FIX', 'DYN_MONAT', 'DIFF_MONAT', 'EURO_MONAT', 'SHIFT_JAHR',
                   'ANN', 'AUDIO', 'PUSH', 'PTEST', 'STATUS_TS', 'STATUS_RECHNE', 'STATUS_ZAEHLER', 'STATUS_OK',
                   'REGEL_AKTIV', 'REGEL_IN', 'REGEL_REST', 'REGEL_CT', 'REGEL_EIN', 'REGEL_VERDRAENGT',
                   'REGEL_SPERRE', 'REGEL_RANG', 'REGEL_FEHLT', 'REGEL_SPART', 'REGEL_SPART_EUR',
                   'PLAN_BUDGET', 'PLAN_BUDGET2', 'PLAN_SPART', 'PLAN_LAST') as $k) {
        $l[$k] = 0;
    }
    $l['PLAN_PV_PROGNOSE'] = 1;
    $l['PLAN_SOC'] = 1;
    return $l;
}

/** O9: der Sprachschluessel eines Themas - regel/3/aktiv -> REGEL_AKTIV, status/ts -> STATUS_TS. */
function spot_mqtt_text_schluessel($thema) {
    $t = preg_replace('#^regel/[0-9]+/#', 'regel/', (string) $thema);
    return strtoupper(str_replace('/', '_', $t));
}

/**
 * O9: die Themenliste fuer den Reiter MQTT - jedes gesendete Thema (samt
 * Lebenszeichen), dazu die nur bei Daten gesendeten. Rueckgabe Liste von
 * array(thema, retained 0|1, Sprachschluessel).
 */
function spot_mqtt_themenliste($st = null) {
    $themen = array_keys(spot_mqtt_themen($st));
    foreach (array('plan/pv_prognose', 'plan/soc') as $t) {
        if (!in_array($t, $themen, true)) {
            $themen[] = $t;
        }
    }
    $aus = array();
    foreach ($themen as $t) {
        $aus[] = array($t, spot_retain_fuer($t, 'x'), spot_mqtt_text_schluessel($t));
    }
    return $aus;
}

/** O9: Liste gegen Sendemenge in beide Richtungen. Rueckgabe array(0|1, Klartext). */
function spot_mqtt_liste_pruefen($st = null) {
    $beschr = spot_mqtt_beschreibung();
    $gesendet = array_keys(spot_mqtt_themen($st));
    $ohne_text = array();
    $gesehen = array();
    foreach ($gesendet as $t) {
        $k = spot_mqtt_text_schluessel($t);
        $gesehen[$k] = true;
        if (!isset($beschr[$k]) || spot_t('MQTTTHEMA.' . $k) === 'MQTTTHEMA.' . $k) {
            $ohne_text[] = $t;
        }
    }
    $nie = array();
    foreach ($beschr as $k => $optional) {
        if (!$optional && !isset($gesehen[$k])) {
            $nie[] = $k;
        }
    }
    if (!$gesendet) {
        return array(2, spot_t('PRUEFTEXT.MQTT_LISTE_LEER'));
    }
    if ($ohne_text || $nie) {
        return array(0, sprintf(spot_t('PRUEFTEXT.MQTT_LISTE_FEHLT'),
            $ohne_text ? implode(', ', array_slice($ohne_text, 0, 8)) : '-',
            $nie ? implode(', ', array_slice($nie, 0, 8)) : '-'));
    }
    return array(1, sprintf(spot_t('PRUEFTEXT.MQTT_LISTE_OK'), count($gesendet), count($beschr)));
}

/** Wo der Merker der zuletzt gesendeten Werte liegt. */
function spot_mqtt_merker() {
    return spot_tmpdir() . '/mqtt_letzte.json';
}

/**
 * Welche Themen haben sich gegenueber dem letzten Versand geaendert?
 * Verglichen wird als Zeichenkette: aus dem Merker kommen die Werte ueber
 * json_decode() zurueck, und aus 0 kann dabei "0" geworden sein.
 */
function spot_mqtt_diff($jetzt, $vorher) {
    $neu = array();
    foreach ((array) $jetzt as $k => $v) {
        if (!array_key_exists($k, (array) $vorher) || (string) $vorher[$k] !== (string) $v) {
            $neu[$k] = $v;
        }
    }
    return $neu;
}

/**
 * Zustand veroeffentlichen - nur die Themen, deren Wert sich geaendert hat.
 *
 * WARUM NICHT MEHR ALLES AUF EINMAL (neu in 1.2.26): bis dahin ging bei
 * jeder Aenderung der Signatur der volle Satz von 89 Datagrammen in einem
 * Stoss hinaus, mindestens stuendlich. Regeln/07 verlangt "nur Aenderungen
 * und den vollen Satz in grobem Takt". Am Geraet (17.09.2026) stand die
 * Warteschlange des Gateways auf Port 11884 ueber 1 MB; ein erzwungener
 * Vollversand kam dreieinhalb Minuten spaeter im Gateway-Protokoll an.
 * Bauart wie AWM-Abfuhr 1.4.9 - samt der dortigen Lehre: der Merker wird
 * NUR fortgeschrieben, wenn wirklich etwas hinausging. Sonst gilt ein Lauf
 * ohne UDP-Port als erledigt, und Felder, die tagelang gleich stehen,
 * fehlten dauerhaft.
 *
 * Das Lebenszeichen (status/...) geht hier NICHT mit: es geht bei jedem
 * Lauf ueber spot_mqtt_lebenszeichen() hinaus, am Filter vorbei.
 *
 * $erzwingen = true schickt alles (Vollversand, halbstuendlich).
 * Rueckgabe: Zahl der abgesetzten Themen, -1 wenn nicht gesendet werden
 * konnte.
 */
function spot_mqtt_publish($st = null, $erzwingen = false) {
    $cfg = spot_config();
    if (empty($cfg['mqtt_enabled'])) {
        return -1;
    }
    $p = spot_paths();
    if ($p['lbhome'] === '') {
        return -1;
    }
    if ($st === null) {
        $st = spot_state();
    }
    $gen = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    $udpport = 0;
    if (isset($gen['Mqtt']['Udpinport'])) { $udpport = (int) $gen['Mqtt']['Udpinport']; }
    if (!$udpport && isset($gen['mqtt']['udpinport'])) { $udpport = (int) $gen['mqtt']['udpinport']; }
    if (!$udpport) {
        return -1;
    }
    $prefix = spot_mqtt_praefix($cfg);
    /* M3 (Pruefbericht mqtt, B4): haelt der Broker noch Altwerte frueher
     * zurueckbehaltener Themen (spot_mqtt_altlast()), gehen GENAU DIESE Themen
     * mit - die leere retain-Nutzlast unmittelbar vor dem gueltigen Wert
     * (spot_mqtt_senden()). Bis 1.2.31 ging dann jeder Lauf VOLL hinaus:
     * gemessen 90 Datagramme je Minute, solange eine Loeschung am Eingang
     * verloren ging - genau dann, wenn der Eingang ohnehin ueberlastet ist.
     * Bei unbekannter Lage nichts zusaetzlich (sonst ginge ohne erreichbaren
     * Broker jede Minute alles hinaus; Pruefung-Spotpreis-aWATTar-1.2.28, R9). */
    $alt = spot_mqtt_altlast($prefix);
    $belegt = ($alt['lage'] === 'belegt') ? $alt['themen'] : array();
    $msgs = array();
    foreach (spot_mqtt_themen($st) as $k => $v) {
        if (strpos((string) $k, 'status/') !== 0) {
            $msgs[$k] = $v;
        }
    }
    $vorher = array();
    $merker = spot_mqtt_merker();
    if (is_file($merker)) {
        $d = json_decode((string) @file_get_contents($merker), true);
        if (is_array($d)) { $vorher = $d; }
    }
    /* P2 (Pruefbericht mqtt, B1; Entscheidungen Nr. 8 und 26): ohne Preise
     * (ok=0) geht nur das Signal hinaus - ok und die Schaltsignale
     * regel/N/aktiv (ein Schaltsignal darf nicht auf 1 stehen bleiben) -, und
     * zwar in JEDEM Lauf, nicht nur beim Wechsel. Bis 1.2.31 gingen cur, next,
     * avg_heute ... als 0 hinaus und ueberschrieben in Loxone den letzten
     * Preis; eine 0 sieht aus wie die guenstigste Stunde. Der Merker der
     * uebrigen Themen bleibt, wie er war: nach dem Ausfall gehen sie nur bei
     * einer Aenderung erneut hinaus. Der HTTP-Endpunkt antwortet wie bisher 503. */
    $ausfall = empty($st['ok']);
    if ($ausfall) {
        $neu = spot_mqtt_ausfall_themen($msgs);
    } else {
        $neu = spot_mqtt_diff($msgs, $erzwingen ? array() : $vorher);
        foreach ($belegt as $t) {
            if (array_key_exists($t, $msgs)) {
                $neu[$t] = $msgs[$t];
            }
        }
    }
    if (!$neu) {
        return 0;
    }
    $n = spot_mqtt_senden($prefix, $udpport, $neu);
    if ($n < 1) {
        return -1;      // nichts hinausgegangen - Merker NICHT fortschreiben
    }
    spot_write_atomic($merker, json_encode($ausfall ? array_merge($vorher, $neu) : $msgs));
    return $n;
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
    $prefix = spot_mqtt_praefix($cfg);    // M2
    spot_mqtt_senden($prefix, $udpport, array(
        'status/ts' => spot_cron_puls(),
        'status/rechne' => ($st !== null && isset($st['ts'])) ? (int) $st['ts'] : time(),
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
/**
 * Welche Themen gehen ZURUECKBEHALTEN (retained) hinaus?
 *
 * Hausstandard seit 03.09.2026 (Regeln/07): Zustaende retained, damit
 * Loxone nach einem Neustart des Miniservers oder des Gateways sofort den
 * Stand hat; Messwerte mit Zeitbezug nicht; das Lebenszeichen nie.
 *
 * Bis 1.2.23 ging alles fluechtig hinaus. Am Geraet gemessen (13.09.2026):
 * unter spotpreis/# lagen 0 zurueckbehaltene Themen, waehrend andere
 * Linien am selben Broker 19 bis 59 fuehrten.
 *
 * Der UDP-Weg kann das: `retain <thema> <wert>` statt `publish ...`
 * (mqttgateway.pl, sub udpin). Am laufenden Gateway nachgemessen.
 *
 * NICHT in der Tabelle, mit Absicht:
 *   status/ts, status/rechne, status/zaehler, status/ok - das
 *       Lebenszeichen. Zurueckbehalten stuende dort fuer immer "laeuft".
 *   regel/N/... - Schaltsignale fuer den laufenden Augenblick. Ein
 *       stehengebliebenes 1 liesse einen Verbraucher eingeschaltet.
 *   alle Preise, Raenge, Fenster, CO2- und Waermepumpenwerte,
 *       plan/last, plan/pv_prognose, plan/soc, plan/spart - Messwerte mit
 *       Zeitbezug.
 *   ann und ptest - sie wechseln allein durch Zeitablauf.
 *
 * BERICHTIGT in 1.2.28 (Entscheidungen des Hausherrn vom 18./19.09.2026,
 * Regeln/07 Abschnitt 3, und Hausstandard "Messwerte mit Zeitbezug nicht
 * retained"): bis 1.2.27 standen hier auch
 *   ok          "fuer HEUTE liegen Preise vor" - eine Aussage des Dienstes
 *               aus dem eigenen Abruf, und sie wird um Mitternacht falsch;
 *   morgen_ok   "fuer MORGEN liegen Preise vor" - um Mitternacht falsch;
 *   dyn_monat, diff_monat, euro_monat - der LAUFENDE Monat aus der
 *               Historie (spot_month_compare(1)), falsch zum Monatswechsel;
 *   shift_jahr  aus den letzten sieben Tagen (spot_shift_saving(7)),
 *               mit jedem Tag ein anderer Wert.
 * Die Frage je Thema ist, ob der Wert OHNE neue Nachricht allein durch den
 * Lauf der Uhr falsch wird. Stirbt der Minutenlauf, stuenden diese Werte
 * weiter im Broker und kaemen nach jedem Neustart von Broker oder Gateway
 * wieder. Sie gehen jetzt fluechtig hinaus; ihre Altwerte raeumt
 * spot_mqtt_altlast() einmal ab (in WSL gemessen,
 * Pruefung-Spotpreis-aWATTar-1.2.28, Faelle R1 bis R15). Preis: nach einem
 * Neustart fehlen sie, bis sich ein Wert aendert oder der volle Satz
 * (halbstuendlich, bin/cron.php) hinausgeht.
 *
 * Zurueckbehalten bleibt nur, was der Anwender EINGESTELLT hat - wahr, bis
 * er es aendert, und dann aendert sich der Wert und er geht neu hinaus.
 */
function spot_retain_liste() {
    return array(
        /* Freigaben aus der Konfiguration. */
        'audio' => 1,
        'push' => 1,
        /* Einstellungen des Fahrplaners. */
        'plan/budget' => 1,
        'plan/budget2' => 1,
        /* Der eingetragene feste Arbeitspreis (fixed_price). */
        'fix' => 1,
    );
}

/**
 * Geht dieses Thema zurueckbehalten hinaus?
 *
 * Eine LEERE Nutzlast loescht ein zurueckbehaltenes Thema im Broker; sie
 * geht deshalb immer als publish hinaus, auch wenn die Tabelle retain sagt.
 */
function spot_retain_fuer($thema, $nutzlast = null) {
    if ($nutzlast !== null && (string) $nutzlast === '') { return 0; }
    $l = spot_retain_liste();
    return isset($l[(string) $thema]) ? 1 : 0;
}

/**
 * Die Themen, die frueher zurueckbehalten hinausgingen und es heute nicht mehr
 * tun: spot_retain_liste() der Archive 1.2.24 bis 1.2.27 (gelesen am
 * 24.09.2026; 1.2.16 bis 1.2.23 sendeten nichts zurueckbehalten). Ihre
 * Altwerte stehen auf bestehenden Anlagen im Broker, bis jemand sie loescht -
 * ein spaeteres publish ersetzt einen zurueckbehaltenen Wert nicht.
 */
function spot_mqtt_frueher_behalten() {
    return array('ok', 'morgen_ok', 'dyn_monat', 'diff_monat', 'euro_monat', 'shift_jahr');
}

/** Der UDP-Eingangsport des Gateways aus der general.json, 0 wenn keiner. */
function spot_mqtt_udpport($lbhome) {
    if ((string) $lbhome === '') {
        return 0;
    }
    $gen = @json_decode((string) @file_get_contents($lbhome . '/config/system/general.json'), true);
    $udpport = isset($gen['Mqtt']['Udpinport']) ? (int) $gen['Mqtt']['Udpinport'] : 0;
    if (!$udpport && isset($gen['mqtt']['udpinport'])) { $udpport = (int) $gen['mqtt']['udpinport']; }
    return ($udpport >= 1 && $udpport <= 65535) ? $udpport : 0;
}

/**
 * Den Broker fragen, welche der Themen $themen er zurueckbehaelt - in EINER
 * Verbindung, ein SUBSCRIBE mit allen Filtern.
 *
 * Rueckgabe array('lage' => 'ok'|'unbekannt', 'belegt' => array(thema => true)).
 * 'ok' heisst: der Broker hat das Abonnement bestaetigt (oder einen Wert
 * geschickt); was dann nicht unter 'belegt' steht, ist leer. 'unbekannt':
 * er war nicht zu fragen (keine Wurzel, keine Verbindung, Anmeldung
 * abgewiesen, keine Antwort).
 *
 * Warum ueberhaupt fragen: das Abraeumen laeuft ueber den UDP-Eingang des
 * Gateways, und dort meldet sendto() auch fuer ein verworfenes Datagramm
 * Erfolg; der Eingang verwirft unter Last (Regeln/07). Ein Merker nach einem
 * blossen Senden waere kein Beleg. Ein MQTT-3.1.1-Abonnement ohne fremde
 * Bibliothek; die Anmeldung nimmt Brokeruser/Brokerpass aus der general.json
 * (Regeln/07, Abschnitt 2). Das Kennwort steht nur im CONNECT-Paket, nie in
 * einem Protokoll und nie auf einer Kommandozeile. Bauart
 * tb_mqtt_behalten_liste() aus Spotpreis-Tibber 0.9.19.
 */
function spot_mqtt_behalten_liste(array $themen) {
    $aus = array('lage' => 'unbekannt', 'belegt' => array());
    $soll = array();
    foreach ($themen as $t) {
        if ((string) $t !== '') { $soll[(string) $t] = true; }
    }
    if (!$soll) {
        $aus['lage'] = 'ok';
        return $aus;
    }
    $p = spot_paths();
    if ($p['lbhome'] === '') { return $aus; }
    $gen = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) { $m = $gen['Mqtt']; }
    elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) { $m = $gen['mqtt']; }
    if (!$m) { return $aus; }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) { return (string) $m[$gross]; }
        return isset($m[$klein]) ? (string) $m[$klein] : '';
    };
    $host = trim($hol('Brokerhost', 'brokerhost'));
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    $port = (int) $hol('Brokerport', 'brokerport');
    if ($port <= 0 || $port > 65535) { $port = 1883; }
    $benutzer = $hol('Brokeruser', 'brokeruser');
    $kennwort = $hol('Brokerpass', 'brokerpass');

    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 2);
    if (!$s) { return $aus; }
    stream_set_timeout($s, 1);

    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) { $b |= 128; }
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    };
    /* Genau $n Bytes lesen oder null - bei Zeitablauf und Verbindungsende. */
    $lies = function ($n) use ($s) {
        $d = '';
        while (strlen($d) < $n) {
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    /* Ein Paket: array(kopfbyte, rumpf) oder null. */
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) { return null; }
        $n = 0; $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = $lies(1);
            if ($b === null) { return null; }
            $n += (ord($b) & 127) * $mult;
            $mult *= 128;
            if (!(ord($b) & 128)) { break; }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };

    $flags = 0x02;                                  // saubere Sitzung
    $nutz = $zk('sprueck' . getmypid());
    if ($benutzer !== '') {
        $flags |= 0x80;
        // Ein Kennwort ohne Benutzer laesst MQTT 3.1.1 nicht zu (Abschnitt
        // CONNECT, Kennwort-Merkmal).
        if ($kennwort !== '') { $flags |= 0x40; }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 10);
    if ($benutzer !== '') {
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') { $nutz .= $zk($kennwort); }
    }
    /* Geschrieben ist erst, was ganz geschrieben ist (Bauart B, Regeln/03):
     * bis 1.2.31 galt jede Rueckgabe von fwrite ausser false als Erfolg. */
    $sp_connect = chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz;
    if (@fwrite($s, $sp_connect) === strlen($sp_connect)) {
        $ack = $paket();
        if ($ack !== null && ($ack[0] >> 4) === 2 && strlen($ack[1]) >= 2 && ord($ack[1][1]) === 0) {
            $sub = pack('n', 1);
            foreach (array_keys($soll) as $t) { $sub .= $zk($t) . chr(0); }
            @fwrite($s, chr(0x82) . $laenge(strlen($sub)) . $sub);
            $bestaetigt = false;
            $ende = microtime(true) + 3.0;
            while (microtime(true) < $ende) {
                $pk = $paket();
                if ($pk === null) { break; }           // Zeitablauf: nichts mehr gekommen
                $art = $pk[0] >> 4;
                if ($art === 9) {
                    /* Je Filter ein Rueckgabebyte hinter der Paketkennung, in der
                       Reihenfolge des SUBSCRIBE; ab 0x80 heisst abgelehnt (etwa
                       durch eine ACL). Danach schickt der Broker nichts - ungeprueft
                       hiesse das "nichts belegt", und der Merker laege auf einer
                       Antwort, die keine war (in WSL gemessen, Pruefung-Spotpreis-aWATTar-1.2.29,
                       Faelle S3, S4, S7, S9, S11). Bauart bw_mqtt_behalten_liste(),
                       Beschattungswaechter 0.9.21. */
                    $rc = (string) substr($pk[1], 2);
                    if (strlen($rc) !== count($soll)) { break; }
                    $abgelehnt = false;
                    for ($i = 0; $i < strlen($rc); $i++) {
                        if (ord($rc[$i]) >= 0x80) { $abgelehnt = true; }
                    }
                    if ($abgelehnt) { break; }
                    $bestaetigt = true;
                    // Zurueckbehaltenes kommt unmittelbar nach dem SUBACK.
                    $ende = min($ende, microtime(true) + 1.0);
                } elseif ($art === 3 && strlen($pk[1]) >= 2) {
                    $tl = unpack('n', substr($pk[1], 0, 2));
                    $t = substr($pk[1], 2, $tl[1]);
                    $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
                    $wert = (string) substr($pk[1], $versatz);
                    if (isset($soll[$t]) && ($pk[0] & 1) && $wert !== '') {
                        $aus['belegt'][$t] = true;
                        if (count($aus['belegt']) === count($soll)) { break; }
                    }
                }
            }
            if ($bestaetigt || $aus['belegt']) { $aus['lage'] = 'ok'; }
        }
        @fwrite($s, chr(0xE0) . chr(0));
    }
    fclose($s);
    return $aus;
}

/**
 * Welche Altwerte muessen in diesem Lauf noch abgeraeumt werden?
 *
 * Rueckgabe array('lage' => 'erledigt'|'belegt'|'unbekannt',
 *                 'themen' => array(<thema ohne praefix>, ...)).
 *
 * Je Lauf, bis der Merker liegt:
 *   1. den Broker nach allen Themen aus spot_mqtt_frueher_behalten() fragen;
 *   2. keines belegt -> Merker schreiben, nichts abraeumen ('erledigt');
 *      einige belegt -> genau diese abraeumen, kein Merker ('belegt'); der
 *      Aufrufer sendet dann VOLL (spot_mqtt_publish()), damit die leere
 *      retain-Nutzlast unmittelbar vor dem gueltigen Wert steht;
 *      nicht zu fragen -> alle, aber nur unmittelbar vor einem Wert, der
 *      ohnehin hinausgeht ('unbekannt'), kein Merker, einmal je Stunde ins
 *      Protokoll.
 * Der Merker liegt im Datenordner und traegt die Kennung
 * "leer-bestaetigt <praefix>: <Themenliste>": ein anderer Inhalt - ein
 * anderes Praefix, eine andere Liste, der Merker einer spaeteren Fassung mit
 * laengerer Liste - gilt nicht. purge_installation raeumt ihn bei jedem
 * Upgrade mit ab; dann wird genau einmal nachgefragt. Bauart
 * tb_mqtt_altlast() aus Spotpreis-Tibber 0.9.19.
 */
function spot_mqtt_altlast($praefix) {
    static $cache = array();
    $praefix = (string) $praefix;
    if (isset($cache[$praefix])) { return $cache[$praefix]; }
    $liste = spot_mqtt_frueher_behalten();
    $merker = spot_datadir() . '/.mqtt_altlast_geraeumt';
    $kennung = 'leer-bestaetigt ' . $praefix . ': ' . implode(' ', $liste);
    if (is_file($merker) && trim((string) @file_get_contents($merker)) === $kennung) {
        return $cache[$praefix] = array('lage' => 'erledigt', 'themen' => array());
    }
    $voll = array();
    foreach ($liste as $t) { $voll[] = $praefix . '/' . $t; }
    $f = spot_mqtt_behalten_liste($voll);
    if ($f['lage'] === 'ok' && !$f['belegt']) {
        if (@file_put_contents($merker, $kennung . "\n") === false) {
            spot_log_if_changed('mqtt_merker', 'MQTT: der Merker ' . $merker . ' liess sich '
                . 'nicht schreiben - der Broker wird im naechsten Lauf wieder gefragt.');
        } else {
            spot_log('MQTT: unter ' . $praefix . '/ steht keines der ' . count($liste)
                . ' frueher zurueckbehaltenen Themen mehr im Broker (vom Broker bestaetigt).');
        }
        return $cache[$praefix] = array('lage' => 'erledigt', 'themen' => array());
    }
    if ($f['lage'] === 'ok') {
        $l = strlen($praefix) + 1;
        $t = array();
        foreach (array_keys($f['belegt']) as $v) { $t[] = substr($v, $l); }
        return $cache[$praefix] = array('lage' => 'belegt', 'themen' => $t);
    }
    spot_log_if_changed('mqtt_rueckfrage', 'MQTT: der Broker liess sich nicht befragen, ob '
        . 'unter ' . $praefix . '/ noch frueher zurueckbehaltene Werte stehen. Sie werden '
        . 'deshalb unmittelbar vor jedem Senden geloescht, bis der Broker antwortet ('
        . date('Y-m-d H') . ' Uhr).');
    return $cache[$praefix] = array('lage' => 'unbekannt', 'themen' => $liste);
}

/**
 * Alle Themen, die diese Linie je zurueckbehalten gesendet hat - fuer die
 * Deinstallation: die heutige Retain-Tabelle (spot_retain_liste(), keine
 * abgeschriebene Liste) und dazu, was frueher darin stand
 * (spot_mqtt_frueher_behalten()).
 */
function spot_mqtt_leer_themen() {
    $t = array();
    foreach (spot_mqtt_frueher_behalten() as $k) { $t[$k] = true; }
    foreach (array_keys(spot_retain_liste()) as $k) { $t[$k] = true; }
    ksort($t);
    return array_keys($t);
}

/**
 * Aus der Deinstallation (bin/cron.php --mqtt-leeren): die zurueckbehaltenen
 * Themen der Linie leeren.
 *
 * Der Weg ist derselbe wie beim Senden - der UDP-Eingang des Gateways,
 * "retain <thema> " mit leerer Nutzlast (am Geraet belegt: die leere
 * Nachricht geht als Loeschung an den Broker, Regeln/07, Nachtraege vom
 * 19.09.2026). VOR der ersten Runde und nach jeder wird der Broker gefragt
 * (spot_mqtt_behalten_liste()); hinaus geht nur, was dort noch steht,
 * hoechstens $runden Runden. Steht nichts da, geht nichts hinaus. Ist der
 * Broker nicht zu fragen, gehen alle Themen in jeder Runde hinaus, und die
 * Ausgabe sagt, dass nicht nachgelesen wurde - der Eingang verwirft unter
 * Last Datagramme (Regeln/07), ein blosses Senden ist kein Beleg. Bauart
 * tb_mqtt_leeren() aus Spotpreis-Tibber 0.9.19.
 *
 * Bis 1.2.27 raeumte die Deinstallation nichts ab: die zurueckbehaltenen
 * Themen blieben im Broker, und nach jedem Neustart von Broker oder Gateway
 * bekam der Miniserver sie wieder - von einem Plugin, das es nicht mehr gibt
 * (in WSL gemessen, Pruefung-Spotpreis-aWATTar-1.2.28, Faelle U1, U3, U4, U6).
 *
 * Liest die Konfiguration ohne Selbstheilung (spot_nur_lesen()) und schreibt
 * weder Protokoll noch Datei. Ausgabe im Format der Hakenskripte
 * (<OK>/<INFO>/<WARNING>). Rueckgabe 0 geleert oder nicht nachpruefbar,
 * 1 es steht noch etwas bzw. der Eingang war nicht erreichbar, 2 nicht
 * moeglich.
 */
function spot_mqtt_leeren($runden = 3, $pause = 1.0) {
    spot_nur_lesen(true);
    $p = spot_paths();
    if ($p['lbhome'] === '') {
        echo "<WARNING> MQTT: keine LoxBerry-Wurzel - zurueckbehaltene Themen wurden nicht geleert.\n";
        return 2;
    }
    /* M1 (Pruefbericht mqtt, B2; Entscheidung Nr. 26): unter dem eingestellten
     * Praefix UND unter jedem frueher eingestellten (mqtt_praefixe.json, fuehrt
     * die Oberflaeche beim Praefixwechsel und beim Ausschalten). Bis 1.2.31
     * blieben Themen unter einem frueheren Praefix fuer immer im Broker. */
    $liste = array(spot_mqtt_praefix(spot_config()));
    foreach (spot_mqtt_praefixe_gemerkt() as $sp_pf) {
        if (!in_array($sp_pf, $liste, true)) {
            $liste[] = $sp_pf;
        }
    }
    $rc = 0;
    foreach ($liste as $sp_pf) {
        $e = spot_mqtt_praefix_leeren($sp_pf, $runden, $pause);
        foreach ($e['zeilen'] as $z) {
            echo $z . "\n";
        }
        $rc = max($rc, (int) $e['rc']);
    }
    return $rc;
}

/**
 * Die zurueckbehaltenen Themen der Linie unter EINEM Praefix leeren (M1).
 * Derselbe Weg wie bisher die Deinstallation: UDP-Eingang des Gateways, "retain
 * <thema> " mit leerer Nutzlast, vorher und nach jeder Runde beim Broker
 * nachgelesen, hoechstens $runden Runden. Schreibt weder Protokoll noch Datei.
 * Rueckgabe array('rc' => 0|1|2, 'zeilen' => Ausgabezeilen <OK>/<INFO>/<WARNING>,
 * 'offen' => Liste, 'nachgelesen' => bool).
 */
function spot_mqtt_praefix_leeren($praefix, $runden = 3, $pause = 1.0) {
    $erg = array('rc' => 0, 'zeilen' => array(), 'offen' => array(), 'nachgelesen' => false);
    $p = spot_paths();
    $praefix = trim((string) $praefix);
    if ($p['lbhome'] === '' || $praefix === '' || preg_match('/[#+\s]/', $praefix)) {
        $erg['rc'] = 2;
        $erg['zeilen'][] = "<WARNING> MQTT: das Themenpraefix ist leer oder enthaelt einen Platzhalter oder "
           . "Leerraum - zurueckbehaltene Themen wurden nicht geleert.";
        return $erg;
    }
    $udpport = spot_mqtt_udpport($p['lbhome']);
    if (!$udpport) {
        $erg['rc'] = 2;
        $erg['zeilen'][] = "<INFO> MQTT: in der general.json steht kein UDP-Eingangsport des Gateways - "
           . "zurueckbehaltene Themen unter " . $praefix . "/ wurden nicht geleert.";
        return $erg;
    }
    $alle = array();
    foreach (spot_mqtt_leer_themen() as $t) { $alle[] = $praefix . '/' . $t; }
    $n = count($alle);
    $f = spot_mqtt_behalten_liste($alle);
    $nachgelesen = ($f['lage'] === 'ok');
    $offen = $nachgelesen ? array_keys($f['belegt']) : $alle;
    if ($nachgelesen && !$offen) {
        $erg['nachgelesen'] = true;
        $erg['zeilen'][] = "<OK> MQTT: der Broker bestaetigt: keines der " . $n . " Themen unter " . $praefix
           . "/ steht zurueckbehalten - nichts zu leeren.";
        return $erg;
    }
    $strom = @stream_socket_client('udp://127.0.0.1:' . $udpport, $errno, $errstr, 2);
    if (!$strom) {
        $erg['rc'] = 1;
        $erg['offen'] = $offen;
        $erg['zeilen'][] = "<WARNING> MQTT: der UDP-Eingang des Gateways war nicht erreichbar - "
           . "zurueckbehaltene Themen unter " . $praefix . "/ wurden nicht geleert.";
        return $erg;
    }
    $zu_leeren = count($offen);
    $datagramme = 0;
    for ($r = 1; $r <= max(1, (int) $runden) && $offen; $r++) {
        if ($r > 1) { usleep((int) ($pause * 1000000)); }
        foreach ($offen as $t) {
            // Ein Leerzeichen hinter dem Thema, sonst keine Nutzlast: die
            // Form, die das Gateway als Loeschung liest.
            @fwrite($strom, 'retain ' . $t . ' ');
            $datagramme++;
        }
        usleep(300000);     // dem Gateway Zeit bis zum Broker lassen
        $f = spot_mqtt_behalten_liste($offen);
        if ($f['lage'] === 'ok') {
            $nachgelesen = true;
            $offen = array_keys($f['belegt']);
        } else {
            $nachgelesen = false;
        }
    }
    fclose($strom);
    $erg['nachgelesen'] = $nachgelesen;
    $erg['offen'] = $offen;
    $erg['zeilen'][] = "<INFO> MQTT: " . $zu_leeren . " von " . $n . " Themen unter " . $praefix . "/ mit leerer "
       . "Nutzlast an den UDP-Eingang " . $udpport . " des Gateways gesendet ("
       . $datagramme . " Datagramme).";
    if ($nachgelesen && !$offen) {
        $erg['zeilen'][] = "<OK> MQTT: der Broker bestaetigt: keines der " . $n . " Themen steht mehr "
           . "zurueckbehalten.";
        return $erg;
    }
    if ($nachgelesen) {
        $erg['rc'] = 1;
        $erg['zeilen'][] = "<WARNING> MQTT: " . count($offen) . " Themen stehen noch zurueckbehalten im Broker ("
           . implode(', ', array_slice($offen, 0, 5)) . (count($offen) > 5 ? ', ...' : '')
           . "). Von Hand: mosquitto_pub -r -n -t <thema>";
        return $erg;
    }
    $erg['zeilen'][] = "<INFO> MQTT: der Broker liess sich nicht befragen - nicht nachgelesen. Der UDP-Eingang "
       . "verwirft unter Last Datagramme; was stehen bleibt, laesst sich mit "
       . "mosquitto_pub -r -n -t <thema> von Hand loeschen.";
    return $erg;
}

/** M1: wo die frueher benutzten Praefixe liegen (Datenordner; preupgrade.sh traegt die Datei ueber ein Update). */
function spot_mqtt_praefixe_datei() {
    return spot_paths()['datadir'] . '/mqtt_praefixe.json';
}

/** M1: die frueher eingestellten Praefixe - nur solche in der Form eines Themas. */
function spot_mqtt_praefixe_gemerkt() {
    $f = spot_mqtt_praefixe_datei();
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    $aus = array();
    foreach (is_array($d) ? $d : array() as $pf) {
        if (is_string($pf) && $pf !== '' && strlen($pf) <= 64 && !preg_match('/[#+\s]/', $pf)
            && preg_match('#^[A-Za-z0-9_/-]+\z#', $pf)) {
            $aus[$pf] = true;
        }
    }
    return array_keys($aus);
}

/** M1: ein Praefix vormerken (hoechstens die letzten 20). Rueckgabe true, wenn es danach in der Liste steht. */
function spot_mqtt_praefix_merken($praefix) {
    $praefix = trim((string) $praefix);
    if ($praefix === '' || strlen($praefix) > 64 || preg_match('/[#+\s]/', $praefix)
        || !preg_match('#^[A-Za-z0-9_/-]+\z#', $praefix)) {
        return false;
    }
    $l = spot_mqtt_praefixe_gemerkt();
    if (in_array($praefix, $l, true)) {
        return true;
    }
    $l[] = $praefix;
    spot_datadir();
    return spot_write_atomic(spot_mqtt_praefixe_datei(), json_encode(array_values(array_slice($l, -20))));
}

function spot_mqtt_senden($prefix, $udpport, $msgs) {
    /* Rueckgabe: Zahl der abgesetzten Datagramme, -1 wenn keines. Das ist
     * KEINE Zustellbestaetigung - sendto() meldet auch fuer ein am Gateway
     * verworfenes Datagramm Erfolg (Regeln/07). Es sagt nur, ob der Weg
     * ueberhaupt offen war. */
    $udpport = (int) $udpport;
    if ($udpport < 1 || $udpport > 65535 || !$msgs) {
        return -1;
    }
    /* Altwerte frueher zurueckbehaltener Themen abraeumen, solange der
     * Broker sie noch haelt: die leere retain-Nutzlast unmittelbar VOR dem
     * gueltigen Wert (spot_mqtt_altlast() fragt den Broker vorher). Das ist
     * die eine gewollte leere Nutzlast dieses Plugins;
     * spot_mqtt_wert_saeubern() laesst sonst keine durch. */
    $alt = spot_mqtt_altlast($prefix);
    $raeumen = array_flip($alt['themen']);
    $n = 0;
    if (function_exists('socket_create')) {
        $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$s) {
            return -1;
        }
        foreach ($msgs as $k => $v) {
            $wert = spot_mqtt_wert_saeubern($v);
            if ($wert !== '' && isset($raeumen[$k])) {
                $leer = 'retain ' . $prefix . '/' . $k . ' ';
                @socket_sendto($s, $leer, strlen($leer), 0, '127.0.0.1', $udpport);
            }
            /* Das Befehlswort entscheidet die Tabelle, nicht der
             * Aufruf - sonst ginge das Lebenszeichen zurueckbehalten
             * hinaus oder die Zustaende fluechtig. */
            $verb = spot_retain_fuer($k, $wert) ? 'retain' : 'publish';
            $msg = $verb . ' ' . $prefix . '/' . $k . ' ' . $wert;
            if (@socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $udpport) !== false) {
                $n++;
            }
        }
        socket_close($s);
        return $n > 0 ? $n : -1;
    }
    $nr = 0; $txt = '';
    $strom = @stream_socket_client('udp://127.0.0.1:' . $udpport, $nr, $txt, 2);
    if (!$strom) {
        return -1;
    }
    foreach ($msgs as $k => $v) {
        $wert = spot_mqtt_wert_saeubern($v);
        if ($wert !== '' && isset($raeumen[$k])) {
            @fwrite($strom, 'retain ' . $prefix . '/' . $k . ' ');
        }
        $verb = spot_retain_fuer($k, $wert) ? 'retain' : 'publish';
        $sp_dg = $verb . ' ' . $prefix . '/' . $k . ' ' . $wert;
        if (@fwrite($strom, $sp_dg) === strlen($sp_dg)) {    // Bauart B: ganz geschrieben
            $n++;
        }
    }
    fclose($strom);
    return $n > 0 ? $n : -1;
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
    $o = sprintf("SPOT;OK=%d;MINH=%d;MINP=%.3f;MAXH=%d;MAXP=%.3f;AVG=%.3f;HOK=%d;HMINH=%d;HMINP=%.3f;HMAXH=%d;HMAXP=%.3f;HAVG=%.3f;CUR=%.3f;CURB=%.3f;NEXT=%.3f;NEG=%d;RANK=%d;RANKD=%d;LEVEL=%d;WINH=%d;WININ=%d;WINCT=%.3f;ANN=%d;AUDIO=%d;PUSH=%d;PTEST=%d;CO2=%d;CO2MIN=%d;CO2MINH=%d;CO2CLEAN=%d;WPCUR=%.3f;WPNEXT=%.3f;FIX=%.3f;DYNM=%.3f;DIFFM=%.3f;EUROM=%.2f;SHIFTJ=%.2f;CURX=%d\n",
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
        $st['fix'], $st['dyn_monat'], $st['diff_monat'], $st['euro_monat'], $st['shift_jahr'],
        isset($st['cur_fehlt']) ? (int) $st['cur_fehlt'] : 0);

    /* Lebenszeichen als eigene Zeile. TS ist der Zeitpunkt, zu dem der
     * Zustand zuletzt wirklich gerechnet wurde - nicht der Zeitpunkt dieses
     * Abrufs. Genau das ist der Sinn: fragt der Miniserver alle 300 s und
     * der Cron ist seit zwei Stunden tot, dann steht hier ein zwei Stunden
     * alter TS, waehrend jede andere Zahl der Zeile unveraendert plausibel
     * aussieht. */
    /* TS ist der Zeitpunkt des letzten CRON-Laufs, RECHNE der des letzten
     * Zustandsrechnens. Bis 1.2.19 stand an beiden Stellen dieselbe Zahl -
     * und weil der Abruf des Miniservers den Zustand selbst neu rechnet,
     * war TS immer frisch. Ein toter Cron sah aus wie ruhige Preise.
     *
     * RECHNE bleibt trotzdem da: es beantwortet die andere Frage - wie alt
     * sind die Zahlen dieser Zeile? Zwei Fragen, zwei Zahlen. Angehaengt,
     * nicht eingeschoben: bestehende Eingaenge merken davon nichts. */
    $o .= sprintf("LEBEN;TS=%d;LAUF=%d;RECHNE=%d\n",
        spot_cron_puls(),
        spot_lauf_stand(),
        isset($st['ts']) ? (int) $st['ts'] : time());

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
        'OK'      => array(0, 0, 1, '', 'Preise morgen da'),
        'HOK'     => array(0, 0, 1, '', 'Preise heute da'),
        'CUR'     => array(1, -100, 200, 'ct/kWh', 'Endpreis jetzt'),
        'CURB'    => array(1, -100, 200, 'ct/kWh', 'Börsenanteil jetzt'),
        'NEXT'    => array(1, -100, 200, 'ct/kWh', 'Preis nächste Stunde'),
        'NEG'     => array(0, 0, 1, '', 'Börsenpreis negativ'),
        /* MinVal -1: die drei senden -1, wenn keine Preise vorliegen.
         * Ohne das stuende in der Visualisierung eine 0, und 0 waere
         * bei einem Rang eine Aussage statt einer Luecke. */
        'RANK'    => array(1, -1, 48, '', 'Rang jetzt (1 = günstigste)'),
        'RANKD'   => array(1, -1, 48, '', 'Rang von hinten'),
        'LEVEL'   => array(1, -1, 3, '', 'Preisniveau 1 bis 3'),
        'MINH'    => array(1, 0, 23, 'h', 'Günstigste Stunde morgen'),
        'MINP'    => array(1, -100, 200, 'ct/kWh', 'Tiefstpreis morgen'),
        'MAXH'    => array(1, 0, 23, 'h', 'Teuerste Stunde morgen'),
        'MAXP'    => array(1, -100, 200, 'ct/kWh', 'Höchstpreis morgen'),
        'AVG'     => array(1, -100, 200, 'ct/kWh', 'Tagesmittel morgen'),
        'HMINH'   => array(1, 0, 23, 'h', 'Günstigste Stunde heute'),
        'HMINP'   => array(1, -100, 200, 'ct/kWh', 'Tiefstpreis heute'),
        'HMAXH'   => array(1, 0, 23, 'h', 'Teuerste Stunde heute'),
        'HMAXP'   => array(1, -100, 200, 'ct/kWh', 'Höchstpreis heute'),
        'HAVG'    => array(1, -100, 200, 'ct/kWh', 'Tagesmittel heute'),
        'WINH'    => array(1, -1, 23, 'h', 'Fenster: Startstunde'),
        'WININ'   => array(1, -1, 48, 'h', 'Fenster: Stunden bis Start'),
        'WINCT'   => array(1, -100, 200, 'ct/kWh', 'Fenster: Schnitt'),
        'CO2'     => array(1, 0, 1000, 'g/kWh', 'CO₂ jetzt'),
        'CO2MIN'  => array(1, 0, 1000, 'g/kWh', 'CO₂ sauberste Stunde'),
        'CO2MINH' => array(1, -1, 23, 'h', 'Sauberste Stunde'),
        'CO2CLEAN' => array(0, 0, 1, '', 'Strommix sauber'),
        'WPCUR'   => array(1, -100, 200, 'ct/kWh', '§14a-Preis jetzt'),
        'WPNEXT'  => array(1, -100, 200, 'ct/kWh', '§14a nächste Stunde'),
        'FIX'     => array(1, 0, 200, 'ct/kWh', 'Fester Tarif'),
        'DYNM'    => array(1, -100, 200, 'ct/kWh', 'Dynamisch im Monat'),
        'DIFFM'   => array(1, -200, 200, 'ct/kWh', 'Vorteil dynamisch'),
        'EUROM'   => array(1, -10000, 10000, 'EUR', 'Vorteil im Monat'),
        'SHIFTJ'  => array(1, 0, 10000, 'EUR', 'Verschiebe-Potenzial Jahr'),
        'ANN'     => array(0, 0, 1, '', 'Meldefenster offen'),
        'AUDIO'   => array(0, 0, 1, '', 'Ansage freigegeben'),
        'PUSH'    => array(0, 0, 1, '', 'Push freigegeben'),
        'PTEST'   => array(0, 0, 1, '', 'Test-Push angefordert'),
        /* Lebenszeichen. TS ist eine Unix-Sekundenzahl und damit gross -
         * MaxVal muss reichen, sonst kappt Loxone den Wert. 2147483647 ist
         * das Ende der 32-Bit-Zeitrechnung (2038) und die groesste Zahl,
         * die Config an dieser Stelle sinnvoll fuehrt.
         * In Loxone: Alter in Sekunden = (Zeit-Baustein + 1230768000) - TS. */
        'CURX'    => array(0, 0, 1, '', 'Ersatzwert jetzt'),
        'TS'      => array(1, 0, 2147483647, 's', 'Letzter Minutenlauf'),
        'RECHNE'  => array(1, 0, 2147483647, 's', 'Letzte Zustandsrechnung'),
        'LAUF'    => array(1, 0, 999, '', 'Laufzähler'),
    );
    // Schaltregeln - der Grund fuer die ganze Uebung: R<n> ist digital.
    for ($i = 1; $i <= SPOT_REGELN; $i++) {
        $f['R' . $i]          = array(0, 0, 1, '', 'Regel ' . $i . ': einschalten');
        $f['R' . $i . 'IN']   = array(1, -1, 48, 'h', 'Regel ' . $i . ': Stunden bis Start');
        $f['R' . $i . 'REST'] = array(1, 0, 48, 'h', 'Regel ' . $i . ': Reststunden');
        $f['R' . $i . 'CT']   = array(1, -100, 200, 'ct/kWh', 'Regel ' . $i . ': Schnitt');
        // Fahrplaner ab 1.2.0. VERD und SPERRE beantworten die Frage, die
        // sonst im Dunkeln bleibt: warum laeuft es gerade NICHT?
        $f['R' . $i . 'VERD']   = array(1, 0, 96, '',
            'Regel ' . $i . ': verdrängt');
        $f['R' . $i . 'SPERRE'] = array(1, 0, 3, '',
            'Regel ' . $i . ': Sperre');
    }
    // Fahrplaner, global
    $f['PVSUM'] = array(1, 0, 1000, 'kWh', 'PV-Prognose 24 h');
    $f['SOC']   = array(1, -1, 100, '%', 'Speicherstand');
    $f['BUDGET'] = array(1, 0, 200, 'kW', 'Leistungsbudget');
    $f['PLANLAST'] = array(1, 0, 200, 'kW', 'Verplante Leistung jetzt');
    $cfg = spot_config();
    $modus = (string) $cfg['profil_ein'];
    if ($modus === 'absolut' || $modus === 'beides') {
        for ($h = 0; $h < 24; $h++) {
            $f[sprintf('PH%02d', $h)] = array(1, -100, 200, 'ct/kWh', sprintf('Preis heute %02d Uhr', $h));
            $f[sprintf('PM%02d', $h)] = array(1, -100, 200, 'ct/kWh', sprintf('Preis morgen %02d Uhr', $h));
        }
    }
    if ($modus === 'relativ' || $modus === 'beides') {
        for ($h = 0; $h < 24; $h++) {
            $f[sprintf('PR%02d', $h)] = array(1, -100, 200, 'ct/kWh', sprintf('Preis in %d Stunden', $h));
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
    /* Der Ordner, unter dem der Endpunkt erreichbar ist: installiert der
     * eigene (spot_paths()['plugin']). Bis 1.2.27 stand hier
     * getenv('LBPPLUGINDIR') mit dem festen Rueckfall 'spotpreis' - eine
     * Zweitinstallation spotpreis_01 bekam die Adresse der ersten in ihre
     * Vorlage (in WSL gemessen, Pruefung-Spotpreis-aWATTar-1.2.28, Fall B11). */
    $ordner = basename(dirname(__DIR__, 2));
    if ($p['lbhome'] !== '') {
        $ordner = $p['plugin'];
    }
    $st = spot_state();
    $token = spot_cfg_wert('token', '');
    $token = spot_endpunkt_token_form_ok($token) ? $token : '';
    $cmds = array();
    foreach (spot_felder() as $name => $d) {
        list($analog, $min, $max, $einheit, $text) = $d;
        // Die Namen der Regeln aus der Konfiguration einsetzen - ein Eingang
        // "Wallbox" ist beim Verdrahten mehr wert als "Schaltregel 1".
        if (preg_match('/^R([0-9]+)/', $name, $m)) {
            $i = (int) $m[1] - 1;
            if (isset($st['regeln'][$i]) && $st['regeln'][$i]['name'] !== '') {
                /* Hoechstens 14 Zeichen des Namens - der Kachelname soll
                 * kurz bleiben. Gezaehlt in Zeichen, nicht in Byte
                 * (Modifikator u, ohne mbstring). */
                $sp_name = preg_replace('/^(.{0,14}).*$/su', '$1', (string) $st['regeln'][$i]['name']);
                $text = str_replace('Regel ' . ($i + 1), $sp_name, $text);
            }
        }
        $cmds[] = array(
            'title' => 'SPOT_' . $name,
            /* Der Comment wird in Loxone Config zum Kachelnamen (Regeln/07) -
             * kurz, mit Umlauten und mit Vorsatz. Bis 1.2.26 standen hier
             * ganze Erklaersaetze: 55 von 117 ueber 40 Zeichen. */
            'comment' => 'aWATTar: ' . $text . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            /* MIT SEMIKOLON. Loxone nimmt die ERSTE Fundstelle des
             * Suchtextes in der Antwortzeile. Ohne Trennzeichen trifft
             * "CUR=" auch in "WPCUR=", "MINH=" auch in "HMINH=" und in
             * "CO2MINH=". Gemessen an der echten Zeile mit 141 Feldnamen:
             * acht Felder treffen mehrfach, MINH dreifach; mit Semikolon
             * keines. Heute steht das kuerzere Feld zufaellig vorn, die
             * Werte stimmen also - aber jede Umsortierung der Zeile
             * machte daraus eine lautlose Falschmessung. Jedes Feld der
             * Zeile hat ein Semikolon vor sich, auch das erste: die
             * Zeilen beginnen mit SPOT;, LEBEN;, REGEL;, PLAN;, PROFIL;
             * und PROFILR;. Haus-Form nach REGELN_3 A11. */
            'check' => '\i;' . $name . '=\i\v',
            'unit' => ($einheit !== '' ? '<v.1> ' . $einheit : '<v.1>'),
            'analog' => $analog, 'min' => $min, 'max' => $max,
        );
    }
    return array('VI_spotpreis.xml', spot_xml_virtual_in_http(array(
        'title' => 'Spotpreis aWATTar',
        /* MIT MARKE, wenn eine gesetzt ist. spot.php verlangt sie bei
         * JEDEM Abruf, sobald sie in den Einstellungen steht - auch beim
         * reinen Lesen. Bis 1.2.19 stand die Adresse ohne sie da; der
         * Miniserver bekam 403 und "SPOT;OK=0;GRUND=TOKEN", und von 139
         * Eingaengen fanden 115 gar nichts mehr. Die Anlage sah dabei
         * eingerichtet aus.
         *
         * Die Marke steht damit in der erzeugten Vorlagendatei. Das ist
         * gewollt: die Datei geht vom LoxBerry in die Loxone Config des
         * Anwenders, nicht ins Netz. Ohne sie waere die Vorlage nutzlos,
         * und eine nutzlose Vorlage laedt zum Abschalten der Marke ein. */
        'address' => 'http://' . $host . '/plugins/' . $ordner . '/spot.php'
                   . ($token !== '' ? '?token=' . rawurlencode($token) : ''),
        'polling' => '300',
        'comment' => 'Erzeugt vom LoxBerry-Plugin Spotpreis aWATTar (' . date('d.m.Y') . '). '
                   . 'Loxone Config legt beim Import neu an und überschreibt nichts - '
                   . 'zweimal eingelesen ergibt doppelte Bausteine.',
    ), $cmds));
}

/* ---------------- Sprachausgabe (seit 1.2.34 in Hausform, Nr. 36 b Stufe 2) ----------------
 *
 * Gesprochen wird ueber die gemeinsame Sprachausgabe (sprachausgabe.php, Abschrift neben dieser Datei):
 * ansage_sprechen() fuer jede Ausgabeart. Das Modul prueft vor jedem Senden, dass Adresse und Vorlage im
 * Heimnetz liegen (Entscheidung Nr. 40, F1), folgt keiner Umleitung, nimmt keinen Proxy und haelt 10 s
 * ein. Vom Text kommt nur die Laenge ins Protokoll (ansage_kurz()); das Sprechtoken steht nur im
 * POST-Koerper an Alexa-NG bzw. Chromecast 4 Lox NG. Das Ergebnis jeder Ansage (Zeit, ok, Kennung - nie
 * Text oder Token) legt das Modul als <art>_letzte.json im Datenordner ab; die Pruefzeile im Reiter Test
 * nennt es.
 *
 * Bis 1.2.33 standen hier eigene Zweige je Ausgabeart (spot_tts_url, spot_sprech_ziel, spot_http_lokal,
 * spot_sprechen_bewerten, spot_sprechen_an, spot_sprech_selbsttest, spot_ansage_letzte). Sie prueften das
 * Heimnetz nicht und sind entfallen.
 */

/** Ausgabearten dieser Linie: alle des Moduls ausser Sonos4Lox (bis 1.2.33 nicht angeboten). 'aus' ist
 *  neu waehlbar; ab Werk bleibt es musicserver (spot_tts()). */
function spot_ansage_modi() {
    return array('aus', 'musicserver', 'ms4h', 'audioserver', 'custom', 'alexang', 'cc4lox');
}

/** Optionen fuer Formular-Baustein und Formular-Lesen: die erlaubten Arten und die POST-Namen der
 *  Loesch-Haken, wie diese Linie sie seit Ansage-2 fuehrt (kein POST-Name aendert sich). */
function spot_ansage_opt() {
    return array('modi' => spot_ansage_modi(),
                 'namen' => array('alexa_token_loeschen' => 'tts_alexa_token_weg',
                                  'google_token_loeschen' => 'tts_google_token_weg'));
}

/** Der Block tts, vervollstaendigt mit den Vorgaben des Moduls - ab Werk musicserver wie bis 1.2.33. */
function spot_tts($cfg = null) {
    if (!is_array($cfg)) {
        $cfg = spot_config();
    }
    list($t) = ansage_vervollstaendigen(isset($cfg['tts']) && is_array($cfg['tts']) ? $cfg['tts'] : array(),
                                        'musicserver');
    return $t;
}

/** Kontext der gemeinsamen Sprachausgabe: Webport, Kennung dieses Plugins, Datenordner fuer
 *  <art>_letzte.json (nur wenn es ihn gibt) und die Texte aus der Sprachdatei. */
function spot_ansage_k() {
    $d = spot_paths()['datadir'];
    return array('port' => spot_webport(), 'kopf' => array('User-Agent: LoxBerry-Plugin-Spotpreis'),
                 'ordner' => @is_dir($d) ? $d : '',
                 't' => function ($s) { return spot_t($s); },
                 /* Ab Werk ist in dieser Linie der Music Server gewaehlt (spot_tts()). Seit Modul 1.1.2
                  * sagt das Modul es selbst ('werk'); die eigenen Saetze TEXT.ANSAGE_ART_HINWEIS und
                  * TEXT.ANSAGE_O_AUS (Entwurf F7) sind seit 1.2.36 gestrichen. */
                 'werk' => 'musicserver');
}

/**
 * Eine Ansage ueber die eingestellte Ausgabeart. Rueckgabe: das Ergebnis von ansage_sprechen() - stand
 * (1 gesendet, 0 gescheitert, -1 nichts gesendet ohne Fehler: aus, Original-Audioserver, leerer Text),
 * kennung, art, http, zeile, zeichen; nie Text oder Token. Ins Protokoll kommt genau eine Zeile.
 */
function spot_say_ergebnis($text) {
    $r = ansage_sprechen((string) $text, spot_tts(), spot_ansage_k());
    spot_log('Ansage: ' . ansage_kurz($r));
    return $r;
}

/** Wie bis 1.2.33: true nur, wenn gesendet. */
function spot_say($text) {
    $r = spot_say_ergebnis($text);
    return $r['stand'] === 1;
}

/**
 * Das Textfeld der Antwort auf ?say=1 und ?saytomorrow=1 (seit 1.2.33,
 * Nr. 40): beim Original-Audioserver der Text selbst (TEXT=, Loxone gibt ihn
 * ueber den Textgenerator an den Audioserver), sonst nur seine Laenge
 * (TEXTLAENGE=).
 */
function spot_say_feld($text) {
    $cfg = spot_config();
    if ((string) $cfg['tts']['mode'] === 'audioserver') {
        return 'TEXT=' . $text;
    }
    return 'TEXTLAENGE=' . ansage_zeichen((string) $text);
}

/** Form eines Sprechtokens: 8 bis 128 Zeichen aus A-Z a-z 0-9 _ - (aus der gemeinsamen Sprachausgabe). */
function spot_sprech_token_ok($t) {
    return ansage_token_ok($t);
}

/** Port des Webservers dieses LoxBerry (lbwebserverport(), sonst general.json), sonst 80. */
function spot_webport() {
    $p = spot_paths();
    return ansage_webport($p['lbhome'] !== '' ? $p['lbhome'] . '/config/system/general.json' : '');
}

/**
 * Die Pruefzeile der Sprachausgabe fuer den Reiter Test und ?selftest=1: array(0|1|2, Klartext). Gefragt
 * werden Alexa-NG bzw. Chromecast 4 Lox NG nur mit $offen (Knopf; selftest=1, spricht nicht), der Music
 * Server nie (eine Probe dort spraeche). 2 = nicht beurteilt (aus, Original-Audioserver, Knopf nicht
 * gedrueckt, Hinweis). Der Text ist roh - die Tabelle maskiert (sp_e()).
 */
function spot_ansage_pruefzeile($offen) {
    $k = spot_ansage_k();
    $k['e'] = function ($s) { return (string) $s; };
    list($st, $text) = ansage_pruefzeile(spot_tts(), (bool) $offen, $k);
    return array($st === 1 ? 1 : ($st === 0 ? 0 : 2), $text);
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
        $t = sprintf(spot_t('SPOT_ANSAGE.NEGATIV'), spot_num($st['cur'], 1));
    } else {
        $t = sprintf(spot_t('SPOT_ANSAGE.PREIS'), spot_num($st['cur'], 1));
        if ($st['level'] === 1) {
            $t .= spot_t('SPOT_ANSAGE.GUENSTIG');
        } elseif ($st['level'] === 3) {
            $t .= spot_t('SPOT_ANSAGE.TEUER');
        }
    }
    if ($st['fenster']['in'] === 0) {
        $t .= sprintf(spot_t('SPOT_ANSAGE.FENSTER_JETZT'), (int) $st['fenster_len']);
    } elseif ($st['fenster']['in'] > 0) {
        $t .= sprintf(spot_t('SPOT_ANSAGE.FENSTER_SPAETER'), (int) $st['fenster']['h']);
    }
    if (!empty($st['co2_ok']) && !empty($st['co2_clean'])) {
        $t .= sprintf(spot_t('SPOT_ANSAGE.CO2'), (int) $st['co2']);
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
    return sprintf(spot_t('SPOT_ANSAGE.MORGEN'),
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
    $t = sprintf(spot_t('SPOT_ANSAGE.MONAT'), spot_num($vm['dynp'], 1), spot_num($vm['fix'], 1));
    $t .= sprintf(spot_t($vm['diff'] >= 0 ? 'SPOT_ANSAGE.MONAT_DYN' : 'SPOT_ANSAGE.MONAT_FIX'),
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
    /* FUENF MINUTEN, nicht eine.
     *
     * Bis 1.2.19 stand hier (int) date('i') === 0. Das ist genau die
     * Bedingung, die beim Monatsbericht in 1.1.1 als Fehler erkannt und
     * behoben wurde - die Begruendung steht ausfuehrlich in bin/cron.php:
     * ein Cron-Lauf, der sich unter Last um eine Minute verspaetet oder
     * ganz ausfaellt (Neustart, Update), kostet die Ansage. Dort kostete
     * es den Bericht des ganzen Monats, hier die Ansage der ganzen Stunde.
     *
     * Zweimal kann sie dadurch nicht kommen: der Merker said_<YmdH> steht
     * schon und traegt die Stunde im Namen. */
    if (!empty($cfg['notify']['audio']) && $st['ok'] && (int) date('i') < 5) {
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
    /* Unteilbar wie jeder andere Schreibvorgang dieses Plugins.
     * Die Datei wird taeglich VOLLSTAENDIG neu geschrieben, und
     * file_put_contents kuerzt sie dazu zuerst auf null - genau das
     * Muster, das Zeile 1682 als abgeschafft beschreibt. Betroffen
     * war ausgerechnet die eine Datei, die preupgrade.sh als nicht
     * nachladbar bezeichnet: die Preishistorie. */
    spot_write_atomic($f, implode("\n", $lines) . "\n");
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
        //
        // Die Wurzel kommt aus spot_paths(): ohne Wurzel NICHTS ab der
        // Laufwerkswurzel und kein fest verdrahteter Systempfad. Bis 1.2.27
        // stand hier als Rueckfall das Heimatverzeichnis des Benutzers
        // loxberry, und ohne Wurzel hiess der Pfad
        // '' . '/templates/plugins/html/lang' - was dort lag, galt vor den
        // eigenen Sprachdateien (in WSL gemessen,
        // Pruefung-Spotpreis-aWATTar-1.2.28, Faelle C1 und C2; dieselbe
        // Stelle in tb_t() von Spotpreis-Tibber 0.9.19).
        $home = spot_paths()['lbhome'];
        $ordner = basename(dirname(__FILE__));
        $pfad = $home !== '' ? $home . '/templates/plugins/' . $ordner . '/lang' : '';
        if ($pfad === '' || !is_dir($pfad)) {
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


/* ==================================================================
 * Wertpruefung - EINE Stelle fuer Formular, Zurueckspielen und Sichern
 * (C4, Durchgang 01.10.2026; Regeln/04 "Eine Grenze steht genau einmal",
 * Regeln/05 "Jeder Wert der Sicherungsdatei wird geprueft")
 *
 * Bis 1.2.31 standen die Grenzen nur in index.php - dort wurden sie beim
 * Speichern STILL geklemmt (Nr. 19 verlangt seit 01.10.2026: beanstanden) -,
 * und das Zurueckspielen pruefte gar nichts. spot_config_normalisieren()
 * kappt weiterhin, was schon in einer Datei steht, damit das Plugin daran
 * nicht stirbt; geprueft wird HIER.
 *
 * Arten: zahl (int/float, endlich), ganz (int oder ganzzahliger float),
 * schalter (0/1), wahl (eine der Moeglichkeiten), text (Laenge in Zeichen,
 * keine Steuerzeichen, kein Leerraum am Rand, wahlweise verbotene Zeichen),
 * adresse ('' oder http/https), thema (MQTT-Praefix), monate, stunden.
 * ================================================================== */

/** Die Schranken je Schluessel; Unterschluessel als 'regel.<k>', 'notify.<k>'. Den Block tts prueft seit
 *  1.2.34 die gemeinsame Sprachausgabe (spot_wert_mangel()). */
function spot_schranken() {
    $text_feld = array('text', 0, 200, '/["\']/');
    return array(
        'market' => array('wahl', array('de', 'at')),
        'netz' => array('zahl', 0, 50), 'steuer' => array('zahl', 0, 20),
        'konzession' => array('zahl', 0, 20), 'umlagen' => array('zahl', 0, 20),
        'aufschlag' => array('zahl', -10, 30), 'grundpreis' => array('zahl', 0, 100),
        'vat' => array('zahl', 0, 30), 'cheap' => array('zahl', 0, 200), 'expensive' => array('zahl', 0, 400),
        'window' => array('ganz', 1, 12),
        'wp_enabled' => array('schalter'), 'wp_name' => array('text', 1, 100, ''),
        'wp_netz' => array('zahl', 0, 50), 'wp_konzession' => array('zahl', 0, 20),
        'co2_enabled' => array('schalter'), 'co2_clean' => array('zahl', 0, 1000),
        'fixed_price' => array('zahl', 0, 200), 'fix_grund' => array('zahl', 0, 500),
        'fix_sofortbonus' => array('zahl', 0, 5000), 'fix_neubonus' => array('zahl', 0, 5000),
        'fix_neubonus_pct' => array('zahl', 0, 100), 'fix_rabatt' => array('zahl', 0, 100),
        // Mit gepflegten Monaten ist der Jahresverbrauch deren Summe (hoechstens 12 x 20000).
        'consumption' => array('ganz', 100, 240000), 'months' => array('monate', 0, 20000),
        'shift_kwh' => array('zahl', 0, 100),
        'marstek_enabled' => array('schalter'), 'marstek_url' => array('adresse'),
        'marstek_hours' => array('ganz', 1, 12), 'marstek_power' => array('ganz', 100, 10000),
        'marstek_neg' => array('schalter'), 'marstek_fremd_beanstanden' => array('schalter'),
        'profil_ein' => array('wahl', array('aus', 'absolut', 'relativ', 'beides')),
        'mqtt_enabled' => array('schalter'), 'mqtt_topic' => array('thema'),
        'pv_quelle' => array('wahl', array('', 'forecast_solar', 'objekt', 'liste')),
        'pv_url' => array('adresse'), 'pv_pfad' => $text_feld, 'pv_zeitfeld' => $text_feld,
        'pv_wertfeld' => $text_feld, 'pv_einheit' => array('wahl', array('wh', 'w', 'kw')),
        'soc_url' => array('adresse'), 'soc_pfad' => $text_feld,
        'last_quelle' => array('wahl', array('', 'objekt', 'liste')), 'last_url' => array('adresse'),
        'last_pfad' => $text_feld, 'last_zeitfeld' => $text_feld, 'last_wertfeld' => $text_feld,
        'last_einheit' => array('wahl', array('kwh', 'wh', 'w', 'kw')),
        'hysterese' => array('schalter'),
        'budget_kw' => array('zahl', 0, 200), 'pv_bonus' => array('zahl', 0, 100),
        'pv_schwelle' => array('ganz', 1, 100000), 'budget2_kw' => array('zahl', 0, 200),
        'budget2_von' => array('ganz', 0, 23), 'budget2_bis' => array('ganz', 0, 23),
        // Schaltregeln (je Regel)
        'regel.aktiv' => array('schalter'), 'regel.name' => array('text', 0, 100, '/"/'),
        'regel.art' => array('wahl', array('fenster', 'stunden', 'schwelle', 'mittel')),
        'regel.n' => array('ganz', 1, 12), 'regel.von' => array('ganz', 0, 23), 'regel.bis' => array('ganz', 0, 23),
        'regel.horizont' => array('ganz', 1, 48), 'regel.schwelle' => array('zahl', -100, 200),
        'regel.prozent' => array('ganz', 0, 90), 'regel.neg' => array('schalter'),
        'regel.rang' => array('ganz', 1, 99), 'regel.leistung' => array('zahl', 0, 100),
        'regel.energie' => array('zahl', 0, 500), 'regel.frist' => array('ganz', -1, 23),
        'regel.pv_sperre' => array('zahl', 0, 500), 'regel.soc_min' => array('ganz', 0, 100),
        'regel.soc_max' => array('ganz', 0, 100), 'regel.min_lauf' => array('ganz', 0, 720),
        'regel.min_pause' => array('ganz', 0, 720),
        // Meldungen
        'notify.audio' => array('schalter'), 'notify.push' => array('schalter'),
        'notify.only_cheap' => array('schalter'), 'notify.negative' => array('schalter'),
        'notify.tomorrow' => array('schalter'), 'notify.hours' => array('stunden'),
    );
}

/** Schluessel, die es erst seit dem Durchgang 01.10.2026 gibt (aeltere Sicherungen tragen sie nicht). */
function spot_sicherung_neue_schluessel() {
    return array('marstek_fremd_beanstanden');
}

/** Ein Wert gegen seine Schranke. Rueckgabe null (in Ordnung) oder array(Kennwort[, a, b]). */
function spot_wert_gegen($w, $s) {
    switch ($s[0]) {
        case 'zahl':
            if (!(is_int($w) || is_float($w)) || !is_finite((float) $w)) { return array('ZAHL'); }
            return ((float) $w < $s[1] || (float) $w > $s[2]) ? array('BEREICH', $s[1], $s[2]) : null;
        case 'ganz':
            if (is_float($w) && is_finite($w) && floor($w) == $w && abs($w) < 1e9) { $w = (int) $w; }
            if (!is_int($w)) { return array('GANZ'); }
            return ($w < $s[1] || $w > $s[2]) ? array('BEREICH', $s[1], $s[2]) : null;
        case 'schalter':
            return in_array($w, array(0, 1, true, false), true) ? null : array('SCHALTER');
        case 'wahl':
            return (is_string($w) && in_array($w, $s[1], true)) ? null
                : array('WAHL', implode(', ', array_map(function ($x) { return $x === '' ? '-' : $x; }, $s[1])));
        case 'text':
            if (!is_string($w)) { return array('TYP'); }
            if (preg_match('//u', $w) !== 1) { return array('UTF8'); }
            if (preg_match('/[\x00-\x1F\x7F]/', $w)) { return array('STEUERZEICHEN'); }
            if ($w !== trim($w)) { return array('RAND'); }
            $l = preg_match_all('/./us', $w);
            if ($l === 0 && $s[1] > 0) { return array('LEER'); }
            if ($l < $s[1] || $l > $s[2]) { return array('LAENGE', $s[1], $s[2]); }
            if (isset($s[3]) && $s[3] !== '' && preg_match($s[3], $w)) { return array('ZEICHEN'); }
            return null;
        case 'adresse':
            if (!is_string($w)) { return array('TYP'); }
            if ($w === '') { return null; }
            return (preg_match('/[\x00-\x20\x7F"\']/', $w) || !spot_url_ok($w)) ? array('ADRESSE') : null;
        case 'thema':
            if (!is_string($w)) { return array('TYP'); }
            if ($w === '') { return array('LEER'); }
            return (strlen($w) > 64 || !preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*\z#', $w)) ? array('THEMA') : null;
        case 'monate':
            if (!is_array($w) || count($w) > 12 || ($w && array_keys($w) !== range(0, count($w) - 1))) {
                return array('MONATE');
            }
            foreach ($w as $m) {
                $x = spot_wert_gegen($m, array('zahl', $s[1], $s[2]));
                if ($x !== null) { return $x; }
            }
            return null;
        case 'stunden':
            if (!is_array($w) || count($w) > 24 || ($w && array_keys($w) !== range(0, count($w) - 1))) {
                return array('STUNDEN');
            }
            foreach ($w as $h) {
                if (!is_int($h) || $h < 0 || $h > 23) { return array('STUNDEN'); }
            }
            return count(array_unique($w)) !== count($w) ? array('DOPPELT') : null;
    }
    return array('TYP');
}

/**
 * Einen Schluessel der Konfiguration pruefen - mit seinen Unterschluesseln
 * (regeln, notify, tts). Rueckgabe: Liste array(Name, Mangel), leer = in Ordnung.
 * Die Token-Schluessel prueft der Aufrufer (spot_sicherung_lesen()); die
 * Sprechtoken in tts duerfen nur leer dastehen.
 */
function spot_wert_mangel($k, $w) {
    $s = spot_schranken();
    $aus = array();
    if ($k === 'regeln') {
        if (!is_array($w) || count($w) > SPOT_REGELN || ($w && array_keys($w) !== range(0, count($w) - 1))) {
            return array(array('regeln', array('TYP')));
        }
        $bekannt = array_keys(spot_regel_vorgabe());
        foreach ($w as $i => $r) {
            if (!is_array($r)) { $aus[] = array('regeln.' . $i, array('TYP')); continue; }
            foreach ($r as $rk => $rw) {
                $rk = (string) $rk;
                if (!in_array($rk, $bekannt, true)) { $aus[] = array('regeln.' . $i . '.' . $rk, array('FREMD')); continue; }
                $m = spot_wert_gegen($rw, $s['regel.' . $rk]);
                if ($m !== null) { $aus[] = array('regeln.' . $i . '.' . $rk, $m); }
            }
        }
        return $aus;
    }
    if ($k === 'notify' || $k === 'tts') {
        if (!is_array($w) || ($w && array_keys($w) === range(0, count($w) - 1))) {
            return array(array($k, array('TYP')));
        }
        foreach ($w as $uk => $uw) {
            $uk = (string) $uk;
            if ($k === 'tts' && in_array($uk, array('alexa_token', 'google_token'), true)) {
                if ($uw !== '') { $aus[] = array('tts.' . $uk, array('SPRECHTOKEN')); }
                continue;
            }
            /* Seit 1.2.34 prueft die gemeinsame Sprachausgabe jeden Wert des Blocks tts - dieselbe Pruefung
             * wie das Formular (Heimnetz fuer Adresse und Vorlage, Entscheidung Nr. 40). Ein Eintrag, den das
             * Modul nicht kennt, bleibt FREMD; genannt werden Name und Grund, nie der Wert. */
            if ($k === 'tts') {
                if (!array_key_exists($uk, ansage_vorgaben())) { $aus[] = array('tts.' . $uk, array('FREMD')); continue; }
                $sp_g = '';
                if (ansage_wert_pruefen(array($uk => $uw), $sp_g, spot_ansage_modi()) === null) {
                    $aus[] = array('tts.' . $uk, array('ANSAGE', $sp_g));
                }
                continue;
            }
            if (!isset($s[$k . '.' . $uk])) { $aus[] = array($k . '.' . $uk, array('FREMD')); continue; }
            $m = spot_wert_gegen($uw, $s[$k . '.' . $uk]);
            if ($m !== null) { $aus[] = array($k . '.' . $uk, $m); }
        }
        return $aus;
    }
    if (!isset($s[$k])) {
        return array();
    }
    $m = spot_wert_gegen($w, $s[$k]);
    return $m === null ? array() : array(array($k, $m));
}

/** Alle Maengel einer ganzen Konfiguration (X-3) - nur die Namen. */
function spot_konfig_mangel(array $daten) {
    $namen = array();
    foreach ($daten as $k => $w) {
        if ($k === '' || $k[0] === '_' || in_array($k, array('token', 'marstek_token'), true)) {
            continue;
        }
        foreach (spot_wert_mangel((string) $k, $w) as $x) {
            $namen[] = $x[0];
        }
    }
    return $namen;
}

/** Der Grund eines Mangels als Satzteil (Sprachdatei, Abschnitt WERT). */
function spot_wert_text($m) {
    $k = isset($m[0]) ? (string) $m[0] : 'TYP';
    $t = spot_t('WERT.' . $k);
    if ($k === 'BEREICH' || $k === 'LAENGE') {
        $f = function ($z) { return rtrim(rtrim(sprintf('%.3f', (float) $z), '0'), '.'); };
        return sprintf($t, $f($m[1]), $f($m[2]));
    }
    if ($k === 'WAHL') {
        return sprintf($t, $m[1]);
    }
    if ($k === 'ANSAGE') {
        // Seit 1.2.34: der Satz der gemeinsamen Sprachausgabe zu ihrer Kennung (Abschnitt [ANSAGE]). Manche
        // Saetze beginnen schon mit dem Namen (tts.port: ...); der steht in der Meldung bereits davor.
        $sp_s = ansage_kennung_text(isset($m[1]) ? (string) $m[1] : '', spot_ansage_k());
        return (string) preg_replace('/^tts\\.[a-z_]+: /', '', $sp_s);
    }
    return $t;
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
    $sp_tok_aus_datei = false;
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
        /* Das Marstek-Token kommt nie aus einer Datei (Energie-1 C2). Eine
         * Sicherung dieses Plugins traegt es nicht. Ein LEERER Wert sagt
         * nichts und wird uebergangen (wie das leere Formularfeld: das
         * geltende bleibt). Traegt der Schluessel einen Wert - Text oder
         * Liste -, ist die Datei fremd oder von Hand bearbeitet: abgewiesen
         * wird die ganze Datei, ohne den Wert zu nennen. Ebenso ein Token in
         * der Marstek-Adresse. */
        /* Das Endpunkt-Token (Klasse 12). Bis 1.2.29 ging es ungeprueft
         * durch: eine Liste wurde gespeichert, und danach war der Endpunkt
         * mit ?token=Array offen (gemessen, HTTP 200 mit voller Zeile). Ein
         * leerer Wert schaltete den Schutz still ab. Jetzt: nur eine
         * Zeichenkette in der Form der eigenen Erzeugung; leer = das geltende
         * Token bleibt; alles andere weist die ganze Datei ab. */
        if ($k === 'token') {
            if (!is_string($w) || ($w !== '' && !spot_endpunkt_token_form_ok($w))) {
                $mangel[] = spot_t('TEXT.SICH_TOKEN_FORM');
            } elseif ($w !== '') {
                $neu['token'] = $w;
                $sp_tok_aus_datei = true;
                $anzahl++;
            }
            continue;
        }
        if ($k === 'marstek_token') {
            if ($w !== '') {
                $mangel[] = spot_t('TEXT.SICH_MARSTEK_TOKEN');
            }
            continue;
        }
        if ($k === 'marstek_url' && is_string($w) && spot_marstek_url_hat_token($w)) {
            $mangel[] = spot_t('TEXT.SICH_MARSTEK_URL_TOKEN');
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            /* O7: hier NICHT maskieren - die Oberflaeche gibt die Meldung mit
             * sp_e() aus. Bis 1.2.31 stand hier htmlspecialchars(), und der
             * Anwender las "x&lt;b&gt;&amp;y" statt "x<b>&y". */
            $mangel[] = sprintf(spot_t('TEXT.SICH_FREMD'), (string) $k);
            continue;
        }
        /* C4 (Pruefbericht code, Befund 7; oberflaeche, Befund 8): JEDER Wert
         * mit derselben Pruefung wie das Formular - Typ, Bereich, Liste. Bis
         * 1.2.31 ging hier alles ausser dem Token ungeprueft durch: mqtt_topic
         * als Liste machte das Praefix zu "Array", marstek_hours 99 liess die
         * Kopplung in jeder Stunde laden. Genannt wird nur der Name und der
         * Grund, nie der Wert. */
        $sp_wm = spot_wert_mangel((string) $k, $w);
        if ($sp_wm) {
            foreach ($sp_wm as $sp_x) {
                $mangel[] = sprintf(spot_t('TEXT.SICH_WERT'), $sp_x[0], spot_wert_text($sp_x[1]));
            }
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = spot_t('TEXT.SICH_LEER');
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall.
     *
     * Bis hierher war die Vorgabenliste der Ausgangspunkt, und nur was in
     * der Datei stand wurde darueber geschrieben. Eine Datei mit einem
     * einzigen Schluessel lief damit ohne Beanstandung durch, wurde
     * gespeichert, und alle uebrigen Einstellungen fielen auf Werk
     * zurueck - quittiert mit "1 Wert uebernommen".
     *
     * Gemessen an VolkswagenID 0.9.11 am 03.09.2026 unter PHP 7.4 und 8.4:
     * dort fiel dabei auch das Aktionstoken auf '', und jede im Miniserver
     * eingetragene Adresse war stumm ungueltig. Am 07.09.2026 ueber den
     * Bestand ausgerollt (30 Linien).
     *
     * Der Hausstandard sagt: eine halb gueltige Datei aendert gar nichts.
     * Verglichen wird gegen die VORGABEN, nicht gegen $bekannt: was
     * ausserhalb der Konfigurationsdatei liegt - Zugangsdaten in einer
     * eigenen Datei - faellt nicht auf Werk zurueck und darf hier fehlen. */
    $fehlend = array();
    foreach (array_keys(spot_vorgaben()) as $fk) {
        // marstek_token steht nie in einer Sicherung - sein Fehlen ist richtig.
        // Schluessel, die es erst seit dem Durchgang 01.10.2026 gibt, fehlen in
        // jeder aelteren Sicherung; sie sind ab Werk aus und gelten dann so.
        if ($fk !== 'marstek_token' && !in_array($fk, spot_sicherung_neue_schluessel(), true)
            && !array_key_exists($fk, $daten)) {
            $fehlend[] = $fk;
        }
    }
    if ($fehlend) {
        // O7: ohne htmlspecialchars - die Ausgabe maskiert (sp_e()).
        $mangel[] = sprintf(spot_t('TEXT.SICH_FEHLEND'), count($fehlend), implode(', ', $fehlend));
    }
    /* Zurueckspielen behaelt das geltende Marstek-Token (Energie-1 C2): die
     * Datei traegt keines, und die Vorgabe '' haette es sonst still geloescht. */
    $sp_jetzt = spot_config();
    $neu['marstek_token'] = isset($sp_jetzt['marstek_token']) ? (string) $sp_jetzt['marstek_token'] : '';
    /* S1: ebenso die beiden Sprechtoken (Alexa-NG, Chromecast 4 Lox NG). Eine
     * Datei MIT Token hat spot_wert_mangel() schon abgewiesen. */
    if (isset($neu['tts']) && is_array($neu['tts'])) {
        foreach (array('alexa_token', 'google_token') as $sp_tk) {
            $neu['tts'][$sp_tk] = (isset($sp_jetzt['tts'][$sp_tk]) && is_string($sp_jetzt['tts'][$sp_tk]))
                ? $sp_jetzt['tts'][$sp_tk] : '';
        }
    }
    // Ein leeres Token in der Datei laesst das geltende stehen (Klasse 12).
    if (!$sp_tok_aus_datei) {
        $neu['token'] = isset($sp_jetzt['token']) ? $sp_jetzt['token'] : '';
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
                    . ' und nicht an einen Fehlerbericht heften.'
                    . ' Nicht darin: das Marstek-Token und die Sprechtoken fuer Alexa-NG'
                    . ' und Chromecast 4 Lox NG - sie bleiben beim Zurueckspielen, wie sie sind.',
        '_stand'   => date('Y-m-d H:i:s'),
        '_fassung' => spot_fassung(),
    );
    /* Ein Token in der Marstek-Adresse - bis 1.2.29 der einzige Weg, die
     * Kopplung zum Laufen zu bringen - kommt ebenfalls nicht mit: es wird aus
     * der Adresse genommen, und der Kopf der Datei sagt es (X-3). Das
     * Zurueckspielen wiese eine Adresse mit Token ab. */
    $sp_mu = (isset($cfg['marstek_url']) && is_string($cfg['marstek_url'])) ? $cfg['marstek_url'] : '';
    $sp_mu_tok = ($sp_mu !== '' && spot_marstek_url_hat_token($sp_mu));
    if ($sp_mu_tok) {
        $daten['_warnung'] = 'marstek_url trug ein Token. Es steht NICHT in dieser Datei;'
            . ' bitte im Plugin in das Feld "Aktionstoken des Marstek-Plugins" eintragen.';
    }
    foreach (spot_vorgaben() as $k => $v) {
        // Das Marstek-Token gehoert nie in eine Sicherung (Energie-1 C2).
        if ($k === 'marstek_token') {
            continue;
        }
        $daten[$k] = isset($cfg[$k]) ? $cfg[$k] : $v;
    }
    if ($sp_mu_tok) {
        $daten['marstek_url'] = spot_marstek_url_ohne_token($sp_mu);
    }
    /* S1: die Sprechtoken fuer Alexa-NG und Chromecast 4 Lox NG kommen nie in
     * die Datei - wie das Marstek-Token. Sie werden beim Umzug im jeweiligen
     * Plugin abgelesen. */
    if (isset($daten['tts']) && is_array($daten['tts'])) {
        // seit 1.2.33 aus der gemeinsamen Sprachausgabe (eine Liste der Geheimnisse)
        $daten['tts'] = ansage_sicherung_bereinigen($daten['tts']);
    }
    /* X-3 (Pruefbericht oberflaeche, Befund 11): was das Zurueckspielen
     * abweisen wuerde, sagt die Datei schon beim Sichern - nur mit Namen, nie
     * mit Werten. Bis 1.2.31 warnte nur die Marstek-Adresse. */
    $sp_x3 = spot_konfig_mangel($daten);
    if (isset($cfg['token']) && $cfg['token'] !== '' && !spot_endpunkt_token_form_ok($cfg['token'])) {
        array_unshift($sp_x3, 'token');
    }
    if ($sp_x3) {
        $daten['_warnung'] = (isset($daten['_warnung']) ? $daten['_warnung'] . ' ' : '')
            . 'Diese Werte wuerde das Zurueckspielen abweisen: ' . implode(', ', $sp_x3)
            . '. Bitte in den Einstellungen berichtigen und neu sichern.';
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
    /* NEU: zuerst LoxBerry selbst fragen.
     *
     * Am Geraet gemessen (07.09.2026): plugininstall.pl liest die
     * plugin.cfg aus dem Auspackordner und loescht sie danach -
     * installiert wird sie NIRGENDWOHIN. Eine Fassungsfunktion, die nur
     * Dateien kennt, gibt auf jeder Installation eine leere Zeichenkette
     * zurueck; im Arbeitsordner faellt das nie auf, weil dort der
     * Archivfall der Kandidatenliste immer trifft.
     *
     * LBSystem::pluginversion() (loxberry_system.php:403) liest die
     * plugindatabase.json und ist die Auskunft von LoxBerry selbst.
     * Die Dateikandidaten darunter bleiben stehen - sie tragen den
     * Auspackordner, und der ist der Pruefstand. */
    if (class_exists('LBSystem', false)
        && method_exists('LBSystem', 'pluginversion')) {
        /* Ueber den Ordnernamen fragen (Regeln/03): ohne Argument haengt die
         * Antwort am ersten eingebundenen Skript - am Geraet gemessen
         * 17.09.2026: aus einem fremden Einstieg (php -r) NULL, mit dem
         * Ordnernamen die installierte Fassung. Installiert liegt diese Datei
         * unter webfrontend/html(auth)/plugins/<ordner>/. */
        $aus = @LBSystem::pluginversion(basename(__DIR__));
        if ($aus !== null && trim((string) $aus) !== '') {
            return trim((string) $aus);
        }
    }
    /* Nur zwei Orte: die Anlage (nur mit Wurzel) und das eigene Archiv.
     * Bis 1.2.27 stand dazwischen dirname(__DIR__, 3)/plugin.cfg - aus einem
     * Archiv unter /plugin heisst das /plugin.cfg, VOR der eigenen Datei -,
     * und ohne Wurzel begann der erste Kandidat an der Laufwerkswurzel (in
     * WSL gemessen, Pruefung-Spotpreis-aWATTar-1.2.28, Fall C9: ein fremdes
     * /plugin.cfg setzte die angezeigte Fassung). Installiert antwortet
     * LBSystem oben; die plugin.cfg wird nicht mitinstalliert (Regeln/06). */
    $p = spot_paths();
    foreach (array(
        $p['lbhome'] !== '' ? $p['lbhome'] . '/config/plugins/' . basename(dirname($p['config'])) . '/plugin.cfg' : '',
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
    $tok = spot_cfg_wert('token', '');
    $tok = spot_endpunkt_token_form_ok($tok) ? $tok : '';
    $url = 'http://127.0.0.1:' . $port . '/plugins/' . rawurlencode($ordner) . '/spot.php'
         . ($tok !== '' ? '?token=' . rawurlencode($tok) : '');
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 5, 'user_agent' => 'LoxBerry Spotpreis Selbsttest', 'ignore_errors' => true)));
    $r = @file_get_contents($url, false, $ctx);
    if ($r === false) {
        $erg = array(2, 'ENDPUNKT_UNKLAR');
    } elseif (strpos($r, 'GRUND=TOKEN') !== false) {
        $erg = array(0, 'ENDPUNKT_TOKEN');
    } elseif (strpos($r, 'GRUND=KEINE_PREISE') !== false) {
        // HTTP 503 ohne Preise: der Endpunkt tut, was er soll; ueber die
        // Zeile selbst laesst sich so nichts sagen.
        $erg = array(2, 'ENDPUNKT_KEINE_PREISE');
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
    $sp_text = spot_t('PRUEFTEXT.KONFIG_' . strtoupper($lage));
    $sk = spot_konfig_schluessel();
    if ($sk !== null && $sk[0]) {
        $sp_text .= ' ' . sprintf(spot_t('PRUEFTEXT.KONFIG_FEHLEND'), count($sk[0]), implode(', ', $sk[0]));
    }
    if ($sk !== null && $sk[1]) {
        $sp_text .= ' ' . sprintf(spot_t('PRUEFTEXT.KONFIG_FREMD'), count($sk[1]), implode(', ', $sk[1]));
    }
    $add('PRUEF.KONFIG', in_array($lage, array('kaputt', 'zweit_kaputt'), true) ? 0 : ($lage === 'ok' ? 1 : 2), $sp_text);

    // Marktdaten. Ohne sie ist jede Zahl darunter eine Null, und das hat
    // dann nichts mit der Einrichtung zu tun.
    $add('PRUEF.PREISE', !empty($st['ok']) ? 1 : 0,
        !empty($st['ok'])
            ? sprintf(spot_t('PRUEFTEXT.PREISE_OK'), (int) $st['heute']['n'],
                      !empty($st['tomorrow_ok']) ? (int) $st['morgen']['n'] : 0)
            : spot_t('PRUEFTEXT.PREISE_FEHLT'));

    /* Die Retain-Tabelle gegen die Themen, die wirklich hinausgehen.
     *
     * Nennt die Tabelle einen Namen, den spot_mqtt_themen() nicht
     * bildet, wirkt der Eintrag still nicht - gesendet wird trotzdem,
     * nur fluechtig. Die Zeile nennt die Zahl der angesehenen Stellen
     * mit; eine Null waere kein 'in Ordnung'. */
    $rt = array_keys(spot_retain_liste());
    $alle = array_keys(spot_mqtt_themen($st));
    $fremd = array_diff($rt, $alle);
    $add('PRUEF.RETAIN', (count($rt) > 0 && !$fremd) ? 1 : 0,
        (count($rt) > 0 && !$fremd)
            ? sprintf(spot_t('PRUEFTEXT.RETAIN_OK'), count($rt), count($alle))
            : sprintf(spot_t('PRUEFTEXT.RETAIN_FREMD'),
                      $fremd ? implode(', ', $fremd) : '-'));

    /* Lebenszeichen. Erst pruefen, ob der Cron ueberhaupt schon einmal
     * gelaufen ist - ueber eine leere Menge wird nicht geurteilt.
     *
     * GEMESSEN WIRD DER LAUFZAEHLER, nicht state.json. Bis 1.2.19 stand
     * hier state.json - und die schreibt auch der Abruf des Miniservers
     * neu. Diese Pruefung war damit genauso blind wie das TS in der Zeile:
     * mit einem zwei Stunden gealterten Zustand und ohne einen einzigen
     * Cron-Lauf meldete sie "Alter 0 Minuten". */
    $puls = spot_cron_puls();
    if ($puls <= 0) {
        $add('PRUEF.LEBEN', 2, spot_t('PRUEFTEXT.LEBEN_NIE'));
    } else {
        $alter = time() - $puls;
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
    /* O9: die Themenliste des Reiters MQTT gegen die Sendemenge, in beide
     * Richtungen (spot_mqtt_liste_pruefen()). */
    list($sp_ml_ok, $sp_ml_text) = spot_mqtt_liste_pruefen($st);
    $add('PRUEF.MQTT_LISTE', $sp_ml_ok, $sp_ml_text);

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
    if (empty($st['ok']) || (int) $st['heute']['n'] <= 0) {
        /* UEBER DIE LEERE MENGE WIRD NICHT GEURTEILT. Gibt es fuer heute
         * gar keine Preise, ist auch die Lueckenliste leer - bis 1.2.19
         * meldete diese Pruefung daraufhin einen Haken, mit "0 Stunden"
         * daneben. Ein Haken, der bedeutet "es ist nichts da", ist
         * schlimmer als kein Haken. Dass die Preise fehlen, sagt
         * PRUEF.PREISE eine Zeile weiter oben. */
        $add('PRUEF.STUNDEN', 2, spot_t('PRUEFTEXT.STUNDEN_KEINE'));
    } elseif (!$luecken && !$doppelt) {
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
        /* JEDES absendende Formular muss ein Merkmal TRAGEN. Nachgesehen,
         * nicht gezaehlt.
         *
         * Bis 1.2.19 stand hier ein Vergleich zweier Zahlen: Vorkommen von
         * 'spot_fmt()' gegen Zahl der Formulare. Das ging an zwei Stellen
         * daneben. Erstens zaehlte es auch, was keine Ausgabe ist -
         * index.php traegt den Namen in einem Kommentar, gemessen wurden
         * daher 13 Merkmale auf 12 Formulare. Zweitens sagt eine Summe
         * nichts ueber die Verteilung: zwei Merkmale im einen Formular und
         * keines im anderen ergeben dieselbe Zahl.
         *
         * Geeicht: nimmt man dem ersten Formular sein Merkmal, meldet die
         * alte Regel weiter gruen (12 >= 12), die neue nennt das Formular.
         *
         * Der Ausdruck verlangt ein passendes </form>. Faende er weniger
         * Formulare als die einfache Suche nach <form ... method=post>,
         * waere das selbst ein Befund - deshalb wird beides gezaehlt und
         * die kleinere Zahl beanstandet. */
        $formulare = preg_match_all('/<form\b[^>]*method=["\']post["\']/i', $q);
        $paare = preg_match_all('/<form\b[^>]*method=["\']post["\'][^>]*>(.*?)<\/form>/is',
                                $q, $sp_mm, PREG_SET_ORDER);
        $ohne = array();
        foreach ($sp_mm as $sp_i => $sp_m) {
            if (strpos($sp_m[1], 'spot_fmt()') === false) { $ohne[] = $sp_i + 1; }
        }
        if ($formulare === 0) {
            $add('PRUEF.FORMULARE', 2, spot_t('PRUEFTEXT.FORMULARE_KEINE'));
        } elseif ($paare !== $formulare) {
            $add('PRUEF.FORMULARE', 0,
                sprintf(spot_t('PRUEFTEXT.FORMULARE_UNPAAR'), $formulare, $paare));
        } elseif ($ohne) {
            $add('PRUEF.FORMULARE', 0,
                sprintf(spot_t('PRUEFTEXT.FORMULARE_FEHLT'),
                        implode(', ', $ohne), $formulare));
        } else {
            $add('PRUEF.FORMULARE', 1,
                sprintf(spot_t('PRUEFTEXT.FORMULARE_OK'), $formulare));
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
        $leiste = preg_match_all('/data-ziel="tab-([a-z0-9_]+)"/', $q, $l) ? $l[1] : array();
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
    /* Marstek-Kopplung (Energie-1 C2). Nur, wenn sie eingeschaltet ist: eine
     * ausgeschaltete Kopplung hat nichts zu pruefen. Hinten angehaengt, weil
     * die PRUEF-Zeile des Endpunkts die Punkte der Reihe nach nennt. */
    if (!empty($cfg['marstek_enabled'])) {
        list($sp_mok, $sp_mtext) = spot_marstek_pruefen($endpunkt_pruefen);
        $add('PRUEF.MARSTEK', $sp_mok, $sp_mtext);
    }
    /* Sprachausgabe (seit 1.2.34 die Zeile der gemeinsamen Sprachausgabe, ansage_pruefzeile()). Alexa-NG
     * bzw. Chromecast 4 Lox NG werden nur auf den Knopf gefragt (selftest=1, spricht nicht) - sonst
     * kostete ein haengender Dienst jeden Seitenaufbau; der Music Server nie (eine Probe dort spraeche).
     * Die Zeile steht, wenn eine dieser Arten gewaehlt oder die Ansage eingeschaltet ist; ohne Ansage gibt
     * es nichts zu beurteilen. Hinten angehaengt wie bisher. */
    $sp_tm = (string) spot_tts($cfg)['mode'];
    if ($sp_tm === 'alexang' || $sp_tm === 'cc4lox' || !empty($cfg['notify']['audio'])) {
        list($sp_sok, $sp_stext) = spot_ansage_pruefzeile($endpunkt_pruefen);
        $add('PRUEF.SPRECHEN', $sp_sok, $sp_stext);
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
    /* Die Anlage nur mit Wurzel. Bis 1.2.27 begann der erste Kandidat ohne
     * Wurzel an der Laufwerkswurzel (/webfrontend/htmlauth/plugins/...), vor
     * der eigenen Datei (in WSL gemessen, Pruefung-Spotpreis-aWATTar-1.2.28,
     * Fall C10). */
    foreach (array(
        $p['lbhome'] !== '' ? $p['lbhome'] . '/webfrontend/htmlauth/plugins/' . $ordner . '/index.php' : '',
        /* Im Archiv liegt die Oberflaeche neben html/, also unter
         * <archiv>/webfrontend/htmlauth/. Bis 1.2.27 stand hier
         * dirname(dirname(__DIR__)), das ist <archiv>/htmlauth/ - der Kandidat
         * traf nie (in WSL gemessen, Pruefung-Spotpreis-aWATTar-1.2.28,
         * Nachtrag 2 zu Fall C10). */
        dirname(__DIR__) . '/htmlauth/index.php',
    ) as $k) {
        if ($k !== '' && strpos($k, '/index.php') !== false && is_file($k)) {
            return $k;
        }
    }
    return '';
}

/* ==================================================================
 * Einmalmeldung (O2, PRG) - Bauform oc_meldung_ablegen() (Octopus 1.1.16)
 *
 * Jeder POST der Oberflaeche endet mit einer Umleitung (303). Das Ergebnis
 * reist in dieser Datei (Datenordner, 0600, 120 s gueltig) und wird nur beim
 * GET gelesen - und dabei geloescht. Aktionstoken, Marstek-Token, die
 * Sprechtoken und das Formularmerkmal stehen darin nie im Klartext. Bis 1.2.31
 * lieferte jeder POST die Seite direkt (Pruefbericht oberflaeche, Befunde 2/3):
 * F5 wiederholte das Speichern und wuerfelte das Token neu.
 * ================================================================== */
function spot_meldung_datei() {
    return spot_paths()['datadir'] . '/einmalmeldung.json';
}

function spot_meldung_ablegen(array $daten) {
    $daten['zeit'] = time();
    $c = spot_config(false);
    $geheim = array();
    foreach (array($c['token'], $c['marstek_token'], $c['tts']['alexa_token'], $c['tts']['google_token'],
                   spot_formtoken()) as $g) {
        if (is_string($g) && $g !== '') {
            $geheim[] = $g;
        }
    }
    array_walk_recursive($daten, function (&$w) use ($geheim) {
        if (is_string($w)) {
            foreach ($geheim as $g) {
                $w = str_replace($g, '***', $w);
            }
        }
    });
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    spot_datadir();
    return is_string($js) && spot_geheim_schreiben(spot_meldung_datei(), $js);
}

function spot_meldung_abholen() {
    $f = spot_meldung_datei();
    clearstatcache(true, $f);
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);                         // loeschen VOR der Anzeige
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    return $d;
}

/* ==================================================================
 * Eingaben nach einer Beanstandung (X-2, Regeln/04) - Bauform
 * oc_eingaben_*() (Octopus 1.1.18), hier zusaetzlich mit <textarea>.
 *
 * Nach der Umleitung zeigte der GET sonst die GESPEICHERTEN Werte: wer zehn
 * Felder richtig und eines falsch eingab, tippte alle elf neu (Pruefbericht
 * oberflaeche, Befund 4). Die Eingaben DIESES Formulars reisen mit der
 * Einmalmeldung; nie ein Token (die Kennwortfelder bleiben leer) und nie
 * Formularmerkmal oder Reiter. Eingesetzt wird am fertigen HTML des Formulars.
 * ================================================================== */

/** Die Formulare (Name des versteckten Merkmalfeldes) und was in ihnen NIE zurueckreist. */
function spot_eingaben_formulare() {
    return array(
        'save'      => array('marstek_token', 'marstek_token_weg', 'tts_alexa_token', 'tts_alexa_token_weg',
                             'tts_google_token', 'tts_google_token_weg'),
        'mqtt_save' => array(),
    );
}

/** Felder, die als Liste name[] abgeschickt werden (Stundenhaken). */
function spot_eingaben_listen() {
    return array('hours');
}

/** Taugt der Name als Feldname? name, name[3] oder name[] - sonst nichts. */
function spot_eingaben_name_ok($k) {
    return is_string($k) && preg_match('/^[a-z][a-z0-9_]{0,40}(\[[0-9]{1,2}\]|\[\])?$/', $k) === 1;
}

/** Die Eingaben eines abgewiesenen POST fuer die Einmalmeldung. */
function spot_eingaben_sammeln($formular, $beanstandet) {
    $liste = spot_eingaben_formulare();
    if (!isset($liste[$formular])) {
        return array();
    }
    $nie = array_merge($liste[$formular], array('fmt', 'activetab', 'tab', $formular));
    $werte = array();
    foreach ($_POST as $k => $v) {
        if (!is_string($k) || in_array($k, $nie, true) || count($werte) >= 400) {
            continue;
        }
        if (in_array($k, spot_eingaben_listen(), true)) {
            $l = array();
            foreach ((array) $v as $w) {
                if (!is_array($w)) { $l[] = substr((string) $w, 0, 16); }
            }
            $werte[$k . '[]'] = array_slice($l, 0, 48);
            continue;
        }
        if (is_array($v)) {
            foreach ($v as $i => $w) {
                $n = $k . '[' . $i . ']';
                if (!is_array($w) && spot_eingaben_name_ok($n)) { $werte[$n] = substr((string) $w, 0, 1000); }
            }
            continue;
        }
        if (spot_eingaben_name_ok($k)) {
            /* Ein Token in der eingetippten Marstek-Adresse reist nicht mit zurueck
             * (es gehoert ins eigene Feld und nie ins Formular). */
            $werte[$k] = substr($k === 'marstek_url' ? spot_marstek_url_ohne_token((string) $v) : (string) $v, 0, 1000);
        }
    }
    $felder = array();
    foreach ((array) $beanstandet as $k) {
        if (spot_eingaben_name_ok($k) && !in_array($k, $nie, true) && !in_array($k, $felder, true)) { $felder[] = $k; }
    }
    return array('formular' => $formular, 'werte' => $werte, 'felder' => $felder);
}

/** Die Eingaben aus der Einmalmeldung - nur, was die Regeln oben zulassen. */
function spot_eingaben_pruefen($e) {
    $liste = spot_eingaben_formulare();
    if (!is_array($e) || !isset($e['formular']) || !is_string($e['formular']) || !isset($liste[$e['formular']])) {
        return array();
    }
    $f = $e['formular'];
    $nie = array_merge($liste[$f], array('fmt', 'activetab', 'tab', $f));
    $werte = array();
    if (isset($e['werte']) && is_array($e['werte'])) {
        foreach ($e['werte'] as $k => $w) {
            if (!spot_eingaben_name_ok($k) || in_array($k, $nie, true)) { continue; }
            if (substr($k, -2) === '[]') {
                if (is_array($w)) { $werte[$k] = array_values(array_filter($w, 'is_string')); }
            } elseif (is_string($w)) {
                $werte[$k] = $w;
            }
        }
    }
    $felder = array();
    if (isset($e['felder']) && is_array($e['felder'])) {
        foreach ($e['felder'] as $k) {
            if (spot_eingaben_name_ok($k) && !in_array($k, $nie, true)) { $felder[] = $k; }
        }
    }
    return array('formular' => $f, 'werte' => $werte, 'felder' => $felder);
}

/**
 * Die Eingaben in das fertige HTML EINES Formulars einsetzen und die
 * beanstandeten Felder markieren. Gehoeren die Eingaben zu einem anderen
 * Formular (oder gibt es keine), kommt das HTML unveraendert zurueck. Ein
 * Haken, der nicht abgeschickt wurde, war nicht gesetzt.
 */
function spot_eingaben_einsetzen($html, $formular, $e) {
    if (!is_array($e) || !isset($e['formular']) || $e['formular'] !== $formular
        || !isset($e['werte']) || !is_array($e['werte'])) {
        return $html;
    }
    $werte = $e['werte'];
    $felder = (isset($e['felder']) && is_array($e['felder'])) ? $e['felder'] : array();
    $nie = spot_eingaben_formulare();
    $nie = $nie[$formular];
    $attr = function ($tag, $name) {
        return preg_match('/\s' . $name . '="([^"]*)"/', $tag, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
    };
    $marke = function ($tag, $name) use ($felder) {
        if (!in_array($name, $felder, true)) { return $tag; }
        if (preg_match('/\sclass="/', $tag)) {
            $tag = preg_replace('/\sclass="/', ' class="sm-beanstandet ', $tag, 1);
        } else {
            $tag = preg_replace('/^<([a-z]+)\b/', '<$1 class="sm-beanstandet"', $tag, 1);
        }
        return preg_replace('/^<([a-z]+)\b/', '<$1 aria-invalid="true"', $tag, 1);
    };
    $wert_setzen = function ($tag, $wert) {
        $neu = ' value="' . sp_e($wert) . '"';
        if (preg_match('/\svalue="[^"]*"/', $tag, $m, PREG_OFFSET_CAPTURE)) {
            return substr_replace($tag, $neu, $m[0][1], strlen($m[0][0]));
        }
        return substr($tag, 0, -1) . $neu . '>';
    };
    $html = preg_replace_callback('/<input\b[^>]*>/', function ($m) use ($werte, $nie, $attr, $marke, $wert_setzen) {
        $tag = $m[0];
        $name = $attr($tag, 'name');
        if ($name === null || in_array($name, $nie, true)) { return $tag; }
        $typ = strtolower((string) $attr($tag, 'type'));
        if ($typ === '') { $typ = 'text'; }
        if (in_array($typ, array('hidden', 'submit', 'button', 'file', 'password', 'reset', 'image'), true)) {
            return $marke($tag, $name);
        }
        if ($typ === 'checkbox' || $typ === 'radio') {
            $v = $attr($tag, 'value');
            $v = ($v === null) ? 'on' : $v;
            if (substr($name, -2) === '[]') {
                $an = isset($werte[$name]) && is_array($werte[$name]) && in_array($v, $werte[$name], true);
            } elseif ($typ === 'radio') {
                $an = isset($werte[$name]) && $werte[$name] === $v;
            } else {
                $an = isset($werte[$name]);
            }
            $tag = preg_replace('/\schecked(="[^"]*")?(?=[\s>\/])/', '', $tag);
            if ($an) { $tag = rtrim(substr($tag, 0, -1)) . ' checked>'; }
            return $marke($tag, $name);
        }
        if (isset($werte[$name]) && is_string($werte[$name])) { $tag = $wert_setzen($tag, $werte[$name]); }
        return $marke($tag, $name);
    }, $html);
    $html = preg_replace_callback('/(<select\b[^>]*>)(.*?)(<\/select>)/s', function ($m) use ($werte, $nie, $attr, $marke) {
        $name = $attr($m[1], 'name');
        if ($name === null || in_array($name, $nie, true)) { return $m[0]; }
        $innen = $m[2];
        if (isset($werte[$name]) && is_string($werte[$name])) {
            $soll = $werte[$name];
            $innen = preg_replace_callback('/<option\b[^>]*>/', function ($o) use ($soll, $attr) {
                $t = preg_replace('/\sselected(="[^"]*")?(?=[\s>\/])/', '', $o[0]);
                $v = $attr($t, 'value');
                return ($v !== null && $v === $soll) ? rtrim(substr($t, 0, -1)) . ' selected>' : $t;
            }, $innen);
        }
        return $marke($m[1], $name) . $innen . $m[3];
    }, $html);
    $html = preg_replace_callback('/(<textarea\b[^>]*>)(.*?)(<\/textarea>)/s', function ($m) use ($werte, $nie, $attr, $marke) {
        $name = $attr($m[1], 'name');
        if ($name === null || in_array($name, $nie, true)) { return $m[0]; }
        $innen = (isset($werte[$name]) && is_string($werte[$name])) ? sp_e($werte[$name]) : $m[2];
        return $marke($m[1], $name) . $innen . $m[3];
    }, $html);
    // Oben im Formular ein Satz, warum die Felder nicht den gespeicherten Stand zeigen.
    $hinweis = '<div class="sm-warnung">' . sp_e(spot_t('TEXT.EINGABEN_ZURUECK')) . '</div>';
    return preg_replace_callback('/<form\b[^>]*>/', function ($m) use ($hinweis) {
        return $m[0] . "\n" . $hinweis;
    }, $html, 1);
}

/**
 * O1 (Pruefbericht oberflaeche, Befund 1): Ist jede Reiterflaeche ein Kind des
 * Reiterbehaelters? Gemessen am GERENDERTEN HTML: die Tiefe der <div>-Schachtelung
 * am Anfang jeder Flaeche muss gleich sein, und jede Flaeche muss sich schliessen,
 * bevor die naechste beginnt. Bis 1.2.31 lagen "Kostenvergleich" und "Logdateien"
 * in der Flaeche "Test" (ein <div class="sm-breit"> ohne Ende) - leer, ausser im
 * Reiter Test -, und die Pruefzeile meldete einen Haken, weil sie nur die
 * Namen im Quelltext verglich. Rueckgabe array(0|1|2, Klartext).
 */
function spot_flaechen_schachtel($html, array $ids) {
    $html = preg_replace('/<!--.*?-->|<script\b.*?<\/script>/s', '', (string) $html);
    $tiefe = 0;
    $start = array();
    $offen = array();
    $in = array();
    preg_match_all('/<div\b[^>]*>|<\/div>/', $html, $m);
    foreach ($m[0] as $tag) {
        if ($tag === '</div>') {
            $tiefe--;
            foreach ($offen as $id => $t) {
                if ($t === $tiefe) { unset($offen[$id]); }
            }
            continue;
        }
        if (preg_match('/\sid="tab-([a-z0-9_]+)"/', $tag, $i) && in_array($i[1], $ids, true)) {
            if ($offen) { $in[$i[1]] = implode(', ', array_keys($offen)); }
            $start[$i[1]] = $tiefe;
            $offen[$i[1]] = $tiefe;
        }
        $tiefe++;
    }
    if (!$start) {
        return array(2, spot_t('PRUEFTEXT.SCHACHTEL_UNKLAR'));
    }
    if ($in || $offen || count(array_unique($start)) !== 1 || count($start) !== count($ids)) {
        $l = array();
        foreach ($in as $id => $wo) { $l[] = $id . ' in ' . $wo; }
        foreach ($offen as $id => $t) { $l[] = $id; }
        return array(0, sprintf(spot_t('PRUEFTEXT.SCHACHTEL_FEHLT'), $l ? implode('; ', array_unique($l)) : '-'));
    }
    return array(1, sprintf(spot_t('PRUEFTEXT.SCHACHTEL_OK'), count($start)));
}

/* Der Escape-Helfer gehoert in die Bibliothek, nicht in
 * index.php: sonst steht er dem Endpunkt und jedem weiteren
 * Aufrufer nicht zur Verfuegung (Hausform, REGELN_2). */
function sp_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
