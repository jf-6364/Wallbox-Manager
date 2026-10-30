# Ladeabrechnung

Jede der vier Wallboxen kann ihre Ladegänge getrennt abrechnen. Die Funktion ist
standardmäßig deaktiviert und beginnt nach ihrer Aktivierung mit der Erfassung.

## Einstellungen je Wallbox

Unter **Wallbox → Abrechnung** werden folgende Werte eingestellt:

| Einstellung | Bedeutung | Standard |
|---|---|---|
| Abrechnung aktivieren | Ladegänge und Tarifmengen aufzeichnen | Aus |
| PV-Überschussladen | Boolvariable: true = PV-Tarif, false = Normaltarif | Ohne Verknüpfung: Normal |
| PV-Preis | Preis pro kWh im PV-Modus, in EUR | 0,00 |
| Normalpreis | Preis pro kWh im Normalmodus, in EUR | 0,00 |
| Ladepause bis zum Abschluss | Durchgehende Zeit ohne Ladeleistung in Sekunden | 600 |
| Leistungsschwelle | Nur gemessene Leistung oberhalb dieser Grenze zählt als Laden | 50,00 W |

Die gleiche PV-Boolvariable kann bei mehreren Wallboxen verknüpft werden; jede
Wallbox besitzt eigene Preise und eigene Abrechnungen. 0,00 EUR/kWh bedeutet
kostenlos. Preise haben höchstens zwei Nachkommastellen und werden mit Komma
eingegeben, etwa `0,10` und `0,32`.

Im allgemeinen Bereich **Abrechnung** steht die Zeitzone der PDFs, standardmäßig
`Europe/Berlin`. Eine Änderung gilt für neu beginnende Ladegänge. Zeitpunkte
werden intern als Unix-Zeit gespeichert; die PDF nennt die verwendete Zeitzone.

## Rechnung: Adressen, Zahlung und Nummern

Im allgemeinen Bereich **Abrechnung → Rechnung** stehen die gemeinsamen Daten:

- Rechnungssteller: Name/Firma, Straße, Postleitzahl, Ort und Land.
- Optionales Logo-Kürzel für den runden Monogrammbereich der Vorlage.
- Kontakt: Telefon, E-Mail, Webseite und zusätzlicher Fußtext.
- Zahlung: Bank, Kontoinhaber, IBAN, BIC, Zahlungsmethode, Zahlungsfrist und
  optionaler eigener Zahlungshinweis. Ohne eigenen Text werden Zahlungsziel und
  Rechnungsnummer automatisch eingesetzt.
- Nummer und Steuer: Präfix, Startnummer, Umsatzsteuerdarstellung und Steuersatz,
  optional Steuernummer und USt-IdNr.

Je **Wallbox → Abrechnung → Rechnung** sind Empfängername/Firma, Straße,
Postleitzahl, Ort und Land sowie Kundennummer und Referenz/Kennzeichen einstellbar.
Die Rechnungsdaten werden beim Abschluss des Ladegangs gespeichert. Spätere
Änderungen gelten für weitere Abschlüsse. Bereits gespeicherte PDFs und noch
ausstehende Schreibversuche behalten ihre ursprünglichen Daten.

Die Nummer wird automatisch vergeben, beispielsweise `RE-2026-000001`.
Der Nummernkreis läuft innerhalb einer Managerinstanz über alle Wallboxen weiter.
Eine kleinere Startnummer verwendet bereits reservierte Nummern nicht erneut.
Auch Neustarts und Energiezähler-Reset setzen diesen Zähler nicht zurück.
Bei mehreren unabhängigen Managerinstanzen sind unterschiedliche Präfixe sinnvoll.

### Umsatzsteuer ignorieren oder einbeziehen

Die kWh-Preise sind **Endpreise**. Der Gesamtbetrag wird durch einen Wechsel der
Umsatzsteuerdarstellung nicht erhöht.

| Auswahl | Darstellung |
|---|---|
| Umsatzsteuer ignorieren | Keine gesonderte Steuerposition; Gesamtbetrag entspricht den Tarifpositionen |
| Umsatzsteuer einbeziehen | Enthaltene Steuer wird mit dem eingestellten Satz herausgerechnet und separat ausgewiesen |
| Kleinunternehmerregelung | Kein Umsatzsteuerausweis und ausdrücklich gewählter Hinweis zu § 19 UStG |

