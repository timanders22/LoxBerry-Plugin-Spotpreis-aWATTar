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

## Datenschutz

Es sind **keine persönlichen Daten** im Plugin enthalten. Alle Einstellungen
liegen lokal (`config/plugins/spotpreis/spot.json`). Externe Verbindungen gibt
es ausschließlich zur öffentlichen aWATTar-Preis-API (ohne Kennung).

## Lizenz

MIT — siehe [LICENSE](LICENSE).
