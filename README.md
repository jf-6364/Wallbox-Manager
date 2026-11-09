# Wallbox Manager

Der Wallbox Manager verteilt ein verfügbares Leistungsbudget auf **eine bis vier
Wallboxen** in IP-Symcon ab Version 7.1. Er steuert vorhandene Gerätevariablen über
Symcon-Aktionen und berücksichtigt die tatsächlich gemessene Ladeleistung.
Optional kann ein Batteriespeicher zusätzliche Leistung bereitstellen.

Unter **Allgemein → Anzahl Wallboxen** sind ein bis vier Plätze
wählbar. Die passenden Konfigurationsbereiche erscheinen direkt bei der Auswahl.
Mit „Übernehmen“ wird die Anzahl wirksam: Ausgeblendete Plätze werden gestoppt
und aus der Regelung genommen. Ihre Einstellungen, Energiezähler und PDFs
bleiben erhalten. Beim erneuten Einblenden stehen die bisherigen Einstellungen
wieder zur Verfügung; jeder verwendete Platz muss zusätzlich aktiviert sein.

## Leistungsbudget und Verteilung

Das Grundbudget beschreibt die insgesamt verfügbare Leistung für alle Wallboxen,
wahlweise in Watt oder Kilowatt. Bei zwei gleichberechtigten Wallboxen und
12.000 W Budget kann der Manager beispielsweise je 6.000 W anfordern.

| Budget | Wallbox 1 | Wallbox 2 |
|---|---|---|
| 12.000 W | 6.000 W | 6.000 W |
| 10.000 W | 5.000 W | 5.000 W |
| 0 W | Stoppwert | Stoppwert |

Diese Beispiele setzen passende Mindest- und Höchstgrenzen, Freigaben und
Messrückmeldungen voraus. Start- und Wartezeiten können die Umsetzung verzögern.

**Gleichmäßig:** Die aktiven Wallboxen erhalten möglichst gleiche Wattwerte.
Erreicht eine Box ihre konfigurierte Höchstleistung, kann die verbleibende
Leistung an andere Boxen gehen. Reicht das Budget nicht für die Mindestleistung
aller Boxen, werden geeignete Geräte ausgewählt. Eine Rotation ermöglicht
abwechselndes Laden bei knappem Budget.

**Priorität:** Höher priorisierte Boxen werden zuerst versorgt. Priorität 1 ist
die höchste. Boxen mit gleichem Rang teilen sich die Leistung gleichmäßig.
Laufzeitbedingungen können den Wechsel beeinflussen.

Das Budget ist die verfügbare Gesamtleistung ohne zusätzliche Speicherunterstützung.
Ein Restüberschuss, der laufende Wallboxen bereits abzieht, muss entsprechend
aufbereitet werden. Negative Budgetwerte werden als 0 W behandelt; ungültige
Messwerte führen zum Stopp.

## Verknüpfte Variablen

Für zwei Wallboxen benötigt der Grundbetrieb fünf Verknüpfungen:

| Variable | Aufgabe |
|---|---|
| Gemeinsames Leistungsbudget | Verfügbares Gesamtlimit in W oder kW |
| Sollwert Wallbox 1 | Steuerbare Gerätevariable mit Aktion |
| Gemessene Ladeleistung Wallbox 1 | Tatsächliche Leistung in W oder kW |
| Sollwert Wallbox 2 | Steuerbare Gerätevariable mit Aktion |
| Gemessene Ladeleistung Wallbox 2 | Tatsächliche Leistung in W oder kW |

Sollwerte sind in A, W oder kW möglich. Die Einheit wird für jede Box separat
festgelegt. Sollwert und tatsächliche Ladeleistung haben unterschiedliche Rollen.

Optional können Fahrzeug-/Ladebedarf, externe Freigaben, Gerätefehler, separate
Geräte-Ladefreigaben, Energiezähler und Heartbeats verknüpft werden. Ohne eine
Ladebedarfsvariable nimmt der Manager Ladebedarf an; 0 W Messleistung allein
bedeutet daher nicht, dass ein Fahrzeug voll oder abgesteckt ist.

