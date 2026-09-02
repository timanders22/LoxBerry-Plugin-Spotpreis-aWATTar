# LoxBerry-Plugin: Spotpreis aWATTar

Holt die stündlichen Börsenstrompreise (EPEX SPOT Day-Ahead) über die offene
**aWATTar-API** (Deutschland oder Österreich), rechnet sie mit den eigenen
Preisbestandteilen auf den **Endpreis in ct/kWh** hoch und liefert sie an Loxone,
per MQTT und als JSON — mit stündlicher Sprachansage und Push-Auslöser.

Kein Konto, kein API-Key, keine Cloud-Bindung. Kompatibel mit LoxBerry 3.x und
**LoxBerry 4** (reines PHP, läuft mit PHP 7.4 und 8.x).

## Funktionen

- **Endpreis statt Börsenpreis**: Netzentgelte, Stromsteuer, Konzessionsabgabe,
  Umlagen, Anbieter-Aufschlag und Umsatzsteuer sind einzeln einstellbar
  (Richtwerte 2026 vorbelegt, DE und AT)
- Aktuelle Stunde, nächste Stunde, Börsenanteil, **Rang** der laufenden Stunde
  in den nächsten 24 h, **Preisniveau** (günstig/normal/teuer über frei
  wählbare Schwellen), Flag für negative Börsenpreise
- Günstigste/teuerste Stunde und Tagesdurchschnitt für **heute und morgen**
- **Günstigstes zusammenhängendes X-Stunden-Fenster** (Länge einstellbar) —
  ideal für Waschmaschine, Spülmaschine, E-Auto, Warmwasser
- **Stündliche Ansage (TTS)**, je Stunde per Checkbox aktivierbar; optional nur
  unterhalb der Günstig-Schwelle, zusätzlich immer bei negativem Preis, und eine
  Tagesvorschau-Ansage, sobald die Preise für morgen veröffentlicht sind
- **Push-Auslöser** für Loxone (`ANN`) samt Test-Push-Funktion
- **CO₂-Intensität** des Strommixes als zweite Kennzahl (Fraunhofer ISE
  Energy-Charts, kostenlos und ohne Konto): aktueller Wert, sauberste Stunde der
  nächsten 24 h, Flag „Ökostrom-Zeit" — für CO₂-optimiertes Schalten
- **Zweiter Preissatz nach § 14a EnWG** für steuerbare Wärmepumpe oder Wallbox
  mit eigenem Zählpunkt (reduziertes Netzentgelt + Schwachlast-Konzessionsabgabe)
- **Tarifvergleich fest ↔ dynamisch**: Monatstabelle mit lastprofil-gewichtetem
  Durchschnitt gegen den eigenen Festpreis, in ct/kWh und Euro — plus
  Monatsbericht als Ansage/Protokoll am Monatsersten. Der Netzbezug lässt sich
  **je Monat** eintragen (wichtig mit PV: im Winter viel Zukauf bei hohen
  Börsenpreisen, im Sommer wenig); der Jahresverbrauch ergibt sich dann
  automatisch aus der Summe
- **Ersparnis durch verschobenen Verbrauch**: Was hätte es gebracht, täglich
  X kWh in die günstigste Stunde zu verschieben (Woche und Jahreshochrechnung)
- **Optionale Kopplung mit dem Marstek-Speicher-Plugin** (Standard aus): lädt den
  Speicher in den X günstigsten Stunden bzw. bei negativem Preis
- **MQTT** über das LoxBerry MQTT Gateway, **JSON** inklusive aller Stundenwerte
- **Lebenszeichen** (`TS` und `LAUF`): daran erkennt der Miniserver, ob das
  Plugin noch arbeitet — ohne das steht bei einem Ausfall weiterhin der letzte
  Preis in Loxone, und in der App sieht alles normal aus
- Preisdiagramm (heute + morgen) in der Oberfläche, Tageswert-Historie,
  **Verlauf als CSV** zum Nachrechnen
- **Einstellungen sichern und zurückspielen** über zwei Knöpfe — für den Umzug
  auf einen zweiten LoxBerry
- Optional: **eigener Lastgang** statt des eingebauten Haushaltsprofils, damit
  der Tarifvergleich eine Messung wird statt einer Modellrechnung
- Reiter: Einstellungen, MQTT, Einbindung in Loxone (Schritt-für-Schritt inkl.
  kompletter Baustein-Liste zum 1:1-Nachbauen), Kostenvergleich, Test,
  Logdateien
- Konfiguration, Preishistorie und Merker überleben Updates und
  Neuinstallation. Das **Protokoll** nicht: `log/plugins` liegt auf dem
  LoxBerry auf der Ramdisk und ist nach jedem Neustart ohnehin leer

## Endpunkte

**Lesende Aufrufe** — ein Token ist nur nötig, wenn eines eingerichtet ist:

| Aufruf | Zweck |
|---|---|
| `/plugins/spotpreis/spot.php` | Loxone-Zeile `SPOT;OK=..;MINH=..;…;CUR=..;RANK=..;LEVEL=..;WINH=..;ANN=..;CURX=..`, dazu `LEBEN;TS=..;LAUF=..;RECHNE=..` |
| `/plugins/spotpreis/spot.php?debug=1` | alle Stundenpreise heute + morgen |
| `/plugins/spotpreis/spot.php?json=1` | kompletter Zustand als JSON |
| `/plugins/spotpreis/spot.php?selftest=1` | die Selbstprüfung als Zeile: `PRUEF;PANZ=13;PFEHL=0;PUNKLAR=5;KONFIG=1;PREISE=1;…`, dahinter der Klartext je Punkt (seit 1.2.20) |

Drei Felder der Zeile sind neu oder haben ihre Bedeutung geschärft:

| Feld | Bedeutung |
|---|---|
| `TS` | Zeitpunkt des letzten **Cron-Laufs**. Bis 1.2.19 stand hier der Zeitpunkt der letzten Zustandsrechnung — und die stößt der Abruf des Miniservers selbst an. Die Ausfallerkennung (Formel Zeit minus `TS`, Ein bei 900) konnte damit nie anschlagen. |
| `RECHNE` | Zeitpunkt der letzten Zustandsrechnung. Beantwortet die andere Frage: wie alt sind die Zahlen dieser Zeile? |
| `CURX` | 1, solange für die laufende Stunde ein **Ersatzwert** steht (der Tageshöchstpreis, damit die Stunde nie gewählt wird). Fehlt die Stunde in den Marktdaten, ist `CUR` also nicht der Preis dieser Stunde. |

**Auslösende Aufrufe** — seit 1.2.13 **immer** mit Token
(`&token=…`, im Reiter *Einbindung in Loxone* zu setzen):

| Aufruf | Zweck |
|---|---|
| `/plugins/spotpreis/spot.php?refresh=1` | Marktdaten sofort neu abrufen |
| `/plugins/spotpreis/spot.php?say=1` | Test-Ansage (aktueller Preis) |
| `/plugins/spotpreis/spot.php?saytomorrow=1` | Test-Ansage (Preise für morgen) |
| `/plugins/spotpreis/spot.php?ptest=1` | Test-Pushnachricht auslösen |

Der Unterschied hat einen Grund: `?say=1` spricht über die Lautsprecher der
Wohnung, `?ptest=1` legt eine Datei an, `?refresh=1` stößt einen Abruf bei
einem fremden Dienst an. Bis 1.2.12 konnte das jedes Gerät im Netz, ohne jede
Hürde. Das Lesen bleibt tokenfrei, damit kein bestehender Aufbau abreißt —
Loxone ruft die auslösenden Adressen ohnehin nicht ab, und die Knöpfe auf der
Plugin-Seite führen das Token automatisch mit.

## Preisbestandteile (Voreinstellung: deutsche Großstadt, 2026)

| Bestandteil | ct/kWh netto |
|---|---|
| Netzentgelt (Arbeitspreis, Grundpreis separat) | 6,47 |
| Stromsteuer | 2,05 |
| Konzessionsabgabe (Gemeinde über 500.000 EW) | 2,39 |
| Umlagen (KWKG 0,446 + Offshore 0,941 + § 19 StromNEV 1,558) | 2,945 |
| Anbieter-Aufschlag | 0,00 |
| **Summe** | **13,855** |
| Umsatzsteuer | 19 % (AT: 20 %) |
| Grundpreis (Netz + Messstellenbetrieb, nur informativ) | 5,27 €/Monat |

Beispiel: 8,00 ct Börsenpreis → **26,01 ct/kWh** Endpreis.

Alle Werte sind frei änderbar — bitte mit der eigenen Stromrechnung abgleichen.
Netzentgelte sind stark regional (typisch 5–12 ct/kWh); das Preisblatt des
eigenen Netzbetreibers nennt den Arbeitspreis im „Grundpreis-/Arbeitspreissystem".
Konzessionsabgabe nach Gemeindegröße: bis 25.000 EW 1,32 · bis 100.000 EW 1,59 ·
bis 500.000 EW 1,99 · über 500.000 EW 2,39 ct/kWh.

**§ 14a EnWG** (zweiter Preissatz, optional): steuerbare Wärmepumpe 3,43 ·
Speicherheizung 1,71 · Elektromobilität 4,28 · Modul 2 pauschal 2,59 ·
Modul 3 HT 7,14 / NT 2,59 ct/kWh (Beispielwerte 2026), Konzessionsabgabe
Schwachlast 0,61 ct/kWh.


## Verhältnis zum aWATTar-Plugin von Christian Fenzl

