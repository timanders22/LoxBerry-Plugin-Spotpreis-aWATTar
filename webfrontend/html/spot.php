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
 *                       MIN*/MAX*/AVG = MORGEN, HMIN*/HMAX*/HAVG = HEUTE.
 *                       ACHTUNG: Preise jetzt in ct/kWh als ENDPREIS (inkl. Netzentgelte,
 *                       Abgaben, USt) - das alte Skript lieferte EUR/kWh nur mit USt.
 *                       CUR = aktuelle Stunde, CURB = reiner Boersenanteil,
 *                       NEXT = naechste Stunde, LEVEL 1=guenstig 2=normal 3=teuer,
 *                       WINH/WININ/WINCT = guenstigstes zusammenhaengendes Fenster,
 *                       ANN = Meldefenster (erste 10 min einer aktivierten Stunde).
 *   ?debug=1         -> alle Stundenpreise heute und morgen
 *   ?refresh=1       -> Marktdaten sofort neu abrufen
 *   ?json=1          -> kompletter Zustand als JSON (inkl. aller Stundenwerte)
 *   ?say=1           -> Test: Ansage sofort abspielen
 *   ?saytomorrow=1   -> Test: Ansage "Preise fuer morgen" abspielen
 *   ?ptest=1         -> Test-Pushnachricht ausloesen (setzt PTEST fuer 5 Minuten)
 */

require_once __DIR__ . '/spot_lib.php';

/* ---------- JSON ---------- */
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    $st = spot_state(isset($_GET['refresh']));
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
        $text = 'Ding Dong! Dies ist eine Testansage des Spotpreis-Plugins. Es liegen noch keine Preisdaten vor.';
    }
    $ok = spot_say($text);
    echo 'SAY;OK=' . ($ok ? 1 : 0) . ";TEXT=$text\n";
    exit;
}

/* ---------- Test-Pushnachricht ---------- */
if (isset($_GET['ptest'])) {
    @file_put_contents(spot_tmpdir() . '/ptest', '1');
    spot_log('Test-Pushnachricht angefordert (PTEST=1 fuer 5 Minuten)');
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

printf("SPOT;OK=%d;MINH=%d;MINP=%.3f;MAXH=%d;MAXP=%.3f;AVG=%.3f;HOK=%d;HMINH=%d;HMINP=%.3f;HMAXH=%d;HMAXP=%.3f;HAVG=%.3f;CUR=%.3f;CURB=%.3f;NEXT=%.3f;NEG=%d;RANK=%d;RANKD=%d;LEVEL=%d;WINH=%d;WININ=%d;WINCT=%.3f;ANN=%d;AUDIO=%d;PUSH=%d;PTEST=%d;CO2=%d;CO2MIN=%d;CO2MINH=%d;CO2CLEAN=%d;WPCUR=%.3f;WPNEXT=%.3f;FIX=%.3f;DYNM=%.3f;DIFFM=%.3f;EUROM=%.2f;SHIFTJ=%.2f\n",
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