Beispiel bei 1,89 EUR Gesamtbetrag und 19,00 %: 1,59 EUR netto + 0,30 EUR USt
= 1,89 EUR Endbetrag. Die Tarifpositionen bleiben Endpreise; Steuer und Nettosumme
werden auf Cent gerundet und stimmen zusammen mit dem Gesamtbetrag überein.

Eine [Beispiel-PDF mit fiktiven Daten](examples/rechnung-beispiel.pdf) zeigt
Adressblöcke, Datum und Nummer, Bankangaben, Positionstabelle, Summen und Fußbereich.

## Beginn und Abschluss eines Ladegangs

Ein Ladegang beginnt, wenn die tatsächlich gemessene Leistung die eingestellte
Schwelle überschreitet. Die ersten bekannten Messwerte bilden die Messbasis;
vor Aktivierung unbekannte Ladeenergie wird nicht rückwirkend zugeordnet.

Sinkt die Leistung auf oder unter die Schwelle, beginnt die Ladepause. Wird noch
vor Ablauf der Pause weitergeladen, bleibt es derselbe Ladegang. Nach Ablauf
wird die Abrechnung im nächsten Regeldurchlauf abgeschlossen und als PDF
abgelegt. Als Ende wird der Beginn der abschließenden Ladepause ausgewiesen.
Lange Unterbrechungen, beispielsweise wegen fehlendem PV-Budget, erzeugen nach
der gewählten Pausenzeit getrennte Abrechnungen.

Fehlende oder ungültige Leistungswerte sind kein bestätigtes Ladeende. Eine
solche Messlücke unterbricht die Erkennung der durchgehenden Ladepause. Nach
Wiederkehr gültiger Nullwerte beginnt die Pausenzeit erneut.

## Energie und Tarifwechsel

Die Abrechnungsenergie wird aus tatsächlicher Ladeleistung in W oder kW über die
Zeit berechnet. Angeforderte Sollwerte fließen nicht als Verbrauch ein. Werte
auf oder unter der Leistungsschwelle werden mit 0 W berücksichtigt. Messwert-
und Heartbeat-Überwachung sowie die maximale Integrationslücke werden beachtet.

Ein Wechsel der PV-Boolvariable wird direkt verarbeitet: Das bisherige Intervall
wird mit seinem bisherigen Tarif erfasst, kommende Intervalle mit dem neuen.
Die Präzision richtet sich nach der zeitlichen Auflösung und Aktualisierung der
Messwerte. Symcon-Leistungszeitstempel besitzen Sekundenauflösung.

Auch bei verknüpftem Energiezähler nutzt die Tarifaufteilung die Leistungsmessung.
So hängt die Zuordnung nicht vom Aktualisierungsintervall des Energiezählers ab.
Der bisherige Geräte-/Gesamtenergiezähler bleibt eine separate Auswertung;
Abweichungen zur berechneten Abrechnungsenergie sind möglich.

Wird eine verknüpfte PV-Boolvariable ungültig oder gelöscht, wird die betroffene
Energie ausdrücklich **unzugeordnet** erfasst. Sie erhält keinen angenommenen
Tarif. Kachel, Statusvariable und PDF weisen auf die erforderliche Prüfung hin.

Preise werden für jedes erfasste Intervall festgehalten. Bei Änderungen während
eines Ladegangs entstehen getrennte Tarifpositionen; alte Energie wird nicht
nachträglich mit neuen Preisen bewertet. Positionen mit gleichem Tarif und Preis
werden zusammengefasst. Der Betrag je Position wird auf Cent gerundet; der
Gesamtbetrag ist die Summe dieser Centbeträge. Energie bleibt intern ungerundet.

## Beispiel

| Tarif | Energie | Preis pro kWh | Betrag |
|---|---|---|---|
| PV-Überschussladen | 8,50 kWh | 0,10 EUR | 0,85 EUR |
| Normalladen | 3,25 kWh | 0,32 EUR | 1,04 EUR |
| Gesamt | 11,75 kWh | | 1,89 EUR |

## PDF und Aufbewahrung