Für LoxBerry gibt es seit Längerem das Plugin
[aWATTar von Christian Fenzl](https://github.com/christianTF/LoxBerry-Plugin-aWATTar)
(Fassung 0.1.6, Ordner `awattar`). Dieses Plugin hier ist **kein Ableger davon**:
Es ist eigenständig entstanden und spricht lediglich dieselbe öffentliche
aWATTar-Schnittstelle an — so wie zwei Programme dieselbe Wetter-API benutzen
können, ohne miteinander verwandt zu sein.

Nachgeprüft statt behauptet: Die beiden Quelltexte haben **keine einzige
gemeinsame Funktion** (22 gegenüber 65) und **keine einzige übereinstimmende
Codezeile** über 40 Zeichen (354 gegenüber 1193 geprüften Zeilen). Auch die
Versionsgeschichten haben keinen gemeinsamen Vorfahren.

Das ist hier keine Förmlichkeit: Das ältere Plugin steht **ohne Lizenzangabe**
im Netz. Ohne Lizenz gibt es keine Erlaubnis, Code daraus zu übernehmen — und
genau deshalb ist wichtig, dass keiner übernommen wurde. Beide Plugins können
nebeneinander installiert sein; Kennung, Ordner (`spotpreis` gegen `awattar`)
und Endpunkte sind verschieden.

## Datenschutz

Es sind **keine persönlichen Daten** im Plugin enthalten. Alle Einstellungen
liegen lokal in `config/plugins/spotpreis/spot.json`; die Datei trägt den
Aktionstoken und steht deshalb auf 0600.

Ausgehende Verbindungen — vollständig, am Quelltext nachgezählt:

| Ziel | wann | Kennung |
|---|---|---|
| `api.awattar.de` bzw. `.at` | immer, für die Preise | keine |
| `api.energy-charts.info` | **ab Werk eingeschaltet**, für die CO₂-Intensität | keine |
| die eingetragene PV-Prognose | nur wenn eine Quelle eingerichtet ist | wie eingetragen |
| der eingetragene eigene Lastgang | nur wenn eine Quelle eingerichtet ist | wie eingetragen |
| Hausspeicher (Marstek) | nur wenn eingeschaltet, im eigenen Netz | — |
| Music Server / Audioserver | nur für Ansagen, im eigenen Netz | — |
| `127.0.0.1` | MQTT über das UDP-Relais des Gateways | — |

Bis 1.2.19 stand hier, es gebe Verbindungen „ausschließlich zur
öffentlichen aWATTar-Preis-API". Das war falsch: die CO₂-Abfrage ist ab
Werk eingeschaltet. Sie lässt sich in den Einstellungen abschalten.

**Die erzeugte Loxone-Vorlage trägt den Aktionstoken**, sonst wäre sie
nutzlos. Sie geht vom LoxBerry in die Loxone Config — nicht ins Netz.

## Änderungen

Absteigend nach Fassung. **Zu 1.2.15 und 1.2.16 gibt es kein Kapitel** —
die beiden Nummern kommen im ganzen Plugin nirgends vor, und was sie
enthielten, lässt sich hier nicht nachtragen, ohne es zu erfinden. Die
Lücke steht deshalb da, statt wie ein Versehen auszusehen.

## Fassung 1.2.20 — was hinter der grünen Prüfkette stand

1.2.19 war grün: 44 von 44 Prüfungen des Prüfstands unter PHP 7.4 **und**
8.4, 137 Fälle des Planer-Selbsttests ohne Fehlschlag, 26 von 26 Mutationen
erkannt, das Freigabetor ohne Befund. Diese Fassung ist das Ergebnis der
Frage, was **trotzdem** nicht stimmt.

Jede Korrektur unten ist in **beide Richtungen** geeicht: gemessen, dass der
Fehler auftritt, und gemessen, dass die zugehörige Prüfung rot wird, wenn
man die Korrektur zurückbaut. Eine Prüfung, die grün bleibt, wenn man ihren
Code entfernt, misst nichts.

### Der Miniserver bekam eine 0 für die laufende Stunde

Fehlte in den Marktdaten genau die laufende Stunde, standen `CUR`, `CURB`
und `NEXT` auf 0 — und `HOK` blieb dabei auf 1, denn der Tag war ja da.
Gemessen an einem vollständigen Tag, aus dem genau diese eine Stunde
entfernt wurde:

| | Kontrollfall (alle 24 h) | Prüffall (laufende Stunde fehlt) |
|---|---|---|
| `HOK` | 1 | 1 |
| `CUR` | 46,761 | **0,000** |
| `RANK` | 13 | **1** |
| `LEVEL` | 3 | **1** |
| `WPCUR` | 49,141 | **20,200** |

`LEVEL=1` und `RANK=1` heißt für den Spot Price Optimizer: günstigste Stunde
des Tages. Das Stundenprofil war gegen genau diesen Fall längst geschützt —
`PH19` stand im selben Lauf korrekt auf 49,141, mit einer ausführlichen
Begründung im Quelltext, warum eine fehlende Stunde den **Tageshöchstpreis**
bekommt. Nur `CUR` hielt sich nicht daran.

Jetzt gilt derselbe Ersatzwert. Und die Zeile **sagt es an**: das neue Feld
`CURX` steht auf 1, solange ein Ersatzwert im Spiel ist. Eine stille
Ersetzung wäre nur die nächste Fassung desselben Fehlers.

### Am 25. Oktober fehlte die letzte Stunde des Tages

Das Abruffenster war fest 24 Stunden lang. Nachgerechnet, ohne Netz:

| Tag | | Stunden | im Fenster | nicht abgerufen |
|---|---|---|---|---|
| 15.06.2026 | gewöhnlich | 24 | 24 | — |
| 25.10.2026 | Ende der Sommerzeit | **25** | 24 | **23:00** |
| 29.03.2026 | Beginn der Sommerzeit | 23 | 23 | — |

Zusammen mit dem Befund darüber ergab das einmal im Jahr eine Stunde mit
`CUR=0`, `RANK=1`, `LEVEL=1` bei `HOK=1`. Das Fenster endet jetzt am Anfang
des nächsten Tages, gerechnet über die Datumsfunktion und nicht mit
`+86400` — an genau diesen beiden Tagen ist ein Tag nicht 86 400 Sekunden
lang.

### Die Ausfallerkennung konnte nicht anschlagen

Der Reiter Loxone schreibt sie vor: virtueller Eingang `\i;TS=\i\v`, Formel
„Zeit minus TS", Schwellwert Ein 900. Damit sie greift, muss `TS` **alt
bleiben**, wenn der Cron tot ist. Gemessen — Zustand künstlich zwei Stunden
gealtert, danach die Zeile abgerufen, ohne den Cron zu starten:

| | 1.2.19 | 1.2.20 |
|---|---|---|
| Cron nie gelaufen | schlägt **nicht** an | schlägt an |
| Cron gerade gelaufen | schlägt nicht an | schlägt nicht an |
| Cron zwei Stunden tot | schlägt **nicht** an | schlägt an |

`TS` kam aus dem Zeitpunkt, zu dem der **Zustand** zuletzt gerechnet wurde —
und rechnen lässt ihn auch der Abruf des Miniservers selbst. `TS` maß also
den Abruf, nicht den Cron. Jetzt kommt es aus dem Zeitstempel des
Laufzählers, den ausschließlich `bin/cron.php` schreibt, und zwar als erstes
im Lauf. Der Rechenzeitpunkt geht nicht verloren: er steht als neues Feld
`RECHNE` in derselben Zeile. Zwei Fragen, zwei Zahlen.

Dieselbe Blindheit hatte die Selbstprüfung `PRUEF.LEBEN`; auch sie sieht
jetzt auf den Laufzähler.

### Die erzeugte Loxone-Vorlage trug kein Token

Steht in den Einstellungen eine Marke, verlangt `spot.php` sie bei **jedem**
Abruf — auch beim reinen Lesen. Die erzeugte Vorlage rief die Adresse aber
ohne Marke auf. Der Miniserver bekam darauf 403 und die Zeile
`SPOT;OK=0;GRUND=TOKEN`; von den Eingängen fand keiner mehr seinen Wert. Die
Anlage sah dabei eingerichtet aus. Die Vorlage trägt die Marke jetzt mit.

Im selben Zug tragen alle Suchtexte das Trennzeichen: `\i;NAME=\i\v` statt
`\iNAME=\i\v`. Gemessen an der echten Antwortzeile mit 141 Feldnamen treffen
ohne Semikolon acht Felder mehrfach (`AVG`, `CUR`, `MAXH`, `MAXP`, `MINH`,
`MINP`, `NEXT`, `OK` — `MINH` sogar dreifach), mit Semikolon keines. **Ehrlich
dazu:** heute liefert die erste Fundstelle bei allen acht trotzdem den
richtigen Wert, weil das kürzere Feld in der Zeile zufällig vorne steht. Das
ist also keine laufende Falschmessung, sondern eine, die die nächste
Umsortierung der Zeile auslösen würde — lautlos, denn die Zahl sähe weiter
plausibel aus.

### Anzeige und Miniserver rechneten mit verschiedenen Einheiten

`planer.php` legt im Kopf fest: Preise in **ct/kWh**. `spot_state()` hält
sich daran, `spot_fahrplan()` — die Quelle der Anzeige — reichte den
Endpreis in EUR/kWh weiter, also hundertmal zu klein, während das Tagesmittel
im selben Aufruf in ct stand. Gemessen an einer Regel „unter 20,0 ct" bei
einem Endpreis von rund 26 ct, die also **nicht** laufen darf:

| | 1.2.19 | 1.2.20 |
|---|---|---|
| Loxone-Zeile | `R1=0` | `R1=0` |
| Anzeige | `aktiv=1, grund=schwelle` | `aktiv=0` |
| Preisspalte | 0,36 ct | 26,01 ct |

### Der Fahrplaner: fünf Befunde, zwei davon fassungsabhängig

`planer.php` steht auf **1.1.3** und ist in der Octopus-Linie byteweise
dieselbe Datei. Der Selbsttest zählt jetzt 170 Fälle statt 137, die
Mutationsdeckung 34 von 34.

* **Runden.** Die eingebaute Rundung von PHP entscheidet an der Hälfte je
  nach Fassung verschieden. Die Schaltschwelle der Regelart „mittel" ergab
  für ein Tagesmittel von 5,05 ct minus 15 % unter **7.4.33 den Wert 4,293**
  und unter **8.4.24 den Wert 4,292** — dieselbe Regel schaltet unter der
  einen Fassung und unter der anderen nicht. LoxBerry fährt heute 7.4, mit
  Debian 13 kommt 8.x: der Wechsel hätte die Schwelle von selbst verstellt.
  `plan_runde()` bildet die 7.4-Antwort nach, die Zahl 15 aber fest verdrahtet
  statt aus der ini-Einstellung. Geeicht über 72 042 Werte: gegen 7.4 kein
  Unterschied, gegen 8.4 genau 629.
* **Fristen an den Umstellungstagen.** Die Zeitfunktion mit Einzelargumenten
  löst die doppelte Stunde am 25.10. fassungsabhängig auf — 7.4 nimmt
  02:00 CET, 8.4 nimmt 02:00 CEST. Eine Stunde Unterschied bei der Frist,
  allein durch den Interpreter. Jetzt über `strtotime` auf eine
  Datumszeichenkette; fünf Fälle gemessen, beide Fassungen einig.
* **Der Taktschutz riss Lücken auf.** Das Verlängern zu kurzer Blöcke
  entstand nach der Prüfung der Mindestpause — und niemand sah danach noch
  hin. Ein Streifzug über 3815 Fälle gegen die beiden Zusagen des
  Funktionskopfes fand **171 Ergebnisse**, die die Mindestpause nicht
  einhielten; keines verletzte die Mindestlaufzeit. Jetzt wird zweimal
  zugemacht. Die Reihenfolge zu tauschen wäre falsch gewesen: das verwirft
  genau den Block, den das Zumachen rettet — dafür gibt es seit 1.1.0 einen
  Prüffall, und der ging beim Versuch prompt rot.
* **Gleichstand beim Fenster.** Zwei rechnerisch gleich teure Fenster sind
  in Gleitkomma fast nie identisch; der Planer nahm das **spätere**. Die
  Regelarten `stunden` und `scheiben` fangen das seit 1.1.0 ab, `fenster`
  nicht.
* **Kennzahlen nach dem Negativpreis.** Der Zweig trägt die laufende
  Scheibe nach und ändert damit `slots`, `anzahl` und `ct` — die davon
  abhängigen Zahlen wurden aber vorher gerechnet. Eine Regel zu 4 kW, die
  allein wegen des Negativpreises lief, meldete `kwh=0` und `fehlt=2` statt
  `kwh=4,0` und `fehlt=1`.

Dazu zwei Prüffälle **für eine alte Korrektur**: dass die Hysterese sich an
die Kandidatenliste hält, stand seit 1.1.1 im Quelltext begründet, war aber
von keinem Fall gedeckt — der Rückbau blieb grün. Jetzt geht er rot.

### Selbstprüfung, Oberfläche, Sicherung

* **Der Endpunkt kennt die Selbstprüfung.** `?selftest=1` gibt sie als Zeile
  im Hausformat aus (`PRUEF;PANZ=…;PFEHL=…;KONFIG=1;…`), dahinter den
  Klartext je Punkt. Bis 1.2.19 gab es sie nur im Reiter Test — also nur,
  wenn ein Mensch hinsah.
* **`PRUEF.STUNDEN` urteilte über die leere Menge.** Ohne Preise für heute
  ist auch die Lückenliste leer, und die Prüfung meldete einen Haken mit
  „0 Stundenwerte" daneben.
* **`PRUEF.FORMULARE` zählte statt nachzusehen.** Sie verglich die Zahl der
  Merkmale mit der Zahl der Formulare — und zählte dabei auch das Vorkommen
  in einem **Kommentar** mit (13 statt 12). Selbst mit richtiger Zahl sagt
  eine Summe nichts über die Verteilung. Jetzt wird je Formular nachgesehen.
  Geeicht: nimmt man dem ersten Formular sein Merkmal, blieb die alte Regel
  grün.
* **Eine beschädigte Konfiguration wurde still zur leeren.** Die
  Selbstheilung kannte „fehlt" und „leer", nicht aber „da, aber kaputt".
  Gemessen: 1.2.19 fiel auf die Werkseinstellung samt leerem Token zurück —
  und das nächste Speichern kopierte diese Werkseinstellung über die
  Sicherung. Jetzt wird die Datei beiseitegelegt (`.kaputt.<Zeitstempel>`)
  und aus der Sicherung zurückgeholt, beides mit Eintrag im Protokoll.
* **Rechte vor Inhalt.** In der Konfiguration steht der Aktionstoken. Sie
  wird jetzt leer angelegt, auf 0600 gesetzt und dann gefüllt; die Zweitschrift
  bekommt dieselben Rechte. *Am Gerät nicht nachgemessen — auf dem
  Arbeitsrechner gibt es keine POSIX-Rechte.*
* **Geklemmte Werte werden gesagt.** Wer 500 kW einträgt, bekam 100
  gespeichert und kein Wort darüber. Gekappt wird weiterhin — abweisen
  hieße, dass ein Zahlendreher das Speichern aller übrigen Felder
  verhindert —, aber es steht jetzt als Hinweis da.
* **Eine abgeschaltete Regel blockierte das Speichern.** Die Prüfung der
  Speichergrenzen fragte als einzige ihrer drei Nachbarn nicht nach `aktiv`.
* **„Nichts gespeichert" wurde gesetzt und nie gelesen.** Die Variable kam
  genau einmal in der Datei vor: in ihrer Zuweisung.
* **Der Reiterwechsel lud die Seite neu** und nahm dabei jede nicht
  gespeicherte Eingabe mit. Jetzt wird der Klick abgefangen — außer beim
  Reiter Test, dessen Inhalt nur gerendert wird, wenn er angefragt ist.
* **Knopffarben als Einzelanweisung.** Acht Knöpfe trugen ihre Farbe als
  `style`, für die Hausstandardprüfung unsichtbar; zwei davon (Speichern)
  blieben dadurch grün, obwohl sie etwas verändern, und „Log leeren" war
  rot — eine vierte Farbe, die es im Haus nicht gibt.
* **Sechzehn Sprachwerte trugen Auszeichnung.** Das schließende `>` ihres
  HTML-Tags stand in der Zeichenkette. Wer übersetzt und es wegließe,
  bekäme ein offenes Tag. Geeicht daran, dass alle sechs Reiter vor und nach
  der Umstellung zeichengleich sind.
* **Deutsche Sätze im JavaScript** und im Protokolleintrag „Protokoll
  geleert" — in einem Plugin, dessen Oberfläche seit 1.1.2 zweisprachig ist.
* **Drei breite Tabellen ohne Rollbereich.** `.sm-breit` fehlte in der
  Oberfläche ganz.
* **Die stündliche Ansage hatte ein Fenster von einer Minute.** Genau die
  Bedingung, die beim Monatsbericht in 1.1.1 als Fehler erkannt wurde. Der
  Merker verhindert längst, dass sie zweimal kommt; das Fenster ist jetzt
  fünf Minuten breit.
* **Der Cron verschluckte seine Fehlerausgabe.** `>/dev/null 2>&1`
  verschluckt auch „Bibliothek nicht gefunden". Die Fehlerausgabe geht jetzt
  ins Protokoll, nur die normale Ausgabe wird unterdrückt.
* **Die MQTT-Signatur kannte die Schaltregeln nicht.** Schaltete eine Regel
  um, ohne dass sich Preis, Rang oder Niveau änderten, blieb die
  Veröffentlichung aus — Loxone erfuhr davon erst beim Ruhefunk nach bis zu
  1800 Sekunden.
* **Fester Zwischenspeichername.** `/tmp/spotpreis` und `spot_cron.lock`
  standen fest, während der Ordnername daneben ermittelt wird. Zwei
  Installationen teilten sich damit Zustand und Sperrdatei.
* **Zwei Wahrheiten bei den Schranken.** `spot_config()` kappt jetzt auch
  die Preisbestandteile — mit **denselben** Grenzen wie die Oberfläche.

### Was gemessen wurde

* Prüfstand `Pruefung-Spotpreis-aWATTar-1.2.20`: 44 von 44 unter PHP 7.4.33
  und 8.4.24.
* Planer-Selbsttest: 170 Fälle, 0 Fehlschläge unter beiden Fassungen.
* Rückbau jeder Planerkorrektur einzeln: jede zugehörige Prüfung wird rot.
* Mutationsdeckung `planer.php`: 34 von 34 erkannt.
* Alle sechs Reiter gerendert, unter beiden PHP-Fassungen, ohne Warnung.

### Was **nicht** gemessen wurde

Am Gerät nichts. Kein aWATTar-Tarif vorhanden — nur die offene
Preis-Schnittstelle ist abrufbar. Das MQTT-Gateway läuft in **Version 1**;
was die Oberfläche über V2 sagt, stammt aus einem fremden Plugin und ist
Sekundärquelle. Retain am laufenden Gateway, Mithören fremder Themen am
Broker, das eigene Lastprofil an einer echten Quelle und die Dateirechte
unter POSIX sind unbelegt.

## Fassung 1.2.19 — was der Miniserver bekam, wenn eine Stunde fehlte

Diese Fassung entstand aus einer vollständigen Durchsicht. Die schwersten
vier Befunde haben eines gemeinsam: sie liefern eine Zahl, die richtig
aussieht, und sie tun es genau dann, wenn niemand hinsieht.

* **Der Ersatzwert für fehlende Stunden war seit 1.2.12 immer 0,000.** Eine
  Zeile in `spot_state()` überschrieb `$sh` — bis dahin die Tagesstatistik —
  mit dem Ergebnis der Verschiebungsrechnung. Siebenundzwanzig Zeilen später
  wurde `$sh['maxp']` gelesen; das Feld gibt es dort nicht. Damit war der
  Tageshöchstpreis, der eine fehlende Stunde unwählbar machen soll, immer
  0,000 — und 0 ct sieht für den Spot Price Optimizer wie die günstigste
  Stunde des Tages aus. Der Kommentar zwei Zeilen darüber beschreibt genau
  diese Gefahr. Gemessen an einem Tag, dem zwei Stunden fehlen: `PH03` und
  `PH04` standen auf 0,000 statt auf 49,141. Betroffen waren außerdem alle
  24 Werte für morgen, solange die Preise noch nicht veröffentlicht sind —
  vor etwa 14 Uhr der Normalfall.
* **Aus demselben Grund meldete die Selbstprüfung nie eine fehlende oder
  doppelte Stunde.** `luecken_heute` war immer leer, `doppelt_heute` immer 0.
  Die Zeile, die den 25.10. und den 29.03. ansagen soll, konnte nicht
  anschlagen.
* **Das rollende Stundenprofil hatte den Ersatzwert nie.** `PR00`…`PR23`
  (Modus *Relativ*) fiel für jede Stunde ohne Preis auf 0,000 zurück.
  Gemessen um 12 Uhr mit Preisen nur für heute: **zwölf von 24 Eingängen
  standen auf 0,000**. Jetzt gilt dort derselbe Höchstpreis wie bei `PH`/`PM`.
* **Die Hysterese hielt drei Minuten statt drei Stunden.** Der Planer gibt
  `rest` in Minuten aus, `spot_regeln()` rechnet es in Stunden um, und
  `spot_laufend_fortschreiben()` multiplizierte diese Stunden noch einmal mit
  60. Ein Dreistundenblock lief damit 180 Sekunden; ab der vierten Minute
  jeder Stunde war der Schutz weg, den die Funktion geben soll.

### Der Fahrplaner — beide Linien betroffen

`webfrontend/html/planer.php` ist in diesem Plugin und in *Spotpreis Octopus*
byteweise dieselbe Datei — **im Regelfall.** Zum Zeitpunkt dieser
Veröffentlichung ist sie es *nicht*: die beiden Korrekturen stehen hier
(`PLAN_FASSUNG 1.1.2`), in *Spotpreis Octopus* steht die veröffentlichte
1.1.4 noch auf `PLAN_FASSUNG 1.1.1`. Gemessen am 02.09.2026: 21 Zeilen nur
hier, 3 nur dort — und diese drei sind die alten Fassungen genau der
geänderten Stellen. **Octopus braucht dieselbe Datei in einer Folgefassung;
bis dahin kann dort die Hysterese das Leistungsbudget weiterhin reißen.**

* **Die Hysterese riss das Leistungsbudget und das Zeitfenster.** Sie füllte
  ihre Trefferliste aus *allen* Preisen auf statt aus den Kandidaten — der
  einzigen Liste, die Budget, Fenster, Frist und Horizont schon geprüft hat.
  Gemessen mit zwei Regeln zu je 3,0 kW bei `budget_kw` 3,0 und einem
  laufenden Block: in der Belegung standen **6 kW**. Eine Regel mit Fenster
  02–03 Uhr lief drei Stunden vor ihrem Fenster. Das ist dieselbe Klasse wie
  der Lückenschluss in `plan_takt()`, die dort in 1.1.3 behoben wurde und
  hier stehenblieb. Der Kontrollfall ohne Hysterese hielt das Budget.
* **Der Zweitschlüssel bei gleichem Stundenmittel griff nie.** Verglichen
  wurde die ungerundete Summe mit `!==`; zwei rechnerisch gleiche Mittel sind
  in Gleitkomma fast nie identisch. Gemessen mit Viertelstunden
  0,1/0,2/0,1/0,2 gegen 0,15/0,15/0,15/0,15 — beide Mittel 0,15, verglichen
  0.15000000000000002 gegen 0.14999999999999999 — nahm der Planer die
  **spätere** Stunde. Über die Reihenfolge entschied damit die siebzehnte
  Nachkommastelle statt der frühere Zeitpunkt. `PLAN_FASSUNG` steht auf 1.1.2.

### Oberfläche

* **Zwei Meldungen zeigten ihre Auszeichnung wörtlich.** Im Fahrplaner stand
  die Formatierungsmarke als Text auf dem Bildschirm, und die Meldung über
  eine abgelehnte Sicherungsdatei ebenso. Beim Fahrplaner ist die Auszeichnung
  gewollt und die eingesetzten Werte sind Zahlen — dort entfällt das
  Maskieren. Der Fehlerkasten bleibt durchgehend maskiert, weil dort auch
  Fremdes landet; aus seinem Text ist die Auszeichnung genommen.
* **Eine Prüfung konnte nie auslösen.** Der Widerspruch aus Frist und langer
  Laufzeit wurde an einem Feld gemessen, das auf 1 bis 12 begrenzt ist, und
  gegen 24 verglichen. Erreichbar ist der Fall über die Energiemenge: 500 kWh
  bei 1 kW sind 500 Stunden. Genau das wird jetzt gerechnet.
* **Drei Vorgabewerte gab es doppelt und verschieden.** Wer das Feld
  *Netzentgelte* leerte und speicherte, bekam 9,00 ct/kWh statt der
  dokumentierten 6,47; bei der Konzessionsabgabe 1,32 statt 2,39, beim
  Grundpreis 0,00 statt 5,27. Die Vorgaben kommen jetzt aus `spot_vorgaben()`,
  also aus derselben Quelle wie die Beschreibung.

### Protokoll, Daten und Update

* **Der Endpunkt protokollierte seine Abweisungen nicht.** `spot.php` liegt
  im unangemeldeten Bereich. Ohne diese Zeile ließ sich der Fall, dass der
  Miniserver nicht anruft, nicht von dem unterscheiden, dass er anruft und
  abgewiesen wird; und wer im Netz Marken durchprobiert, hinterließ keine
  Spur. Jetzt schreibt jede Abweisung eine Zeile mit Grund und Anrufer — die
  Marke selbst nie.
* **`history.csv` wurde als einzige geschützte Datei nicht unteilbar
  geschrieben.** Sie wird täglich vollständig neu geschrieben, und
  `file_put_contents` kürzt sie dazu zuerst auf null. Betroffen war
  ausgerechnet die eine Datei, die sich nicht nachladen lässt.
* **Die Merker überlebten ein Update nicht** — entgegen dem, was drei eigene
  Texte behaupteten. Der Installer löscht `data/plugins/<ordner>/` in jedem
  Upgrade-Zweig; gesichert wurde bisher nur `history.csv`. Ein Update am
  Monatsersten nach 8 Uhr hätte den Monatsbericht ein zweites Mal ausgelöst.
  `preupgrade.sh` und `postinstall.sh` tragen jetzt auch die Merker und den
  Laufzähler über das Update; der Preis-Zwischenspeicher bleibt bewusst
  draußen, weil er sich nachladen lässt.
* **Die Rettung löschte ihre eigene Sicherung auch dann, wenn das
  Zurückholen fehlgeschlagen war.** Das Aufräumen stand ohne Bedingung hinter
  der Schleife, und die Fehlerausgabe war unterdrückt. Geprüft wird jetzt die
  Wirkung — liegt die Datei hinterher da? —, und sonst bleibt die Sicherung
  stehen und sagt es.

### Aufgeräumt

* Die Einzelregel-Rechnung von vor 1.1.2 (`spot_regel_werte()` samt zwei
  Helfern, 103 Zeilen) ist entfernt. Sie stand seit 1.1.2 unbenutzt da,
  während ein Kommentar behauptete, der Reiter *Test* zeige damit die alte
  und die neue Rechnung nebeneinander.
* Vierzehn sichtbare HTML-Entitäten je Sprachdatei sind durch die Zeichen
  selbst ersetzt. Bedeutungstragende und unsichtbare bleiben.
* Drei Textstellen widersprachen dem Code: die Fallzahl des Planer-Selbsttests
  (133 statt 137), die Zusage, Konfiguration und Protokoll überlebten ein
  Update (das Protokoll liegt auf der Ramdisk und wird nicht gesichert) und
  ein Satz, der den MQTT-Reiter für abgeschafft erklärte.

### Gemessen

44 Prüfungen an der ausgelieferten Seite, unter PHP 7.4.33 und 8.4.24, je
44 bestanden. `plan_selbsttest()` 137 Fälle, 0 Fehlschläge in beiden
Fassungen. Jede Korrektur wurde vorher am Fehlerbild und hinterher an ihrer
Wirkung gemessen, die Rettung über das Update an einem vollständigen
Durchlauf aus `preupgrade.sh`, Löschen des Datenordners und `postinstall.sh`.

**Nicht gemessen** und deshalb nicht behauptet: `retain` am laufenden
MQTT-Gateway, das Mithören fremder Themen am Broker, Gateway V2 (der Anwender
fährt V1; was die Oberfläche über V2 sagt, stammt aus einem fremden Plugin)
und der eigene Lastgang an einer echten Quelle. Ein aWATTar-Tarif liegt nicht
vor; die Preis-Schnittstelle ist offen und wurde abgerufen.

## Fassung 1.2.18 — zwei Rechenfehler im Fahrplaner

`webfrontend/html/planer.php` ist in diesem Plugin und in
*Spotpreis Octopus* **byteweise dieselbe Datei**. Gefunden wurden die beiden
Fehler bei der Durchsicht von Octopus 1.1.4.

> **Berichtigung, gemessen am 02.09.2026 vor dieser Veröffentlichung.** Hier
> stand: „behoben sind sie in beiden Linien, und die Prüfsumme stimmt danach
> wieder überein". Das trifft nicht zu. Die veröffentlichte Octopus 1.1.4
> trägt `PLAN_FASSUNG 1.1.1` und **keine** der beiden Korrekturen; die
> Prüfsummen weichen ab (`116102c6…` gegen `252cc917…`, 101.323 gegen
> 100.046 Byte). Octopus braucht dieselbe Datei in einer Folgefassung.
> Ein Satz über eine Prüfsumme, den niemand nachgerechnet hat, ist die
> Fehlerklasse, gegen die dieses Plugin an vier anderen Stellen gebaut wurde.

* **Eine Zeitscheibe zu viel bei glatten kWh/kW-Paaren.** 6,9 kWh bei 2,3 kW
  sind rechnerisch genau drei Stunden, in Gleitkomma aber
  3,00000000000000044409 — `ceil()` machte vier daraus. Ergebnis: ein Drittel
  zu viel gebuchte Energie und eine Stunde Leistung, die den anderen Regeln
  im Budget fehlt. 4,2 / 1,4 verhält sich genauso; 7,4 / 3,7 und 11,0 / 2,2
  waren nie betroffen. Ob es zuschlägt, entscheidet allein die
  Gleitkommadarstellung des Paares.
* **Der Taktschutz riss das Leistungsbudget.** Beim Schließen einer Lücke
  unterhalb der Mindestpause setzte `plan_takt()` Zeitscheiben, die gar keine
  Kandidaten waren — der Kopfkommentar derselben Funktion versprach das
  Gegenteil. Gemessen mit zwei Regeln zu je 2,0 kW bei `budget_kw` 2,0 und
  `min_pause` 30: in der Belegung standen 4 kW. Mit derselben Anordnung war
  auch das zweite Budget nach § 14a EnWG gerissen, und eine Regel mit
  Fenster 20–10 Uhr lief zehn Stunden außerhalb ihres Fensters. Zugemacht
  wird jetzt alles oder nichts, und nur aus Kandidaten: eine gerissene
  Budgetgrenze ist teurer als ein Takt zu viel.

Beide Korrekturen haben eigene Prüffälle im eingebauten Selbsttest
(`plan_selbsttest()`, jetzt 137 Fälle) und sind in beide Richtungen geeicht —
grün mit der Korrektur, rot ohne, unter PHP 7.4.33 wie unter 8.4.24. Die 26
Mutationen von `mutation_planer.py` werden weiterhin alle erkannt.
`PLAN_FASSUNG` steht damit auf 1.1.1; der Reiter *Test* zeigt sie an.

Sonst ist an diesem Plugin nichts geändert.

## Fassung 1.2.17 — der Stat-Zwischenspeicher
Die Protokollkappung (512 000 Byte) steht in `spot_log()` in
`webfrontend/html/spot_lib.php`. *(Hier stand bis 1.2.19 eine Zeilennummer.
Eine Zeilennummer in einem Text, der die Datei überlebt, zeigt nach der
nächsten Änderung woanders hin — der Funktionsname nicht.)* PHP merkt sich
aber die Antworten von
`stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste Größe
und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)` macht
den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. Folgen hatte das
hier nicht: die Aufrufer sind kurzlebig, und ein **frischer** Prozess kappt
richtig. Eine Funktion darf aber nicht davon abhängen, wer sie wie oft ruft.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

## Fassung 1.2.14 — der Fahrplaner, und was seine Prüfzahlen verschwiegen

Der gemeinsame Fahrplaner `planer.php` hat neue Fähigkeiten bekommen
(Taktschutz, Hysterese, zweites Netzentgelt-Budget, Rangfolge als eigene
Funktion). Sein Selbsttest stand danach bei 115 Fällen, der Mutationslauf
meldete 18 von 18 erkannt. Beides beruhigt — und beides sagte weniger, als
es aussah:

* **Alle 18 Mutationsanker standen schon in der veröffentlichten 1.2.13.**
  Den Code, der seither dazugekommen ist, rührte keine einzige Mutation an.
  Eine handverlesene Liste kennt nur, woran jemand gedacht hat.
* **Zwei von achtzehn Rückgabefeldern prüfte kein einziger Fall**: `rest`
  und `startmin`. `rest` geht als `R<n>REST` nach Loxone — eine falsche
  Zahl steht dort als „läuft noch X Stunden" in der Visualisierung, und sie
  sieht dabei völlig plausibel aus.
* **45 von 176 Verzweigungen** ließen sich auf `true` oder `false` zwingen,
  ohne dass ein Fall rot wurde. Die meisten davon zu Recht (gleichwertig
  oder Schutz gegen Eingaben, die kein Fall baut) — nicht alle.

Geschlossen mit **18 neuen Prüffällen** (jetzt 133) und **8 neuen
Mutationen** (jetzt 26, alle erkannt). Neu geprüft werden unter anderem die
Restlaufzeit, die Startminute bei Viertelstundenscheiben, die Einheit `w`
(kam in der ganzen Datei nicht vor), der Pfadauflöser für fremdes JSON, die
Gründe `wartet` und `budget` und der Taktschutz samt der Reihenfolge, auf
die sein eigener Kommentar sich beruft.

**Jeder neue Fall ist einzeln geeicht**: die Stelle, die er prüfen soll,
wurde zurückgebaut, und er wurde rot. Ein Fall, der das nicht tut, hebt nur
die Fallzahl. Und weil `planer.php` in diesem und im Octopus-Plugin
byteweise gleich liegt, ist beides in beiden Linien dasselbe.

Dazu entfernt: der Helfer `spot_mqtt_gateway_autostart`, der seit dem
Zusammenlegen in 1.2.13 unbenutzt dastand. Alle drei Stellen, die den
Gateway-Zustand brauchen, brauchen Autostart **und** Fassung — ein Helfer
für nur die Hälfte spart nirgends etwas.

## Fassung 1.2.13 — Durchsicht Zeile für Zeile

Eine vollständige Durchsicht mit einem Prüfstand, der die Seite **wirklich
ausliefert** statt sie nur einzulesen: `php -S` mit einer SDK-Attrappe, deren
`lbheader()` echt ausgibt, unter PHP 7.4 **und** 8.4. Das war nötig, weil am
PHP-CLI `header()` wirkungslos und `headers_sent()` immer falsch ist — die drei
Hauswerkzeuge für die Konfigurationssicherung meldeten für 1.2.11 alle „ok",
während der Knopf *Einstellungen sichern* auf jedem echten LoxBerry eine Seite
statt einer Datei lieferte.

### Vier Fehler, die still waren

**Das Feld Stromsteuer hieß auf Englisch `sexpensive`.** Ein automatischer
Übersetzungslauf hatte das Wort „teuer" **innerhalb** von „steuer" durch einen
Sprachschlüssel ersetzt: `name="s<?= spot_t('TEXT.TEUER') ?>"`. Auf Deutsch
ergab das zufällig wieder `steuer`, auf Englisch `sexpensive`. Das Feld kam
damit nie an, und **jedes Speichern schrieb den Vorgabewert 2,05 ct** statt des
angezeigten Werts — bei einem angezeigten Wert von 9,99 also 7,94 ct/kWh netto
daneben, ohne Meldung und ohne Protokolleintrag. Ein Feldname ist eine
Schnittstelle, kein Anzeigetext.

**Nach dem Zurückspielen zeigte die Seite den alten Stand.** Die Konfiguration
wurde gelesen, bevor der Rückspielzweig lief. Die Meldung sagte „49 Werte
übernommen", jedes Feld auf der Seite zeigte die Werte von vorher — und ein
Klick auf *Speichern* nahm die Sicherung wieder zurück. Der Block steht jetzt
**vor** dem Laden.

**Eine abgelehnte Sicherungsdatei erzeugte gar keine Meldung.** Die
Beanstandungen wurden gesammelt, aber nur innerhalb des Speicherzweigs
ausgegeben; alles, was der Sicherungszweig hineinschrieb, fiel heraus. Die
Ablehnung selbst war vorbildlich — es wurde nichts geändert —, nur erfuhr es
niemand. Ausgegeben wird jetzt an **einer** Stelle für alle Zweige.

**Der Sicherungsblock stand außerhalb jeder Reiterfläche** und war deshalb
unter jedem Reiter sichtbar, auch unter Logdateien. Er steht jetzt im Reiter
*Einstellungen*, zu den Einstellungen, die er sichert.

### MQTT-Gateway V1 und V2 werden unterschieden

Bis 1.2.12 hat das Plugin `Mqtt.Gatewayversion` **nicht gelesen** — und den
Abo-Satz überhaupt nicht gesagt. Wer MQTT einschaltete, bekam die Themenliste
und sonst nichts; unter Gateway V1, der Vorgabe, kam damit am Miniserver kein
einziger Wert an, ohne dass irgendwo stand, warum. Das ist die häufigste
Fehlerursache überhaupt, und sie fehlte hier vollständig.

Der Reiter *Einbindung in Loxone* hat jetzt einen eigenen Schritt 6 mit dem
Thema zum Abschreiben (`<präfix>/#`) und dem Satz, der zur **erkannten
Fassung** passt:

| Erkannt | Was dasteht |
|---|---|
| V1 | „Ohne diesen Eintrag kommt am Miniserver nichts an." |
| V2 | „V2 erkennt die Themengruppe von selbst — einzutragen ist nichts." |
| nicht lesbar | **beide** Sätze, mit dem Hinweis, wo die Fassung steht |

Einen von beiden zu behaupten wäre für die Hälfte der Anlagen falsch. Gemessen
an allen drei Lagen. *(Dass V2 die Themen von selbst erkennt, ist nicht selbst
gemessen — es stammt aus der Oberfläche des fremden MGiSmart-Plugins und passt
zu den Knöpfen, die der LoxBerry-Kern unter V2 abschaltet.)*

### Lebenszeichen: `TS` und `LAUF`

Ein virtueller Eingang behält seinen letzten Wert. Stirbt der Minutenlauf,
steht in Loxone weiter der Preis vom Ausfallzeitpunkt — das ist keine fehlende
Auskunft, sondern eine Falschaussage, und sie sieht aus wie eine richtige. Die
Schaltregeln laufen dann nach einem eingefrorenen Fahrplan weiter.

Neu in der Zeile und über MQTT (`/status/ts`, `/status/zaehler`, `/status/ok`):

* **`TS`** — Zeitpunkt des letzten Laufs in Unix-Sekunden. Der Miniserver
  rechnet selbst: `Alter = (Loxone-Zeit + 1230768000) − TS`.
* **`LAUF`** — ein Zähler, der bei 999 umläuft. Er beantwortet, was der
  Zeitstempel nicht kann: ein Raspberry ohne Echtzeituhr springt beim ersten
  Zeitabgleich, und ein Alter kann danach negativ sein, obwohl alles läuft.

`TS` geht bei **jedem** Durchgang hinaus, auch wenn sich sonst nichts geändert
hat — daran hängt der ganze Zweck. Die Baustein-Liste hat dafür einen neuen
Abschnitt *4g) Ausfallerkennung* mit drei Bausteinen und einer Gegenprobe.

