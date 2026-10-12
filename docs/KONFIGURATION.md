# Einstellungen und Variablen

Die Definitionen werden aus libs/Schema.php erzeugt. Wn steht für W1 bis W4.
Die Sollwertziele müssen im Normalbetrieb Geräteaktionen besitzen. Alle externen Freigaben sind ausschließlich Lesebedingungen.

Von Gerätemodulen angelegte Sollwertvariablen können als Objekt schreibgeschützt sein und trotzdem eine Geräteaktion besitzen. Dieser Objektschutz muss für die Steuerung nicht entfernt werden. Gesperrte Variablenwerte und fehlende Geräteaktionen werden weiterhin abgelehnt.

## Variablenverknüpfungen

| Eigenschaft | Zweck | Datentyp | Einheit | Pflicht | Zugriff |
|---|---|---|---|---|---|
| `BudgetVar` | Absolutes Grundbudget ohne Speicher | Integer/Float | W/kW (BudgetUnit) | bei aktivem Manager/Platz | Lesen, nie Gerätebefehl |
| `WnExternalVar` | Externe Freigabebedingung (optional) | Boolean | – | optional, 0 = unverbunden | Lesen, nie Gerätebefehl |
| `WnDemandVar` | Fahrzeug verbunden / Ladebedarf (optional) | Boolean | – | optional, 0 = unverbunden | Lesen, nie Gerätebefehl |
| `WnErrorVar` | Gerätefehler (true = Fehler; optional) | Boolean | – | optional, 0 = unverbunden | Lesen, nie Gerätebefehl |
| `WnSetpointVar` | Gerätesollwert | Integer/Float | A/W/kW (SetpointUnit) | bei aktivem Manager/Platz | Schreiben per Aktion; Lesen zur Diagnose |
| `WnDeviceEnableVar` | Separate Geräte-Ladefreigabe (optional) | Boolean | – | optional, 0 = unverbunden | Schreiben per Aktion; Lesen zur Diagnose |
| `WnPowerVar` | Tatsächliche Ladeleistung | Integer/Float | W/kW (PowerUnit) | bei aktivem Manager/Platz | Lesen, nie Gerätebefehl |
| `WnEnergyVar` | Kumulativer Geräte-Energiezähler (optional) | Integer/Float | Wh/kWh (EnergyUnit) | optional, 0 = unverbunden | Lesen, nie Gerätebefehl |
| `WnHeartbeatVar` | Geräte-Heartbeat (optional; Aktualisierung zählt) | Boolean/Integer/Float/String | – | optional, 0 = unverbunden | Lesen, nie Gerätebefehl |
| `WnPhaseCommandVar` | Phasenwechsel: Befehlsvariable | Integer/Float | Gerätewerte für 1/3 Phasen | nur dynamischer Betrieb | Schreiben per Aktion; Lesen zur Diagnose |
| `WnPhaseFeedbackVar` | Phasenwechsel: tatsächliche Rückmeldung | Integer/Float | Wertezuordnung für 1/3 Phasen | nur dynamischer Betrieb | Lesen, nie Gerätebefehl |
| `BSocVar` | Aktueller SoC (%) | Integer/Float | % | bei aktiver Speicherfunktion | Lesen, nie Gerätebefehl |
| `BExternalVar` | Externe Speicherfreigabe (optional) | Boolean | – | optional, 0 = unverbunden | Lesen, nie Gerätebefehl |
| `BSetpointVar` | Entladeleistung: Geräte-Sollwert | Integer/Float | W/kW + Sign | bei aktiver Speicherfunktion | Schreiben per Aktion; Lesen zur Diagnose |
| `BPowerVar` | Tatsächliche Speicherleistung (optional) | Integer/Float | W/kW (PowerUnit) | optional, 0 = unverbunden | Lesen, nie Gerätebefehl |
| `BLimitVar` | Aktuelle Entladeleistungsgrenze (optional) | Integer/Float | W/kW (LimitUnit) | optional, 0 = unverbunden | Lesen, nie Gerätebefehl |