Nach einem Ladegang mit erfasster Energie erstellt der Manager selbstständig ein
PDF-Dokumentmedium. Die Dokumente liegen im Symcon-Objektbaum unter der Instanz
in **Abrechnung – Wallbox 1** bis **Abrechnung – Wallbox 4**. Die Dateien liegen im
Symcon-Verzeichnis `media/`. Namen und Identifikationen verhindern, dass
Wiederholungsversuche dieselbe Abrechnung mehrfach anlegen.

Die Kachel zeigt je aktiver Wallbox den aufklappbaren Bereich **Abrechnung** mit
laufendem beziehungsweise letztem Ladegang, PV-/Normalenergie und Kosten. Der
Button **Rechnung … als PDF** lädt das letzte gespeicherte Dokument
herunter. Ältere PDFs bleiben im jeweiligen Symcon-Ordner erreichbar. Ein
offener Browser ist für die automatische Erstellung nicht erforderlich.

Zum Download in der Visualisierung die gewünschte **Wallbox → Abrechnung**
aufklappen und **„Rechnung … als PDF“** anklicken (bei älteren Dokumenten
„Letzte Abrechnung als PDF“). Der Browser speichert
die Datei im Download-Ordner oder fragt nach einem Speicherort. Solange kein
abgeschlossener Ladegang mit erfasster Energie vorliegt, ist der Button deaktiviert.
Der Ladegang wird nach der eingestellten Ladepause abgeschlossen, standardmäßig
nach 600 Sekunden. Bei einem PDF-Schreibfehler zeigt die Kachel den Fehler an.

Im Symcon-Objektbaum die Managerinstanz und darunter **„Abrechnung – Wallbox n“**
öffnen. Jedes dortige Dokument steht für einen abgeschlossenen Ladegang und kann
über die Dokumentansicht geöffnet beziehungsweise heruntergeladen werden.
Wird die Anzahl der Wallboxen reduziert, bleiben auch die PDFs ausgeblendeter
Plätze in diesen Ordnern erhalten. Ein dabei noch laufender Ladegang wird mit
der bisher erfassten Energie abgeschlossen.

Kann ein PDF nicht gespeichert werden, bleibt die Abrechnung in einer
persistenten Warteschlange. Der Fehler wird angezeigt; die Erstellung wird im
nächsten Regeldurchlauf erneut versucht. Ein Dokumentfehler beendet die
Laderegelung nicht. PDFs werden nicht automatisch gelöscht oder versandt.
Es wird **für jeden Ladegang eine eigene PDF** gespeichert; der Downloadbutton
zeigt lediglich auf das letzte Dokument. Frühere PDFs werden nicht überschrieben.

Ein Neustart bewahrt laufende Ladegänge, bisherige Tarifpositionen und noch zu
erstellende PDFs. Ausfallzeiten werden nicht als angenommene Ladeenergie
berechnet. Messlücken erscheinen als Hinweise. Beim Gerätewechsel oder
Deaktivieren der Abrechnung wird der bisher erfasste Ladegang abgeschlossen.

Der bestätigte **Energiezähler-Reset** setzt nur die bisherigen Platz- und
Gesamtenergiezähler zurück. Abrechnungssummen, laufende Ladegänge und bestehende
PDFs bleiben erhalten.

## Statusvariablen und Skripte

`Wn` steht für `W1` bis `W4`:

- `WnBillingPVEnergy`, `WnBillingNormalEnergy`: kumulierte Abrechnungsenergie.
- `WnBillingUnassignedEnergy`: Energie mit ungeklärtem Tarif.
- `WnBillingPVCost`, `WnBillingNormalCost`, `WnBillingCost`: Kosten einschließlich
  des laufenden Ladegangs.
- `WnBillingSessions`: Anzahl abgeschlossener Ladegänge mit Energie.
- `WnBillingStatus`: Status beziehungsweise Fehler bei der Dokumenterstellung.
- `WnBillingLastPDF`: Medien-ID des letzten Ladegangs; 0, solange dessen PDF noch
  nicht gespeichert ist.

`WBM_GetBillingStatus(InstanzID, Wallboxnummer)` liefert den Abrechnungszustand als
JSON. `WBM_GetBillingPDF(InstanzID, Wallboxnummer)` liefert die letzte gespeicherte
PDF als Base64-String, beziehungsweise einen leeren String, wenn sie noch fehlt.
Die Wallboxnummer liegt zwischen 1 und 4.

Die Energie ist berechnet und nicht für eichrechtskonforme Abrechnung vorgesehen.
