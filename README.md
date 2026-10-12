# Wallbox Manager

Der Wallbox Manager verteilt ein verfügbares Leistungsbudget auf **eine bis vier
Wallboxen** in IP-Symcon ab Version 7.1. Er steuert vorhandene Gerätevariablen über
Symcon-Aktionen und berücksichtigt die tatsächlich gemessene Ladeleistung.
Optional kann ein Batteriespeicher zusätzliche Leistung bereitstellen.

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

## Grenzen, Zeiten und Freigaben

Jede Wallbox besitzt eigene Strom- und Leistungsgrenzen, ein Sollwertraster und
einen gerätespezifischen Stoppwert. Strom- und Leistungsgrenzen gelten gleichzeitig,
auch bei Watt-Sollwerten. Drei Phasen mit 230 V und mindestens 6,0 A je Phase
erfordern beispielsweise rund 4,14 kW Mindestleistung.

Einschaltverzögerung, Hysterese, Mindestlaufzeit, Nachlauf und Mindestpause vermeiden
häufiges Umschalten. Standardmäßig beträgt das Regelintervall 5 Sekunden.

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

## Kachelvisualisierung

Die eigene Symcon-Kachel zeigt Budget, aktuelle Ladeleistung und Gesamtenergie
ohne zusätzliche Unterkacheln. Jede aktive Wallbox erscheint als schlichte Zeile
mit Ladeleistung, Status und Freigabe. Unter „Details“ stehen Sollwert, Energie,
Phasen und die bedienbare Priorität. Der Verteilungsmodus ist direkt wählbar.
Das Layout passt sich der verfügbaren Breite und Höhe an; umfangreiche Inhalte
scrollen innerhalb der Kachel.

Der Button **„Energie-Dashboard“** öffnet ein Ringdiagramm mit der Gesamtenergie
und den Anteilen der einzelnen Wallboxen. Beim Bewegen des Mauszeigers über
Diagramm, Legende oder Verbrauchsliste werden Wallbox, kWh und Prozentanteil
hervorgehoben. Auch Berührung und Tastaturbedienung sind möglich. Live-Werte
aktualisieren das geöffnete Dashboard. Ein Vollbild-Button erscheint, wenn die
Umgebung diese Browserfunktion erlaubt.

Die Verteilung zeigt den **gesamten Zählzeitraum**, keine Tagesauswertung.
Deaktivierte Plätze mit bereits erfasster Energie bleiben enthalten. Bei einem
Gerätewechsel wird archivierte Energie demselben Wallbox-Platz zugeordnet;
der Gesamtverbrauch bleibt erhalten. Ein bestätigter Energie-Reset setzt die
Zähler zurück. Berechnete Energie ist in der Verbrauchsliste gekennzeichnet.

Die Darstellung übernimmt Symcon-Farben für Hell- und Dunkelmodus. Ohne übergebene
Symcon-Farben gilt die Browser-Einstellung. Zahlen werden mit Dezimalkomma und
höchstens zwei Nachkommastellen angezeigt, Ampere- und Wattwerte in der Kachel
mit einer Nachkommastelle.

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
