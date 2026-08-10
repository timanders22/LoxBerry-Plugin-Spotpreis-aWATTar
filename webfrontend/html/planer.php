<?php
/**
 * Fahrplaner fuer preisgesteuerte Verbraucher
 *
 * DIESE DATEI IST IN MEHREREN PLUGINS BYTEWEISE GLEICH.
 * Sie liegt derzeit in LoxBerry-Plugin-Spotpreis-aWATTar und in
 * LoxBerry-Plugin-Octopus. Wer sie aendert, aendert sie in beiden - und
 * prueft danach mit md5sum ueber beide Ablageorte, dass die Pruefsumme
 * wieder uebereinstimmt. Der Reiter Test zeigt sie an.
 *
 * Deshalb traegt sie das neutrale Kuerzel 'plan_' statt des Plugin-Kuerzels.
 * Das ist die einzige Ausnahme von der Regel "Funktionen tragen das Kuerzel
 * der Bibliothek", und sie ist bewusst gemacht: zwei auseinanderlaufende
 * Kopien derselben Rechnung waeren schlimmer als ein zweites Kuerzel. Die
 * beiden Plugins laufen nie im selben Prozess; Namenskollisionen kann es
 * also nicht geben.
 *
 * ------------------------------------------------------------------
 * Was der Planer kann - und warum
 * ------------------------------------------------------------------
 *
 * Die Schaltregeln der beiden Plugins beantworteten bisher jede fuer sich
 * die Frage "laeuft es gerade guenstig?". Drei Dinge fehlten, und alle drei
 * treten erst auf, wenn mehr als ein Geraet mitspielt:
 *
 *   1. FRIST.  "Die Waschmaschine braucht 2,5 h und muss um 7 Uhr fertig
 *      sein" liess sich nicht ausdruecken. Es gab ein Zeitfenster und einen
 *      Horizont, aber keinen Endtermin und keine Energiemenge.
 *
 *   2. RANGFOLGE UND LEISTUNGSBUDGET.  Jede Regel rechnete fuer sich.
 *      Waermepumpe, Wallbox und Waschmaschine fanden dieselbe guenstigste
 *      Stunde und schalteten gleichzeitig. Niemand hielt ein Budget.
 *
 *   3. PV-PROGNOSE UND SPEICHERSTAND.  Der Preis allein sagt nicht, ob es
 *      sich lohnt: wer nachts auf Vorrat laedt, obwohl morgen die Sonne
 *      scheint, zahlt zweimal.
 *
 * ------------------------------------------------------------------
 * Der Ablauf in vier Schritten
 * ------------------------------------------------------------------
 *
 *   1. Aus dem Preis je Zeitscheibe wird ein EFFEKTIVPREIS: liegt fuer die
 *      Scheibe eine PV-Prognose vor, wird eine Gutschrift abgezogen. Damit
 *      gewinnt die sonnige Mittagsstunde gegen die billige Nachtstunde,
 *      ohne dass jemand Schwellen von Hand pflegt.
 *
 *   2. Die Regeln werden nach RANG sortiert. Rang 1 waehlt zuerst.
 *
 *   3. Jede Regel waehlt ihre Zeitscheiben aus dem, was Frist, Zeitfenster,
 *      Horizont UND das noch freie Leistungsbudget uebrig lassen. Was eine
 *      hoeher gereihte Regel belegt hat, steht nicht mehr zur Verfuegung.
 *
 *   4. Die gewaehlten Scheiben werden gebucht, dann kommt die naechste Regel.
 *
 * Das ist ein gieriges Verfahren, kein optimales. Es ist dafuer in einem
 * Satz erklaerbar - "wer vorne steht, sucht sich zuerst aus" - und genau
 * das braucht jemand, der um drei Uhr nachts wissen will, warum die
 * Wallbox nicht laedt. Ein Verfahren, dessen Ergebnis niemand nachvollzieht,
 * waere im Hausgebrauch schlechter, auch wenn es ein paar Cent findet.
 *
 * ------------------------------------------------------------------
 * Einheiten - einmal festgelegt, damit nichts durcheinandergeraet
 * ------------------------------------------------------------------
 *
 *   Zeitscheibe   $slotlen Sekunden (3600 bei aWATTar, 900 bei Octopus)
 *   Preis         ct/kWh, brutto, so wie ihn das aufrufende Plugin liefert
 *   Leistung      kW
 *   Energie       kWh
 *   PV-Prognose   Wh JE ZEITSCHEIBE
 *   Speicherstand Prozent
 *
 * Alles hier ist reine Rechnung: kein Netz, keine Dateien, keine Uhr ausser
 * dem uebergebenen Zeitpunkt. Deshalb laesst sich der Planer vollstaendig
 * durchpruefen - plan_selbsttest() rechnet 30 Faelle nach.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

/** Fassung dieser Datei. Steht in beiden Plugins im Reiter Test. */
define('PLAN_FASSUNG', '1.0.0');

/* ==================================================================
 * Vorgaben
 * ================================================================== */

/**
 * Die Felder, die eine Regel ZUSAETZLICH zu den bisherigen bekommt.
 * Das aufrufende Plugin mischt sie in seine eigene Regelvorgabe.
 *
 * Jede Vorgabe ist so gewaehlt, dass sich fuer eine bestehende Regel
 * NICHTS aendert: Rang 50 fuer alle, kein Leistungsanteil, keine Frist,
 * keine Sperre. Wer nichts einstellt, bekommt das Verhalten von vorher.
 */
function plan_regel_vorgabe()
{
    return array(
        'rang'      => 50,    // 1 = waehlt zuerst, 99 = zuletzt
        'leistung'  => 0.0,   // kW; 0 = nimmt am Leistungsbudget nicht teil
        'energie'   => 0.0,   // kWh; 0 = Anzahl kommt weiter aus 'n'
        'frist'     => -1,    // Stunde, bis zu der es fertig sein muss; -1 = keine
        'pv_sperre' => 0.0,   // kWh Tagesprognose, ab der die Regel schweigt; 0 = aus
        'soc_min'   => 0,     // Prozent; Regel erst ab diesem Speicherstand
        'soc_max'   => 0,     // Prozent; Regel nur bis zu diesem Stand; 0 = aus
    );
}

