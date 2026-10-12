# Wallbox Manager

Installierbare PHP-Modulbibliothek für **IP-Symcon ab 7.1**. Eine Instanz verwaltet
eine bis vier Wallboxen über vorhandene Variablen und deren Geräteaktionen sowie
optional einen Batteriespeicher. Die Instanz besitzt eine eigene HTML-SDK-Kachel.

## Installation

Die Bibliothek liegt vollständig in diesem Projektordner. Das Paket
`Wallbox-Manager-1.0.zip` enthält sie ohne portable Laufzeit und Recherchedateien.

**Lokale Installation für Entwicklung / erste Abnahme:**

1. Das Datenverzeichnis des Symcon-Servers feststellen, bei Bedarf mit
   `echo IPS_GetKernelDir();` in einem Symcon-Skript.
2. Das ZIP so in dessen `modules`-Verzeichnis entpacken, dass
   `modules/Wallbox-Manager/library.json` und
   `modules/Wallbox-Manager/WallboxManager/module.php` vorhanden sind.
   Der Ordner muss auf dem **Symcon-Server** liegen.
3. Symcon-Dienst neu starten, damit die lokale Bibliothek eingelesen wird.
4. **Instanz hinzufügen → Wallbox Manager** wählen (Herstellergruppe „Sonstige“).

**Installation über GitHub mit Updatefunktion:**

1. Unter **Kern Instanzen → Modules → +** diese Repository-URL hinzufügen:
   `https://github.com/jf-6364/Wallbox-Manager`
2. Den Branch `main` auswählen und die Bibliothek installieren.
3. **Instanz hinzufügen → Wallbox Manager** wählen (Herstellergruppe „Sonstige“).