Nach dem Übernehmen geänderter Verknüpfungen verwendet der Manager ausschließlich
die aktuell eingetragenen Variablen. Abgewählte IDs werden weder gelesen noch
beschrieben; alte Stoppaufträge, Referenzen und Steuerzielreservierungen werden
automatisch entfernt. Das gilt auch für bereits gespeicherte Altaufträge nach
einem Modulupdate. Energiezähler, archivierte Geräteenergie und Rechnungen bleiben
erhalten. Ein abgewähltes Gerät erhält auch keinen nachträglichen Stoppbefehl:
Eine laufende Wallbox daher vor dem Entfernen ihrer Verknüpfungen stoppen.

Die Stoppprüfung der **aktuell verknüpften** Wallboxen bleibt aktiv. Nach einem
Neustart oder Gerätewechsel beginnt die Verteilung erst nach erfolgreichem
Stoppbefehl und frischer Leistungsrückmeldung nahe 0 W. Ein manuell festgehaltener
Messwert von beispielsweise 11.040 W bestätigt diesen Stillstand nicht.

## Grenzen, Zeiten und Freigaben

Jede Wallbox besitzt eigene Strom- und Leistungsgrenzen, ein Sollwertraster und
einen gerätespezifischen Stoppwert. Strom- und Leistungsgrenzen gelten gleichzeitig,
auch bei Watt-Sollwerten. Drei Phasen mit 230 V und mindestens 6,0 A je Phase
erfordern beispielsweise rund 4,14 kW Mindestleistung.

Unter **Allgemein → Zeitbedingungen** werden Einschaltverzögerung, Mindestlaufzeit,
Nachlauf, Mindestpause, Wallbox-Hysterese und Mindestabstand für Erhöhungsbefehle
einmal für alle Wallboxen eingestellt. Sie vermeiden häufiges Umschalten.
Standardmäßig beträgt das Regelintervall 5 Sekunden.

Die globale Managerfreigabe, die manuelle Freigabe je Box und optionale externe
Freigaben bestimmen gemeinsam, welche Geräte laden dürfen. Externe Freigaben
werden ausschließlich gelesen.

Im strikten Modus bleiben die zugeteilten Sollleistungen innerhalb des Budgets;
bei Leistungsmangel können Mindestlaufzeit und Nachlauf übersteuert werden.
Optional sind begrenzte zeitweilige Überschreitungen für bereits laufende Geräte
möglich. Ein zusätzlicher harter Gesamtgrenzwert begrenzt auch Speicherunterstützung.

## Rückmeldungen und Fehler

Bei einer Umverteilung reduziert der Manager zuerst Leistung. Noch nicht abgebaute
Last bleibt reserviert, bevor andere Geräte erhöht werden. Die reale Reaktion hängt
von den Wallboxen und ihren Abfragezeiten ab.

Messwertalter und Heartbeats können überwacht werden. Ein Watchdog kann Sollwerte
regelmäßig erneut senden. Die Diagnose zeigt Zustände, Wartegründe und die letzten
Befehle. Befehls- und Phasenfehler bleiben bis zur Quittierung nach Ursachenprüfung
gesperrt. Ein ausgeschalteter Manager ist ein normaler Betriebszustand.

## Phasen und Batteriespeicher

Der Manager unterstützt festen einphasigen oder dreiphasigen Betrieb. Geeignete
Wallboxen können zusätzlich dynamisch umgeschaltet werden: Stopp, bestätigter
Stillstand, Phasenbefehl, Rückmeldung und geregelter Wiederanlauf.

Die optionale Speicherfunktion berücksichtigt Ladezustand, Reserve, Kapazität und
Entladegrenzen. Mit Leistungsrückmeldung wird zusätzliche Ladeleistung erst nach
bestätigter Entladung freigegeben. Ohne Rückmeldung gilt ein konfigurierbarer
konservativer Anteil nach einer Wartezeit. Automatische Speicherladung ist nicht
enthalten.

## Energie und gespeicherte Zustände

Ein vorhandener Geräte-Energiezähler liefert Verbrauchsdifferenzen in Wh oder kWh.
Ohne Zähler wird die Energie aus der gemessenen Ladeleistung berechnet und als
„berechnet“ gekennzeichnet. Messlücken werden berücksichtigt; Energie stammt nicht
aus den angeforderten Sollwerten.

Bedienwerte, Energie und Zustände bleiben über Neustarts erhalten. Gerätewechsel
können über eine Gerätekennung getrennt erfasst werden. Beim Neustart bestätigt
der Manager zunächst den Stopp der Geräte. Zwei Managerinstanzen dürfen dieselben
Steuerziele nicht gleichzeitig beanspruchen. Ein Energie-Reset benötigt eine
explizite Bestätigung.

