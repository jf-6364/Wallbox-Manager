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

## PV-Überschuss

Der eigene Bereich **PV-Überschuss** enthält eine optionale Boolvariable
**„PV-Überschuss aktiv“** und eine zweite numerische Variable
**„Verfügbares PV-Leistungsbudget“**. Die Einheit ist separat als W oder kW wählbar.

| Boolvariable | Budget für die Regelung |
|---|---|
| Nicht verknüpft oder false | Bisheriges Leistungsbudget unter Allgemein |
| true | Ausschließlich das PV-Leistungsbudget, beispielsweise 7.000 W |

Das PV-Budget **ersetzt** das normale Budget. Es wird weder zum normalen Budget
addiert noch durch Speicherunterstützung oder erlaubte Überschreitungen erhöht.
Ein konfigurierter harter Gesamtgrenzwert und die Gerätegrenzen gelten weiterhin.
Bei 0 W oder einem negativen PV-Budget werden die individuellen Stoppwerte gesendet.
Reicht das Budget nicht für die Mindestleistung aller Wallboxen, lädt eine passende
Teilmenge; 7.000 W reichen etwa nicht für zwei dreiphasige Wallboxen mit je 6 A.

Die PV-Variable beschreibt wie das normale Budget die insgesamt für Wallboxen
verfügbare Leistung. Ein verbleibender Überschuss, der deren Ladeleistung bereits
abzieht, muss vor der Verknüpfung entsprechend aufbereitet werden.

Optional lässt sich das Alter des PV-Budgetwerts überwachen. Ist PV aktiv und der
Wert fehlt, ist veraltet oder ungültig, wird gestoppt; es gibt keinen Rückfall auf
das normale Budget. Ein ungültiges verknüpftes Boolsignal ist ebenfalls ein Fehler.
Bei false werden fehlerhafte Werte der inaktiven PV-Budgetquelle nicht verwendet.
Die Kachel zeigt bei aktiver PV-Regelung **„PV-Budget“**; die Diagnose nennt die
aktive Quelle und deren Variablen-ID.

Für die **PV-Abrechnung** kann dieselbe Boolvariable zusätzlich unter
**Allgemein → Abrechnung → PV-Überschussladen** verknüpft werden. Budgetumschaltung
und Tarifzuordnung bleiben getrennt einstellbar.

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

Nach einer Leistungsreduzierung wartet der Manager auf die gemessene Rückmeldung.
Ein verknüpfter, gültiger Geräte-Heartbeat kann dabei die Aktualität eines
unveränderten Leistungswerts bestätigen. Ohne rechtzeitige Bestätigung wird der
konfigurierte Stoppwert angefordert. Ein **„Timeout: Lastabbau nicht bestätigt“**
hebt sich automatisch auf, sobald eine gültige Rückmeldung nach dem Stopp die
Leistung innerhalb der Stillstandstoleranz bestätigt. Das gilt auch für bereits
gespeicherte Lastabbau-Timeouts; ein erneutes manuelles Quittieren ist dann nicht
nötig. Bei einem zuvor nur teilweise reduzierten Sollwert genügt die alte
Teilreduzierung nicht: Nach dem Timeout muss zunächst Stillstand bestätigt sein.
Andere Geräteaktions- und Phasenfehler bleiben separat zu quittieren. Der
Start-/Stoppzustand und eine laufende Abrechnung bleiben bei der Erholung erhalten.

## Grenzen, Zeiten und Freigaben

Jede Wallbox besitzt eigene Strom- und Leistungsgrenzen, ein Sollwertraster und
einen gerätespezifischen Stoppwert. Strom- und Leistungsgrenzen gelten gleichzeitig,
auch bei Watt-Sollwerten. Drei Phasen mit 230 V und mindestens 6,0 A je Phase
erfordern beispielsweise rund 4,14 kW Mindestleistung.

Unter **Allgemein → Zeitbedingungen** werden Einschaltverzögerung, Mindestlaufzeit,
Nachlauf, Mindestpause, Wallbox-Hysterese und Mindestabstand für Erhöhungsbefehle
einmal für alle Wallboxen eingestellt. Sie vermeiden häufiges Umschalten.
Standardmäßig beträgt das Regelintervall 5 Sekunden.

