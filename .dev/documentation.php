<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/bootstrap.php';
use WBM\Schema;
$t = chr(96);
$md = "# Einstellungen und Variablen\n\nDie Definitionen werden aus libs/Schema.php erzeugt. Wn steht für W1 bis W4.\n";
$md .= "Die Sollwertziele müssen im Normalbetrieb Geräteaktionen besitzen. Alle externen Freigaben sind ausschließlich Lesebedingungen.\n\n";
$md .= "## Variablenverknüpfungen\n\n| Eigenschaft | Zweck | Datentyp | Einheit | Pflicht | Zugriff |\n|---|---|---|---|---|---|\n";
$unitMap = ['BudgetVar' => 'W/kW (BudgetUnit)', 'SetpointVar' => 'A/W/kW (SetpointUnit)', 'PowerVar' => 'W/kW (PowerUnit)', 'EnergyVar' => 'Wh/kWh (EnergyUnit)',
    'SocVar' => '%', 'LimitVar' => 'W/kW (LimitUnit)', 'PhaseCommandVar' => 'Gerätewerte für 1/3 Phasen', 'PhaseFeedbackVar' => 'Wertezuordnung für 1/3 Phasen'];
foreach (['' => Schema::global(), 'Wn' => Schema::wallbox(), 'B' => Schema::battery()] as $prefix => $defs) {
    foreach ($defs as $key => $d) {
        if (!isset($d[4])) continue;
        $type = implode('/', array_map(fn($v) => ['Boolean', 'Integer', 'Float', 'String'][$v], $d[4]));
        $unit = $unitMap[$key] ?? '–';
        if ($prefix === 'B' && $key === 'SetpointVar') $unit = 'W/kW + Sign';
        $mandatory = $d[5] ? ($prefix === 'B' ? 'bei aktiver Speicherfunktion' : ($key === 'PhaseCommandVar' || $key === 'PhaseFeedbackVar' ? 'nur dynamischer Betrieb' : 'bei aktivem Manager/Platz')) : 'optional, 0 = unverbunden';
        $read = $d[3] === 'write' ? 'Schreiben per Aktion; Lesen zur Diagnose' : 'Lesen, nie Gerätebefehl';
        $md .= "| $t$prefix$key$t | {$d[1]} | $type | $unit | $mandatory | $read |\n";
    }
}
$md .= "\nExterne Freigabe = zusätzliche Sperrbedingung. DeviceEnableVar = tatsächlicher Gerätebefehl mit konfigurierbarer Polaritität. Manual = eigene Bedienvariable des Managers. Diese Rollen sind nicht austauschbar.\n";
$md .= "\nErrorVar: true meldet einen Fehler. DemandVar: true meldet verbundenen/ladewilligen Zustand. HeartbeatVar: der Wert ist unerheblich, seine letzte Aktualisierung zählt.\n";
foreach (['Allgemein' => Schema::global(), 'Wallbox Wn' => Schema::wallbox(), 'Speicher B' => Schema::battery()] as $caption => $defs) {
    $prefix = $caption === 'Allgemein' ? '' : ($caption === 'Wallbox Wn' ? 'Wn' : 'B');
    $md .= "\n## $caption: sämtliche Einstellungen\n\n| Eigenschaft | Standard | Bedeutung / Einheit | Bereich |\n|---|---|---|---|\n";
    foreach ($defs as $key => $d) {
        $value = is_bool($d[0]) ? ($d[0] ? 'true' : 'false') : ($d[0] === '' ? '(leer)' : (string)$d[0]);
        if ($key === 'Name' && $prefix === 'Wn') $value = 'Wallbox n';
        if ($key === 'Priority' && $prefix === 'Wn') $value = 'n';
        $md .= "| $t$prefix$key$t | $t$value$t | {$d[1]} | {$d[2]} |\n";
    }
}
$md .= "\nRaster beginnt bei 0 in der Sollwerteinheit. Stop ist unabhängig vom aktiven Raster; ein mit einem aktiven zulässigen Rasterwert kollidierender Wallbox-Stoppwert wird abgelehnt. Negative eigene Hysterese ist nur als -1 (global) erlaubt.\n";
$md .= "\n## Automatisch erzeugte Status- und Bedienvariablen\n\nAlle vier Plätze werden dauerhaft angelegt. Unverknüpfte / ungültige numerische Messungen erscheinen in numerischen Statusvariablen als 0; die Kachel zeigt stattdessen fehlende Messung und Fehlertexte. Verfügbarer Speicher ist theoretisch; Confirmed ist die tatsächlich zur Planung verwendete Bestätigung.\n\n";
$md .= "| Ident | Typ | Einheit | Bedienung | Bedeutung |\n|---|---|---|---|---|\n";
$status = [
'ManagerEnabled' => ['Boolean', '–', 'ja', 'Globale Laufzeitfreigabe'],
'DistributionMode' => ['Integer', '0/1', 'ja', 'Gleichmäßig/Priorität'],
'BatteryEnabled' => ['Boolean', '–', 'ja', 'Manuelle Speicherfreigabe'],
'Status' => ['String', '–', 'nein', 'Managerstatus und Fehlerhinweise'],
'TotalPower' => ['Float', 'W', 'nein', 'Summe gültiger tatsächlicher Ladeleistungen aktiver Plätze'],
'AssignedPower' => ['Float', 'W', 'nein', 'Aktuell erfolgreich beauftragte nominelle Ladeleistung'],
'TotalEnergy' => ['Float', 'kWh', 'nein', 'Alle Fahrzeugenergien einschließlich archivierter Gerätegenerationen'],
'BaseBudget' => ['Float', 'W', 'nein', 'Grundbudget, auf 0 / harten Grenzwert begrenzt'],
'OverrunPower' => ['Float', 'W', 'nein', 'Zuweisung über Grundbudget plus bestätigtem Speicherbetrag'],
'BatteryAvailable' => ['Float', 'W', 'nein', 'Theoretisch nutzbare Unterstützung'],
'BatteryConfirmed' => ['Float', 'W', 'nein', 'Bestätigte / konservativ freigegebene Unterstützung'],
'BatteryRequested' => ['Float', 'W', 'nein', 'Erfolgreich beauftragte Entladeleistung, intern positiv'],
'BatteryMeasured' => ['Float', 'W', 'nein', 'Gemessene Entladung, intern positiv; bei Ladung 0'],
'BatterySoc' => ['Float', '%', 'nein', 'Aktueller SoC'],
'BatteryEnergy' => ['Float', 'kWh', 'nein', 'Geschätzte Energie oberhalb Reserve'],
'BatteryState' => ['String', '–', 'nein', 'Zustand / Begrenzungsgrund'],
'BatteryExternalLocked' => ['Boolean', '–', 'nein', 'Externe Speicherfreigabe false'],
'WnManual' => ['Boolean', '–', 'ja', 'Eigene manuelle Freigabe, bedeutet automatischer Betrieb'],
'WnPriority' => ['Integer', '1–999', 'ja', 'Persistente Laufzeitpriorität, 1 ist höchste'],
'WnPower' => ['Float', 'W', 'nein', 'Tatsächliche gemessene Ladeleistung'],
'WnAssigned' => ['Float', 'W', 'nein', 'Erfolgreich beauftragte nominelle Ladeleistung'],
'WnSetpoint' => ['Float', 'A/W/kW laut Konfiguration', 'nein', 'Beauftragter numerischer Wert; ausgeschaltet = individueller Stop'],
'WnPhases' => ['Integer', '1/3; 0 unbestätigt', 'nein', 'Feste bzw. bestätigte Phasenanzahl'],
'WnEnergy' => ['Float', 'kWh', 'nein', 'Energie der aktuellen Gerätegeneration'],
'WnExternalLocked' => ['Boolean', '–', 'nein', 'Externe Freigabe false'],
'WnState' => ['String', '–', 'nein', 'Betriebszustand / Wartegrund'],
'WnRemaining' => ['Integer', 's', 'nein', 'Verbleibende Zeitbedingung'],
'WnEnergyMethod' => ['String', '–', 'nein', 'berechnet oder Gerätezähler'],
'WnMeasurementGap' => ['String', '–', 'nein', 'Messlücke, neue Zählerbasis oder Gerätewechsel']
];
foreach ($status as $ident => $r) $md .= "| $t$ident$t | " . implode(' | ', $r) . " |\n";
$md .= "\nDie 17 Manager-/Speichervariablen und 12 Variablen je Platz ergeben 65 Variablen. Statusvariablen haben keine Geräteaktion. Bedienvariablen verwenden RequestAction und behalten ihre Werte über Neustart / ApplyChanges; geänderte anfängliche Werte im Formular werden bewusst übernommen.\n";
$md .= "\n## Öffentliche Modulfunktionen\n\n| Funktion | Zweck |\n|---|---|\n";
foreach (['WBM_Control(int InstanceID)' => 'Einen Regeldurchlauf auslösen', 'WBM_Diagnose(int InstanceID)' => 'JSON mit Konfigurationsfehlern, Budget, Zuteilungen, Zuständen und letzten 40 Befehlen',
'WBM_ClearFaults(int InstanceID)' => 'Befehls-/Phasenfehler nach Ursachenprüfung quittieren', 'WBM_ResetEnergy(int InstanceID, string Confirmation)' => 'Alle Zähler nur bei exakt ENERGIE ZURÜCKSETZEN löschen',
'WBM_ControlTargets(int InstanceID)' => 'Internes JSON der beanspruchten Steuerziele; dient Instanzkonfliktprüfung'] as $name => $purpose) $md .= "| $t$name$t | $purpose |\n";
$md .= "\nRuntime, Configuration, Telemetry und OwnedTargets sind persistente String-Attribute. Puffer werden nur für flüchtige Startmarkierung verwendet. Steuerzielübernahme ist instanzübergreifend durch eine Semaphore geschützt.\n";
file_put_contents(__DIR__ . '/../docs/KONFIGURATION.md', $md);