/** Die Felder, die die Plugin-Konfiguration zusaetzlich bekommt. */
function plan_global_vorgabe()
{
    return array(
        'budget_kw'    => 0.0,   // gleichzeitige Leistung; 0 = kein Budget
        'pv_bonus'     => 0.0,   // ct/kWh Gutschrift bei voller PV-Scheibe; 0 = aus
        'pv_schwelle'  => 500,   // Wh je Zeitscheibe fuer die volle Gutschrift
    );
}

/* ==================================================================
 * Kleine Helfer
 * ================================================================== */

/** Liegt die Stunde $h im Zeitfenster? von == bis bedeutet: ganzer Tag. */
function plan_in_zeitfenster($h, $von, $bis)
{
    $h = (int) $h; $von = (int) $von; $bis = (int) $bis;
    if ($von === $bis) { return true; }
    if ($von < $bis) { return $h >= $von && $h < $bis; }
    return $h >= $von || $h < $bis;   // ueber Mitternacht, z. B. 22 bis 6
}

/**
 * Wie viele Zeitscheiben ergeben eine Stunde?
 * Bei stuendlichen Preisen 1, bei Viertelstunden 4.
 */
function plan_pro_stunde($slotlen)
{
    $slotlen = max(1, (int) $slotlen);
    return $slotlen >= 3600 ? 1 : (int) max(1, round(3600 / $slotlen));
}

/**
 * Der Zeitpunkt, zu dem eine Frist ablaeuft.
 *
 * Die Frist ist eine Uhrzeit, kein Datum: "bis 7 Uhr" heisst das naechste
 * Mal, dass es 7 Uhr ist. Ist es jetzt 5 Uhr, ist das heute; ist es 9 Uhr,
 * ist es morgen. Genau so denkt jemand, der die Waschmaschine abends
 * einstellt.
 *
 * Rueckgabe: Zeitstempel, oder 0 wenn keine Frist gesetzt ist.
 */
function plan_frist_ende($jetzt, $frist)
{
    $frist = (int) $frist;
    if ($frist < 0 || $frist > 23) { return 0; }
    $tagesbeginn = (int) mktime(0, 0, 0, (int) date('n', $jetzt), (int) date('j', $jetzt),
                                (int) date('Y', $jetzt));
    $ziel = $tagesbeginn + $frist * 3600;
    if ($ziel <= $jetzt) {
        // Schon vorbei - dann ist morgen gemeint. Ueber die Datumsfunktion
        // gerechnet und nicht mit +86400: an den beiden Umstellungstagen hat
        // ein Tag 23 oder 25 Stunden.
        $ziel = (int) mktime($frist, 0, 0, (int) date('n', $jetzt),
                             (int) date('j', $jetzt) + 1, (int) date('Y', $jetzt));
    }
    return $ziel;
}

/**
 * Wie viele Zeitscheiben braucht die Regel?
 *
 * Steht eine Energiemenge und eine Leistung, ergibt sich die Zahl daraus -
 * das ist die Angabe, die ein Mensch kennt ("7 kWh, 3,7 kW"). Sonst bleibt
 * es bei der bisherigen Angabe in Stunden.
 *
 * Aufgerundet wird immer: eine halbe Zeitscheibe gibt es nicht, und zu kurz
 * laden ist schlechter als eine Scheibe zu viel.
 */
function plan_slots_noetig($r, $slotlen)
{
    $pro = plan_pro_stunde($slotlen);
    $energie = isset($r['energie']) ? (float) $r['energie'] : 0.0;
    $leistung = isset($r['leistung']) ? (float) $r['leistung'] : 0.0;
    if ($energie > 0 && $leistung > 0) {
        $stunden = $energie / $leistung;
        return max(1, (int) ceil($stunden * $pro));
    }
    return max(1, (int) $r['n']) * $pro;
}

/* ==================================================================
 * Effektivpreis
 * ================================================================== */

/**
 * Preis je Zeitscheibe abzueglich PV-Gutschrift.
 *
 * Die Gutschrift steigt linear bis zur Schwelle und bleibt dann stehen:
 *
 *     Gutschrift = pv_bonus * min(1, Wh_der_Scheibe / pv_schwelle)
 *
 * Eine reine Ja/Nein-Schwelle waere eine Klippe - eine Scheibe mit 499 Wh
 * bekaeme nichts, eine mit 501 Wh alles, und der Fahrplan spraenge bei
 * einer minimal geaenderten Prognose um Stunden. Der lineare Anstieg
 * vermeidet das und bleibt in einer Zeile erklaerbar.
 *
 * Der Effektivpreis darf negativ werden. Das ist gewollt: eine Stunde mit
 * viel Eigenstrom ist tatsaechlich billiger als der Boersenpreis sagt.
 *
 * Rueckgabe: array(ts => Effektivpreis)
 */
function plan_effektivpreise($preise, $pv, $bonus, $schwelle)
{
    $bonus = (float) $bonus;
    $schwelle = max(1.0, (float) $schwelle);
    $out = array();
    foreach ($preise as $ts => $ct) {
        $eff = (float) $ct;
        if ($bonus > 0 && is_array($pv) && isset($pv[$ts]) && (float) $pv[$ts] > 0) {
            $anteil = min(1.0, (float) $pv[$ts] / $schwelle);
            $eff -= $bonus * $anteil;
        }
        $out[$ts] = round($eff, 4);
    }
    return $out;
}

/* ==================================================================
 * Auswahl je Regel
 * ================================================================== */

/**
 * Die Zeitscheiben, die fuer eine Regel ueberhaupt in Frage kommen.
 *
 * Vier Filter, in dieser Reihenfolge:
 *   1. Vergangenheit               - was vorbei ist, laesst sich nicht schalten
 *   2. Horizont oder Frist         - die Frist gewinnt, wenn sie frueher endet
 *   3. Zeitfenster (Uhrzeit)       - "nur zwischen 22 und 6"
 *   4. freies Leistungsbudget      - was hoeher gereihte Regeln belegt haben,
 *                                    ist weg
 *
 * Rueckgabe: array(ts => Effektivpreis)
 */