Externe Freigabe = zusätzliche Sperrbedingung. DeviceEnableVar = tatsächlicher Gerätebefehl mit konfigurierbarer Polaritität. Manual = eigene Bedienvariable des Managers. Diese Rollen sind nicht austauschbar.

ErrorVar: true meldet einen Fehler. DemandVar: true meldet verbundenen/ladewilligen Zustand. HeartbeatVar: der Wert ist unerheblich, seine letzte Aktualisierung zählt.

## Allgemein: sämtliche Einstellungen

| Eigenschaft | Standard | Bedeutung / Einheit | Bereich |
|---|---|---|---|
| `Enabled` | `false` | Manager bei Erstkonfiguration aktivieren | Allgemein und Budget |
| `BudgetVar` | `0` | Absolutes Grundbudget ohne Speicher | Allgemein und Budget |
| `BudgetUnit` | `W` | Einheit Grundbudget | Allgemein und Budget |
| `Interval` | `5` | Regelintervall (s) | Allgemein und Budget |
| `Hysteresis` | `200` | Globale Hysterese | Allgemein und Budget |
| `HysteresisUnit` | `W` | Einheit Hysterese | Allgemein und Budget |
| `HardLimitW` | `0` | Harter Gesamtgrenzwert (W; 0 = kein zusätzlicher) | Allgemein und Budget |
| `BudgetMaxAge` | `0` | Budgetüberwachung (s; 0 = aus) | Allgemein und Budget |
| `Mode` | `0` | Anfängliche Verteilung (0 = gleichmäßig, 1 = Priorität) | Verteilungsmodus |
| `Rotation` | `900` | Rotation bei knappem Budget (s; 0 = aus) | Verteilungsmodus |
| `StrictBudget` | `true` | Budget strikt einhalten | Verteilungsmodus |
| `OverrunW` | `0` | Maximal erlaubte zeitweilige Überschreitung (W) | Verteilungsmodus |
| `OverrunSeconds` | `120` | Maximale Dauer einer Überschreitung (s) | Verteilungsmodus |
| `Simulation` | `false` | Simulation: SetValue bei Zielen ohne Aktion erlauben | Diagnose |
| `Watchdog` | `0` | Sollwert-Wiederholung (s; 0 = aus) | Diagnose |
| `FeedbackTimeout` | `30` | Timeout Lastabbau / Reduzierungsrückmeldung (s) | Diagnose |
| `FeedbackToleranceW` | `100` | Toleranz Lastabbau (W) | Diagnose |
| `EnergyMaxGap` | `120` | Maximale Integrationslücke (s) | Energiezählung |
| `MeterJumpFactor` | `2` | Plausibilitätsfaktor für Zählersprünge | Energiezählung |
| `MeterJumpSlack` | `0.02` | Zählersprung-Toleranz (kWh) | Energiezählung |

## Wallbox Wn: sämtliche Einstellungen

