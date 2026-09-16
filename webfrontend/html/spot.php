<?php
/**
 * Spotpreis aWATTar - Miniserver-Endpunkt
 *
 * Aufrufe:
 *   (ohne Parameter) -> SPOT;OK=..;MINH=..;MINP=..;MAXH=..;MAXP=..;AVG=..;
 *                       HOK=..;HMINH=..;HMINP=..;HMAXH=..;HMAXP=..;HAVG=..;
 *                       CUR=..;CURB=..;NEXT=..;NEG=..;RANK=..;RANKD=..;LEVEL=..;
 *                       WINH=..;WININ=..;WINCT=..;ANN=..;AUDIO=..;PUSH=..;PTEST=..
 *                       Die ersten Felder sind identisch zum klassischen spotzeit.php:
 *                       MIN.., MAX.. und AVG gelten fuer MORGEN,
 *                       HMIN.., HMAX.. und HAVG fuer HEUTE.
 *                       ACHTUNG: Preise jetzt in ct/kWh als ENDPREIS (inkl. Netzentgelte,
 *                       Abgaben, USt) - das alte Skript lieferte EUR/kWh nur mit USt.
 *                       CUR = aktuelle Stunde, CURB = reiner Boersenanteil,
 *                       NEXT = naechste Stunde, LEVEL 1=guenstig 2=normal 3=teuer,
 *                       WINH/WININ/WINCT = guenstigstes zusammenhaengendes Fenster,
 *                       ANN = Meldefenster (erste 10 min einer aktivierten Stunde).
 *
 *                       Danach die Zeile LEBEN;TS=..;LAUF=.. - das
 *                       Lebenszeichen. TS ist der Zeitpunkt, zu dem der
 *                       Zustand zuletzt WIRKLICH gerechnet wurde, LAUF ein
 *                       Zaehler, der bei 999 umlaeuft. Ohne die beiden kann
 *                       der Miniserver einen toten Cron nicht von ruhigen
 *                       Preisen unterscheiden - ein virtueller Eingang
 *                       behaelt seinen letzten Wert, und in der App sieht
 *                       das aus wie ein normaler Tag.
 *   ?debug=1         -> alle Stundenpreise heute und morgen
 *   ?json=1          -> kompletter Zustand als JSON (inkl. aller Stundenwerte)
 *
 *   Die folgenden Aufrufe LOESEN ETWAS AUS und verlangen deshalb seit
 *   1.2.13 IMMER ein Token (siehe unten):
 *   ?refresh=1       -> Marktdaten sofort neu abrufen
 *   ?say=1           -> Test: Ansage sofort abspielen
 *   ?saytomorrow=1   -> Test: Ansage "Preise fuer morgen" abspielen
 *   ?ptest=1         -> Test-Pushnachricht ausloesen (setzt PTEST fuer 5 Minuten)
 */

require_once __DIR__ . '/spot_lib.php';

/* Der unangemeldete Endpunkt legt nichts an (Regeln/05) - siehe
 * spot_nur_lesen(). Muss vor dem ersten Lesen der Konfiguration stehen. */
spot_nur_lesen(true);

/* ---------------- Token ----------------
 *
 * Dieser Ordner ist bewusst der UNANGEMELDETE Bereich: der Miniserver soll
 * ohne Zugangsdaten lesen koennen. Damit erreicht ihn aber auch jedes andere
 * Geraet im Netz - und der Endpunkt kann mehr als lesen.
 *
 * SEIT 1.2.13 WIRD GETRENNT, und zwar entlang der Frage, ob ein Aufruf
 * etwas AUSLOEST:
 *
 *   lesend    (ohne Parameter, ?debug=1, ?json=1)
 *             Token nur noetig, wenn eines eingerichtet ist. Damit bleibt
 *             jeder bestehende Aufbau unveraendert - ein Pflichttoken haette
 *             ueberall die Werte im Miniserver abreissen lassen, ohne dass
 *             jemand versteht, warum.
 *
 *   ausloesend (?say=1, ?saytomorrow=1, ?ptest=1, ?refresh=1)
 *             Token IMMER noetig. ?say=1 spricht ueber die Lautsprecher der
 *             Wohnung, ?ptest=1 legt eine Datei an, ?refresh=1 stoesst einen
 *             Abruf bei einem fremden Dienst an. Bis 1.2.12 konnte das jedes
 *             Geraet im Netz, ohne jede Huerde.
 *
 * Das kostet die Rueckwaertskompatibilitaet nichts: Loxone ruft die
 * ausloesenden Adressen nicht ab, und die Knoepfe der Plugin-Oberflaeche
 * fuehren das Token ohnehin mit.
 */