function plan_kandidaten($r, $eff, $jetzt, $slotlen, $belegt, $budget)
{
    $ende = $jetzt + max(1, (int) $r['horizont']) * 3600;
    $frist = plan_frist_ende($jetzt, isset($r['frist']) ? $r['frist'] : -1);
    if ($frist > 0 && $frist < $ende) { $ende = $frist; }

    $leistung = isset($r['leistung']) ? (float) $r['leistung'] : 0.0;
    $budget = (float) $budget;

    $out = array();
    foreach ($eff as $ts => $ct) {
        if ($ts < $jetzt || $ts >= $ende) { continue; }
        if (!plan_in_zeitfenster((int) date('G', $ts), $r['von'], $r['bis'])) { continue; }
        if ($budget > 0 && $leistung > 0) {
            $schon = isset($belegt[$ts]) ? (float) $belegt[$ts] : 0.0;
            // Rundung auf vier Stellen, damit 3.7 + 3.7 <= 7.4 nicht an der
            // Gleitkommadarstellung scheitert.
            if (round($schon + $leistung, 4) > round($budget, 4)) { continue; }
        }
        $out[$ts] = (float) $ct;
    }
    ksort($out);
    return $out;
}

/**
 * Aus den Kandidaten die Treffer waehlen.
 *
 * Vier Arten, wie bisher:
 *   fenster   die guenstigsten $anzahl Scheiben AM STUECK
 *   stunden   die guenstigsten VOLLEN Stunden, ueber den Tag verstreut
 *   schwelle  alles unter einem festen Preis
 *   mittel    alles X Prozent unter dem Tagesmittel
 *
 * Bei 'stunden' wird bewusst auf volle Stunden gemittelt statt die
 * guenstigsten Viertelstunden zu picken - sonst schaltet die Wallbox im
 * Viertelstundentakt an und aus. Bei stuendlichen Preisen ist das
 * dieselbe Rechnung, nur mit Bloecken der Laenge eins.
 *
 * Rueckgabe: sortierte Liste von Zeitstempeln.
 */
function plan_waehlen($r, $kand, $slotlen, $anzahl, $mittel)
{
    $art = isset($r['art']) ? (string) $r['art'] : 'fenster';
    $ks = array_keys($kand);
    $treffer = array();

    if ($art === 'fenster') {
        $len = min(max(1, (int) $anzahl), count($ks));
        $best = null;
        for ($i = 0; $len > 0 && $i + $len <= count($ks); $i++) {
            // Zusammenhaengend heisst luekenlos: ueber eine fehlende Scheibe
            // hinweg wird nicht geklebt, sonst stuende die Wallbox mittendrin.
            if ($ks[$i + $len - 1] - $ks[$i] !== ($len - 1) * $slotlen) { continue; }
            $s = 0.0;
            for ($j = 0; $j < $len; $j++) { $s += $kand[$ks[$i + $j]]; }
            if ($best === null || $s / $len < $best[1]) { $best = array($i, $s / $len); }
        }
        if ($best !== null) {
            for ($j = 0; $j < $len; $j++) { $treffer[] = $ks[$best[0] + $j]; }
        }

    } elseif ($art === 'stunden') {
        $pro = plan_pro_stunde($slotlen);
        $bloecke = array();
        foreach ($kand as $ts => $ct) {
            $h = $ts - ($ts % 3600);
            if (!isset($bloecke[$h])) { $bloecke[$h] = array(0.0, 0); }
            $bloecke[$h][0] += $ct;
            $bloecke[$h][1]++;
        }
        $mittelwerte = array();
        foreach ($bloecke as $h => $v) {
            // Angebrochene Stunden nicht bewerten - sie waeren kuenstlich
            // guenstig oder teuer, je nachdem welche Viertel fehlen.
            if ($v[1] === $pro) { $mittelwerte[$h] = $v[0] / $pro; }
        }
        asort($mittelwerte);
        $wieviele = max(1, (int) ceil($anzahl / $pro));
        $gewaehlt = array_slice(array_keys($mittelwerte), 0, $wieviele);
        foreach ($kand as $ts => $ct) {
            if (in_array($ts - ($ts % 3600), $gewaehlt, true)) { $treffer[] = $ts; }
        }

    } else {
        if ($art === 'schwelle') {
            $grenze = (float) $r['schwelle'];
        } else {
            $m = (float) $mittel;
            if ($m <= 0 && $kand) { $m = array_sum($kand) / count($kand); }
            $grenze = round($m * (1 - max(0, min(90, (int) $r['prozent'])) / 100), 3);
        }
        foreach ($kand as $ts => $ct) {
            if ($ct <= $grenze) { $treffer[] = $ts; }
        }
    }

    sort($treffer);
    return $treffer;
}

/**
 * Sperrt eine Umweltbedingung diese Regel?
 * Rueckgabe: '' wenn nicht, sonst der Grund als Kuerzel.
 */
function plan_gesperrt($r, $umwelt)
{
    $pvs = isset($r['pv_sperre']) ? (float) $r['pv_sperre'] : 0.0;
    if ($pvs > 0 && isset($umwelt['pv_summe']) && $umwelt['pv_summe'] !== null
        && (float) $umwelt['pv_summe'] >= $pvs) {
        // Morgen kommt genug vom Dach - heute nacht nicht auf Vorrat laden.
        return 'pv';
    }
    $soc = isset($umwelt['soc']) ? $umwelt['soc'] : null;
    if ($soc !== null) {
        $min = isset($r['soc_min']) ? (int) $r['soc_min'] : 0;
        $max = isset($r['soc_max']) ? (int) $r['soc_max'] : 0;
        if ($min > 0 && (float) $soc < $min) { return 'soc_min'; }
        if ($max > 0 && (float) $soc > $max) { return 'soc_max'; }
    }
    return '';
}

/* ==================================================================
 * Fremde Auskuenfte: PV-Prognose und Speicherstand
 *
 * BEWUSST NUR AUSWERTUNG, KEIN ABRUF. Das Holen macht das aufrufende
 * Plugin mit seiner eigenen HTTP-Funktion - die kennt schon Zeitgrenzen,
 * Kopfzeilen und Fehlermeldungen. Hier steht nur, wie man aus der Antwort
 * Zahlen macht. So bleibt der Planer ohne Netz vollstaendig pruefbar.
 *
 * Drei Formen, weil es keine gemeinsame Sprache fuer PV-Prognosen gibt:
 *
 *   forecast_solar  der feste Pfad result.watt_hours_period, Werte in Wh
 *                   je Zeitabschnitt. forecast.solar ist kostenlos und
 *                   ohne Konto benutzbar - deshalb als Vorgabe.
 *   objekt          ein frei angegebener Pfad auf ein Objekt, dessen
 *                   SCHLUESSEL Zeitangaben sind und dessen Werte Zahlen.
 *   liste           ein frei angegebener Pfad auf eine Liste von Objekten,
 *                   dazu der Name des Zeit- und des Wertfeldes. Das ist die
 *                   Form von Solcast und den meisten anderen.
 * ================================================================== */