## Ladeabrechnung je Wallbox

Jede Wallbox kann geladene Energie getrennt nach **PV-Überschussladen** und
**Normalladen** abrechnen. Die Einstellungen stehen gemeinsam unter
**Allgemein → Abrechnung**. Eine gemeinsam verknüpfte Boolvariable legt den Tarif fest:
`true` zählt zum PV-Tarif, `false` zum Normaltarif. Für beide gelten separat
einstellbare Preise in EUR/kWh, die für alle Wallboxen gelten. Ohne verknüpfte
Boolvariable gilt der Normaltarif.
Die Boolvariable bestimmt die Abrechnung; die Leistungsregelung erfolgt weiterhin
über das Budget und die Freigaben.

Ein Ladegang beginnt bei gemessener Ladeleistung oberhalb der einstellbaren
Schwelle. Er endet nach einer **einstellbaren Zeit ohne Ladeleistung**, standardmäßig
600 Sekunden. Kürzere Pausen bleiben Teil desselben Ladegangs. Nach dem Abschluss
entsteht automatisch eine PDF mit Zeitraum, beiden Energiemengen, Tarifpositionen
und Gesamtkosten. Alle PDFs bleiben unter der Instanz im jeweiligen Ordner
„Abrechnung – Wallbox n“; die letzte PDF ist auch in der Kachel herunterladbar.

Für eine zeitliche Tarifaufteilung wird die Abrechnungsenergie **aus der gemessenen
Ladeleistung berechnet**, auch bei verknüpftem Geräte-Energiezähler. PV-Wechsel
werden als Ereignisse verarbeitet; Messlücken werden nicht mit angenommener
Ladeleistung aufgefüllt. Daher können Abrechnung und Geräte-Energiezähler
voneinander abweichen. Die Aufzeichnung beginnt mit aktivierter Abrechnung.

Preise sind standardmäßig 0,00 EUR/kWh und vor der Nutzung einzustellen.
Preisänderungen gelten für kommende Energieintervalle. Laufende Ladegänge und
PDFs bleiben bei Neustarts erhalten; der Gesamtenergie-Reset verändert sie nicht.
Weitere Details und ein Rechenbeispiel stehen in der
[Dokumentation zur Ladeabrechnung](docs/ABRECHNUNG.md).

Unter **Allgemein → Abrechnung → Rechnung** werden Rechnungssteller, Anschrift,
Kontakt, Bankverbindung, Zahlungsfrist, Nummernkreis, Umsatzsteuer sowie der
gemeinsame Empfänger mit Adresse und optionaler Kundennummer eingestellt.
Die PDF orientiert sich an einer klassischen Rechnung
mit Adressblöcken, Positionstabelle, Summen und Zahlungsinformationen.

Umsatzsteuer kann ignoriert oder als bereits im Endpreis enthaltene Steuer
ausgewiesen werden; der Steuersatz ist einstellbar. Der §-19-Hinweis erscheint
bei ausdrücklich gewählter Kleinunternehmerregelung. Bereits erstellte Rechnungen
behalten ihre Daten und Nummern. **Jeder Ladegang erzeugt ein eigenes Dokument**;
die Dateien werden nicht automatisch überschrieben oder gelöscht.
Eine [Beispielrechnung mit fiktiven Daten](docs/examples/rechnung-beispiel.pdf)
zeigt das Layout.

Beim Umstieg auf die gemeinsamen Einstellungen werden einmalig die bisherigen
Zeitbedingungen, Tarife und Empfängerdaten von **Wallbox 1** übernommen.
Anschließend gelten ausschließlich die gemeinsamen Einstellungen. Bereits
erfasste Tarifmengen, Energiezähler und vorhandene PDFs bleiben erhalten;
Preisänderungen berechnen frühere Energieintervalle nicht neu.

## Kachelvisualisierung

Die eigene Symcon-Kachel folgt dem schlichten Energierechner-Stil: große, leichte
Leistungswerte, dezente Beschriftungen und flache Bereiche ohne Unterkacheln.
Oben stehen die aktuelle Gesamtleistung in kW, Gesamtenergie und Budget.
**„Gleichmäßig“ / „Priorität“** ändern die Verteilungsart direkt; der
Manager-Schalter aktiviert oder stoppt die gesamte Regelung.