### Der Endpunkt

`?token[]=x` ergab unter PHP 8.4 **HTTP 200 statt 403**: der `(string)`-Wandel
eines Feldes erzeugt die Warnung „Array to string conversion", und die geht
**vor** `http_response_code()` hinaus. Die Abweisung kam beim Aufrufer als
Erfolg an. Behoben mit `is_string()` — erst prüfen, dann alles andere.
Dazu die Trennung lesend/auslösend, siehe oben unter *Endpunkte*.

### Ein Wachposten für die Oberfläche

Alle **zehn** Formulare dieser Fassung trugen ein Merkmal gegen fremde
Absender *(heute sind es zwölf)*. Der
Wachposten steht **einmal** am Kopf der Datei und leert `$_POST`, wenn das
Merkmal fehlt — damit läuft kein Zweig mehr an, ohne dass einer davon davon
wissen muss. Ein „`&& $post`" je Zweig wirkt nur, wenn wirklich jeder daran
hängt, und einen vergisst man.

Das Merkmal liegt in **einer** Quelle, einer Datei im Datenordner; die
PHP-Sitzung ist nur ein Zwischenspeicher mit demselben Wert. Beim Bauen hat
sich gezeigt, warum: `session_start()` gelang auf dem Prüfstand bei einem
Aufruf und beim nächsten nicht — die Seite zeigte dann ein Merkmal aus der
Sitzung, während der Wachposten gegen das aus der Datei verglich. Zwei Quellen
für ein Geheimnis laufen auseinander.