/** Einen Punktpfad in einem verschachtelten Feld aufloesen. */
function plan_pfad($daten, $pfad)
{
    $pfad = trim((string) $pfad);
    if ($pfad === '') { return $daten; }
    foreach (explode('.', $pfad) as $teil) {
        if (!is_array($daten) || !isset($daten[$teil])) { return null; }
        $daten = $daten[$teil];
    }
    return $daten;
}

/**
 * Einen Wert in Wh je Zeitscheibe umrechnen.
 *
 * 'wh' ist die Energie der Scheibe selbst. 'w' und 'kw' sind eine
 * mittlere Leistung - daraus wird die Energie erst mit der Scheibenlaenge.
 * Wer das verwechselt, bekommt bei Viertelstunden den vierfachen Wert,
 * und die PV-Gutschrift greift viel zu frueh.
 */
function plan_nach_wh($wert, $einheit, $slotlen)
{
    $w = (float) $wert;
    $std = max(1, (int) $slotlen) / 3600.0;
    if ($einheit === 'kw') { return $w * 1000.0 * $std; }
    if ($einheit === 'w')  { return $w * $std; }
    return $w;   // 'wh'
}

/**
 * Eine PV-Prognose auswerten.
 *
 * Rueckgabe: array(ts => Wh), auf die Zeitscheiben gerundet. Liefert die
 * Quelle feinere Abschnitte als eine Zeitscheibe, werden sie aufaddiert;
 * liefert sie groebere, steht der Wert nur in der ersten Scheibe. Beides
 * ist besser als eine erfundene Verteilung - und beides steht so in der
 * Oberflaeche.
 *
 * Rueckgabe: array(Werte, Meldung). Bei einem Fehler ist Werte leer und
 * die Meldung sagt, woran es lag.
 */
function plan_pv_lesen($daten, $art, $pfad, $zeitfeld, $wertfeld, $einheit, $slotlen)
{
    if (!is_array($daten)) {
        return array(array(), 'KEINE_ANTWORT');
    }
    $slotlen = max(1, (int) $slotlen);
    $paare = array();

    if ($art === 'forecast_solar') {
        $k = plan_pfad($daten, 'result.watt_hours_period');
        if (!is_array($k)) { return array(array(), 'PFAD_LEER'); }
        foreach ($k as $zeit => $wert) { $paare[] = array($zeit, $wert); }
        $einheit = 'wh';

    } elseif ($art === 'objekt') {
        $k = plan_pfad($daten, $pfad);
        if (!is_array($k)) { return array(array(), 'PFAD_LEER'); }
        foreach ($k as $zeit => $wert) { $paare[] = array($zeit, $wert); }

    } elseif ($art === 'liste') {
        $k = plan_pfad($daten, $pfad);
        if (!is_array($k)) { return array(array(), 'PFAD_LEER'); }
        $zf = trim((string) $zeitfeld);
        $wf = trim((string) $wertfeld);
        if ($zf === '' || $wf === '') { return array(array(), 'FELDNAMEN_FEHLEN'); }
        foreach ($k as $eintrag) {
            if (!is_array($eintrag) || !isset($eintrag[$zf], $eintrag[$wf])) { continue; }
            $paare[] = array($eintrag[$zf], $eintrag[$wf]);
        }

    } else {
        return array(array(), 'KEINE_QUELLE');
    }

    $out = array();
    $unlesbar = 0;
    foreach ($paare as $p) {
        $ts = is_numeric($p[0]) ? (int) $p[0] : strtotime((string) $p[0]);
        if ($ts === false || $ts <= 0 || !is_numeric($p[1])) { $unlesbar++; continue; }
        $scheibe = $ts - ($ts % $slotlen);
        $wh = plan_nach_wh($p[1], $einheit, $slotlen);
        $out[$scheibe] = (isset($out[$scheibe]) ? $out[$scheibe] : 0.0) + $wh;
    }
    ksort($out);
    if (!$out) {
        return array(array(), $unlesbar > 0 ? 'ZEITEN_UNLESBAR' : 'PFAD_LEER');
    }
    return array($out, '');
}

/** Summe der Prognose ueber die naechsten $stunden Stunden, in kWh. */
function plan_pv_summe($pv, $jetzt, $stunden = 24)
{
    if (!is_array($pv) || !$pv) { return null; }
    $ende = (int) $jetzt + max(1, (int) $stunden) * 3600;
    $s = 0.0;
    foreach ($pv as $ts => $wh) {
        if ($ts >= $jetzt && $ts < $ende) { $s += (float) $wh; }
    }
    return round($s / 1000.0, 3);
}

/**
 * Einen Speicherstand auswerten.
 * Rueckgabe: array(Prozent|null, Meldung).
 */
function plan_soc_lesen($daten, $pfad)
{
    if (!is_array($daten)) { return array(null, 'KEINE_ANTWORT'); }
    $v = plan_pfad($daten, $pfad);
    if ($v === null || is_array($v) || !is_numeric($v)) { return array(null, 'PFAD_LEER'); }
    $p = (float) $v;
    if ($p < 0 || $p > 100) {
        // Eine Zahl ausserhalb 0 bis 100 ist kein Prozentwert. Sie
        // durchzulassen hiesse, jede Sperre unbrauchbar zu machen.
        return array(null, 'AUSSERHALB');
    }
    return array(round($p, 1), '');
}

/* ==================================================================
 * Der Fahrplan
 * ================================================================== */

/**
 * Alle Regeln in Rangfolge planen.
 *
 * $preise   array(ts => ct/kWh), aufsteigend
 * $slotlen  Sekunden je Zeitscheibe
 * $jetzt    Beginn der laufenden Zeitscheibe
 * $regeln   Liste der Regeln (mit den Feldern beider Vorgaben)
 * $umwelt   array('pv' => array(ts=>Wh)|null, 'pv_summe' => kWh|null,
 *                 'soc' => Prozent|null, 'neg' => 0/1, 'mittel' => ct)
 * $g        array('budget_kw','pv_bonus','pv_schwelle')
 *
 * Rueckgabe je Regel: nr, aktiv, in, rest, ct, start, startmin, grund,
 * slots, anzahl, verdraengt, gesperrt, rang, leistung.
 *
 * 'in' und 'rest' zaehlen in MINUTEN, damit dieselbe Rechnung fuer
 * stuendliche und viertelstuendliche Preise passt. Das aufrufende Plugin
 * rechnet um, wenn es Stunden ausgeben will.
 */
