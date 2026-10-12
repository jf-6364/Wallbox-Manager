# Abnahme an einer echten Symcon-Installation

Diese Prüfungen sind noch offen. Automatisierte Tests verwenden weder einen
laufenden Symcon-Kernel noch echte Hardware.

- [ ] Bibliothek unter Symcon 7.1 und der eingesetzten Version laden;
      „Wallbox Manager“ erscheint unter „Instanz hinzufügen“.
- [ ] Formular, Auswahlfelder, Übernehmen, Bestätigungsdialoge und Fehlertexte prüfen.
- [ ] Eine, zwei und vier Wallboxen; unbenutzte Plätze deaktiviert.
- [ ] Stoppwert am konkreten Gerät nachweisen, einschließlich 1 A falls geeignet.
      Prüfen, dass Stopp keine Begrenzung aufhebt.
- [ ] Numerischer Sollwert ohne separate Gerätefreigabe sowie optionale
      Gerätefreigabe mit beiden Polaritäten.
- [ ] Globale, manuelle und externe Sperren; Kachel schreibt externe Freigaben nie.
- [ ] A/W/kW, Spannung, Phasen, Raster und Min-/Max-Grenzen.
- [ ] Gleichmäßig/Priorität, gleiche Ränge, Sättigung und faire Rotation.
- [ ] Startverzögerung bei wechselndem Budget, Mindestlaufzeit, Nachlauf,
      Mindestpause und eigene/globale Hysterese.
- [ ] Striktes Budget, begrenzte Überschreitung und verbindlicher harter Grenzwert.
- [ ] Umverteilung: Reduzierung, reale Last fällt, erst dann Erhöhung; Timeout.
- [ ] Fehlende Aktion, false-Rückgabe, nicht erreichbares Gerät und Fehlerquittierung.
- [ ] Unveränderte Aktualisierungen, Heartbeat, veraltete/gelöschte Messvariablen.
- [ ] Watchdog-Wiederholung, externer Sollwertwechsel und Befehlsabstand.
- [ ] Speicher: SoC, Reserve, Hysterese, Sperren, Vorzeichen/Einheiten und Leistungsgrenze.
- [ ] Speicher liefert teilweise/gar nicht; sofort kleinere bestätigte Leistung,
      Timeout und sichtbarer Fehler.
- [ ] Ohne Speicherleistungsmessung Anteil und Anlaufwartezeit vor Ort abnehmen.
- [ ] Phasenwechsel nur bei geeigneter Wallbox: Stopp, frische niedrige Leistung,
      Bestätigungsdauer, Vorwartezeit, Phasenbefehl, Rückmeldung, Nachwartezeit.
- [ ] Phasen-Timeout, widersprüchliche Rückmeldung und Last während Wechsel sperren.
- [ ] Neustart während Laden und jeder Phase des Phasenwechsels.
- [ ] Integration gegen echten Zähler vergleichen; schnelle Änderungen,
      gleichzeitige Timer/Ereignisse und Ausfälle.
- [ ] Energiezähler: Wh/kWh, Reset, Wechsel, Sprung, ApplyChanges und Neustart.
- [ ] Gerät deaktivieren/entfernen/ersetzen: alte Ziele stoppen, neue Basis,
      Archiv und Gesamtenergie nachvollziehbar.
- [ ] Zweite Instanz: unabhängig; doppelte Ziele ohne Eingriff in das erste Gerät ablehnen.
- [ ] Eigene Kachel in Symcon: Aktionen, Priorität, Energie, Phasen und Zeitbedingungen.
- [ ] Smartphone/Tablet, schmale Kacheln, Hell-/Dunkelmodus visuell prüfen.
- [ ] Geschützter Energie-Reset und Erhalt der Bedienwerte nach Neustart.

Reale Leistungswerte während der Abnahme parallel beobachten. Lastabbau und
Umschaltungen hängen von tatsächlichen Rückmeldungen und Geräteabfragezeiten ab.