### Reiter Test: eine echte Selbstprüfung

Dreizehn Zeilen mit **drei** Ausgängen — Haken, Kreuz und **Strich**. Der
Strich heißt „nicht feststellbar" und ist ausdrücklich kein Haken; eine
Zusammenfassung, die besser aussieht als ihr schlechtester Punkt, ist
schlimmer als keine. Geprüft wird unter anderem, ob die Konfiguration heil ist,
ob der Minutenlauf noch arbeitet, ob der **eigene Cron-Eintrag** überhaupt
installiert ist, ob die Loxone-Zeile alle angekündigten Felder trägt und ob
Reiterleiste und Flächen zusammenpassen.

Der Aufruf des **eigenen Endpunkts** ist die einzige Zeile, die die getrennten
Verzeichnisbäume findet — das sieht keine Leseprüfung. Er kostet eine
HTTP-Anfrage und läuft deshalb nur auf Knopfdruck; ohne Knopfdruck steht dort
ein Strich. Die ganze Selbstprüfung läuft nur im geöffneten Reiter: alle
Flächen werden serverseitig gerendert, sonst liefe sie bei jedem Klick mit.

### Zwei Tage im Jahr hat ein Tag nicht 24 Stunden

Beide Fälle waren still falsch. Am **Ende der Sommerzeit** (25.10.2026) liefert
aWATTar 25 Stundenpreise, zwei davon auf Stunde 2 — bis 1.2.12 gewann der
zweite und der erste verschwand lautlos (gemessen: 25 Werte hinein, 24 heraus).
Jetzt gilt der erste, der zweite wird vermerkt.