function plan_rechnen($preise, $slotlen, $jetzt, $regeln, $umwelt, $g)
{
    $slotlen = max(1, (int) $slotlen);
    $budget = isset($g['budget_kw']) ? (float) $g['budget_kw'] : 0.0;
    $eff = plan_effektivpreise($preise,
        isset($umwelt['pv']) ? $umwelt['pv'] : null,
        isset($g['pv_bonus']) ? $g['pv_bonus'] : 0,
        isset($g['pv_schwelle']) ? $g['pv_schwelle'] : 500);
    $mittel = isset($umwelt['mittel']) ? (float) $umwelt['mittel'] : 0.0;
    $neg = !empty($umwelt['neg']);

    /* Reihenfolge: erst nach Rang, bei gleichem Rang nach der Nummer.
     * Der zweite Teil ist wichtig - ohne ihn haengt das Ergebnis bei
     * gleichem Rang von der Sortierfunktion ab und kann sich zwischen zwei
     * PHP-Fassungen aendern. Ein Fahrplan, der ohne Zutun anders aussieht,
     * ist nicht nachvollziehbar. */
    $reihe = array();
    foreach ($regeln as $i => $r) {
        $reihe[] = array('i' => $i, 'rang' => isset($r['rang']) ? (int) $r['rang'] : 50);
    }
    usort($reihe, function ($a, $b) {
        if ($a['rang'] !== $b['rang']) { return $a['rang'] < $b['rang'] ? -1 : 1; }
        return $a['i'] < $b['i'] ? -1 : ($a['i'] > $b['i'] ? 1 : 0);
    });

    $belegt = array();
    $erg = array();

    foreach ($reihe as $z) {
        $i = $z['i'];
        $r = $regeln[$i];
        $leistung = isset($r['leistung']) ? (float) $r['leistung'] : 0.0;

        $e = array(
            'nr' => $i + 1, 'aktiv' => 0, 'in' => -1, 'rest' => 0, 'ct' => 0.0,
            'start' => -1, 'startmin' => 0, 'grund' => 'aus', 'slots' => array(),
            'anzahl' => 0, 'verdraengt' => 0, 'gesperrt' => '',
            'rang' => isset($r['rang']) ? (int) $r['rang'] : 50, 'leistung' => $leistung,
        );

        if (empty($r['aktiv'])) { $erg[$i] = $e; continue; }

        $sperre = plan_gesperrt($r, $umwelt);
        if ($sperre !== '') {
            $e['gesperrt'] = $sperre;
            $e['grund'] = 'gesperrt';
            $erg[$i] = $e;
            continue;
        }

        $noetig = plan_slots_noetig($r, $slotlen);

        /* Wie viele Scheiben faenden ohne Budget Platz? Die Differenz zu dem,
         * was uebrig bleibt, ist die Verdraengung - sie steht in der
         * Oberflaeche und beantwortet die Frage "warum laedt es nicht?". */
        $ohne = plan_kandidaten($r, $eff, $jetzt, $slotlen, array(), 0);
        $mit  = plan_kandidaten($r, $eff, $jetzt, $slotlen, $belegt, $budget);
        $e['verdraengt'] = max(0, count($ohne) - count($mit));

        $treffer = plan_waehlen($r, $mit, $slotlen, $noetig, $mittel);

        if ($treffer) {
            $e['slots'] = $treffer;
            $e['anzahl'] = count($treffer);
            $summe = 0.0;
            foreach ($treffer as $ts) { $summe += isset($preise[$ts]) ? (float) $preise[$ts] : 0.0; }
            $e['ct'] = round($summe / count($treffer), 3);
            $e['aktiv'] = in_array($jetzt, $treffer, true) ? 1 : 0;
            foreach ($treffer as $ts) {
                if ($ts >= $jetzt) {
                    $e['start'] = (int) date('G', $ts);
                    $e['startmin'] = (int) date('i', $ts);
                    $e['in'] = (int) round(($ts - $jetzt) / 60);
                    break;
                }
            }
            if ($e['aktiv']) {
                $rest = 0;
                for ($ts = $jetzt; in_array($ts, $treffer, true); $ts += $slotlen) {
                    $rest += (int) round($slotlen / 60);
                }
                $e['rest'] = $rest;
            }
            $e['grund'] = $e['aktiv'] ? (string) $r['art'] : 'wartet';

            // Buchen - erst jetzt, und nur was wirklich gewaehlt wurde.
            if ($leistung > 0) {
                foreach ($treffer as $ts) {
                    $belegt[$ts] = (isset($belegt[$ts]) ? (float) $belegt[$ts] : 0.0) + $leistung;
                }
            }
        } elseif ($e['verdraengt'] > 0) {
            // Nichts gefunden, aber es waere etwas dagewesen: das war das
            // Budget. Das gehoert unterschieden von "es gibt keine Stunde".
            $e['grund'] = 'budget';
        }

        /* Negativer Preis sticht - wer dann nicht laedt, verschenkt Geld.
         * ABER: das Budget sticht zurueck. Sonst waere die Verdraengung bei
         * negativem Preis wirkungslos, und genau dann laufen alle Geraete
         * gleichzeitig los. Deshalb greift die Ausnahme nur, wenn fuer die
         * laufende Scheibe noch Leistung frei ist. */
        if (!empty($r['neg']) && $neg) {
            $schon = isset($belegt[$jetzt]) ? (float) $belegt[$jetzt] : 0.0;
            $passt = ($budget <= 0 || $leistung <= 0
                      || round($schon + ($e['aktiv'] ? 0 : $leistung), 4) <= round($budget, 4));
            if ($passt) {
                if (!$e['aktiv'] && $leistung > 0) {
                    $belegt[$jetzt] = $schon + $leistung;
                }
                $e['aktiv'] = 1;
                $e['in'] = 0;
                $e['rest'] = max((int) round($slotlen / 60), (int) $e['rest']);
                $e['grund'] = 'negativ';
            }
        }

        $erg[$i] = $e;
    }

    ksort($erg);
    return array_values($erg);
}

