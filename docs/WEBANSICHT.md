# Webansicht und erweitertes Energie-Dashboard

Die automatisch aktive Webansicht öffnet den Wallbox-Manager direkt im Browser über den
vorhandenen Symcon-Webserver. Sie verwendet dieselbe Darstellung, Verteilungswahl,
Start-/Stopp-Steuerung und Abrechnung wie die Symcon-Kachel. Beide Ansichten zeigen
denselben Modulzustand; es entsteht keine zweite Regelung.

Ansichten mit Beispieldaten: [Webansicht](images/webansicht-hell.png) ·
[Dashboard](images/web-dashboard-hell.png) · [Verlauf und Kosten](images/web-details-hell.png).

## Aufrufen

Die Webansicht ist ohne Einschalten automatisch aktiv. Sie verwendet den bestehenden
Symcon-Webserver und ist standardmäßig hier erreichbar:

    http://DEINE-SYMCON-IP:3777/hook/wallboxen

Die feste Anmeldung lautet **Benutzername `admin`, Passwort `admin`**. Frühere
Aktivierungs- und Zugangseinstellungen ändern diese Vorgabe nicht. Das gilt für
Seite, Datenabfragen und PDF-Downloads. Der bisherige Webansicht-Einstellungsbereich
ist entfernt. Der **Webansicht**-Button bei den Diagnoseaktionen öffnet die Seite.
Ein bereits gespeicherter gültiger URL-Pfad bleibt erhalten; neue Instanzen verwenden
`/hook/wallboxen`. Port und HTTPS richten sich nach dem Symcon-Webserver.

Angemeldete Benutzer können die Verteilung ändern und Ladevorgänge starten oder
stoppen. Die globale Managerfreigabe wird weiterhin in Symcon bedient. Das Modul
registriert seinen Pfad automatisch in WebHook Control und entfernt ihn beim
Löschen der Instanz. Fremde WebHooks werden nicht überschrieben. Registrierungsfehler
werden bei den Diagnoseaktionen angezeigt und blockieren keine Laderegelung.

## Konfiguration öffnet nicht: Nicht unterstützter Typ OpenURL

Build 23 verwendete für die neue Webansicht-Schaltfläche versehentlich einen
nicht unterstützten Formulartyp. **Ab Build 24** verwendet sie einen normalen
`Button` mit Link-Ziel. Das Modul über die Modulverwaltung beziehungsweise den
Modulstore aktualisieren und die Instanzkonfiguration erneut öffnen. Dafür muss
die Instanz weder gelöscht noch neu angelegt werden; Verknüpfungen, Energiezähler
und Rechnungen bleiben erhalten.

## Fehler beim Übernehmen: TargetID

Build 20 verwendete bei der Registrierung versehentlich `Target` statt der von
Symcon erwarteten ganzzahligen `TargetID`. Dadurch konnte „Expected value of type
int for TargetID“ erscheinen. **Ab Build 21 ist das korrigiert.** Nach dem Update
im Modul erneut **Übernehmen** drücken. Bereits gespeicherte fehlerhafte Einträge
dieses Managers werden automatisch repariert; die Instanz muss nicht neu angelegt
werden. Fremde WebHooks bleiben unverändert.

## Fehler bei Bedienaktionen: JSON-Bedienaktion erwartet

Build 22 berücksichtigt sowohl die CGI-Typangabe `CONTENT_TYPE` als auch Symcons
`HTTP_CONTENT_TYPE`. Ohne übergebene Typangabe wird der Inhalt als JSON geprüft.
Eine ausdrücklich andere Typangabe oder ungültiges JSON wird weiterhin abgelehnt;
Anmeldung, CSRF-Token, erlaubte Aktionen und Datentypen bleiben erforderlich.

Wenn die Meldung in einer älteren Version erscheint: Modul aktualisieren und die
Webseite neu laden. Start/Stopp und die Verteilungswahl verwenden dieselbe
korrigierte Schnittstelle.

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

Der Leistungsverlauf beginnt automatisch mit dem Modulbetrieb. Im Minutenabstand wird
ein Messpunkt aus den Regeldaten gespeichert; die letzten **48 Stunden** bleiben
erhalten, auch über Modulneustarts hinweg. Fehlende oder ungültige Messungen bleiben
Lücken. Ältere Leistungswerte werden nicht nachträglich erfunden. Die Verlaufskurven
zeigen Leistung in kW, keine Tages- oder Monatsenergie.

Die Webansicht fragt Livewerte jede **Sekunde** ab und übernimmt sie ebenfalls im
Sekundentakt. Verlauf und Archiv werden im geöffneten Dashboard jede **Sekunde**
aktualisiert. Unveränderte Werte werden nicht neu gezeichnet; laufende Abfragen
werden nicht überlappend gestartet. Verdeckte Browserseiten
pausieren die Abfragen. Seiten- und Datenabrufe lesen gespeicherte Regeldaten und
lösen selbst keinen Regeldurchlauf aus; Bedienaktionen gelten unmittelbar über
die vorhandene Modulsteuerung. Das eingestellte Regelintervall und die
Aktualisierungsrate der verknüpften Messvariablen bleiben maßgeblich dafür, wann
neue Messwerte vorliegen. Die native Symcon-Kachel behält ihre 2,5-Sekunden-Anzeige.

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