Jede aktive Wallbox zeigt ihre gemessene Ladeleistung, den Sollwert und einen
**Ein/Aus-Schalter** für die manuelle Freigabe. Ausschalten nimmt die Wallbox aus
der Verteilung; Einschalten gibt sie wieder für die automatische Regelung frei.
Externe Sperren und Gerätegrenzen bleiben wirksam.

Bei aktivierter Abrechnung sind **PV- und Normalpreis in ct/kWh**, der aktuelle
Tarif sowie Energie und Kosten des laufenden oder letzten Ladegangs unmittelbar
sichtbar. Ohne aktivierte Abrechnung steht „Preise nicht eingerichtet“.
**„Details & Rechnung“** öffnet eine einzige zusätzliche Ebene mit Energie,
Priorität, Tarifaufteilung und PDF-Download; ohne Abrechnung heißt sie „Details“.
Das Layout passt Schriftgrößen und Spalten an die Kachelgröße an. Auf breiten
Kacheln stehen zwei Wallboxen nebeneinander, auf schmalen untereinander.
Bei wenig Höhe scrollt der Inhalt innerhalb der Kachel; alle Schalter bleiben
erreichbar.

Der Button **„Energie-Dashboard“** am unteren Rand öffnet ein Ringdiagramm mit der Gesamtenergie
und den Anteilen der einzelnen Wallboxen. Beim Bewegen des Mauszeigers über
Diagramm, Legende oder Verbrauchsliste werden Wallbox, kWh und Prozentanteil
hervorgehoben. Auch Berührung und Tastaturbedienung sind möglich. Live-Werte
aktualisieren das geöffnete Dashboard. Titel und Vergrößern-Pfeil werden
ausschließlich von Symcon dargestellt; die Modulansicht fügt keine zweite
Kopfzeile hinzu. Der Inhalt hält Platz für die Kopfzeile frei, auch wenn ältere
Symcon-Versionen sie über die Kachel legen. Bereits vom Host reservierter Platz
wird berücksichtigt. In der Fußzeile entfallen Budgetmodus und Aktualisierungsuhrzeit.

Die Verteilung zeigt den **gesamten Zählzeitraum**, keine Tagesauswertung.
Deaktivierte Plätze mit bereits erfasster Energie bleiben enthalten. Bei einem
Gerätewechsel wird archivierte Energie demselben Wallbox-Platz zugeordnet;
der Gesamtverbrauch bleibt erhalten. Ein bestätigter Energie-Reset setzt die
Zähler zurück. Berechnete Energie ist in der Verbrauchsliste gekennzeichnet.

Die Darstellung übernimmt Symcon-Farben für Hell- und Dunkelmodus. Ohne übergebene
Symcon-Farben gilt die Browser-Einstellung. Zahlen werden mit Dezimalkomma und
höchstens zwei Nachkommastellen angezeigt, Ampere- und Watt-Sollwerte in der Kachel
mit einer Nachkommastelle. Die Leistungsübersicht verwendet kW; das ändert keine
Einheiten oder Werte der verknüpften Gerätevariablen.

Die Oberfläche nutzt die Systemschrift des WebFronts. Ohne Symcon-Farben gilt
im Dunkelmodus ein dunkelgrauer Hintergrund (`#323338`) mit Mint (`#56CDB0`),
im Hellmodus Weiß mit Türkis (`#00B5AD`). Übergebene Symcon-Farben haben Vorrang.
Runde Auswahlbuttons, schlichte Schalter und feine Trennlinien folgen dem Dashboard.

Ansicht mit Beispieldaten: [Dunkelmodus](docs/images/kachel-dunkel.png) ·
[Hellmodus](docs/images/kachel-hell.png).

## Weitere Informationen

- [Einstellungen und Variablen](docs/KONFIGURATION.md)
- [Beispielkonfigurationen](docs/examples/README.md)
- [SDK-Schnittstellen](docs/API.md)
- [Automatisierte Prüfungen](docs/TESTBERICHT.md)
- [Abnahme am Gerät](docs/ABNAHME.md)
- [Kachelvorschau mit Beispieldaten](docs/preview.html)

Die Simulation erlaubt Testvariablen ohne Geräteaktion. Vorhandene Geräteaktionen
werden auch bei aktivierter Simulation ausgeführt.

Der Manager ist eine Betriebsregelung und ersetzt keine elektrischen oder
geräteseitigen Schutzfunktionen. Die berechnete Energie ist nicht für
eichrechtskonforme Abrechnung vorgesehen.