Schwerer wog der **Beginn der Sommerzeit**: Stunde 2 fehlt ganz, und in `PH02`
stand daraufhin `0.000`. Null Cent sieht für jeden Optimierer wie die
günstigste Stunde des Tages aus — eine Zahl, die richtig aussieht und in Loxone
eine Schaltung auslöst. Eine Stunde, die es auf der Uhr nicht gibt, darf nie
gewählt werden; sie bekommt jetzt den **Tageshöchstpreis**. Dasselbe gilt für
`PM00…PM23`, solange die Preise für morgen noch nicht veröffentlicht sind.

### Viertelstunden: gemessen, kein Handlungsbedarf

Am 27.08.2026 an der öffentlichen Schnittstelle nachgemessen:
`api.awattar.de/v1/marketdata` liefert weiterhin **24 Datensätze mit
60-Minuten-Intervallen** in Eur/MWh. Käme feiner aufgelöstes Material an, hätte
der bisherige Code von je vier Viertelstunden drei lautlos verworfen, weil er
nach der Stundenzahl schlüsselt — ein Tag hätte danach völlig normal ausgesehen
und drei Viertel falscher Preise getragen. Die Schrittweite wird jetzt
**gemessen**, alles Feinere zum Stundenmittel zusammengefasst, und der Reiter
Test zeigt die erkannte Auflösung an.

