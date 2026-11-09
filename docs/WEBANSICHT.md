# Webansicht und erweitertes Energie-Dashboard

Die optionale Webansicht öffnet den Wallbox-Manager direkt im Browser über den
vorhandenen Symcon-Webserver. Sie verwendet dieselbe Darstellung, Verteilungswahl,
Start-/Stopp-Steuerung und Abrechnung wie die Symcon-Kachel. Beide Ansichten zeigen
denselben Modulzustand; es entsteht keine zweite Regelung.

Ansichten mit Beispieldaten: [Webansicht](images/webansicht-hell.png) ·
[Dashboard](images/web-dashboard-hell.png) · [Verlauf und Kosten](images/web-details-hell.png).

## Aufrufen

Im Modul den Bereich **Webansicht** öffnen, **Webansicht aktivieren** einschalten,
Benutzername und ein Passwort mit mindestens acht Zeichen eintragen und
**Übernehmen** anklicken. Der Standardpfad lautet `/hook/wallboxen`:

    http://DEINE-SYMCON-IP:3777/hook/wallboxen

Beim ersten Aufruf fragt der Browser nach den eingetragenen Zugangsdaten. Diese
gelten für die Seite, ihre Daten und die PDF-Downloads. Angemeldete Benutzer können
die Verteilung ändern und Ladevorgänge starten oder stoppen. Die globale
Managerfreigabe wird weiterhin in Symcon bedient.

`/hook/` gehört zur nativen Symcon-WebHook-Schnittstelle; die Adresse lautet daher
nicht nur `/wallboxen`. Port und gegebenenfalls HTTPS richten sich nach dem
Symcon-Webserver. Für Verbindungen außerhalb des eigenen Netzes eine geschützte
HTTPS- oder VPN-Verbindung verwenden.

Das Modul trägt den Pfad automatisch in **WebHook Control** ein. Ist diese
Symcon-Instanz nicht vorhanden oder der Pfad bereits durch eine andere Instanz
belegt, steht die Ursache im Bereich Webansicht. Bei mehreren Wallbox-Managern
unterschiedliche Pfade wählen, etwa `/hook/garage` und `/hook/stellplatz`.
Andere registrierte WebHooks werden nicht verändert. Ausschalten der Webansicht
entfernt ihre Registrierung und beendet den Browserzugriff; die Laderegelung und
die gespeicherten Daten bleiben erhalten.

## Dashboard

**Energie-Dashboard** oben rechts öffnet die erweiterte Ansicht. Die Sprungbuttons
führen direkt zu den einzelnen Bereichen:

| Bereich | Inhalt |
|---|---|
| Übersicht | Abrechnungsenergie, PV-Anteil, Kosten einschließlich laufender Vorgänge und Anzahl abgeschlossener Vorgänge |
| Verteilung | Interaktives Ringdiagramm mit Gesamtenergie und Anteilen aller Wallboxen |
| Leistungsverlauf | Gemessene Leistung je Wallbox für 1, 6, 24 oder 48 Stunden |
| Abrechnung | Gesamtenergie, PV-/Normalenergie, ungeklärte Energie, Kosten, Vorgänge und aktuelle Preise je Wallbox |
| PDF-Archiv | Rechnungen aller Wallboxen, Filter je Wallbox und Download jedes gespeicherten Dokuments |

Mausbewegungen über dem Ringdiagramm zeigen Energie und Anteil. Im Leistungsverlauf
zeigt der Cursor Zeitpunkt und Leistung aller eingeblendeten Wallboxen. Mit den
Pfeiltasten lassen sich Messpunkte auswählen; ein Klick auf die Legende blendet
einzelne Kurven ein oder aus. Diese Diagrammaktionen steuern keine Geräte.

Die Gesamtenergie stammt aus den vorhandenen Energiezählern und umfasst gegebenenfalls
archivierte Gerätegenerationen. Tarifmengen und Kosten stammen aus der aktivierten
Ladeabrechnung und können einen anderen Erfassungszeitraum haben. Sie werden nicht
rückwirkend aus der Gesamtenergie berechnet. Abgeschlossene Vorgänge zählen nur
Ladegänge mit erfasster Energie; laufende Energie und Kosten sind bereits enthalten.

## Verlauf und Aktualisierung

Der Leistungsverlauf beginnt mit Aktivierung der Webansicht. Im Minutenabstand wird
ein Messpunkt aus den Regeldaten gespeichert; die letzten **48 Stunden** bleiben
erhalten, auch über Modulneustarts hinweg. Fehlende oder ungültige Messungen bleiben
Lücken. Ältere Leistungswerte werden nicht nachträglich erfunden. Die Verlaufskurven
zeigen Leistung in kW, keine Tages- oder Monatsenergie.

Livewerte werden alle **2,5 Sekunden** abgefragt. Verlauf und Archiv werden nur im
geöffneten Dashboard alle **15 Sekunden** aktualisiert. Verdeckte Browserseiten
pausieren die Abfragen. Seiten- und Datenabrufe lesen gespeicherte Regeldaten und
lösen selbst keinen Regeldurchlauf aus; Bedienaktionen gelten unmittelbar über
die vorhandene Modulsteuerung.

Oben rechts kann zwischen **System**, **Hell** und **Dunkel** gewechselt werden.
System folgt der Browser-/Betriebssystemeinstellung; eine eigenständige Browserseite
kennt die gewählte Symcon-Oberflächenfarbe nicht. Die Darstellung passt sich an die
Fenstergröße an. Breite Tabellen lassen sich auf kleinen Displays seitlich scrollen.

## Rechnungen herunterladen

Im Dashboard zum **PDF-Archiv** wechseln, bei Bedarf eine Wallbox auswählen und in
der gewünschten Zeile **Herunterladen** anklicken. Weitere Dokumente erscheinen über
**Weitere Rechnungen laden**. Die letzte Rechnung bleibt außerdem unter
**Details & Rechnung** in der jeweiligen Wallbox-Karte erreichbar.

Jeder abgerechnete Ladegang bleibt ein eigenes PDF. Die Webansicht überschreibt oder
löscht keine Rechnungen. Sie bietet ausschließlich Dokumente aus den Rechnungsordnern
dieser Managerinstanz zum Download an.

Für neu erstellte Rechnungen werden kompakte Detaildaten der letzten **2.000 PDFs**
zusätzlich gespeichert. Ältere PDFs bleiben herunterladbar; ohne Detailindex steht
„Details im Dokument“. Bereits vor dem Update vorhandene PDFs werden ebenfalls
aufgelistet, sofern sie noch im ursprünglichen Rechnungsordner liegen. Ihre Energie-
und Kostenpositionen werden nicht nachträglich aus PDF-Text rekonstruiert. Die letzte
Rechnung je Wallbox kann ihre vorhandenen Ladegangdetails zusätzlich anzeigen.
