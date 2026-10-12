<?php
declare(strict_types=1);
require_once __DIR__ . '/SymconStub.php';
require_once __DIR__ . '/../WallboxManager/module.php';

function fixture(int $count = 1, array $properties = []): array
{
    Kernel::reset();
    $m = new WallboxManager(50);
    $m->Create();
    $budget = Kernel::variable(12000.0);
    $ids = ['budget' => $budget];
    $m->properties = array_replace($m->properties, ['Enabled' => true, 'BudgetVar' => $budget, 'Hysteresis' => 0.0]);
    for ($i = 1; $i <= $count; $i++) {
        $setpoint = Kernel::variable(0.0, true);
        $power = Kernel::variable(0.0);
        $ids[$i] = ['setpoint' => $setpoint, 'power' => $power];
        $m->properties = array_replace($m->properties, ['W' . $i . 'Enabled' => true, 'W' . $i . 'SetpointVar' => $setpoint, 'W' . $i . 'PowerVar' => $power,
            'W' . $i . 'SetpointUnit' => 'W', 'W' . $i . 'MinA' => 1.0, 'W' . $i . 'MaxA' => 100.0, 'W' . $i . 'MinKW' => 1.0, 'W' . $i . 'MaxKW' => 11.0,
            'W' . $i . 'StartDelay' => 0, 'W' . $i . 'MinPause' => 0, 'W' . $i . 'MinRun' => 0, 'W' . $i . 'StopDelay' => 0]);
    }
    $m->properties = array_replace($m->properties, $properties);
    return [$m, $ids];
}
function runtimeOf(WallboxManager $m): array { return json_decode($m->attributes['Runtime'], true); }
function telemetryOf(WallboxManager $m): array { return json_decode($m->attributes['Telemetry'], true); }
function setRuntime(WallboxManager $m, array $state): void { $m->attributes['Runtime'] = json_encode($state, JSON_PRESERVE_ZERO_FRACTION); }
function callsTo(int $target): array { return array_values(array_filter(Kernel::$calls, fn($c) => $c['target'] === $target)); }