### Neu: eigener Lastgang (ab Werk aus)

Der Tarifvergleich gewichtete den Tagesschnitt bisher mit einem eingebauten
Haushalts-Lastprofil — einer Modellrechnung für einen Durchschnittshaushalt.
Mit Wärmepumpe, Wallbox oder PV liegt die daneben, je nach Verbrauchszeit in
beide Richtungen.

Wer stündliche Verbrauchswerte liefern kann (Zähler-Plugin, eigenes Skript),
bekommt statt dessen eine **Messung**: jede Stunde mit ihrem wirklichen
Verbrauch gegen den Preis derselben Stunde. Gleiche Bauform wie die
PV-Prognose — Adresse, Pfad, Einheit. Verlangt werden mindestens 20 der 24
Stunden; darunter gilt weiter das Profil, denn ein Tagesschnitt aus drei
Stunden sähe aus wie eine Messung. Die Tabelle und die CSV sagen bei **jedem**
Tag, welches von beiden es war.

### Neu: Verlauf als CSV

Der Tarifvergleich behauptet Beträge in Euro. Wer sie nachrechnen will,
braucht die Zahlen, aus denen sie entstanden sind. Ein Knopf im Reiter Test
liefert `history.csv` mit Kopfzeile, Semikolon als Trenner und Komma als
Dezimalzeichen.

### Die Sicherungsdatei

Sie trägt weiterhin den **Aktionstoken** — ohne ihn stünden nach dem
Zurückspielen alle Felder richtig, und der Miniserver käme trotzdem nicht mehr
an das Plugin. Neu ist ein lesbarer Kopf mit Datum und Fassung (`_hinweis`,
`_stand`, `_fassung`); die Leseseite übergeht Schlüssel mit führendem
Unterstrich, statt sie als fremd abzuweisen.

> **Die Datei ist damit geheimnistragend.** Wie ein Passwort behandeln: nicht
> in ein Forum hängen und nicht an einen Fehlerbericht heften. Das
> Formularmerkmal des Wachpostens ist etwas anderes und steht ausdrücklich
> **nicht** darin.

### Gemessen, nicht behauptet

44 Prüfungen an der laufenden Seite, unter PHP 7.4 und 8.4 je 44 bestanden.
Dazu eine Eichung, die jede Korrektur einzeln zurückbaut und nachsieht, ob die
zugehörige Prüfung **rot** wird — eine Prüfung, die auch ohne die Korrektur
grün bleibt, prüft nichts.

## Fassung 1.2.0 — Fahrplaner