| Eigenschaft | Standard | Bedeutung / Einheit | Bereich |
|---|---|---|---|
| `WnEnabled` | `false` | Platz aktiviert | Allgemein |
| `WnName` | `Wallbox n` | Name | Allgemein |
| `WnDeviceKey` | `(leer)` | Gerätekennung (bei Gerätetausch ändern) | Allgemein |
| `WnPriority` | `n` | Anfängliche Priorität (1 = höchste) | Allgemein |
| `WnExternalVar` | `0` | Externe Freigabebedingung (optional) | Allgemein |
| `WnDemandVar` | `0` | Fahrzeug verbunden / Ladebedarf (optional) | Allgemein |
| `WnErrorVar` | `0` | Gerätefehler (true = Fehler; optional) | Allgemein |
| `WnSetpointVar` | `0` | Gerätesollwert | Sollwertausgabe |
| `WnSetpointUnit` | `A` | Sollwerteinheit | Sollwertausgabe |
| `WnMinA` | `6` | Minimaler Strom je Phase (A) | Sollwertausgabe |
| `WnMaxA` | `16` | Maximaler Strom je Phase (A) | Sollwertausgabe |
| `WnMinKW` | `1.38` | Mindestladeleistung (kW) | Sollwertausgabe |
| `WnMaxKW` | `11.04` | Maximale Ladeleistung (kW) | Sollwertausgabe |
| `WnStep` | `1` | Sollwertraster in Sollwerteinheit (ab 0) | Sollwertausgabe |
| `WnStop` | `0` | Individueller Ausschaltwert in Sollwerteinheit | Sollwertausgabe |
| `WnDeviceEnableVar` | `0` | Separate Geräte-Ladefreigabe (optional) | Sollwertausgabe |
| `WnEnablePolarity` | `true` | Gerätefreigabe: true erlaubt Laden | Sollwertausgabe |
| `WnPowerVar` | `0` | Tatsächliche Ladeleistung | Messwerte |
| `WnPowerUnit` | `W` | Einheit Ladeleistungsmessung | Messwerte |
| `WnEnergyVar` | `0` | Kumulativer Geräte-Energiezähler (optional) | Messwerte |
| `WnEnergyUnit` | `kWh` | Einheit Geräte-Energiezähler | Messwerte |
| `WnMaxAge` | `0` | Messwertalter / Heartbeat-Timeout (s; 0 = aus) | Messwerte |
| `WnHeartbeatVar` | `0` | Geräte-Heartbeat (optional; Aktualisierung zählt) | Messwerte |
| `WnVoltage` | `230` | Spannung Außenleiter–Neutralleiter (V) | Phasen |
| `WnPhaseMode` | `3` | Phasenbetrieb | Phasen |
| `WnPhaseCommandVar` | `0` | Phasenwechsel: Befehlsvariable | Dynamische Phasenumschaltung |
| `WnPhaseFeedbackVar` | `0` | Phasenwechsel: tatsächliche Rückmeldung | Dynamische Phasenumschaltung |
| `WnPhase1Command` | `1` | Befehlswert eine Phase | Dynamische Phasenumschaltung |
| `WnPhase3Command` | `3` | Befehlswert drei Phasen | Dynamische Phasenumschaltung |
| `WnPhase1Feedback` | `1` | Rückmeldewert eine Phase | Dynamische Phasenumschaltung |
| `WnPhase3Feedback` | `3` | Rückmeldewert drei Phasen | Dynamische Phasenumschaltung |
| `WnPhaseUpW` | `6000` | Schwelle auf drei Phasen (W) | Dynamische Phasenumschaltung |
| `WnPhaseDownW` | `4000` | Schwelle auf eine Phase (W) | Dynamische Phasenumschaltung |
| `WnPhaseHysteresisW` | `200` | Phasenschwellen-Hysterese (W) | Dynamische Phasenumschaltung |
| `WnPhaseDelay` | `30` | Schwelle dauerhaft erfüllt (s) | Dynamische Phasenumschaltung |
| `WnPhaseDwell` | `300` | Mindestverweildauer (s) | Dynamische Phasenumschaltung |
| `WnPhaseRestW` | `50` | Zulässiger Restverbrauch (W) | Dynamische Phasenumschaltung |
| `WnPhaseZeroSeconds` | `3` | Restverbrauch dauerhaft unterschritten (s) | Dynamische Phasenumschaltung |
| `WnPhaseBefore` | `5` | Wartezeit vor Phasenbefehl (s) | Dynamische Phasenumschaltung |
| `WnPhaseAfter` | `5` | Wartezeit nach bestätigtem Wechsel (s) | Dynamische Phasenumschaltung |
| `WnPhaseTimeout` | `60` | Stillstands-/Rückmeldetimeout (s) | Dynamische Phasenumschaltung |
| `WnStartDelay` | `10` | Einschaltverzögerung (s) | Zeitbedingungen |
| `WnMinRun` | `120` | Mindestlaufzeit (s) | Zeitbedingungen |
| `WnStopDelay` | `30` | Nachlaufzeit bei Leistungsmangel (s) | Zeitbedingungen |
| `WnMinPause` | `60` | Mindestpause (s) | Zeitbedingungen |
| `WnHysteresisW` | `-1` | Eigene Hysterese (W; -1 = global) | Zeitbedingungen |
| `WnCommandGap` | `0` | Mindestabstand für Erhöhungsbefehle (s) | Zeitbedingungen |

