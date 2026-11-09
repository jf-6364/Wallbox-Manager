# Prüfbericht – 09.11.2026

## Ausgeführt

- **133 automatisierte PHP-Tests erfolgreich**, unter PHP **8.2.34**.
- Darin **1.000 deterministisch erzeugte Verteilungen** mit beiden Modi,
  verschiedenen Rastern, Grenzen, Prioritäten und Laufzuständen.
- **15 JavaScript-Tests erfolgreich**, tatsächliche tile.js in Node mit DOM-Testdouble.
- PV-Budgetumschaltung geprüft: eigener Formularbereich, Bool unverbunden/false/
  true, wiederholter Wechsel und Rückkehr zum Normalbudget. Nur die gewählte
  Budgetquelle wird ausgewertet; ungültige inaktive Daten stören die Regelung
  nicht. Fehlende, nichtendliche oder veraltete aktive Werte stoppen ohne Fallback.
  W/kW, 0/negative Werte, ungültige Boolsignale, Nur-Lese-Zugriffe und Referenzen
  sind abgedeckt. PV begrenzt auch bei erlaubten Überschreitungen und Speicher.
  Der native Adapter setzt den Speicher neutral und nimmt ihn nach dem Rückwechsel
  wieder auf. 7.000 W mit dreiphasigen Mennekes-Sollwerten, 0,5-A-Raster und 1-A-Stopp
  ergeben eine zulässige Verteilung von 6.900 W. Tarifsignal und Regelsignal können
  getrennt oder mit derselben Boolvariable genutzt werden. Die Kachel wechselt
  zwischen „Budget“ und „PV-Budget“ ohne zusätzliche Bedienelemente.
- Deutsche Komma-Eingaben, Nachkommastellen und die Übernahme numerischer
  Einstellungen aus dem Formularentwurf geprüft. Ampereanzeigen besitzen eine,
  andere Dezimalanzeigen höchstens zwei Nachkommastellen; Messwerte bleiben intern
  unverändert präzise.
- Wallboxanzahl 1–4 geprüft: Dropdown und sofortige Formularvorschau, Speicherung
  erst mit „Übernehmen“, Ausblenden ohne Einstellungsverlust, Stoppen mit
  Rückmeldung beim Reduzieren, erneutes Aktivieren sowie Erhalt von Energie und
  Abrechnungen. Bestehende Konfigurationen behalten standardmäßig vier Plätze.
- Umverknüpfen geprüft: abgewählte IDs werden weder gelesen noch beschrieben.
  Bereits gespeicherte Stoppaufträge werden vor Gerätezugriffen bei Regelung,
  Übernehmen und Destroy bereinigt, auch bei gelöschten/gesperrten alten Variablen
  und teilweise geänderten Verknüpfungen. Referenzen, Ereignisabonnements und
  Reservierungen werden freigegeben. Andere Instanzen können diese Ziele übernehmen.
  Gerätefreigaben und Speicherwechsel sind abgedeckt. Die Stillstandsprüfung
  aktueller Wallboxen bleibt wirksam; Energiearchive und vorhandene PDFs bleiben
  erhalten, laufende Abrechnungen werden beim Gerätewechsel abgeschlossen.
- Gemeinsame Einstellungen geprüft: Zeitbedingungen, Abrechnung, PV-Signal und
  Rechnungsempfänger gelten für alle vier Plätze. Migration übernimmt einmalig
  Wallbox 1 und erhält später gesetzte gemeinsame Werte auch bei Create/Neustart.
  Alte Configuration-Schnappschüsse bewahren ihre Tarifwerte; ein gemeinsamer
  PV-/Preiswechsel wird für beide gleichzeitig laufenden Ladegänge verarbeitet.
  PDFs bleiben je Wallbox getrennt. Das Formular zeigt keine alten Einzelbereiche.
- Zusätzliche Chromium-Prüfung der tatsächlichen HTML/CSS/JS-Kachel: acht Größen
  von 240 × 260 bis 1440 × 900 Pixeln, flache Hauptansicht, eine aufklappbare
  Detailebene, erreichbare Bedienung und keine horizontale Überbreite.
- Anzeigenaktualisierung mit kontrollierter Uhr geprüft: 200 eingehende
  Telemetrie-Meldungen werden erst beim 2,5-Sekunden-Takt einmal geparst und
  angezeigt; dabei gilt der neueste Stand. Identische Werte verursachen in
  Hauptansicht und Dashboard keine DOM-Änderungen. Countdown-Werte berücksichtigen
  die Empfangszeit. 200 Mausbewegungen vor einem Bildaufbau benötigen nur eine
  Diagramm-Geometriemessung; weitere Bewegungen nutzen den Cache. Ausgeblendete
  Ansichten pausieren und übernehmen beim Sichtbarwerden den neuesten Stand.
  Aufräumen beendet Timer und Listener. Der Dashboard-Button ist oben mit
  identischer Buttonformatierung erreichbar, auch unter der Symcon-Kopfzeile.
