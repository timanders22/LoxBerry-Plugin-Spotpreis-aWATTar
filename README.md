# LoxBerry-Plugin: Spotpreis aWATTar

Holt die stündlichen Börsenstrompreise (EPEX SPOT Day-Ahead) über die offene
**aWATTar-API** (Deutschland oder Österreich), rechnet sie mit den eigenen
Preisbestandteilen auf den **Endpreis in ct/kWh** hoch und liefert sie an Loxone,
per MQTT und als JSON — mit stündlicher Sprachansage und Push-Auslöser.

Kein Konto, kein API-Key, keine Cloud-Bindung. Kompatibel mit LoxBerry 3.x und
**LoxBerry 4** (reines PHP, läuft mit PHP 7.4 und 8.x).

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
- Preisdiagramm (heute + morgen) in der Oberfläche, Tageswert-Historie
- Reiter: Einstellungen, Einbindung in Loxone (Schritt-für-Schritt inkl.
  kompletter Baustein-Liste zum 1:1-Nachbauen), Test, Logdateien
- Konfiguration und Log überleben Updates und Neuinstallation

## Endpunkte

| Aufruf | Zweck |
|---|---|
| `/plugins/spotpreis/spot.php` | Loxone-Zeile `SPOT;OK=..;MINH=..;…;CUR=..;RANK=..;LEVEL=..;WINH=..;ANN=..` |
| `/plugins/spotpreis/spot.php?debug=1` | alle Stundenpreise heute + morgen |
| `/plugins/spotpreis/spot.php?refresh=1` | Marktdaten sofort neu abrufen |
| `/plugins/spotpreis/spot.php?json=1` | kompletter Zustand als JSON |
| `/plugins/spotpreis/spot.php?say=1` | Test-Ansage (aktueller Preis) |
| `/plugins/spotpreis/spot.php?saytomorrow=1` | Test-Ansage (Preise für morgen) |
| `/plugins/spotpreis/spot.php?ptest=1` | Test-Pushnachricht auslösen |

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
**53 Fälle, jeder von Hand nachgerechnet**, unter PHP 7.4 und 8.2 alle grün.
Darunter die Verdrängung durch das Budget, die Frist über Mitternacht, die
Einheitenumrechnung Wh/W/kW und der Fall „PV-Gutschrift lässt die
Sonnenstunde gegen die billigste Stunde gewinnen".

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

Beide Sprachdateien haben jetzt **619 Schlüssel und sind deckungsgleich**;
jeder wird benutzt, keiner fehlt, die Zahl der `%s`-Platzhalter stimmt in
beiden Sprachen überein.

Nur `REITER.MQTT` sowie `ALLGEMEIN.JA`, `.NEIN` und `.SPEICHERN` waren
tatsächlich tot — der MQTT-Reiter existiert nicht mehr. Sie sind entfernt.

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

## Datenschutz

Es sind **keine persönlichen Daten** im Plugin enthalten. Alle Einstellungen
liegen lokal (`config/plugins/spotpreis/spot.json`). Externe Verbindungen gibt
es ausschließlich zur öffentlichen aWATTar-Preis-API (ohne Kennung).

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
