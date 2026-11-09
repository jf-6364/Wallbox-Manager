<?php
declare(strict_types=1);
use WBM\{Schema, Form, Engine};

test('PV-Überschuss: eigener Bereich mit optionalem Boolsignal und numerischem Budget', function () {
    $flat = Schema::defaults(); $form = Form::build($flat, [], '');
    $panels = array_values(array_filter($form['elements'], fn($item) => ($item['caption'] ?? '') === 'PV-Überschuss'));
    check(count($panels) === 1);
    $fields = array_column($panels[0]['items'], null, 'name');
    check($fields['PVModeVar']['validVariableTypes'] === [0]);
    check($fields['PVBudgetVar']['validVariableTypes'] === [1, 2]);
    $flat['PVBudgetVar'] = 12345; check(!isset(Schema::links($flat)['PVBudgetVar']));
    $flat['PVModeVar'] = 12346; check(isset(Schema::links($flat)['PVBudgetVar']));
});

test('PV-Überschuss: ohne Bool, false, true und zurück wählen jeweils nur das richtige Budget', function () {
    [$m, $ids] = fixture(2); $pv = Kernel::variable(7000.0); $mode = Kernel::variable(false);
    $m->properties['PVBudgetVar'] = $pv; $m->ApplyChanges();
    closeTo(telemetryOf($m)['assigned'], 12000);
    $m->properties['PVModeVar'] = $mode; $m->ApplyChanges();
    foreach ([true, false, true] as $active) {
        Kernel::update($mode, $active, microtime(true));
        foreach ([1, 2] as $id) Kernel::update($ids[$id]['power'], 0.0, microtime(true));
        $m->MessageSink(12345, $mode, VM_UPDATE, [true, $active, false]);
        check($m->timers['Deferred']['interval'] === 200); $m->Control();
        $t = telemetryOf($m); check($m->status === 102, $m->GetValue('Status'));
        closeTo($t['base'], $active ? 7000 : 12000); closeTo($t['assigned'], $active ? 7000 : 12000);
        check($t['pvActive'] === $active); check($t['budgetSource'] === ($active ? 'pv' : 'normal'));
        check($t['budgetVariable'] === ($active ? $pv : $ids['budget']));
    }
    $m->properties['PVModeVar'] = 0; $m->ApplyChanges(); closeTo(telemetryOf($m)['assigned'], 12000);
    check(!in_array($mode, $m->GetReferenceList(), true)); check(!in_array($pv, $m->GetReferenceList(), true));
    check(callsTo($pv) === [] && callsTo($mode) === [] && callsTo($ids['budget']) === []);
});

test('PV-Überschuss: W/kW und ungültige inaktive Budgetquelle beeinflussen die gewählte Quelle nicht', function () {
    foreach (['W' => 7000, 'kW' => 7.0] as $unit => $value) {
        [$m, $ids] = fixture(1); $mode = Kernel::variable(true); $pv = Kernel::variable($value);
        Kernel::update($ids['budget'], NAN);
        $m->properties = array_replace($m->properties, ['PVModeVar' => $mode, 'PVBudgetVar' => $pv, 'PVBudgetUnit' => $unit]);
        $m->ApplyChanges(); check($m->status === 102, $m->GetValue('Status')); closeTo(telemetryOf($m)['assigned'], 7000);
        Kernel::update($ids['budget'], 12000.0); Kernel::update($pv, NAN); Kernel::update($mode, false);
        $m->Control(); check($m->status === 102, $m->GetValue('Status')); closeTo(telemetryOf($m)['assigned'], 11000);
    }
});

test('PV-Überschuss: fehlendes, veraltetes oder nichtendliches PV-Budget stoppt ohne Normalbudget-Fallback', function () {
    foreach (['unlinked', 'deleted', 'stale', 'nan', 'zero', 'negative'] as $failure) {
        [$m, $ids] = fixture(1, ['W1SetpointUnit' => 'A', 'W1Stop' => 1.0, 'W1MinA' => 6.0, 'W1MaxA' => 16.0,
            'W1MinKW' => 4.14, 'W1MaxKW' => 11.04, 'W1Step' => 0.5]);
        $mode = Kernel::variable(true); $pv = Kernel::variable(7000.0);
        $m->properties = array_replace($m->properties, ['PVModeVar' => $mode, 'PVBudgetVar' => $pv, 'PVBudgetMaxAge' => 30]);
        if ($failure === 'unlinked') $m->properties['PVBudgetVar'] = 0;
        if ($failure === 'deleted') unset(Kernel::$variables[$pv]);
        if ($failure === 'stale') Kernel::update($pv, 7000.0, microtime(true) - 60);
        if ($failure === 'nan') Kernel::update($pv, NAN);
        if ($failure === 'zero') Kernel::update($pv, 0.0);
        if ($failure === 'negative') Kernel::update($pv, -100.0);
        $m->ApplyChanges(); closeTo(telemetryOf($m)['assigned'], 0); closeTo(GetValue($ids[1]['setpoint']), 1);
        check(telemetryOf($m)['pvActive']);
        check((telemetryOf($m)['errors'] === []) === in_array($failure, ['zero', 'negative'], true));
        Kernel::update($mode, false); Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->Control();
        check($m->status === 102, $m->GetValue('Status')); closeTo(GetValue($ids[1]['setpoint']), 16);
    }
});

