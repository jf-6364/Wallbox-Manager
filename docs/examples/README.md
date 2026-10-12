# Beispielkonfigurationen

JSON-Dateien enthalten Eigenschaftsnamen und Beispieleinstellungen. Alle
Variablen-IDs sind **Illustrationswerte** und müssen durch existierende IDs des
eigenen Symcon-Servers ersetzt werden. Nicht ungeprüft ausführen.
Nicht aufgeführte Eigenschaften verwenden die dokumentierten Standardwerte.

## Zwei dreiphasige Wallboxen, Leistungsklasse 11 kW

[two-11kw.json](two-11kw.json): 230 V, drei Phasen, 6–16 A, ganzzahlige A,
Mindestleistung 4,14 kW, Manager anfangs aus, striktes Budget, W3/W4 deaktiviert.

16 A × 230 V × 3 sind nominell **11,04 kW**. Deshalb ist die nominelle
MaxKW-Grenze im Beispiel 11,04. Soll exakt 11,00 kW eingehalten werden,
MaxKW = 11,0 einstellen; das ganzzahlige A-Raster erlaubt dann maximal
15 A / 10,35 kW. Geräte- und Anlagenvorgaben haben Vorrang.

Bei 12 kW Grundbudget und gleichmäßiger Verteilung sind beispielsweise
9 A / 6,21 kW und 8 A / 5,52 kW zulässig (zusammen 11,73 kW).
Im Prioritätsmodus erhält Rang 1 zunächst bis zu 16 A / 11,04 kW;
0,96 kW Rest reichen nicht für Rang 2. Bei fehlendem Ladebedarf für Rang 1
wird dessen Budget für Rang 2 frei.

| Beispiel-ID | Zweck | Erwarteter Typ |
|---|---|---|
| 12345 | Absolutes Grundbudget ohne Batterie, kW | Float/Integer, Lesen |
| 23451 / 23461 | Wallbox-Sollstrom | Integer/Float mit Aktion |
| 23452 / 23462 | Tatsächliche Fahrzeug-Ladeleistung, W | Integer/Float, Lesen |

Zusätzliche echte boolesche DemandVar/ExternalVar sind empfehlenswert.
Ohne DemandVar nimmt der Manager Ladebedarf an.

## Optionaler 10-kWh-Speicher, bis 4 kW Entladung

[battery-overlay.json](battery-overlay.json) ergänzt das erste Beispiel.
SoC-Reserve 20 %, Hysterese 5 Prozentpunkte. Entladebefehl in kW negativ,
Leistungsmessung in W mit negativem Entladevorzeichen. Diese Konvention muss
zum konkreten Speicher passen; andernfalls Sign/PowerSign anpassen.

| Beispiel-ID | Zweck | Erwarteter Typ |
|---|---|---|
| 34501 | SoC in Prozent | Integer/Float, Lesen |
| 34502 | Entladesollwert in kW, negativ | Float mit Aktion |
| 34503 | Gemessene Speicherleistung in W, Entladung negativ | Integer/Float, Lesen |
| 34504 | Externe Speicherfreigabe | Boolean, Lesen |

Bei Grundbudget 6 kW und ausreichendem Bedarf können höchstens 10 kW geplant
werden. Die tatsächlich zusätzliche Anforderung hängt vom Raster ab.
Nur 2 kW zusätzlicher sinnvoller Bedarf führen zu höchstens 2 kW Anforderung.
Nach erfolgreichem Befehl wird die zusätzliche Wallboxleistung erst anhand
der Leistungsrückmeldung erlaubt. false an 34504 beendet die Entladeanforderung.

## Individueller Ausschaltwert 1 A

[stop-1a.json](stop-1a.json) zeigt eine rein numerisch gesteuerte Wallbox mit
1 A als Geräte-Stoppbefehl, ohne separate boolesche Gerätefreigabe.
Aktive Ladung beginnt weiterhin erst bei mindestens 6 A / 4,14 kW.

Dies kann für ein konkret geprüftes Mennekes-Gerät passend sein. Es ist keine
Aussage über sämtliche Mennekes-Geräte oder Firmwarestände. Hersteller-/Modul-
dokumentation und reale Geräteaktion müssen den Stoppwert bestätigen.
1 A bedeutet im Manager 0 W Anforderung; Energie stammt aus Fahrzeugmessungen.