## Speicher B: sämtliche Einstellungen

| Eigenschaft | Standard | Bedeutung / Einheit | Bereich |
|---|---|---|---|
| `BEnabled` | `false` | Speicherfunktion aktiviert | Batteriespeicher |
| `BCapacityKWh` | `10` | Nutzbare Kapazität (kWh) | Batteriespeicher |
| `BMaxPower` | `4000` | Maximale Entladeleistung | Batteriespeicher |
| `BMaxPowerUnit` | `W` | Einheit maximale Entladeleistung | Batteriespeicher |
| `BSocVar` | `0` | Aktueller SoC (%) | Batteriespeicher |
| `BMinSoc` | `20` | Mindest-SoC / Reserve (%) | Batteriespeicher |
| `BSocHysteresis` | `5` | SoC-Hysterese (%) | Batteriespeicher |
| `BExternalVar` | `0` | Externe Speicherfreigabe (optional) | Batteriespeicher |
| `BSetpointVar` | `0` | Entladeleistung: Geräte-Sollwert | Batteriespeicher |
| `BSetpointUnit` | `W` | Einheit Entladesollwert | Batteriespeicher |
| `BSign` | `1` | Vorzeichen der Entladung | Batteriespeicher |
| `BStop` | `0` | Neutral-/Stoppwert in Sollwerteinheit | Batteriespeicher |
| `BPowerVar` | `0` | Tatsächliche Speicherleistung (optional) | Batteriespeicher |
| `BPowerUnit` | `W` | Einheit Speichermessung | Batteriespeicher |
| `BPowerSign` | `1` | Vorzeichen der gemessenen Entladung | Batteriespeicher |
| `BLimitVar` | `0` | Aktuelle Entladeleistungsgrenze (optional) | Batteriespeicher |
| `BLimitUnit` | `W` | Einheit Entladeleistungsgrenze | Batteriespeicher |
| `BMaxAge` | `120` | SoC-/Messwertüberwachung (s; 0 = aus) | Batteriespeicher |
| `BStartDelay` | `10` | Konservative Anlaufwartezeit ohne Rückmeldung (s) | Batteriespeicher |
| `BNoFeedbackFraction` | `0.5` | Nutzbarer Anteil ohne Rückmeldung (0 bis 1) | Batteriespeicher |
| `BResponseTimeout` | `30` | Timeout bei unzureichender Entladung (s) | Batteriespeicher |
| `BToleranceW` | `100` | Speicherrückmeldungs-Toleranz (W) | Batteriespeicher |

Raster beginnt bei 0 in der Sollwerteinheit. Stop ist unabhängig vom aktiven Raster; ein mit einem aktiven zulässigen Rasterwert kollidierender Wallbox-Stoppwert wird abgelehnt. Negative eigene Hysterese ist nur als -1 (global) erlaubt.

## Automatisch erzeugte Status- und Bedienvariablen

Alle vier Plätze werden dauerhaft angelegt. Unverknüpfte / ungültige numerische Messungen erscheinen in numerischen Statusvariablen als 0; die Kachel zeigt stattdessen fehlende Messung und Fehlertexte. Verfügbarer Speicher ist theoretisch; Confirmed ist die tatsächlich zur Planung verwendete Bestätigung.