- Helle/dunkle Symcon-Farbparameter und CSS-Variablen als Testvorgaben,
  Wechsel ohne Neuladen und Browser-Fallback geprüft; zusätzlich vier Wallboxen,
  optionaler Speicher und lange Fehlertexte.
- Energie-Dashboard in Chromium geprüft: Ringsegmente und Verbrauchsliste für
  eine bis vier Wallboxen, Gesamtenergie und Prozentanteile, Maus-Hover,
  Touch-Ereignisse, Tastaturfokus, Escape, Fokusbegrenzung und Symcon-Kopfzeile.
  Live-Aktualisierungen erhalten vorhandene Diagrammelemente und den Fokus.
  Nullverbrauch, archivierte Energie, deaktivierte Plätze und ältere Telemetrie
  sind abgedeckt. Die Dashboard-Bedienung löst keine Gerätebefehle aus.
- Die zusätzliche archivierte Platzenergie verändert weder persistente
  Zählerstände noch Geräte-Sollwerte. Übertragung und bestätigter Reset sind
  durch einen Adaptertest geprüft.
- Ladeabrechnung geprüft: Ereignisgrenzen für PV-/Normalmodus, Preiswechsel,
  kostenloser PV-Tarif, unabhängige Wallboxen, kurze/lange Pausen, Leistungsschwelle,
  Messausfälle und veraltete Werte. Energie-Reset und Neustarts erhalten die
  Abrechnung. PDF-Schreibfehler einschließlich einer Unterbrechung nach dem
  Schreiben werden ohne doppelte Medien und ohne Sperre der Laderegelung behandelt.
- Echte A4-PDFs mit deutschen Zeichen, Centbeträgen, Byte-Offsets und mehreren
  Seiten geprüft. Eine Beispielabrechnung wurde zusätzlich mit `pdftotext`
  gelesen und als Bild gerendert. Chromium prüft Abrechnungsanzeige und
  PDF-Download ohne Geräteaktion sowie den Hell-/Dunkelmodus und kleine Kacheln.
- Rechnungsdaten und Nummernkreis geprüft: gemeinsamer Empfänger, gemeinsame
  Absender-/Bankdaten, fortlaufende Nummern und unveränderte Datenschnappschüsse
  bei Wiederholungen nach Schreibfehlern. Neue Rechnungen überschreiben keine
  vorhandenen PDFs. Zahlungsfrist über die Zeitumstellung, lange Adressen und
  mehrseitige Zahlungstexte sind abgedeckt.
- Umsatzsteuer einbeziehen/ignorieren sowie §-19-Auswahl geprüft. Bei enthaltenen
  19 % ergeben 1,89 EUR Endpreis 1,59 EUR netto und 0,30 EUR USt. Steuer und Netto
  stimmen centgenau mit dem unveränderten Endpreis überein.
- UI in Chromium mit Systemschrift, großen leichten Leistungswerten, flachen
  Bereichen, SVG-Linienicons und Mint-/Türkisakzenten geprüft; Symcon-Farben
  haben Vorrang. Verteilungsbuttons sowie manuelle Ein/Aus-Schalter senden
  korrekt typisierte Aktionen. Beide Tarife in ct/kWh und Ladegangkosten sind
  direkt sichtbar, der PDF-Download benötigt nur eine zusätzliche Detailebene
  und nennt die Rechnungsnummer. Hell-/Dunkelmodus und alle vorhandenen
  Größenprüfungen bleiben abgedeckt. Die Modulansicht enthält keinen eigenen
  Titel oder Vergrößern-Pfeil; der Dashboard-Button bleibt erreichbar. Eine
  nachgestellte Symcon-Kopfzeile wird genau einmal angezeigt.
- Überlagernde Symcon-Kopfzeile nachgestellt: Bedienelemente und Dashboard bleiben
  bei 938 × 782, 430 × 375 und 240 × 260 Pixeln unter der Kopfzeile erreichbar.
  Geänderte/ausgeblendete Kopfzeilen und bereits vorhandene Abstände sind geprüft;
  ein Sandbox-iframe verwendet den Abstand auch bei einer außerhalb liegenden
  Kopfzeile. Budgetmodus und Aktualisierungszeit werden nicht mehr im Footer gerendert.
- Alle **23 PHP-Dateien** syntaktisch geprüft; alle **7 vorhandenen JSON-Dateien**
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
durch Watchdog, Lastabbau vor Erhöhung, Bereinigung abgewählter Ziele, Runtime-Modus,
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