Bis 1.1.2 rechnete jede Schaltregel für sich. Wärmepumpe, Wallbox und
Waschmaschine fanden dieselbe günstigste Stunde und schalteten gleichzeitig.
Drei Dinge kommen dazu — **alle drei ab Werk aus**, wer nichts einstellt,
bekommt das Verhalten der Fassung davor:

**Frist und Energiemenge.** Eine Regel kann jetzt sagen „7 kWh bei 3,7 kW,
fertig bis 7 Uhr". Daraus rechnet der Planer die nötige Laufzeit und sucht
nur bis zur Frist — auch wenn es danach billiger wäre. Ist die Uhrzeit heute
schon vorbei, ist morgen gemeint.

**Rangfolge und Leistungsbudget.** Jede Regel bekommt einen Rang und eine
Leistungsangabe, das Plugin ein Gesamtbudget in kW. Geplant wird in
Rangfolge: Rang 1 sucht sich die günstigen Stunden zuerst aus, was er belegt
hat, steht den anderen nicht mehr zur Verfügung. Das ist ein gieriges
Verfahren, kein optimales — dafür in einem Satz erklärbar: *wer vorne steht,
sucht sich zuerst aus.* Wer um drei Uhr nachts wissen will, warum die Wallbox
nicht lädt, bekommt mit `VERD` die Zahl der weggenommenen Stunden und sieht
es sofort.

**PV-Prognose und Speicherstand.** Für jede Stunde mit Sonnenprognose wird
eine Gutschrift vom Preis abgezogen — damit gewinnt die sonnige
Mittagsstunde gegen die billige Nachtstunde. Die Gutschrift steigt linear bis
zu einer Schwelle; eine reine Ja/Nein-Grenze wäre eine Klippe, an der der
Fahrplan bei minimal geänderter Prognose um Stunden springt. Dazu zwei
Sperren je Regel: „nicht laden, wenn morgen mehr als X kWh vom Dach kommen"
und „nur zwischen diesen beiden Speicherständen".

Als Quelle taugt **forecast.solar** (kostenlos, ohne Konto) oder jede eigene
Adresse, die JSON liefert — als Objekt Zeit→Wert oder als Liste von Objekten
mit frei benennbaren Feldern. Für den Speicherstand genügt eine Adresse und
ein Pfad. Beides wird höchstens alle 15 Minuten geholt.

### Der Planer steckt in einer eigenen Datei

`webfrontend/html/planer.php` ist in **diesem und im Octopus-Plugin
byteweise gleich** — dieselbe Rechnung, dieselben Prüffälle. Deshalb trägt
sie das neutrale Kürzel `plan_` statt des Plugin-Kürzels; das ist die einzige
Ausnahme von der Kürzelregel und bewusst gemacht: zwei auseinanderlaufende
Kopien derselben Rechnung wären schlimmer als ein zweites Kürzel.

Sie ist reine Rechnung — kein Netz, keine Dateien, keine Uhr außer dem
übergebenen Zeitpunkt. Deshalb lässt sie sich vollständig durchprüfen:
**137 Fälle, jeder von Hand nachgerechnet**, unter PHP 7.4 und 8.4 alle grün
(53 waren es bei 1.2.0, 101 bei Planerfassung 1.1.0, 133 bei 1.2.14).
Darunter die
Verdrängung durch das Budget, die Frist über Mitternacht, die
Einheitenumrechnung Wh/W/kW und der Fall „PV-Gutschrift lässt die
Sonnenstunde gegen die billigste Stunde gewinnen".

Dazu ein Mutationslauf mit **26 absichtlichen Verfälschungen**, alle
erkannt. Die Zahl der Fälle allein sagt nichts darüber, ob sie die
Rechnung anfassen — erst der Mutationslauf tut das.

**Was das nicht beweist:** dass die Prognosequelle so antwortet, wie sie
soll. Das entscheidet der Dienst am anderen Ende. Der Reiter Einstellungen
zeigt deshalb an, was zuletzt geholt wurde, und nennt den Grund, wenn nichts
ankam.

### Zwei Altfunde nebenbei

**`socket_create()` ohne Absicherung.** Zwei Stellen riefen die Funktion
auf, ohne zu prüfen, ob es die Erweiterung `sockets` überhaupt gibt. Das ist
kein Fehler zur Laufzeit, sondern ein Fatal error: die **ganze Seite** bleibt
weiß, nicht nur die betroffene Zeile — und beim Cron-Lauf steht nichts im
Protokoll, was darauf hinweist. Aufgefallen beim Rendern in einem PHP ohne
die Erweiterung. Beide Stellen haben jetzt einen Rückfallweg über
Datenströme; das Octopus-Plugin machte es an derselben Stelle seit jeher
richtig, die beiden waren auseinandergelaufen.

**Zwei Sprachschlüssel mit Anführungszeichen im Wert.** `<span class="…">`
innerhalb eines quotierten INI-Wertes — HTML-Attribute gehören dort einfach
gequotet. Betroffen waren `TOKEN_AKTIV` und `TOKEN_ERKLAERUNG` in beiden
Sprachdateien.

## Fassung 1.1.2 — nachgemessen und korrigiert

Sechs Punkte aus einer Durchsicht. Vier trafen zu, einer teilweise, einer
beschrieb Code, den es hier nicht gibt. Alles wurde nachgestellt, bevor
etwas geändert wurde.

### Auch die Zwischenspeicher werden unteilbar geschrieben

1.1.1 hatte das `.tmp`+`rename`-Muster auf die `spot.json` gebracht. Drei
Zwischenspeicher schrieben weiter mit einfachem `file_put_contents`: der
Rohabruf von aWATTar, der Zustand (`state.json`) und die CO₂-Werte.

An `state.json` hängen **zwei Schreiber** — der Minutencron und `spot.php`,
wenn der Zwischenspeicher abgelaufen ist — und ein Leser, der bei jedem Abruf
des Miniservers vorbeikommt. `file_put_contents` kürzt die Datei zuerst auf
null.

**Falsche Werte bekommt Loxone dadurch nicht**: `spot_state()` prüft die
gelesene Struktur und rechnet bei Bruch neu. Genau das ist aber der Schaden —
aus einem billigen Lesevorgang wird eine vollständige Neuberechnung, im
schlechtesten Fall mit einem Abruf bei aWATTar, während der Miniserver auf
seine Antwort wartet.

Mit erledigt: `json_encode()` gibt bei ungültigem UTF-8 `false` zurück, und
`file_put_contents($f, false)` schreibt klaglos eine leere Datei und meldet
Erfolg. Der Zwischenspeicher wäre damit dauerhaft unbrauchbar, ohne dass etwas
auffiele — jede Abfrage rechnete neu. `spot_write_json_atomic()` fängt das ab.

### Der Monatsbericht hing an einer Minute

Trifft zu. Die Bedingung lautete `date('H:i') === '08:05'` — das Fenster ist
damit genau **eine Minute** breit. Nachgemessen:

| Der Cron-Lauf startet um | Bericht |
|---|---|
| 08:05:00 – 08:05:59 | kommt |
| 08:06:00 oder später | fällt aus — für den **ganzen Monat** |

Ein Lauf, der sich unter Last um eine Minute verspätet oder ganz ausfällt
(Neustart, Update, Stromausfall um 8 Uhr), kostet den Bericht bis zum
nächsten Monatsersten. Jetzt: am Ersten ab 8 Uhr, sobald der Merker für
diesen Monat fehlt.

**Wo der Merker liegt, ist der ganze Witz.** Der vorgeschlagene Ort
`/tmp/spotpreis/month_report_YYYYMM.done` wäre falsch — `/tmp` ist auf dem
LoxBerry flüchtig. Nachgestellt:

```
Merker im Datenordner, /tmp geleert  -> übersprungen  (richtig)
Merker in /tmp,        /tmp geleert  -> weg -> Bericht käme ein zweites Mal
```

Der Merker liegt deshalb in `data/plugins/<ordner>`, das Neustart *und*
Plugin-Update übersteht, und wird mit `fopen($f, 'x')` angelegt — das schlägt
fehl, wenn er schon da ist, und zwar unteilbar.

**Nebenbefund an derselben Stelle:** Der Merker *„Preise für morgen sind
veröffentlicht"* lag bereits in `/tmp`. Ein Neustart nach 14 Uhr — und die
Ansage samt Pushnachricht kam am selben Tag ein zweites Mal. Auch er liegt
jetzt im Datenordner. Kurzlebige Merker (`ptest`, `said_`, MQTT-Signatur)
bleiben bewusst in `/tmp`: dort ist „nach einem Neustart von vorn" genau
richtig.

### Die Ansagen waren fest auf Deutsch

Trifft zu — und betrifft mehr als den Monatsbericht. Fest verdrahtet waren
**alle** Sprachausgaben: die stündliche Ansage, die Meldung „Preise für
morgen sind da" und der Monatsbericht. Sie kommen jetzt aus dem neuen
Abschnitt `[ANSAGE]` der Sprachdateien.

Dabei fiel eine Kleinigkeit auf, die sich nur im Betrieb zeigt:
`spot_num()` machte aus `24.35` immer `24,4` — mit Komma. Ein englisches TTS
liest „24,3" als zwei Zahlen. Das Dezimalzeichen richtet sich jetzt nach der
Sprache.

Entfallen ist damit auch ein `str_replace` über neun Wörter am Ende von
`spot_announce_text()`, das die umlautfreien Schreibweisen im Quelltext
wieder zurückverwandelte. In den Sprachdateien stehen die Umlaute unmittelbar.