$two = [
'Enabled'=>false,'BudgetVar'=>12345,'BudgetUnit'=>'kW','Interval'=>5,'Hysteresis'=>0.2,'HysteresisUnit'=>'kW','HardLimitW'=>22080.0,'Mode'=>0,'StrictBudget'=>true,'Rotation'=>900,
'W1Enabled'=>true,'W1Name'=>'Garage (11 kW)','W1DeviceKey'=>'garage-2026','W1SetpointVar'=>23451,'W1PowerVar'=>23452,'W1SetpointUnit'=>'A','W1PowerUnit'=>'W','W1MinA'=>6.0,'W1MaxA'=>16.0,'W1MinKW'=>4.14,'W1MaxKW'=>11.04,'W1Step'=>1.0,'W1Stop'=>0.0,'W1PhaseMode'=>'3','W1Priority'=>1,'W1MaxAge'=>120,
'W2Enabled'=>true,'W2Name'=>'Stellplatz (11 kW)','W2DeviceKey'=>'stellplatz-2026','W2SetpointVar'=>23461,'W2PowerVar'=>23462,'W2SetpointUnit'=>'A','W2PowerUnit'=>'W','W2MinA'=>6.0,'W2MaxA'=>16.0,'W2MinKW'=>4.14,'W2MaxKW'=>11.04,'W2Step'=>1.0,'W2Stop'=>0.0,'W2PhaseMode'=>'3','W2Priority'=>2,'W2MaxAge'=>120
];
$storage = ['BEnabled'=>true,'BCapacityKWh'=>10.0,'BMaxPower'=>4.0,'BMaxPowerUnit'=>'kW','BSocVar'=>34501,'BMinSoc'=>20.0,'BSocHysteresis'=>5.0,'BSetpointVar'=>34502,
'BSetpointUnit'=>'kW','BSign'=>-1,'BPowerVar'=>34503,'BPowerUnit'=>'W','BPowerSign'=>-1,'BStop'=>0.0,'BExternalVar'=>34504,'BMaxAge'=>120];
$stop = ['Enabled'=>false,'BudgetVar'=>12345,'BudgetUnit'=>'W','W1Enabled'=>true,'W1Name'=>'Wallbox mit individuellem 1-A-Stopp','W1DeviceKey'=>'wb-1A-2026',
'W1SetpointVar'=>23451,'W1SetpointUnit'=>'A','W1PowerVar'=>23452,'W1PowerUnit'=>'W','W1MinA'=>6.0,'W1MaxA'=>16.0,'W1MinKW'=>4.14,'W1MaxKW'=>11.04,
'W1Step'=>1.0,'W1Stop'=>1.0,'W1PhaseMode'=>'3'];
foreach (['two-11kw.json'=>$two,'battery-overlay.json'=>$storage,'stop-1a.json'=>$stop] as $name=>$data) file_put_contents(__DIR__ . '/../docs/examples/' . $name,
json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n");
echo "Konfigurationstabellen und Beispiele erzeugt\n";