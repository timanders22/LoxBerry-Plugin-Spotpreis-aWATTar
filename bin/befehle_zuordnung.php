<?php
/* Spotpreis aWATTar - Zuordnung der Befehlsbruecke (linieneigen; die Bruecke selbst ist byte-gleich mit der
 * Stammfassung Werkzeuge/gemeinsam/befehlsbruecke.php 1.0.1). Gruppe 27 (1.2.39, V3; Entwurf entwurf_gruppe27_aw.txt 3.1,
 * E-E6/E-E7), Bauart Octopus 1.1.27 - Namen wie dort (testpush, nicht ptest; N3).
 *
 * Alle vier Befehle sind harmlos (sprechen, Push-Test, Abruf bestellen) - kein zweiter Haken ('eingreifend' false).
 * Sie laufen ueber DIESELBE Funktion wie der HTTP-Endpunkt (spot_befehl_ausfuehren(), Quelle 'mqtt'): Plugin-Bremse
 * (Ansage 20 s gemeinsam, testpush 10 s, abruf 60 s), Merker, Ansage. abruf ruft NIE selbst ab, sondern bestellt den
 * Abruf beim naechsten Minutenlauf (Merker abruf_bestellt, wie ?refresh=1). Sperre der Bruecke (gleicher Name, gleicher
 * Wert): Vorgabe 60 s fuer die Ansagen und testpush, 300 s fuer abruf (die Gegenstelle ist ein fremder Dienst).
 */
if (!function_exists('spot_befehl_ausfuehren')) {
    $spot_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $spot_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/spot_lib.php';
    } else {
        $spot_kand[] = dirname(__DIR__) . '/webfrontend/html/spot_lib.php';
    }
    foreach ($spot_kand as $spot_k) {
        if (is_file($spot_k)) { require_once $spot_k; break; }
    }
}
return array(
    'fassung' => 1,
    'linie'   => 'spotpreis',
    'plugin'  => 'Spotpreis aWATTar',
    'geladen' => function_exists('spot_config') && function_exists('spot_befehl_ausfuehren')
                 && function_exists('spot_befehl_bruecke') && function_exists('spot_befehle_einstellungen'),
    'einstellungen' => function () {
        return spot_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        spot_log('Befehlsbruecke ' . ($stufe === 'INFO' ? '' : $stufe . ': ') . $text);
    },
    'befehle' => array(
        'ansage'        => array('art' => 'puls', 'eingreifend' => false,
                                 'titel' => 'Spotpreis aWATTar Preisansage (MQTT)', 'kachel' => 'BEFEHL.K_ANSAGE',
                                 'quittung' => 'ausgefuehrt',
                                 'tun' => function ($w, $t) {
                                     return spot_befehl_bruecke(spot_befehl_ausfuehren('say', 'mqtt'));
                                 }),
        'ansage_morgen' => array('art' => 'puls', 'eingreifend' => false,
                                 'titel' => 'Spotpreis aWATTar Ansage Preise morgen (MQTT)', 'kachel' => 'BEFEHL.K_ANSAGE_MORGEN',
                                 'quittung' => 'ausgefuehrt',
                                 'tun' => function ($w, $t) {
                                     return spot_befehl_bruecke(spot_befehl_ausfuehren('saytomorrow', 'mqtt'));
                                 }),
        'testpush'      => array('art' => 'puls', 'eingreifend' => false,
                                 'titel' => 'Spotpreis aWATTar Test-Pushnachricht (MQTT)', 'kachel' => 'BEFEHL.K_TESTPUSH',
                                 'quittung' => 'ausgefuehrt',
                                 'tun' => function ($w, $t) {
                                     return spot_befehl_bruecke(spot_befehl_ausfuehren('ptest', 'mqtt'));
                                 }),
        'abruf'         => array('art' => 'puls', 'eingreifend' => false, 'sperre_s' => 300,
                                 'begruendung' => 'Abruf bei einem fremden Dienst (aWATTar) - hoechstens alle 5 Minuten',
                                 'titel' => 'Spotpreis aWATTar Preise neu abrufen (MQTT)', 'kachel' => 'BEFEHL.K_ABRUF',
                                 'quittung' => 'uebergeben',
                                 'tun' => function ($w, $t) {
                                     return spot_befehl_bruecke(spot_befehl_ausfuehren('refresh', 'mqtt'));
                                 }),
    ),
);