/**
 * Die Belegung je Zeitscheibe aus einem fertigen Fahrplan.
 * Fuer die Anzeige: eine Zeile je Scheibe, wer wann wie viel zieht.
 */
function plan_belegung($fahrplan)
{
    $out = array();
    foreach ($fahrplan as $e) {
        if ((float) $e['leistung'] <= 0) { continue; }
        foreach ($e['slots'] as $ts) {
            if (!isset($out[$ts])) { $out[$ts] = 0.0; }
            $out[$ts] = round($out[$ts] + (float) $e['leistung'], 4);
        }
    }
    ksort($out);
    return $out;
}

/* ==================================================================
 * Selbsttest
 *
 * Dreissig Faelle, jeder von Hand nachgerechnet. Sie laufen ohne Netz,
 * ohne Dateien und mit einer festen Uhrzeit - deshalb ist das Ergebnis
 * reproduzierbar und taugt als Knopf im Reiter Test.
 *
 * Die Preisreihe ist bewusst klein und von Hand gesetzt, damit sich jeder
 * erwartete Wert nachzaehlen laesst.
 * ================================================================== */

/** Eine Preisreihe: 24 Stunden ab $start, Preise aus der Liste. */
function plan_test_reihe($start, $werte, $slotlen = 3600)
{
    $out = array();
    $ts = $start;
    foreach ($werte as $w) {
        $out[$ts] = (float) $w;
        $ts += $slotlen;
    }
    return $out;
}

function plan_test_regel($feld = array())
{
    return array_merge(array(
        'aktiv' => 1, 'name' => '', 'art' => 'fenster', 'n' => 2,
        'von' => 0, 'bis' => 0, 'horizont' => 24,
        'schwelle' => 20.0, 'prozent' => 20, 'neg' => 0,
    ), plan_regel_vorgabe(), $feld);
}