Die globale Managerfreigabe, der manuell gestartete Ladegang je Wallbox und
optionale externe Freigaben bestimmen gemeinsam, welche Geräte laden dürfen. Externe Freigaben
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

Ein Ladegang beginnt je Wallbox mit **„Ladevorgang starten“** in der Kachel und
endet mit **„Ladevorgang stoppen“**. Ein Wechsel zwischen Normal- und PV-Betrieb
sowie beliebig lange Ladepausen lassen den Vorgang aktiv. Bei zu wenig Budget
wartet die Wallbox und setzt das Laden bei ausreichender Leistung fort.
Die Abrechnung erfasst nur die tatsächlich gemessene Energie oberhalb der
Leistungsschwelle innerhalb dieses Vorgangs; PV- und Normalanteile bleiben getrennt.
Nach Stopp entsteht bei erfasster Energie automatisch eine PDF mit Zeitraum,
beiden Energiemengen, Tarifpositionen und Gesamtkosten. Ohne Ladeenergie entsteht
kein leeres Dokument. Alle PDFs bleiben unter der Instanz im jeweiligen Ordner
„Abrechnung – Wallbox n“; die letzte PDF ist auch in der Kachel herunterladbar.

Für eine zeitliche Tarifaufteilung wird die Abrechnungsenergie **aus der gemessenen
Ladeleistung berechnet**, auch bei verknüpftem Geräte-Energiezähler. PV-Wechsel
werden als Ereignisse verarbeitet; Messlücken werden nicht mit angenommener
Ladeleistung aufgefüllt. Daher können Abrechnung und Geräte-Energiezähler
voneinander abweichen. Die Aufzeichnung setzt aktivierte Abrechnung und einen gestarteten Ladegang voraus.

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

Die Kachel zeigt eine gemeinsame Übersicht für **aktuelle Ladeleistung,
Gesamtenergie und Budget** sowie den aktuellen Managerstatus. Darunter stehen
abgerundete Wallbox-Karten mit feinen Rahmen, dezenten Schatten und direkt
lesbaren Wallbox-Namen. Die großen Symbole neben den Namen entfallen; die
kleinen Symbole für Leistung, Sollwert und Tarife bleiben erhalten. Große Leistungswerte, Statusmarkierungen und getrennte Bereiche für
Sollwert, Lademodus und Preise folgen der Dashboard-Vorlage.

Oben links ändern **„Gleichmäßig“ / „Priorität“** die Verteilungsart direkt.
Oben rechts öffnet **„Energie-Dashboard“** die Energieverteilung. Die globale
Managerfreigabe wird über die Symcon-Bedienvariable **Managerfreigabe** gesteuert;
die Kachel zeigt ihren Zustand an.

Jede aktive Wallbox zeigt ihre gemessene Ladeleistung, den Sollwert und einen
eigenen Button **„Ladevorgang starten“**. Nach dem Start heißt er
**„Ladevorgang stoppen“**. Ohne Start nimmt die Wallbox nicht an der Verteilung teil.
Stopp nimmt sie sofort heraus, auch während einer Mindestlaufzeit, und beendet
den zugehörigen Abrechnungsvorgang. Verteilungsart, PV-Modus und Ladepausen ändern
diesen Startzustand nicht. Der Zustand bleibt über Neustarts erhalten.
Budget, Gerätegrenzen und externe Sperren begrenzen weiterhin die tatsächliche
Ladeleistung; der gestartete Vorgang bleibt dabei erhalten.

Beim ersten Update auf die Start-/Stopp-Steuerung werden bisher automatisch
freigegebene Wallboxen einmalig auf „nicht gestartet“ gesetzt. Danach ist je
Wallbox ein Startklick erforderlich. Vorhandene Energie und PDFs bleiben erhalten;
ein bisher laufender Abrechnungsvorgang wird mit seiner erfassten Energie abgeschlossen.