Die Struktur und der Repository-Installationsweg folgen dem
[offiziellen PHP-SDK](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
und [Module Control](https://www.symcon.de/de/service/dokumentation/modulreferenz/kern-instanzen/module-control/).

## Erste Instanz konfigurieren

1. Numerische **Budgetvariable** und W/kW wählen. Managerfreigabe zunächst aus.
2. Einen Wallbox-Platz aktivieren. Name, Gerätekennung, Sollwertvariable mit Aktion,
   Ladeleistungsmessung, Einheiten, Strom-/Leistungsgrenzen, Raster und den
   **vom konkreten Gerät vorgesehenen Stoppwert** eintragen.
3. Fest ein-/dreiphasigen Betrieb und Spannung einstellen. Dynamische Umschaltung
   erst nach der Geräteabnahme aktivieren.
4. Optional externe Freigabe, Ladebedarf und separaten Gerätefreigabebefehl
   verknüpfen. Diese Funktionen unterscheiden sich.
5. Zeitbedingungen und Messwertüberwachung passend zur Geräteabfrage wählen.
   Ein Heartbeat kann die Überwachung unveränderter Messwerte übernehmen.
6. Übernehmen und Diagnose prüfen. Das Modul sendet zuerst Stopp und wartet auf
   niedrige tatsächliche Last; danach gelten Mindestpause und Startverzögerung.
7. Nach der [Abnahmecheckliste](docs/ABNAHME.md) Manager und gewünschte manuelle
   Freigaben einschalten. Externe Sperren bleiben verbindlich.

Im Normalbetrieb verwendet das Modul `RequestAction`. Nur die ausdrücklich
aktivierte **Simulation** erlaubt direkte Änderungen an Testvariablen ohne
Aktion. Befehls- und Phasenfehler bleiben bis „Gerätefehler nach Prüfung quittieren“
gesperrt. Vorübergehend ungültige Messwerte werden erneut geprüft.

## Budget und Verteilung

**Grundbudget = absolute verfügbare Gesamtleistung für alle Wallboxen, OHNE
Batteriespeicher.** Es ist kein Restüberschuss, der die Wallboxen bereits abzieht.
12 kW bedeuten zusammen maximal 12 kW, bevor bestätigte Speicherunterstützung
hinzukommt. Verbrauch und Batteriemessung werden niemals erneut zum Budget
addiert. Negative Werte gelten als 0 W; ungültige Werte führen zum Stopp.

Intern gelten W/kWh. Nominell: `P = Phasen × Spannung × Strom je Phase`.
Strom- und Leistungsgrenzen gelten gleichzeitig. Mindestwerte werden aufgerundet,
Maxima/Zuteilungen abgerundet. 6 A bei 230 V und drei Phasen sind 4,14 kW.
Exakt 4,2 kW Mindestleistung benötigen bei ganzen Amperewerten **7 A / 4,83 kW**.
Ein Stoppwert von 1 A bleibt ein Ausschaltbefehl mit **0 W Anforderung**.

- **Gleichmäßig:** Ausgleich in W mit Sättigung an Maxima. Wenn nicht alle Minima
  passen: geschützte laufende Geräte, laufende Geräte bis zur Rotationszeit,
  dann am längsten nicht bediente Geräte. Gleichstand entscheidet die Platznummer.
  Rasterreste werden nur innerhalb aller Grenzen vergeben.
- **Priorität:** Rang 1 zuerst, folgende Ränge danach. Gleiche Ränge verwenden
  dieselbe faire Verteilung. Geschützte laufende Geräte reservieren vorher ihre
  Mindestleistung; Zeitbedingungen können damit eine sofortige Rangübernahme verzögern.
- **Strikt (Standard):** Bei Leistungsmangel dürfen Mindestlaufzeit/Nachlauf
  übersteuert werden. Kein Betrieb unter der legalen Mindestleistung.
- **Zeitweise Überschreitung:** Nur bereits laufende Geräte während einer
  Mindestlauf-/Nachlaufbedingung; Betrag und Dauer begrenzt. Neue Starts erhalten
  daraus kein Budget. Ein harter Gesamtgrenzwert gilt immer.

Reduzierungen erfolgen vor Erhöhungen. Noch nicht abgebaute gemessene Last
reserviert Budget; Erhöhungen warten auf frische Rückmeldung. Befehlsfehler geben
kein neues Budget frei. Physischer Lastabbau hängt von Geräte-/Abfragezeiten ab.

Ohne Ladebedarfsvariable wird Ladebedarf angenommen, solange alle Freigaben
gelten. 0 W allein beendet diese Annahme nicht. Für volle oder nicht verbundene
Fahrzeuge eine geeignete boolesche Statusvariable verknüpfen.

## Zeiten und Phasen

Startverzögerung verlangt durchgehend genügend Leistung mit Start-Hysterese.
Mindestlaufzeit bevorzugt gestartete Geräte, Nachlauf überbrückt kurze Einbrüche,
Mindestpause verhindert sofortigen Wiederanlauf. Erhöhungen können einen
Befehlsabstand erhalten; Reduzierungen/Sperren warten darauf nicht.
Rotation gilt standardmäßig nach 900 s, respektiert Zeitbedingungen und ist mit
0 deaktivierbar.

Dynamische Phasen verwenden ein hypothetisches Leistungsangebot unter den
Gerätegrenzen. Aufwärts: `PhaseUpW + PhaseHysteresisW`, abwärts:
`PhaseDownW - PhaseHysteresisW`, jeweils nach dauerhaft erfüllter Schwelle,
Mindestverweildauer und gegebenenfalls Mindestlaufzeit.

Ablauf: Stopp → frische niedrige Istleistung für die Bestätigungsdauer →
Vorwartezeit → Geräte-Phasenbefehl → passende tatsächliche Rückmeldung →
Nachwartezeit → neu berechneter Start. Last, unerwartete Rückmeldung oder Timeout
verriegeln einen Fehler. Nur dafür geeignete Wallbox-Phasenfunktionen sind
unterstützt, keine beliebigen Leistungsschütze. Alle Zeiten verwenden Zustände
und Timer, keine blockierenden Sleeps.

## Speicher

Gültiger SoC ist Voraussetzung. Verfügbarkeit wird begrenzt durch maximale
Entladeleistung, gemeldete aktuelle Leistungsgrenze und geschätzte Energie oberhalb
der Reserve. Diese Energie wird konservativ auf den größeren Zeitraum aus
Regelintervall, Anlaufwartezeit und Reaktionstimeout bezogen. Kapazität wird nicht
als aktueller Energieinhalt interpretiert.

Nur die nach Raster und Freigaben sinnvoll nutzbare zusätzliche Ladeleistung
wird angefordert. Erst bestätigte Entladung erlaubt zusätzliche Wallboxleistung.
Die Messung bestätigt eine bestehende Anforderung und erhöht das theoretische
Budget nicht erneut. Unzureichende Entladung begrenzt das Budget sofort auf den
bestätigten Betrag; nach Timeout wird ein Fehler verriegelt.

Mindest-SoC, manuelle/externe Sperre und Manager-Aus führen zu Neutral/Stopp
und 0 Zusatzbudget. Nach Reserveabschaltung ist SoC-Hysterese erforderlich.
Einheit/Vorzeichen werden erst am Ausgang umgesetzt. Integerziele werden
konservativ abgerundet. Automatische Speicherladung ist nicht enthalten.

Ohne Leistungsrückmeldung: standardmäßig **50 %** der theoretischen Leistung
nach **10 s** erfolgreicher Anforderung. Anteil/Wartezeit sind konfigurierbar;
Anteil 0 deaktiviert solche Unterstützung. Die tatsächliche Entladung ist ohne
Messung nicht überprüfbar und muss vor Ort geprüft werden.

## Energie und Persistenz

Gerätezähler liefern positive Differenzen in kWh. Initialwert, neue Verknüpfung,
Reset und unplausible Sprünge bilden neue Basen ohne künstlichen Verbrauch.
Zulässiger Sprung: `MaxKW × Zeit_s / 3600 × Plausibilitätsfaktor` plus kWh-Toleranz.
Den tatsächlich an Fahrzeuge abgegebenen Energiezähler wählen.

Ohne Zähler gilt stückweise konstante Integration: alter Messwert bis zum
Messzeitpunkt, neuer Messwert danach. Ereignisse und Timer teilen einen Cursor.
Lücken über `EnergyMaxGap` und ungültige/veraltete Messungen ohne frischen
Heartbeat werden nicht extrapoliert. Bei Neustart erhält Leistungsintegration
eine neue Zeitbasis; echte Zähler können positive Differenzen über den Neustart
erfassen. Energie stammt niemals aus Sollwerten. Batterientladung wird nicht
zusätzlich als Fahrzeugenergie gezählt. Berechnete Energie ist entsprechend
gekennzeichnet und nicht für eichrechtskonforme Abrechnung vorgesehen.

Attribute speichern Zustände und Energie. ApplyChanges löscht keine Zähler.
Deaktivierte Plätze behalten ihre Energie. Geänderte Sollwert-/Messvariablen oder
Gerätekennung erzeugen eine neue Platzgeneration und archivieren alte
Platzenergie in der Diagnose. Gesamtenergie enthält alle Generationen.
Bei physischem Gerätetausch mit denselben Variablen **Gerätekennung ändern**;
ein solcher Tausch ist an gleichbleibenden IDs allein nicht erkennbar. Wechsel
nur des Energiezählers erneuert die Basis und erhält die Platzenergie.

Reset ist im Formular geschützt durch `ENERGIE ZURÜCKSETZEN` und Bestätigungsdialog,
nicht in der Kachel. Bei Neustart werden Geräte gestoppt und Lastabbau bestätigt;
danach erfolgt ein regulärer Start. Laufende Phasenwechsel werden weiter sicher
geprüft. Bei Umkonfiguration bleiben alte Ziele referenziert, bis deren Stopp
bestätigt ist. Unerreichbare alte Ziele müssen wieder erreichbar gemacht und
geprüft werden; bis dahin gibt es keine neue Last. Instanzen besitzen unabhängige
Zustände und können dieselben Steuerziele nicht gleichzeitig beanspruchen.

## Kachel einbinden

Im Konfigurator der Symcon-Kachelvisualisierung die **Instanz Wallbox Manager**
in die gewünschte Seite aufnehmen. `SetVisualizationType` und
`GetVisualizationTile` liefern die eigene Darstellung. Keine HTML-Box oder
externer Webhook erforderlich.

Bedienung von Manager, Verteilung, manuellen Freigaben, Prioritäten und Speicher
erfolgt über das HTML-SDK. Externe Freigaben werden nur gelesen. Daten werden ohne
Seitenneuladen aktualisiert. Das Layout nutzt die Breite und Höhe der Kachel.
In kleinen Kacheln lassen sich Energie und Phasen aufklappen; umfangreiche Inhalte
scrollen innerhalb der Kachel. Ohne aktivierten Speicher entfällt dessen Übersicht.
Schrift-, Flächen- und Akzentfarben werden aus den Symcon-CSS-Variablen bzw. den
HTML-SDK-Farbparametern übernommen und bei Änderungen aktualisiert. Ohne
Symcon-Farben gilt die Hell-/Dunkeleinstellung des Browsers. Keine externen CDNs.

Die [lokale Vorschau](docs/preview.html) enthält Beispieldaten und einen
nachgebildeten Aktionskanal; sie ist keine Live-Symcon-Kachel.

## Dateien und Prüfung

- `WallboxManager/`: Modul, Metadaten, Formular und HTML/CSS/JS-Kachel.
- `libs/`: Konfiguration, Validierung, Raster, Verteilung, Zustandsmaschinen, Energie.
- `tests/`: Fachtests, Kernel-Testdouble und JavaScript-Tests.
- [Alle Einstellungen und Variablen](docs/KONFIGURATION.md).
- [Beispiele](docs/examples/README.md), [API-Nachweise](docs/API.md),
  [Prüfbericht](docs/TESTBERICHT.md), [Abnahme](docs/ABNAHME.md).
- `.dev/`: Prüfer, Vorschaugenerator und Paketerstellung.

```powershell
php -n tests/run.php
php -n .dev/verify.php
node tests/tile.test.cjs
```

Zusätzlich liegt eine portable PHP-Laufzeit in `.tools/php/php.exe`, außerhalb
des Installationspakets. PHP-Tests wurden unter PHP 8.3.35 ausgeführt; der Code
verwendet die in Symcon 7.1 vorhandene PHP-8.2-Sprachbasis. JavaScript wurde mit
Node und DOM-Testdouble sowie Chromium geprüft. Der optionale Browsertest
`node tests/tile.browser.cjs` benötigt Playwright und Chromium (alternativer
Browserpfad über `WBM_CHROMIUM_PATH`). Er prüft sieben Kachelgrößen, Designwechsel,
Bedienaktionen sowie vier Wallboxen mit Speicher und langen Fehlertexten.
Live-Symcon-/Hardwaretests bleiben erforderlich.

Das Modul ist eine übergeordnete Betriebsregelung und ersetzt keine elektrischen
oder geräteseitigen Schutzfunktionen. Planung verwendet nominelle Spannung;
tatsächliche Leistung und Energie stammen aus Messwerten.