$spot_soll = (string) spot_cfg_wert('token', '');

/* is_string ZUERST, dann alles andere.
 *
 * ?token[]=x liefert ein Feld. Ein (string) darauf erzeugt unter PHP 8 die
 * Warnung "Array to string conversion" - und die geht VOR
 * http_response_code() hinaus. Gemessen an 8.4.24: der Statuscode blieb
 * daraufhin auf 200, die Abweisung kam beim Aufrufer als Erfolg an.
 * Unter 7.4 war es unauffaellig; mit Debian 13 waere es der Normalfall. */
$spot_ist = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';

$spot_loest_aus = isset($_GET['say']) || isset($_GET['saytomorrow'])
               || isset($_GET['ptest']) || isset($_GET['refresh']);

function spot_abweisen($grund, $klartext) {
    /* JEDEN Weg protokollieren, auch die Abweisung. Dieser Endpunkt
     * liegt im unangemeldeten Bereich; ein fremdes Geraet kann sich
     * nicht beschweren. Ohne diese Zeile laesst sich "der Miniserver
     * ruft nicht an" nicht von "er ruft an und wird abgewiesen"
     * unterscheiden - und wer im Netz Marken durchprobiert, hinterlaesst
     * keine Spur. Die Marke selbst steht nie im Protokoll: ein
     * Protokoll, das Geheimnisse mitschreibt, verlagert das Problem
     * nur in eine Datei, die laenger lebt. */
    if (function_exists('spot_log')) {
        $spot_von = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '?';
        spot_log('Endpunkt abgewiesen: GRUND=' . $grund . ', Anrufer ' . $spot_von);
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'SPOT;OK=0;GRUND=' . $grund . "\n" . $klartext . "\n";
    exit;
}

/* ---------- Selbstpruefung ?selftest=1&token=... ----------
 *
 * Drei Antworten, Hausform nach Regeln/07:
 *   richtiges Token        HTTP 200  SELFTEST;OK=1;TOKEN=OK
 *   falsches oder keines   HTTP 403  SELFTEST;OK=0;ERR=TOKEN
 *   kein Token eingerichtet HTTP 403 SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET
 * Bis 1.2.26 antwortete ?selftest=1 ohne jede Tokenpruefung mit der
 * ganzen Pruefliste - samt Pfaden des Geraets - und mit falschem Token in
 * der Form des Lesezugriffs (SPOT;OK=0;GRUND=TOKEN). Nach der ersten
 * Zeile folgt bei Erfolg die Pruefliste als Zeile im Hausformat und der
 * Klartext je Punkt; Loxone liest die erste Zeile, ein Mensch den Rest.
 *
 * Der Endpunkt selbst wird NICHT mitgeprueft (spot_selbsttest(false)): das
 * waere ein Aufruf dieser Datei aus dieser Datei heraus.
 *
 * Die Werte sind 1 (in Ordnung), 0 (Befund) und 2 (nicht beurteilt). PFEHL
 * zaehlt nur die Nullen. */
if (isset($_GET['selftest'])) {
    header('Content-Type: text/plain; charset=utf-8');
    if ($spot_soll === '') {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n" . spot_t('ENDPUNKT.SELFTEST_KEIN_TOKEN') . "\n";
        exit;
    }
    if (!hash_equals($spot_soll, $spot_ist)) {
        spot_log('Endpunkt abgewiesen: GRUND=SELFTEST_TOKEN, Anrufer '
            . (isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '?'));
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=TOKEN\n" . spot_t('ENDPUNKT.TOKEN_FALSCH') . "\n";
        exit;
    }
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    $spot_pruef = spot_selbsttest(false);
    $spot_teile = array();
    $spot_fehl = 0;
    $spot_unklar = 0;
    foreach ($spot_pruef as $spot_z) {
        // 'PRUEF.LEBEN' -> 'LEBEN'; der Punkt hat in der Zeile nichts zu suchen.
        $spot_k = str_replace('PRUEF.', '', (string) $spot_z['schluessel']);
        $spot_teile[] = $spot_k . '=' . (int) $spot_z['ok'];
        if ((int) $spot_z['ok'] === 0) { $spot_fehl++; }
        if ((int) $spot_z['ok'] === 2) { $spot_unklar++; }
    }
    echo 'PRUEF;PANZ=' . count($spot_pruef) . ';PFEHL=' . $spot_fehl
        . ';PUNKLAR=' . $spot_unklar . ';' . implode(';', $spot_teile) . "\n";
    foreach ($spot_pruef as $spot_z) {
        echo sprintf("%-22s %s  %s\n", $spot_z['schluessel'],
            (int) $spot_z['ok'] === 1 ? 'ok  ' : ((int) $spot_z['ok'] === 0 ? 'BEFUND' : '-   '),
            $spot_z['text']);
    }
    exit;
}

if ($spot_loest_aus && $spot_soll === '') {
    // Fail closed: wo die Angabe fehlt, wird abgewiesen statt geraten.
    // Und die Meldung sagt, WAS zu tun ist - nicht nur, dass es nicht geht.
    spot_abweisen('TOKEN_NOETIG', spot_t('ENDPUNKT.TOKEN_NOETIG'));
}
if ($spot_soll !== '') {
    // hash_equals statt ==: ein zeichenweiser Vergleich verraet ueber die
    // Antwortzeit, wie viele Zeichen schon stimmen.
    if (!hash_equals($spot_soll, $spot_ist)) {
        spot_abweisen('TOKEN', spot_t('ENDPUNKT.TOKEN_FALSCH'));
    }
}

/* ---------- JSON ---------- */
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    $st = spot_state(isset($_GET['refresh']));
    if (empty($st['ok'])) {
        // Regeln/07: faellt die Quelle ganz aus, 503 ohne Daten.
        http_response_code(503);
        echo json_encode(array('ok' => 0, 'hok' => 0, 'grund' => 'KEINE_PREISE',
                               'ts' => spot_cron_puls(), 'lauf' => spot_lauf_stand())) . "\n";
        exit;
    }
    $st['ann'] = spot_ann_active($st);
    $st['ptest'] = spot_ptest_active();
    echo json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

/* ---------- Test-Ansagen ---------- */
if (isset($_GET['say']) || isset($_GET['saytomorrow'])) {
    $st = spot_state();
    $text = isset($_GET['saytomorrow']) ? spot_tomorrow_text($st) : spot_announce_text($st);
    if ($text === '') {
        // Bis 1.2.12 stand dieser Satz fest auf Deutsch im Quelltext - in
        // einem Plugin, dessen Ansagen seit 1.1.2 aus den Sprachdateien
        // kommen. Wer die Oberflaeche auf Englisch fuehrt, bekam eine
        // deutsche Testansage.
        $text = spot_t('ANSAGE.TEST_LEER');
    }
    $ok = spot_say($text);
    echo 'SAY;OK=' . ($ok ? 1 : 0) . ";TEXT=$text\n";
    exit;
}

/* ---------- Test-Pushnachricht ---------- */
if (isset($_GET['ptest'])) {
    @file_put_contents(spot_tmpdir() . '/ptest', '1');
    spot_log('Test-Pushnachricht angefordert (PTEST=1 fuer 5 Minuten)');
    /* Der Ausloeser meldet SOFORT (Regeln/07) - bis 1.2.26 kam PTEST=1 ueber
     * MQTT erst mit dem naechsten Minutenlauf. Gesendet wird nur, was sich
     * geaendert hat, also im Regelfall genau dieses eine Thema. */
    spot_mqtt_publish(null, false);
    echo "PTEST;OK=1;DAUER=300\nHinweis: Loxone pollt alle 300 s - die Push-Nachricht kommt innerhalb von 5 Minuten,\nsofern der Test-Benachrichtigungsbaustein laut Anleitung (Schritt 4) verdrahtet ist.\n";
    exit;
}

/* ---------- Zustand ---------- */
$st = spot_state(isset($_GET['refresh']));
$cfg = spot_config();

if (isset($_GET['debug'])) {
    printf("DEBUG  Markt: %s  Aufschlaege netto: %.3f ct/kWh  USt: %.1f %%\n",
        strtoupper($st['market']), $st['addon'], $st['vat']);
    printf("Aktuelle Stunde %02d Uhr: %.3f ct (davon Boerse %.3f ct) | Rang %d von %d | Niveau %d\n",
        $st['stunde'], $st['cur'], $st['cur_boerse'], $st['rank'], $st['n'], $st['level']);
    printf("Guenstigstes %d-Stunden-Fenster: ab %02d Uhr (in %d h), Schnitt %.3f ct\n",
        $st['fenster_len'], $st['fenster']['h'], $st['fenster']['in'], $st['fenster']['ct']);
    if ($st['co2_ok']) {
        printf("CO2-Intensitaet: jetzt %d g/kWh | sauberste Stunde %02d Uhr mit %d g | Schnitt %d g\n",
            $st['co2'], $st['co2_minh'], $st['co2_min'], $st['co2_avg']);
    }
    if ($st['wp_on']) {
        printf("Par.-14a-Preissatz (%s): Aufschlaege %.3f ct -> jetzt %.3f ct/kWh\n",
            $st['wp_name'], $st['wp_addon'], $st['wp_cur']);
    }
    printf("Tarifvergleich laufender Monat: dynamisch (lastprofil-gewichtet) %.3f ct <-> fest %.3f ct -> %s %.3f ct/kWh (%.2f EUR)\n",
        $st['dyn_monat'], $st['fix'],
        $st['diff_monat'] >= 0 ? 'dynamisch guenstiger um' : 'fest guenstiger um',
        abs($st['diff_monat']), abs($st['euro_monat']));
    printf("Verschiebe-Potenzial (7 Tage): %.3f ct/kWh Spanne -> rund %.2f EUR im Jahr bei %.1f kWh/Tag\n\n",
        $st['shift_ct'], $st['shift_jahr'], (float) $cfg['shift_kwh']);
    foreach (array('heute' => 'HEUTE', 'morgen' => 'MORGEN') as $k => $label) {
        echo "-- $label --\n";
        if (empty($st[$k]['hours'])) {
            echo "(keine Daten)\n\n";
            continue;
        }
        foreach ($st[$k]['hours'] as $h => $row) {
            printf("%02d Uhr: %7.3f ct/kWh   (Boerse %7.3f ct)\n", $h, $row['ct'], $row['boerse']);
        }
        printf("Min %02d Uhr %.3f | Max %02d Uhr %.3f | Schnitt %.3f\n\n",
            $st[$k]['minh'], $st[$k]['minp'], $st[$k]['maxh'], $st[$k]['maxp'], $st[$k]['avg']);
    }
}

/* FAELLT DIE QUELLE GANZ AUS, antwortet der Endpunkt mit HTTP 503 und
 * ohne Preise (Regeln/07). Loxone behaelt dann die letzten Werte und
 * schaltet den Onlinestatus des Behaelters ab - der Ausfall ist sichtbar.
 * Bis 1.2.26 kam HTTP 200 mit einer vollen Zeile aus Nullen und HOK=0;
 * wer HOK nicht auswertete, las 0 ct als Preis. Das Lebenszeichen steht
 * trotzdem darunter: es ist kein Preis, sondern die Auskunft, dass der
 * Minutenlauf lebt. ?debug=1 bleibt fuer den Menschen bei 200. */
if (empty($st['ok']) && !isset($_GET['debug'])) {
    http_response_code(503);
    echo "SPOT;OK=0;HOK=0;GRUND=KEINE_PREISE\n";
    printf("LEBEN;TS=%d;LAUF=%d\n", spot_cron_puls(), spot_lauf_stand());
    exit;
}

echo spot_zeile($st, $cfg);