/** Rueckgabe: array(anzahl, fehl, text) */
function plan_selbsttest()
{
    $z = array();
    $fehl = 0;
    $anzahl = 0;
    $pruefe = function ($name, $ist, $soll) use (&$z, &$fehl, &$anzahl) {
        $anzahl++;
        $ok = ($ist === $soll);
        if (!$ok) { $fehl++; }
        $z[] = ($ok ? '[ OK ] ' : '[FEHL] ') . $name;
        if (!$ok) {
            $z[] = '       erzeugt : ' . json_encode($ist);
            $z[] = '       erwartet: ' . json_encode($soll);
        }
    };

    /* Ein fester Zeitpunkt: 10.08.2026, 00:00 Uhr Ortszeit. Alle Fristen
     * und Zeitfenster rechnen dagegen. */
    $t0 = mktime(0, 0, 0, 8, 10, 2026);
    $g0 = array('budget_kw' => 0.0, 'pv_bonus' => 0.0, 'pv_schwelle' => 500);
    $u0 = array('pv' => null, 'pv_summe' => null, 'soc' => null, 'neg' => 0, 'mittel' => 20.0);

    /* ---------- Helfer ---------- */
    $pruefe('pro Stunde bei 3600 s', plan_pro_stunde(3600), 1);
    $pruefe('pro Stunde bei 900 s', plan_pro_stunde(900), 4);
    $pruefe('pro Stunde bei 1800 s', plan_pro_stunde(1800), 2);

    $pruefe('Zeitfenster 22 bis 6, Stunde 23', plan_in_zeitfenster(23, 22, 6), true);
    $pruefe('Zeitfenster 22 bis 6, Stunde 5', plan_in_zeitfenster(5, 22, 6), true);
    $pruefe('Zeitfenster 22 bis 6, Stunde 12', plan_in_zeitfenster(12, 22, 6), false);
    $pruefe('Zeitfenster 0 bis 0 = ganzer Tag', plan_in_zeitfenster(13, 0, 0), true);

    /* ---------- Frist ---------- */
    $pruefe('Frist 7 Uhr, jetzt 0 Uhr -> heute 7 Uhr',
        plan_frist_ende($t0, 7), mktime(7, 0, 0, 8, 10, 2026));
    $pruefe('Frist 7 Uhr, jetzt 9 Uhr -> morgen 7 Uhr',
        plan_frist_ende(mktime(9, 0, 0, 8, 10, 2026), 7), mktime(7, 0, 0, 8, 11, 2026));
    $pruefe('Frist -1 = keine', plan_frist_ende($t0, -1), 0);
    $pruefe('Frist 24 ist ungueltig', plan_frist_ende($t0, 24), 0);

    /* ---------- Energie zu Zeitscheiben ---------- */
    $pruefe('7 kWh bei 3,7 kW, Stundenscheiben -> 2 Scheiben',
        plan_slots_noetig(plan_test_regel(array('energie' => 7.0, 'leistung' => 3.7)), 3600), 2);
    $pruefe('7 kWh bei 3,7 kW, Viertelstunden -> 8 Scheiben',
        plan_slots_noetig(plan_test_regel(array('energie' => 7.0, 'leistung' => 3.7)), 900), 8);
    $pruefe('11 kWh bei 11 kW -> 1 Scheibe (Stunde)',
        plan_slots_noetig(plan_test_regel(array('energie' => 11.0, 'leistung' => 11.0)), 3600), 1);
    $pruefe('ohne Energie bleibt es bei n=2 Stunden',
        plan_slots_noetig(plan_test_regel(array('n' => 2)), 3600), 2);
    $pruefe('ohne Energie, n=2, Viertelstunden -> 8 Scheiben',
        plan_slots_noetig(plan_test_regel(array('n' => 2)), 900), 8);

    /* ---------- PV-Gutschrift ---------- */
    $p = array($t0 => 30.0, $t0 + 3600 => 30.0, $t0 + 7200 => 30.0);
    $pv = array($t0 => 0, $t0 + 3600 => 250, $t0 + 7200 => 1000);
    $e = plan_effektivpreise($p, $pv, 10.0, 500);
    $pruefe('PV 0 Wh: Preis unveraendert', $e[$t0], 30.0);
    $pruefe('PV 250 Wh von 500: halbe Gutschrift', $e[$t0 + 3600], 25.0);
    $pruefe('PV 1000 Wh von 500: volle Gutschrift, nicht mehr', $e[$t0 + 7200], 20.0);
    $e2 = plan_effektivpreise($p, $pv, 0.0, 500);
    $pruefe('Gutschrift 0 laesst alles unveraendert', $e2[$t0 + 7200], 30.0);

    /* ---------- Auswahl: guenstigstes Fenster ----------
     * Preise ab Mitternacht: 30 30 10 10 30 30 ...
     * Zwei Stunden am Stueck muessen 02:00 und 03:00 sein. */
    $preise = plan_test_reihe($t0, array(30, 30, 10, 10, 30, 30, 30, 30));
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 2))), $u0, $g0);
    $pruefe('Fenster: 2 Stunden am Stueck ab 02:00',
        array($fp[0]['start'], $fp[0]['anzahl'], $fp[0]['in']), array(2, 2, 120));
    $pruefe('Fenster: laeuft um Mitternacht noch nicht', $fp[0]['aktiv'], 0);

    /* ---------- Frist verkuerzt das Fenster ----------
     * Dieselbe Reihe, aber Frist 2 Uhr: dann sind nur 00:00 und 01:00
     * erreichbar, obwohl 02:00/03:00 billiger waeren. */
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 2, 'frist' => 2))), $u0, $g0);
    $pruefe('Frist 2 Uhr: nimmt 00:00, obwohl 02:00 billiger waere',
        array($fp[0]['start'], $fp[0]['anzahl'], $fp[0]['aktiv']), array(0, 2, 1));

    /* ---------- Rangfolge und Budget ----------
     * Budget 5 kW. Zwei Regeln zu je 3,7 kW, beide wollen die billigste
     * Stunde. Rang 1 bekommt sie, Rang 2 muss ausweichen.
     * Preise: 30 30 10 20 30 ... -> billigste Stunde ist 02:00, zweitbeste
     * 03:00 mit 20. */
    $preise2 = plan_test_reihe($t0, array(30, 30, 10, 20, 30, 30, 30, 30));
    $g1 = array('budget_kw' => 5.0, 'pv_bonus' => 0.0, 'pv_schwelle' => 500);
    $regeln = array(
        plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.7, 'rang' => 1, 'name' => 'A')),
        plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.7, 'rang' => 2, 'name' => 'B')),
    );
    $fp = plan_rechnen($preise2, 3600, $t0, $regeln, $u0, $g1);
    $pruefe('Budget: Rang 1 bekommt die billigste Stunde (02:00)', $fp[0]['start'], 2);
    $pruefe('Budget: Rang 2 weicht auf die zweitbeste aus (03:00)', $fp[1]['start'], 3);
    $pruefe('Budget: Rang 2 meldet Verdraengung', $fp[1]['verdraengt'] > 0, true);
    $pruefe('Budget: Rang 1 wird nicht verdraengt', $fp[0]['verdraengt'], 0);

    /* Dieselben zwei Regeln ohne Budget: beide nehmen dieselbe Stunde. */
    $fp = plan_rechnen($preise2, 3600, $t0, $regeln, $u0, $g0);
    $pruefe('Ohne Budget nehmen beide dieselbe Stunde',
        array($fp[0]['start'], $fp[1]['start']), array(2, 2));

    /* Rangfolge umgedreht - dann bekommt die andere Regel den Zuschlag. */
    $regeln2 = array(
        plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.7, 'rang' => 9, 'name' => 'A')),
        plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.7, 'rang' => 1, 'name' => 'B')),
    );
    $fp = plan_rechnen($preise2, 3600, $t0, $regeln2, $u0, $g1);
    $pruefe('Rang entscheidet, nicht die Reihenfolge in der Liste',
        array($fp[0]['start'], $fp[1]['start']), array(3, 2));

    /* Ergebnis bleibt an seinem Platz: Regel 1 ist immer $fp[0]. */
    $pruefe('Reihenfolge der Rueckgabe folgt der Regelnummer',
        array($fp[0]['nr'], $fp[1]['nr']), array(1, 2));

    /* ---------- Sperren ---------- */
    $u_pv = array_merge($u0, array('pv_summe' => 30.0));
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 99, 'pv_sperre' => 25.0))),
        $u_pv, $g0);
    $pruefe('PV-Sperre greift bei 30 kWh Prognose und Schwelle 25',
        array($fp[0]['aktiv'], $fp[0]['gesperrt']), array(0, 'pv'));

    $u_pv2 = array_merge($u0, array('pv_summe' => 5.0));
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 99, 'pv_sperre' => 25.0))),
        $u_pv2, $g0);
    $pruefe('PV-Sperre greift NICHT bei 5 kWh Prognose', $fp[0]['aktiv'], 1);

    $u_soc = array_merge($u0, array('soc' => 90.0));
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 99, 'soc_max' => 80))),
        $u_soc, $g0);
    $pruefe('Speicher voll: soc_max sperrt',
        array($fp[0]['aktiv'], $fp[0]['gesperrt']), array(0, 'soc_max'));

    $u_soc2 = array_merge($u0, array('soc' => 10.0));
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 99, 'soc_min' => 20))),
        $u_soc2, $g0);
    $pruefe('Speicher leer: soc_min sperrt', $fp[0]['gesperrt'], 'soc_min');

    /* Ohne Speicherwert wird nicht gesperrt - eine fehlende Auskunft ist
     * kein Grund, die Anlage stillzulegen. */
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 99,
                                    'soc_min' => 20, 'soc_max' => 80))), $u0, $g0);
    $pruefe('Ohne Speicherwert keine Sperre', $fp[0]['gesperrt'], '');

    /* ---------- Negativpreis gegen Budget ----------
     * Beide Regeln duerfen bei Negativpreis laufen, aber das Budget laesst
     * nur eine zu. */
    $u_neg = array_merge($u0, array('neg' => 1));
    $regeln3 = array(
        plan_test_regel(array('art' => 'schwelle', 'schwelle' => 0, 'leistung' => 3.7,
                              'rang' => 1, 'neg' => 1)),
        plan_test_regel(array('art' => 'schwelle', 'schwelle' => 0, 'leistung' => 3.7,
                              'rang' => 2, 'neg' => 1)),
    );
    $fp = plan_rechnen($preise, 3600, $t0, $regeln3, $u_neg,
        array('budget_kw' => 5.0, 'pv_bonus' => 0.0, 'pv_schwelle' => 500));
    $pruefe('Negativpreis: Rang 1 laeuft', $fp[0]['aktiv'], 1);
    $pruefe('Negativpreis: Rang 2 bleibt aus, weil das Budget voll ist', $fp[1]['aktiv'], 0);

    /* ---------- Viertelstunden ----------
     * Dieselbe Rechnung bei 900 s: 8 Scheiben = 2 Stunden am Stueck. */
    $vs = array();
    $w = array(30, 30, 30, 30, 30, 30, 30, 30, 10, 10, 10, 10, 10, 10, 10, 10, 30, 30, 30, 30);
    $vs = plan_test_reihe($t0, $w, 900);
    $fp = plan_rechnen($vs, 900, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 2, 'leistung' => 0))), $u0, $g0);
    $pruefe('Viertelstunden: 2 Stunden am Stueck sind 8 Scheiben ab 02:00',
        array($fp[0]['anzahl'], $fp[0]['start'], $fp[0]['in']), array(8, 2, 120));

    /* ---------- Belegung ---------- */
    $fp = plan_rechnen($preise2, 3600, $t0, $regeln, $u0, $g1);
    $bel = plan_belegung($fp);
    $pruefe('Belegung: je Stunde 3,7 kW, nicht 7,4',
        array(count($bel), array_values($bel)), array(2, array(3.7, 3.7)));

    /* ---------- Fremde Auskuenfte ---------- */
    $zeit1 = date('Y-m-d H:i:s', $t0);
    $zeit2 = date('Y-m-d H:i:s', $t0 + 3600);
    $fs = array('result' => array('watt_hours_period' => array(
        $zeit1 => 800, $zeit2 => 1500)));
    list($pvw, $m) = plan_pv_lesen($fs, 'forecast_solar', '', '', '', 'wh', 3600);
    $pruefe('forecast.solar: zwei Stunden in Wh',
        array($m, isset($pvw[$t0]) ? $pvw[$t0] : null, isset($pvw[$t0 + 3600]) ? $pvw[$t0 + 3600] : null),
        array('', 800.0, 1500.0));

    /* Zwei Halbstundenwerte fallen in dieselbe Stundenscheibe und werden
     * addiert - nicht ueberschrieben. */
    $fs2 = array('result' => array('watt_hours_period' => array(
        $zeit1 => 300, date('Y-m-d H:i:s', $t0 + 1800) => 500)));
    list($pvw2, $m2) = plan_pv_lesen($fs2, 'forecast_solar', '', '', '', 'wh', 3600);
    $pruefe('Feinere Abschnitte werden aufaddiert', $pvw2[$t0], 800.0);

    /* Liste mit eigenen Feldnamen, Werte in kW - bei Viertelstunden ist
     * 1 kW eine Viertelstunde lang gleich 250 Wh. */
    $liste = array('forecasts' => array(
        array('period_end' => $zeit1, 'pv_estimate' => 1.0),
        array('period_end' => date('Y-m-d H:i:s', $t0 + 900), 'pv_estimate' => 2.0),
    ));
    list($pvw3, $m3) = plan_pv_lesen($liste, 'liste', 'forecasts', 'period_end', 'pv_estimate', 'kw', 900);
    $pruefe('Liste in kW bei Viertelstunden: 1 kW -> 250 Wh',
        array($m3, $pvw3[$t0], $pvw3[$t0 + 900]), array('', 250.0, 500.0));

    list($pvw4, $m4) = plan_pv_lesen($liste, 'liste', 'gibtsnicht', 'period_end', 'pv_estimate', 'kw', 900);
    $pruefe('Falscher Pfad wird gemeldet, nicht verschwiegen', $m4, 'PFAD_LEER');
    list($pvw5, $m5) = plan_pv_lesen(array('a' => array('x' => 'keinedatum')), 'objekt', 'a', '', '', 'wh', 3600);
    $pruefe('Unlesbare Zeitangaben werden gemeldet', $m5, 'ZEITEN_UNLESBAR');
    list($pvw6, $m6) = plan_pv_lesen(null, 'forecast_solar', '', '', '', 'wh', 3600);
    $pruefe('Keine Antwort wird gemeldet', $m6, 'KEINE_ANTWORT');

    $pruefe('PV-Summe der naechsten 24 h in kWh',
        plan_pv_summe(array($t0 => 800, $t0 + 3600 => 1500, $t0 + 200000 => 9999), $t0, 24), 2.3);
    $pruefe('Ohne Prognose keine Summe', plan_pv_summe(array(), $t0, 24), null);

    list($soc, $ms) = plan_soc_lesen(array('bat' => array('soc' => 63.4)), 'bat.soc');
    $pruefe('Speicherstand aus einem Pfad', array($ms, $soc), array('', 63.4));
    list($soc2, $ms2) = plan_soc_lesen(array('bat' => array('soc' => 1234)), 'bat.soc');
    $pruefe('Speicherstand ausserhalb 0 bis 100 wird abgewiesen',
        array($ms2, $soc2), array('AUSSERHALB', null));
    list($soc3, $ms3) = plan_soc_lesen(array('bat' => array('soc' => 'viel')), 'bat.soc');
    $pruefe('Text statt Zahl wird abgewiesen', $ms3, 'PFAD_LEER');

    /* Und der Zweck des Ganzen: mit Gutschrift gewinnt die sonnige Stunde
     * gegen die billige Nachtstunde. Preise 10 20 20 20, PV nur um 02:00. */
    $preise3 = plan_test_reihe($t0, array(10, 20, 20, 20, 30, 30, 30, 30));
    $pv3 = array($t0 + 7200 => 1000);
    $u3 = array_merge($u0, array('pv' => $pv3));
    $fp = plan_rechnen($preise3, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 1))), $u3,
        array('budget_kw' => 0.0, 'pv_bonus' => 15.0, 'pv_schwelle' => 500));
    $pruefe('PV-Gutschrift laesst die Sonnenstunde gewinnen', $fp[0]['start'], 2);
    $fp = plan_rechnen($preise3, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 1))), $u3, $g0);
    $pruefe('Ohne Gutschrift gewinnt die billigste Stunde', $fp[0]['start'], 0);
    $pruefe('Der ausgewiesene Preis bleibt der echte, nicht der Effektivpreis',
        $fp[0]['ct'], 10.0);

    array_unshift($z, sprintf('Planer %s: %d Faelle geprueft, %d Fehlschlaege.',
        PLAN_FASSUNG, $anzahl, $fehl), '');
    return array($anzahl, $fehl, implode("\n", $z));
}