### Die Adresse zum Hausspeicher wurde nicht geprüft

Trifft zu, mit einer Einschränkung bei der Einordnung. Nachgemessen mit
PHP 7.4 und 8.1, jeweils mit dem http-Kontext des Plugins:

| Eingabe | Ergebnis |
|---|---|
| `file:///pfad/datei` | Datei wird **gelesen** |
| `php://filter/…resource=…` | Datei wird **gelesen** (base64) |
| `expect://id` | nichts — Erweiterung nicht vorhanden |
| `ftp://…` | nichts |

Die Antwort landet im Protokoll, und das Protokoll zeigt die Oberfläche an.
Wer die Adresse setzen konnte, holte sich damit beliebige für den Webserver
lesbare Dateien.

**Eine Codeausführung ist das allerdings nicht.** `file_get_contents()` führt
nichts aus; es ist ein Lesezugriff und ein Aufruf an beliebige Rechner und
Ports (SSRF). Und dafür braucht es bereits Zugang zur *angemeldeten*
Plugin-Oberfläche. Erlaubt sind jetzt nur noch `http` und `https`, geprüft an
zwei Stellen — beim Speichern und vor dem Absetzen des Befehls. Eine
abgewiesene Eingabe lässt den bisherigen Wert stehen, statt ihn zu leeren.

### Das Protokoll wurde ganz eingelesen

Befund richtig, vorgeschlagene Abhilfe falsch. An einer Datei kurz vor der
Rotationsgrenze (512 kB, 6384 Zeilen, 300 gewünscht), 7.4 und 8.1 gleich:

| Verfahren | Zeit | Speicherspitze |
|---|---|---|
| `file()` + `array_reverse` (bisher) | 0,3 ms | 1445 kB |
| `exec("tail -n 300")` (Vorschlag) | 1,9 ms | 72 kB |
| rückwärts mit `fseek` (jetzt) | **0,04 ms** | 123 kB |

`tail` ist der langsamste der drei Wege: ein Prozessstart kostet mehr, als
das Einlesen je gespart hat. Rückwärts lesen ist in beidem besser und braucht
keine Shell.

### Was nicht zutraf

**Der `/tmp`-Fehler in `preupgrade.sh`.** Beanstandet war die Zeile
`mkdir -p "/tmp/${ARGV3}_upgrade"` — die es hier nicht gibt. Das Skript
benutzte bereits `$ARGV1`, also genau das, was vorgeschlagen wurde.

Bei der Gelegenheit geprüft, was `$1` überhaupt ist: **kein Pfad**, sondern
eine zehnstellige Zufallskennung (`&generate(10)` in `plugininstall.pl`). Der
absolute Arbeitsordner kommt als **sechstes** Argument. Das alte Skript lief
also nur, weil der Installer vorher in seinen Arbeitsordner wechselt — es
entstand ein relativer Ordner mit dem Namen der Kennung darin. Ein Skript,
das nur wegen des Arbeitsverzeichnisses des Aufrufers funktioniert, ist eine
Falle für den Nächsten; der Arbeitsordner wird jetzt ausdrücklich benutzt,
mit Rückfall auf den bisherigen Weg. Die Sicherung des **Protokolls** ist
entfallen: `log/plugins` liegt auf dem LoxBerry fest auf der Ramdisk und ist
nach jedem Neustart ohnehin leer.

**Das Lob für die Konfigurationsdateien.** Es hieß, das `.tmp`+`rename`-Muster
sei bei den JSON-Dateien bereits vorbildlich umgesetzt und fehle nur bei den
kleinen Merkdateien. Es war umgekehrt: die `spot.json` wurde mit einem
einfachen `file_put_contents` geschrieben, also gekürzt und neu gefüllt. Beide
Stellen schreiben jetzt über `spot_config_save()` mit temp und `rename`.

### Zusätzlich

Der Endpunkt `spot.php` liegt im unangemeldeten Bereich und kann mehr als
lesen: `?say=1` spielt eine Ansage über die Lautsprecher, `?ptest=1` löst eine
Pushnachricht aus. Es gibt jetzt ein **freiwilliges** Token im Reiter
*Einbindung in Loxone* (Vergleich mit `hash_equals`). Freiwillig, weil ein
Pflichttoken bei jedem bestehenden Aufbau die Werte im Miniserver abreißen
ließe; die Knöpfe auf der Plugin-Seite führen es automatisch mit. Außerdem
neu: `prerelease.cfg`, die bisher fehlte, obwohl `PRERELEASECFG` gesetzt war.

## Fassung 1.1.1 — aufgeräumt

### Der Cron-Lauf lag im unangemeldeten Web-Verzeichnis

`cron.php` stand unter `webfrontend/html/`. LoxBerry veröffentlicht diesen
Ordner als `/plugins/<ordner>/` **ohne Anmeldung** — jeder im Netz konnte

    http://<loxberry>/plugins/spotpreis/cron.php

aufrufen und damit den Minutenlauf auslösen: Sprachansage, Pushnachricht,
MQTT-Veröffentlichung und, wenn eingeschaltet, `spot_marstek_control()` —
ein HTTP-Aufruf an den Hausspeicher mit Ladeleistung und Laufzeit.

Ein Cron-Skript wird von der Kommandozeile gestartet, nicht vom Browser. Es
liegt jetzt unter `bin/`, wo es über HTTP gar nicht erreichbar ist; `cron.01min`
zeigt auf `REPLACELBPBINDIR` statt `REPLACELBPHTMLDIR`. Die Bibliothek
`spot_lib.php` bleibt unter `webfrontend/html/`: sie wird auch vom
Miniserver-Endpunkt und von der Oberfläche gebraucht, definiert aber nur
Funktionen — ein Aufruf über HTTP liefert nichts.

### Reste eines automatischen Übersetzungslaufs

- **Drei Kommentare** gingen durch `spot_t()`. In einem war sogar ein Wort
  zerschnitten: `spot_t('TEXT.EINHEIT') . 'liches'` für „Einheitliches" — auf
  Englisch wäre daraus „Unitliches" geworden. Kommentare sind für den
  Entwickler, nicht für den Anwender; sie stehen wieder als Klartext da.
- **Fünf Schlüssel waren angelegt, aber nirgends angeschlossen**
  (`GNSTIG`, `SAUBER`, `BRSENPREIS_NEGATIV`, `FESTER_TARIF_IST_GNSTIGER_UM`,
  `DEN_GEPFLEGTEN_MONATSMENGEN`). Sie gehören zu Texten, die in PHP-Ternären
  stehen — die der automatische Lauf nicht erwischt hat. Sie sind jetzt
  angeschlossen, nicht gelöscht.
- **Rund 50 weitere sichtbare Texte** hatten noch gar keinen Schlüssel:
  Monatsnamen, Spaltenköpfe der Baustein-Tabellen, die Sätze des
  Kostenvergleichs, die Achsenbeschriftung des Diagramms und die Meldungen
  nach einem Abruf.

Beide Sprachdateien hatten **mit 1.2.11 619 Schlüssel und waren
deckungsgleich**; jeder wurde benutzt, keiner fehlte, die Zahl der
`%s`-Platzhalter stimmte in beiden Sprachen überein. *(Die Zahl ist die von
damals. Heute sind es 893 — sie steht hier, weil dieses Kapitel eine
Fassung beschreibt, nicht den heutigen Stand.)*

Nur `ALLGEMEIN.JA`, `.NEIN` und `.SPEICHERN` waren tatsächlich tot und sind
entfernt. `REITER.MQTT` wurde damals mit entfernt, weil der Reiter in 1.1.1
kurzzeitig fehlte — er ist seither zurück, und der Schlüssel mit ihm.

### Weiteres

- **`uninstall/uninstall` fehlte.** Beim Deinstallieren blieben
  `config/plugins/<ordner>.backup.json` (die Sicherung der Tarifeinstellungen,
  bewusst *neben* dem Konfigordner) und `/tmp/spotpreis` liegen. Im MQTT-Broker
  bleibt nichts stehen — das Plugin sendet mit `publish`, nicht mit `retain`.
- **Die Reiter waren `<div>`, keine Verweise**, und den Reiterwunsch nahm die
  Seite nur per POST an. Alle Flächen stehen bis zum Lauf des JavaScripts auf
  `display:none` — ohne JavaScript war die Seite leer, und auf einen Reiter
  verlinken ging nicht. Jetzt echte Links mit `?tab=…`, und der Server setzt
  `sm-active` an Reiter und Fläche.

Bewusst **nicht** übersetzt: die 41 Loxone-Feldnamen (`Prio`, `MinSoc`, `ANN`),
die Protokolleinträge und die Voreinstellung `Wärmepumpe` — letztere ist ein
gespeicherter, vom Anwender änderbarer Wert, kein Oberflächentext.

## Lizenz

MIT — siehe [LICENSE](LICENSE).

## Marken und Gewährleistung

aWATTar ist eine Marke der aWATTar GmbH, Energy-Charts ein Angebot des
Fraunhofer-Instituts für Solare Energiesysteme ISE. Dieses Plugin steht in
keiner Verbindung zu diesen Einrichtungen und wird von ihnen weder
herausgegeben noch unterstützt. Es benutzt lediglich deren öffentlich
zugängliche Schnittstellen.

Die Preise dienen der Steuerung im eigenen Haus. Maßgeblich für die
Abrechnung ist allein das, was der Stromlieferant in Rechnung stellt — die
hier eingestellten Preisbestandteile sind Richtwerte und müssen selbst
geprüft werden.