test('Adapter: HTML-Kachel ohne nicht vorhandene SDK-Konstante', function () {
    check(!defined('INSTANCE_VISUALIZATION_TYPE_HTML'));
    Kernel::reset();
    $m = new WallboxManager(50);
    $m->Create();
    check($m->visualType === 1);
    $m->visualType = 0;
    $m->ApplyChanges();
    check($m->visualType === 1);
});
test('Adapter: installierbare Metadaten, deutsches Formular und HTML-SDK', function () {
    [$m] = fixture(); $m->ApplyChanges();
    check($m->status === 102, $m->GetValue('Status'));
    $form = json_decode($m->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
    check(count($form['elements']) >= 10);
    $html = $m->GetVisualizationTile();
    check(!str_contains($html, '/*__')); check(str_contains($html, 'handleMessage')); check($m->visualType === 1);
    check(count($m->visualUpdates) > 0);
    $library = json_decode(file_get_contents(__DIR__ . '/../library.json'), true, 512, JSON_THROW_ON_ERROR);
    $module = json_decode(file_get_contents(__DIR__ . '/../WallboxManager/module.json'), true, 512, JSON_THROW_ON_ERROR);
    check($library['compatibility']['version'] === '7.1');
    check(str_replace(' ', '', $module['name']) === 'WallboxManager');
});
test('Adapter: RequestAction-Geräteweg und kein wiederholter identischer Befehl', function () {
    [$m, $ids] = fixture(); $m->ApplyChanges();
    closeTo((float)GetValue($ids[1]['setpoint']), 11000);
    $count = count(Kernel::$calls); $m->Control(); check(count(Kernel::$calls) === $count);
});
test('Adapter: individuelle 1-A-Stoppwerte ohne separate Freigabe', function () {
    [$m, $ids] = fixture(1, ['W1SetpointUnit' => 'A', 'W1MinA' => 6.0, 'W1MaxA' => 16.0, 'W1Stop' => 1.0]);
    $m->ApplyChanges(); $m->RequestAction('W1Manual', false);
    closeTo((float)GetValue($ids[1]['setpoint']), 1); closeTo($m->GetValue('W1Assigned'), 0);
});
test('Adapter: fehlende Aktion ergibt Fehler statt SetValue', function () {
    [$m, $ids] = fixture(); Kernel::$variables[$ids[1]['setpoint']]['VariableAction'] = 0;
    Kernel::update($ids[1]['setpoint'], 7.0);
    $m->ApplyChanges(); check($m->status === 200); closeTo(GetValue($ids[1]['setpoint']), 7);
    $m->properties['Simulation'] = true; $m->ApplyChanges(); $m->ClearFaults();
    check($m->status === 102, $m->GetValue('Status'));
});
test('Adapter: externe Sperre überschreibt laufende Mindestlaufzeit', function () {
    [$m, $ids] = fixture(1, ['W1MinRun' => 600]); $external = Kernel::variable(true); $m->properties['W1ExternalVar'] = $external;
    $m->ApplyChanges(); Kernel::update($external, false);
    $m->MessageSink(123456789, $external, VM_UPDATE, []); $m->Control();
    closeTo(GetValue($ids[1]['setpoint']), 0); check(GetValue($external) === false);
    check($m->GetValue('W1ExternalLocked'));
});
test('Adapter: Gerätesperre mit invertierter Polaritität', function () {
    [$m, $ids] = fixture(); $enable = Kernel::variable(true, true);
    $m->properties['W1DeviceEnableVar'] = $enable; $m->properties['W1EnablePolarity'] = false;
    $m->ApplyChanges(); check(GetValue($enable) === false);
    $m->RequestAction('ManagerEnabled', false); check(GetValue($enable) === true); closeTo(GetValue($ids[1]['setpoint']), 0);
});
test('Adapter: Priorität/Modus überleben ApplyChanges und Neustart', function () {
    [$m] = fixture(); $m->ApplyChanges(); $m->RequestAction('W1Priority', 7); $m->RequestAction('DistributionMode', 1); $m->RequestAction('W1Manual', false);
    $m->ApplyChanges(); closeTo($m->GetValue('W1Priority'), 7); check($m->GetValue('DistributionMode') === 1);
    $m->Create(); $m->ApplyChanges(); check($m->GetValue('W1Manual') === false); closeTo($m->GetValue('W1Priority'), 7);
});
test('Adapter: Reduzierungen vor Erhöhungen und Messrückmeldung abwarten', function () {
    [$m, $ids] = fixture(2); $m->ApplyChanges();
    Kernel::update($ids[1]['power'], 6000.0, microtime(true)); Kernel::update($ids[2]['power'], 6000.0, microtime(true));
    Kernel::$calls = []; $m->RequestAction('W1Manual', false);
    closeTo(GetValue($ids[1]['setpoint']), 0); check(GetValue($ids[2]['setpoint']) <= 6000);
    check(Kernel::$calls[0]['target'] === $ids[1]['setpoint']);
    Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->Control();
    closeTo(GetValue($ids[2]['setpoint']), 11000);
});
test('Adapter: fehlgeschlagene Geräteaktion sperrt Erhöhungen', function () {
    [$m, $ids] = fixture(2); $m->ApplyChanges();
    // Confirm the startup stop before testing a later command failure.
    // Initial integer timestamps can precede the stop at a second boundary.
    foreach ([1, 2] as $id) Kernel::update($ids[$id]['power'], 0.0, microtime(true));
    $m->Control();
    check($m->status === 102, $m->GetValue('Status'));
    Kernel::$failures[$ids[1]['setpoint']] = 'false';
    $m->RequestAction('W1Manual', false);
    check($m->status === 201); check(str_contains($m->GetValue('Status'), 'fehlgeschlagen'));
    check(GetValue($ids[2]['setpoint']) <= 6000);
});
test('Adapter: Umkonfiguration hält alte Referenzen bis bestätigt gestoppt', function () {
    [$m, $ids] = fixture(); $m->ApplyChanges();
    Kernel::update($ids[1]['power'], 9000.0, microtime(true));
    $newTarget = Kernel::variable(0.0, true); $newPower = Kernel::variable(0.0);
    $m->properties['W1SetpointVar'] = $newTarget; $m->properties['W1PowerVar'] = $newPower; $m->ApplyChanges();
    closeTo(GetValue($ids[1]['setpoint']), 0); closeTo(GetValue($newTarget), 0);
    check(in_array($ids[1]['setpoint'], $m->GetReferenceList(), true));
    Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->Control();
    check(!in_array($ids[1]['setpoint'], $m->GetReferenceList(), true)); check(GetValue($newTarget) > 0);
});
test('Adapter: deaktivierter Platz löscht keine Gesamtenergie', function () {
    [$m] = fixture(); $m->ApplyChanges(); $s = runtimeOf($m); $s['totalEnergy'] = 12.5; $s['energy'][1]['energy'] = 12.5; setRuntime($m, $s);
    $m->properties['W1Enabled'] = false; $m->ApplyChanges(); closeTo($m->GetValue('TotalEnergy'), 12.5); closeTo($m->GetValue('W1Energy'), 12.5);
});
test('Adapter: ungültige Messung führt zum Stopp und Fehlerstatus', function () {
    [$m, $ids] = fixture(); $m->ApplyChanges(); Kernel::update($ids[1]['power'], NAN); $m->Control();
    closeTo(GetValue($ids[1]['setpoint']), 0); check($m->status === 201);
});
test('Adapter: Variablen gelöscht und veralteter Heartbeat werden erkannt', function () {
    [$m, $ids] = fixture(); $heartbeat = Kernel::variable(0); $m->properties['W1HeartbeatVar'] = $heartbeat; $m->properties['W1MaxAge'] = 30;
    $m->ApplyChanges(); Kernel::update($heartbeat, 0, microtime(true) - 40); $m->Control(); check($m->status === 201);
    unset(Kernel::$variables[$ids[1]['power']]); $m->Control(); check($m->status === 200);
});
test('Adapter: Unveränderter Messwert mit frischem Heartbeat ist gültig', function () {
    [$m, $ids] = fixture(); $heartbeat = Kernel::variable(0); $m->properties['W1HeartbeatVar'] = $heartbeat; $m->properties['W1MaxAge'] = 30;
    $m->ApplyChanges(); Kernel::update($ids[1]['power'], 0.0, microtime(true) - 1000); Kernel::update($heartbeat, 0, microtime(true));
    $m->Control(); check($m->status === 102, $m->GetValue('Status'));
});
test('Adapter: Instanzen sind unabhängig, doppelte Gerätesteuerung abgelehnt', function () {
    [$m, $ids] = fixture(); $m->ApplyChanges();
    $second = new WallboxManager(60); $second->Create(); $second->properties = $m->properties; $second->ApplyChanges();
    check($second->status === 200); check(str_contains($second->GetValue('Status'), 'anderer Managerinstanz'));
    check($m->GetValue('W1Manual') && $second->GetValue('W1Manual'));
    $second->RequestAction('W1Manual', false); check($m->GetValue('W1Manual'));
    closeTo(GetValue($ids[1]['setpoint']), 11000);
    $m->Control(); check($m->status === 102);
});
test('Adapter: konkurrierender Durchlauf wird zusammengefasst', function () {
    [$m] = fixture(); $m->ApplyChanges(); Kernel::$locks['WBM_50'] = true;
    $m->Control(); check($m->timers['Deferred']['interval'] === 200);
    Kernel::$locks['WBM_50'] = false; $m->Control(); check($m->timers['Deferred']['interval'] === 0);
});
test('Adapter: Speicher zuerst anfordern und externe Sperre stoppt Entladung', function () {
    [$m, $ids] = fixture(); Kernel::update($ids['budget'], 6000.0);
    $soc = Kernel::variable(80.0); $target = Kernel::variable(0.0, true); $power = Kernel::variable(0.0); $external = Kernel::variable(true);
    $m->properties = array_replace($m->properties, ['BEnabled' => true, 'BSocVar' => $soc, 'BSetpointVar' => $target, 'BPowerVar' => $power, 'BExternalVar' => $external]);
    $m->ApplyChanges(); closeTo(GetValue($target), 4000); check(GetValue($ids[1]['setpoint']) <= 6000);
    Kernel::update($power, 4000.0, microtime(true)); $m->Control(); closeTo(GetValue($ids[1]['setpoint']), 10000);
    Kernel::update($external, false); $m->Control(); closeTo(GetValue($target), 0); check(GetValue($ids[1]['setpoint']) <= 6000);
});
test('Adapter: fehlgeschlagener Speicherbefehl gibt keine neue Ladeleistung frei', function () {
    [$m, $ids] = fixture(); Kernel::update($ids['budget'], 6000.0);
    $soc = Kernel::variable(80.0); $target = Kernel::variable(0.0, true); $power = Kernel::variable(0.0);
    $m->properties = array_replace($m->properties, ['BEnabled' => true, 'BSocVar' => $soc, 'BSetpointVar' => $target, 'BPowerVar' => $power]);
    $m->ApplyChanges(); Kernel::$failures[$target] = 'Speicher offline'; $m->RequestAction('BatteryEnabled', false);
    check($m->status === 201); closeTo(GetValue($ids[1]['setpoint']), 0);
});
test('Adapter: Reset nur mit explizitem Bestätigungstext', function () {
    [$m] = fixture(); $m->ApplyChanges();
    try { $m->ResetEnergy(''); throw new RuntimeException('Reset war ungeschützt'); } catch (InvalidArgumentException) {}
    $s = runtimeOf($m); $s['totalEnergy'] = 10; setRuntime($m, $s);
    check($m->ResetEnergy('ENERGIE ZURÜCKSETZEN')); closeTo($m->GetValue('TotalEnergy'), 0);
});