| Ident | Typ | Einheit | Bedienung | Bedeutung |
|---|---|---|---|---|
| `ManagerEnabled` | Boolean | – | ja | Globale Laufzeitfreigabe |
| `DistributionMode` | Integer | 0/1 | ja | Gleichmäßig/Priorität |
| `BatteryEnabled` | Boolean | – | ja | Manuelle Speicherfreigabe |
| `Status` | String | – | nein | Managerstatus und Fehlerhinweise |
| `TotalPower` | Float | W | nein | Summe gültiger tatsächlicher Ladeleistungen aktiver Plätze |
| `AssignedPower` | Float | W | nein | Aktuell erfolgreich beauftragte nominelle Ladeleistung |
| `TotalEnergy` | Float | kWh | nein | Alle Fahrzeugenergien einschließlich archivierter Gerätegenerationen |
| `BaseBudget` | Float | W | nein | Grundbudget, auf 0 / harten Grenzwert begrenzt |
| `OverrunPower` | Float | W | nein | Zuweisung über Grundbudget plus bestätigtem Speicherbetrag |
| `BatteryAvailable` | Float | W | nein | Theoretisch nutzbare Unterstützung |
| `BatteryConfirmed` | Float | W | nein | Bestätigte / konservativ freigegebene Unterstützung |
| `BatteryRequested` | Float | W | nein | Erfolgreich beauftragte Entladeleistung, intern positiv |
| `BatteryMeasured` | Float | W | nein | Gemessene Entladung, intern positiv; bei Ladung 0 |
| `BatterySoc` | Float | % | nein | Aktueller SoC |
| `BatteryEnergy` | Float | kWh | nein | Geschätzte Energie oberhalb Reserve |
| `BatteryState` | String | – | nein | Zustand / Begrenzungsgrund |
| `BatteryExternalLocked` | Boolean | – | nein | Externe Speicherfreigabe false |
| `WnManual` | Boolean | – | ja | Eigene manuelle Freigabe, bedeutet automatischer Betrieb |
| `WnPriority` | Integer | 1–999 | ja | Persistente Laufzeitpriorität, 1 ist höchste |
| `WnPower` | Float | W | nein | Tatsächliche gemessene Ladeleistung |
| `WnAssigned` | Float | W | nein | Erfolgreich beauftragte nominelle Ladeleistung |
| `WnSetpoint` | Float | A/W/kW laut Konfiguration | nein | Beauftragter numerischer Wert; ausgeschaltet = individueller Stop |
| `WnPhases` | Integer | 1/3; 0 unbestätigt | nein | Feste bzw. bestätigte Phasenanzahl |
| `WnEnergy` | Float | kWh | nein | Energie der aktuellen Gerätegeneration |
| `WnExternalLocked` | Boolean | – | nein | Externe Freigabe false |
| `WnState` | String | – | nein | Betriebszustand / Wartegrund |
| `WnRemaining` | Integer | s | nein | Verbleibende Zeitbedingung |
| `WnEnergyMethod` | String | – | nein | berechnet oder Gerätezähler |
| `WnMeasurementGap` | String | – | nein | Messlücke, neue Zählerbasis oder Gerätewechsel |

Die 17 Manager-/Speichervariablen und 12 Variablen je Platz ergeben 65 Variablen. Statusvariablen haben keine Geräteaktion. Bedienvariablen verwenden RequestAction und behalten ihre Werte über Neustart / ApplyChanges; geänderte anfängliche Werte im Formular werden bewusst übernommen.

## Öffentliche Modulfunktionen

| Funktion | Zweck |
|---|---|
| `WBM_Control(int InstanceID)` | Einen Regeldurchlauf auslösen |
| `WBM_Diagnose(int InstanceID)` | JSON mit Konfigurationsfehlern, Budget, Zuteilungen, Zuständen und letzten 40 Befehlen |
| `WBM_ClearFaults(int InstanceID)` | Befehls-/Phasenfehler nach Ursachenprüfung quittieren |
| `WBM_ResetEnergy(int InstanceID, string Confirmation)` | Alle Zähler nur bei exakt ENERGIE ZURÜCKSETZEN löschen |
| `WBM_ControlTargets(int InstanceID)` | Internes JSON der beanspruchten Steuerziele; dient Instanzkonfliktprüfung |

Runtime, Configuration, Telemetry und OwnedTargets sind persistente String-Attribute. Puffer werden nur für flüchtige Startmarkierung verwendet. Steuerzielübernahme ist instanzübergreifend durch eine Semaphore geschützt.
