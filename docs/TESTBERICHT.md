# Prüfbericht – 12.10.2026

## Ausgeführt

- **75 automatisierte PHP-Tests erfolgreich**, unter PHP **8.2.34**.
- Darin **1.000 deterministisch erzeugte Verteilungen** mit beiden Modi,
  verschiedenen Rastern, Grenzen, Prioritäten und Laufzuständen.
- **10 JavaScript-Tests erfolgreich**, tatsächliche tile.js in Node mit DOM-Testdouble.
- Deutsche Komma-Eingaben, Nachkommastellen und die Übernahme numerischer
  Einstellungen aus dem Formularentwurf geprüft. Ampereanzeigen besitzen eine,
  andere Dezimalanzeigen höchstens zwei Nachkommastellen; Messwerte bleiben intern
  unverändert präzise.
- Zusätzliche Chromium-Prüfung der tatsächlichen HTML/CSS/JS-Kachel: sieben Größen
  von 240 × 260 bis 1440 × 900 Pixeln, kompakte aufklappbare Detailwerte,
  erreichbare Bedienung und keine horizontale Überbreite.
- Helle/dunkle Symcon-Farbparameter und CSS-Variablen als Testvorgaben,
  Wechsel ohne Neuladen und Browser-Fallback geprüft; zusätzlich vier Wallboxen,
  optionaler Speicher und lange Fehlertexte.
- Alle **16 PHP-Dateien** syntaktisch geprüft; alle **7 vorhandenen JSON-Dateien**
  syntaktisch geprüft (einschließlich lokalem Testergebnis).
- Metadaten, unterschiedliche gültige GUIDs, Präfix, Modulstruktur,
  vollständige Formularfelder und Beispielkonfigurationen geprüft.
- **59 API-Einträge** aus der heruntergeladenen offiziellen Symcon-Dokumentation
  geprüft, siehe [API-Nachweise](API.md).
- Installationspaket enthält Modul, Bibliotheken, Dokumentation, Beispiele und
  Tests; portable Laufzeiten und Recherchekopie sind ausgeschlossen.

## Abdeckung der angeforderten Abnahmekriterien

| Nr. | Bereich | Automatisierte Prüfung |
|---|---|---|
| 1 | Zwei / vier Wallboxen gleichmäßig | Fachtest und Adapter mit vier Geräten |
| 2 | Priorität | Rangfolge und gleichrangige faire Verteilung |
| 3 | Maxima / Restleistung | Sättigung und Umverteilung |
| 4 | Budget unter Summe der Minima | Zulässige Teilmenge und Rotation |
| 5 | Unterschiedliche Phasen | Ein-/dreiphasiger Mix in W |
| 6 | A/W/kW und Rundung | Raster, aufgerundete Minima und abgerundete Maxima |
| 7 | Individueller Stop 1 A | Fachtest und numerische Geräteaktion ohne Boolfreigabe |
| 8 | Externe Sperre übersteuert Laufzeit | Planer und Adapter |
| 9 | Zeitbedingungen / Hysterese | Dauerhaftes Startbudget, Nachlauf, Laufzeit und Pause |
| 10 | Strikt / erlaubte Überschreitung | Betragslimit, Dauerlimit und harter Grenzwert |
| 11 | Speicherfreigaben | Manueller/externer Stopp und neue Verteilung |
| 12 | SoC / Hysterese | Reserve, Erholungsgrenze und ungültiger SoC |
| 13 | Entladevorzeichen | W/kW, positiv/negativ sowie Integerabrundung |
| 14 | Keine doppelte Anrechnung | Wiederholte Regelzyklen mit gleicher Speichermessung |
| 15 | Fehlende Speicherleistung | Keine unbestätigte Erhöhung, Teilbestätigung, Timeout |
| 16 | Phasenumschaltung | Stillstand, Vor-/Nachwartezeit, Bestätigung, Fremdwechsel, Timeout |
| 17 | Energieintegration / Zähler | Gemeinsamer Cursor, Wh/kWh, positive Differenzen |
| 18 | Neustart / Reset / Verknüpfung | Persistenz, neue Basen, Gerätearchiv, Gesamtenergie |
| 19 | Ungültige Variablen / Aktionen | Fehlende Aktion, false, Exception, gelöschte Variable, Heartbeat |

Weitere Regressionstests prüfen Zielübernahme zwischen Instanzen, Wiederholung
durch Watchdog, Lastabbau vor Erhöhung, Umkonfiguration alter Ziele, Runtime-Modus,
Geschützter Reset, gültige selten gebündelte Energiezähler und den Erhalt des
Gerätearchivs nach Beginn einer neuen Integration.

JavaScript prüft echte Aktions-Idents und Datentypen, sichtbare externe Sperren,
deutsche Zahlen/Einheiten, sichere Textausgabe von Namen, Zeiten, Fehler und
inkrementelle Kartenaktualisierung. Dabei wird kein echter Browser gerendert.

## Nicht ausgeführt

Es war keine laufende Symcon-Installation und keine Wallbox-/Speicherhardware
verfügbar. Daher sind Installation im Kernel, tatsächliche Geräteaktionen,
elektrische Lastreaktionen, reale Phasenwechsel, Symcon-Persistenz im Dienst
und die Kacheleinbindung noch praktisch zu prüfen.

Die Kachel wurde in Chromium gerendert und in heller/dunkler Darstellung visuell
geprüft. Eine echte Symcon-Einbettung mit deren SDK sowie reale Smartphones und
Tablets bleiben zu prüfen. [Lokale Vorschau](preview.html) ist vorhanden.

Das Kernel-Testdouble belegt die Adapterlogik unter den dokumentierten
Schnittstellenannahmen. Es ersetzt keine Kernel-, Berechtigungs-, Geräte- oder
Visualisierungsabnahme. Die vollständige [Checkliste](ABNAHME.md) bleibt offen.

## Reproduzieren

PHP-Laufzeit mit mindestens PHP 8.2 und Node benötigt:

    php -n tests/run.php
    php -n .dev/verify.php
    node tests/tile.test.cjs

Optionaler Layout-/Design-/Bedienungstest mit installiertem Playwright und Chromium:

    node tests/tile.browser.cjs

Ein abweichender Browserpfad kann über WBM_CHROMIUM_PATH angegeben werden.

Auf diesem Arbeitsplatz kann statt php auch .tools/php/php.exe bzw.
.tools/php82/php.exe verwendet werden. Die lokale Ergebnisdatei
tests/results.local.json enthält jeden PHP-Test mit bestanden/Fehler.
