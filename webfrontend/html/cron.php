<?php
/**
 * Spotpreis aWATTar - minutlicher Cron-Lauf (via cron/cron.01min)
 *
 * 1. Zustand aktualisieren (Marktdaten-Cache 15 min, Zustand 5 min).
 * 2. Stuendliche Ansage und Meldung "Preise fuer morgen sind da".
 * 3. MQTT bei Aenderung, mindestens stuendlich.
 * 4. Tages-Statistik fortschreiben.
 */

require_once __DIR__ . '/spot_lib.php';

$st = spot_state();
spot_announce_check();
spot_marstek_control($st); // nur aktiv, wenn in den Einstellungen eingeschaltet

// Monatsbericht am Monatsersten um 8:05: Vergleich fest <-> dynamisch
if ((int) date('j') === 1 && date('H:i') === '08:05') {
    $mc = spot_month_compare(2);
    array_shift($mc); // laufender Monat -> wir wollen den abgeschlossenen Vormonat
    $vm = $mc ? reset($mc) : null;
    if ($vm) {
        spot_log('MONATSBERICHT ' . $vm['monat'] . ': dynamisch (gewichtet) ' . $vm['dynp'] . ' ct, fest '
            . $vm['fix'] . ' ct -> ' . ($vm['diff'] >= 0 ? 'dynamisch waere guenstiger gewesen um ' : 'fester Tarif war guenstiger um ')
            . abs($vm['diff']) . ' ct/kWh (' . abs($vm['euro']) . ' EUR)');
        $cfgm = spot_config();
        if (!empty($cfgm['notify']['audio'])) {
            $t = 'Ding Dong! Monatsbericht Strompreis. Der dynamische Tarif lag im letzten Monat bei '
               . spot_num($vm['dynp'], 1) . ' Cent pro Kilowattstunde, dein fester Tarif bei ' . spot_num($vm['fix'], 1) . ' Cent. '
               . ($vm['diff'] >= 0 ? 'Der dynamische Tarif w' . "\u{00e4}" . 're um ' . spot_num(abs($vm['diff']), 1) . ' Cent g' . "\u{00fc}" . 'nstiger gewesen.'
                                    : 'Dein fester Tarif war um ' . spot_num(abs($vm['diff']), 1) . ' Cent g' . "\u{00fc}" . 'nstiger.');
            spot_say($t);
        }
    }
}

$sig = json_encode(array($st['cur'], $st['rank'], $st['level'], $st['tomorrow_ok'],
                         $st['heute']['avg'], $st['morgen']['avg'], $st['fenster'], $st['co2']));
$sigf = spot_tmpdir() . '/mqtt_sig.txt';
$beat = spot_tmpdir() . '/mqtt_beat';
$old = is_file($sigf) ? (string) file_get_contents($sigf) : '';
if ($sig !== $old || !is_file($beat) || time() - filemtime($beat) > 1800) {
    spot_mqtt_publish($st);
    @file_put_contents($sigf, $sig);
    @touch($beat);
}

if ((int) date('G') === 23 && (int) date('i') >= 50) {
    spot_history_add($st); // Tageswerte kurz vor Mitternacht sichern
}
echo "OK\n";