Bei aktivierter Abrechnung sind **PV- und Normalpreis in ct/kWh**, der aktuelle
Tarif sowie Energie und Kosten des laufenden oder letzten Ladegangs unmittelbar
sichtbar. Ohne aktivierte Abrechnung steht „Preise nicht eingerichtet“.
**„Details & Rechnung“** öffnet eine einzige zusätzliche Ebene mit Energie,
Priorität, Tarifaufteilung und PDF-Download; ohne Abrechnung heißt sie „Details“.
Das Layout passt Schriftgrößen und Spalten an die Kachelgröße an. Auf breiten
Kacheln stehen zwei Wallboxen nebeneinander, auf schmalen untereinander.
Bei wenig Höhe scrollt der Inhalt innerhalb der Kachel; alle Bedienelemente bleiben
erreichbar.

Der Button **„Energie-Dashboard“** oben rechts öffnet ein Ringdiagramm mit der Gesamtenergie
und den Anteilen der einzelnen Wallboxen. Beim Bewegen des Mauszeigers über
Diagramm, Legende oder Verbrauchsliste werden Wallbox, kWh und Prozentanteil
hervorgehoben. Auch Berührung und Tastaturbedienung sind möglich.

Die Anzeige übernimmt alle **2,5 Sekunden** den neuesten eingegangenen Stand,
auch im geöffneten Dashboard. Unveränderte Werte werden nicht neu gezeichnet;
Mausbewegungen im Diagramm werden pro Bildaufbau gebündelt. In ausgeblendeten
Ansichten pausiert die Anzeige und holt beim Öffnen den neuesten Stand nach.
Bedienaktionen werden sofort gesendet; Laderegelung, Messwerterfassung und
Abrechnung arbeiten weiterhin unabhängig im konfigurierten Regelintervall.

Titel und Vergrößern-Pfeil werden
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
im Dunkelmodus eine dunkelgraue Kartenfläche mit Mint (`#56CDB0`), im Hellmodus
weiße Karten auf einem dezent graublauen Hintergrund mit Türkis (`#00B79D`).
Übergebene Symcon-Farben haben Vorrang. Verteilungswahl, Start-/Stopp-Buttons und
aufklappbare Detailzeilen verwenden einheitliche Formen und Abstände. Die Icons
sind eingebettete SVGs; zusätzliche Schriftarten oder externe Grafikdienste sind
nicht erforderlich.

Ansicht mit Beispieldaten: [Übersicht hell](docs/images/uebersicht-hell.png) ·
[Übersicht dunkel](docs/images/uebersicht-dunkel.png) ·
[Schmale Kachel hell](docs/images/kachel-hell.png) ·
[Schmale Kachel dunkel](docs/images/kachel-dunkel.png).
Die Vorschau stellt die normalerweise von Symcon gelieferte Titelzeile nach.

## Eigene Webansicht

Die optionale **Webansicht** bietet dieselbe Bedienung und Darstellung direkt im
Browser: Verteilungswahl, Start/Stopp je Wallbox, Leistungswerte, Tarife und
Rechnungen. Ihr erweitertes **Energie-Dashboard** ergänzt einen interaktiven
Leistungsverlauf, Energie und Kosten je Wallbox sowie ein filterbares PDF-Archiv.

Unter **Webansicht** aktivieren, Benutzername und Passwort festlegen und übernehmen.
Die Standardadresse lautet **`http://DEINE-SYMCON-IP:3777/hook/wallboxen`**. Der
Browser fragt nach den eingestellten Zugangsdaten. Hell-/Dunkelmodus sind wählbar;
die Seite passt sich an die Fenstergröße an. Die Registrierung in Symcons
WebHook Control erfolgt automatisch.

Livewerte aktualisieren sich alle 2,5 Sekunden; Verlauf und Archiv im geöffneten
Dashboard alle 15 Sekunden. Der Leistungsverlauf beginnt mit Aktivierung und
bewahrt 48 Stunden in Minutenabständen. Vorhandene PDFs bleiben erhalten und
herunterladbar. [Aufruf, Dashboard und PDF-Archiv](docs/WEBANSICHT.md).

## Weitere Informationen

- [Webansicht und erweitertes Dashboard](docs/WEBANSICHT.md)
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
