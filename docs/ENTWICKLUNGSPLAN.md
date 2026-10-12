# Technische Grundlage

Die offizielle vollständige Dokumentation wurde am 12.10.2026 von
https://www.symcon.de/de/llms-full.txt heruntergeladen (4.540.390 Byte).
Die lokale Recherchekopie liegt in `.research/`, außerhalb des Installationspakets.
Es waren keine vorhandenen Projektdateien oder AGENTS.md vorhanden.

1. Symcon 7.1 als Mindestversion: GetVisualizationTile, SetVisualizationType,
   UpdateVisualizationValue sowie handleMessage/requestAction benötigen 7.1.
   Es wird IPSModule mit den dafür dokumentierten Signaturen verwendet.
2. Reine PHP-Klassen für Einheiten/Raster, Verteilung, Zeitbedingungen,
   Phasenwechsel, Speicher und Energie. Tests benötigen keinen Symcon-Kernel.
3. Ein Adapter liest Variablen, prüft Aktionen und Referenzen, schützt Zustände
   mit einer instanzbezogenen Semaphore und persistiert sie als Attribute.
4. Einheiten intern W/kWh; Grundbudget absolut ohne Batteriespeicher. Speicher
   wird zuerst angefordert und erst nach Rückmeldung bzw. Anlaufwartezeit verwendet.
5. Gerätebegrenzungen, Sperren und harter Gesamtgrenzwert haben Vorrang.
   Reduzierungen werden vor Erhöhungen gesendet; noch nicht abgebaute gemessene
   Last wird weiter reserviert. Phasenwechsel nur nach bestätigtem Stillstand.
6. Lokale HTML/CSS/JS-Kachel mit SDK-Aktionen und inkrementellen Aktualisierungen.
7. Automatisierte Fachtests, Adaptertests mit Kernel-Testdouble, Syntax- und
   Metadatenprüfung. Echte Geräte und Symcon bleiben getrennte Abnahmeprüfungen.