test('PV-Überschuss: ein ungültiges verknüpftes Boolsignal wird nicht als false behandelt', function () {
    foreach (['deleted', 'type', 'value'] as $failure) {
        [$m, $ids] = fixture(); $mode = Kernel::variable(true); $pv = Kernel::variable(7000.0);
        $m->properties = array_replace($m->properties, ['PVModeVar' => $mode, 'PVBudgetVar' => $pv]);
        if ($failure === 'deleted') unset(Kernel::$variables[$mode]);
        if ($failure === 'type') Kernel::$variables[$mode]['VariableType'] = 1;
        if ($failure === 'value') Kernel::update($mode, 'true');
        $m->ApplyChanges(); closeTo(telemetryOf($m)['assigned'], 0);
        check(telemetryOf($m)['budgetSource'] === 'invalid'); check(telemetryOf($m)['errors'] !== []);
    }
});

test('PV-Überschuss: Speicher und erlaubte Überschreitung erhöhen das PV-Budget nicht', function () {
    foreach ([false, true] as $battery) {
        $cfg = engineConfig(2); $cfg['global']['StrictBudget'] = false; $cfg['global']['OverrunW'] = 10000.0;
        $cfg['battery']['Enabled'] = $battery;
        foreach ($cfg['boxes'] as &$box) { $box['MinKW'] = 4.0; $box['MinRun'] = 600; } unset($box);
        $in = snapshot(7000.0); $in['battery']['power'] = 4000.0;
        $state = ['boxes' => [1 => ['power' => 6000.0, 'started' => 0], 2 => ['power' => 6000.0, 'started' => 0]],
            'battery' => ['requested' => 4000.0, 'requestAt' => -100]];
        $normalState = $state; $normal = Engine::plan($cfg, $in, $normalState, 100);
        check($normal['assigned'] > 7000);
        $in['pvActive'] = true; $pv = Engine::plan($cfg, $in, $state, 100);
        check($pv['assigned'] <= 7000); closeTo($pv['overrun'], 0); closeTo($pv['battery']['requested'], 0);
        closeTo($pv['battery']['confirmed'], 0); check($pv['budgetSource'] === 'pv');
    }
});

test('PV-Überschuss: Regelung und Tarifsignal bleiben getrennt und können dasselbe Boolsignal verwenden', function () {
    [$m, $ids] = fixture(1, ['BillingEnabled' => true]); $mode = Kernel::variable(true); $pv = Kernel::variable(7000.0);
    $m->properties = array_replace($m->properties, ['PVModeVar' => $mode, 'PVBudgetVar' => $pv]); $m->ApplyChanges();
    Kernel::update($ids[1]['power'], 3600.0, microtime(true)); $m->Control();
    check(telemetryOf($m)['pvActive']); check(telemetryOf($m)['boxes'][1]['billing']['mode'] === 'normal');
    $m->properties['BillingPVVar'] = $mode; $m->ApplyChanges();
    check(telemetryOf($m)['boxes'][1]['billing']['mode'] === 'pv');
    Kernel::update($mode, false); $m->MessageSink(12345, $mode, VM_UPDATE, [true, false, true]); $m->Control();
    check(!telemetryOf($m)['pvActive']); check(telemetryOf($m)['boxes'][1]['billing']['mode'] === 'normal');
});

test('PV-Überschuss: 7000 W regeln dreiphasige Mennekes-Sollwerte in halben Ampere', function () {
    $properties = [];
    foreach ([1, 2] as $id) foreach (['SetpointUnit' => 'A', 'MinA' => 6.0, 'MaxA' => 16.0, 'MinKW' => 4.14,
        'MaxKW' => 11.04, 'Step' => 0.5, 'Stop' => 1.0] as $key => $value) $properties['W' . $id . $key] = $value;
    [$m, $ids] = fixture(2, $properties);
    $m->properties['PVModeVar'] = Kernel::variable(true); $m->properties['PVBudgetVar'] = Kernel::variable(7000.0);
    $m->ApplyChanges();
    foreach ([1, 2] as $id) Kernel::update($ids[$id]['power'], 0.0, microtime(true)); $m->Control();
    check($m->status === 102, $m->GetValue('Status')); closeTo(telemetryOf($m)['assigned'], 6900);
    $values = [GetValue($ids[1]['setpoint']), GetValue($ids[2]['setpoint'])]; sort($values);
    closeTo($values[0], 1.0); closeTo($values[1], 10.0);
});

test('PV-Überschuss: Umschalten stoppt zusätzliche Speicherentladung und begrenzt die Geräteausgabe', function () {
    [$m, $ids] = fixture(1, ['StrictBudget' => false]); Kernel::update($ids['budget'], 6000.0);
    $mode = Kernel::variable(false); $pv = Kernel::variable(7000.0); $soc = Kernel::variable(80.0); $target = Kernel::variable(0.0, true);
    $m->properties = array_replace($m->properties, ['PVModeVar' => $mode, 'PVBudgetVar' => $pv,
        'BEnabled' => true, 'BSocVar' => $soc, 'BSetpointVar' => $target]);
    $m->ApplyChanges(); $state = runtimeOf($m); $state['battery']['requestAt'] = microtime(true) - 60; setRuntime($m, $state);
    $m->Control(); check(GetValue($target) > 0); check(telemetryOf($m)['assigned'] > 6000);
    Kernel::update($mode, true); Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->Control();
    closeTo(GetValue($target), 0); closeTo(runtimeOf($m)['battery']['requested'], 0);
    closeTo(telemetryOf($m)['base'], 7000); closeTo(telemetryOf($m)['assigned'], 7000); check(telemetryOf($m)['strict']);
    Kernel::update($mode, false); Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->Control();
    check(GetValue($target) > 0); closeTo(telemetryOf($m)['base'], 6000); check(!telemetryOf($m)['strict']);
});
