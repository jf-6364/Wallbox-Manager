<?php
declare(strict_types=1);
use WBM\{Billing, Form, Schema, Validation};

test('Ladevorgang: hohes Budget startet ohne explizite Aktion keine Wallbox', function () {
    [$m, $ids] = fixture(2, [], false); $m->ApplyChanges();
    foreach ([12000.0, 22000.0, 7000.0] as $budget) {
        Kernel::update($ids['budget'], $budget); $m->Control();
        foreach ([1, 2] as $id) {
            check(!$m->GetValue('W' . $id . 'Manual'));
            closeTo(GetValue($ids[$id]['setpoint']), 0);
            check(telemetryOf($m)['boxes'][$id]['status'] === 'Nicht gestartet');
        }
    }
    foreach ([1, 2] as $id) foreach (callsTo($ids[$id]['setpoint']) as $call) closeTo($call['value'], 0);
    Kernel::update($ids['budget'], 12000.0);
    foreach ([1, 2] as $id) Kernel::update($ids[$id]['power'], 0.0, microtime(true));
    $m->RequestAction('W1Manual', true);
    check($m->GetValue('W1Manual')); check(!$m->GetValue('W2Manual'));
    closeTo(GetValue($ids[1]['setpoint']), 11000); closeTo(GetValue($ids[2]['setpoint']), 0);
});

test('Ladevorgang: Stopp wirkt sofort trotz Mindestlaufzeit und bleibt bei neuem Budget bestehen', function () {
    [$m, $ids] = fixture(2, ['WallboxMinRun' => 600], false); $m->ApplyChanges();
    foreach ([1, 2] as $id) Kernel::update($ids[$id]['power'], 0.0, microtime(true));
    $m->RequestAction('W1Manual', true); $m->RequestAction('W2Manual', true);
    $m->RequestAction('W1Manual', false);
    closeTo(GetValue($ids[1]['setpoint']), 0); check(!$m->GetValue('W1Manual')); check($m->GetValue('W2Manual'));
    Kernel::update($ids['budget'], 22000.0); $m->RequestAction('DistributionMode', 1); $m->ApplyChanges();
    closeTo(GetValue($ids[1]['setpoint']), 0); check(!$m->GetValue('W1Manual'));
    check(GetValue($ids[2]['setpoint']) > 0);
});

test('Ladevorgang: PV-Wechsel, Nullbudget und Rückkehr erhalten Freigabe und Abrechnungs-ID', function () {
    [$m, $ids] = fixture(2, ['BillingEnabled' => true], false);
    $mode = Kernel::variable(false); $pv = Kernel::variable(7000.0);
    $m->properties = array_replace($m->properties, ['PVModeVar' => $mode, 'PVBudgetVar' => $pv, 'BillingPVVar' => $mode]);
    $m->ApplyChanges(); foreach ([1, 2] as $id) Kernel::update($ids[$id]['power'], 0.0, microtime(true));
    $m->RequestAction('W1Manual', true); $session = runtimeOf($m)['billing'][1]['active']['id'];
    foreach ([[true, 7000.0], [true, 0.0], [true, 7000.0], [false, 7000.0]] as [$active, $budget]) {
        Kernel::update($mode, $active); Kernel::update($pv, $budget); $m->Control();
        check($m->GetValue('W1Manual')); check(!$m->GetValue('W2Manual'));
        check(runtimeOf($m)['billing'][1]['active']['id'] === $session);
        check(Kernel::$media === []);
        closeTo(GetValue($ids[1]['setpoint']), $active ? $budget : 11000);
        closeTo(GetValue($ids[2]['setpoint']), 0);
    }
    $m->RequestAction('W1Manual', false); check(runtimeOf($m)['billing'][1]['active'] === null);
});

test('Ladevorgang: Startzustand überlebt ApplyChanges und Neustart, andere Instanzen bleiben gestoppt', function () {
    [$m, $ids] = fixture(1, ['BillingEnabled' => true], false); $m->ApplyChanges();
    Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->RequestAction('W1Manual', true);
    $session = runtimeOf($m)['billing'][1]['active']['id'];
    $m->ApplyChanges(); $m->buffers = []; $m->Create(); $m->ApplyChanges();
    Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->Control();
    check($m->GetValue('W1Manual')); check(runtimeOf($m)['billing'][1]['active']['id'] === $session);
    check(GetValue($ids[1]['setpoint']) > 0);
    $second = new WallboxManager(60); $second->Create(); check(!$second->GetValue('W1Manual'));
    $m->RequestAction('W1Manual', false); $m->Create(); $m->ApplyChanges(); check(!$m->GetValue('W1Manual'));
});

test('Ladevorgang: Update migriert frühere automatische Freigaben einmalig ohne Datenverlust', function () {
    [$m, $ids] = billingFixture(); seedCompletedBill($m); $m->Control(); $pdf = $m->GetBillingPDF(1);
    $s = runtimeOf($m); $s['totalEnergy'] = 7.0; $s['energy'][1]['energy'] = 7.0; setRuntime($m, $s);
    $m->SetValue('W1Manual', true); unset($m->attributes['SessionControl']);
    $m->Create(); check(!$m->GetValue('W1Manual')); $m->ApplyChanges();
    closeTo(GetValue($ids[1]['setpoint']), 0); closeTo($m->GetValue('TotalEnergy'), 7);
    check($m->GetBillingPDF(1) === $pdf);
    $m->RequestAction('W1Manual', true); $m->Create(); check($m->GetValue('W1Manual'));
});

test('Ladevorgang: deaktivierte Plätze und falsch typisierte Startaktionen werden abgelehnt', function () {
    [$m] = fixture(1, [], false); $m->ApplyChanges(); Kernel::$calls = [];
    foreach ([['W2Manual', true], ['W1Manual', 1], ['W5Manual', true]] as [$ident, $value]) {
        $rejected = false;
        try { $m->RequestAction($ident, $value); } catch (InvalidArgumentException) { $rejected = true; }
        check($rejected); check(!$m->GetValue('W1Manual')); check(!$m->GetValue('W2Manual'));
    }
    check(Kernel::$calls === []);
});

test('Ladevorgang: Mennekes bleibt vor Start und nach Stopp beim individuellen 1-A-Stoppwert', function () {
    [$m, $ids] = fixture(1, ['W1SetpointUnit' => 'A', 'W1Stop' => 1.0, 'W1Step' => 0.5,
        'W1MinA' => 6.0, 'W1MaxA' => 16.0, 'W1MinKW' => 4.14, 'W1MaxKW' => 11.04], false);
    $m->ApplyChanges(); closeTo(GetValue($ids[1]['setpoint']), 1);
    Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->RequestAction('W1Manual', true);
    closeTo(GetValue($ids[1]['setpoint']), 16); $m->RequestAction('W1Manual', false);
    closeTo(GetValue($ids[1]['setpoint']), 1);
});

test('Ladevorgang: langer PV-Ausfall beendet keine Rechnung und Tarifwechsel bleiben im selben Vorgang', function () {
    $c = billingConfig(); $s = [];
    billTick($s, $c, 0); billTick($s, $c, 1000, 0.0); $session = $s['active']['id'];
    billTick($s, $c, 1000, 0.0, true);
    foreach ([2000, 3000, 4000, 5000] as $at) billTick($s, $c, $at, 0.0, true);
    check($s['active']['id'] === $session); check($s['last'] === null); check($s['pending'] === []);
    billTick($s, $c, 5000, 3600.0, true); billTick($s, $c, 6000, 0.0, true);
    billTick($s, $c, 6000, 0.0, true, sessionActive: false);
    closeTo($s['last']['normalEnergy'], 1); closeTo($s['last']['pvEnergy'], 1); closeTo($s['last']['totalCost'], .40);
    closeTo($s['last']['ended'], 6000); check($s['last']['id'] === $session); check(count($s['pending']) === 1);
});

test('Ladevorgang: Energie vor Start und nach Stopp zählt nicht in die nächste Rechnung', function () {
    $c = billingConfig(); $s = [];
    billTick($s, $c, 0, pv: true, sessionActive: false); billTick($s, $c, 1000, pv: true, sessionActive: false);
    check(Billing::summary($s)['mode'] === 'pv');
    check($s['active'] === null); closeTo($s['totals']['normalEnergy'], 0);
    billTick($s, $c, 1000); billTick($s, $c, 2000, sessionActive: false);
    $first = $s['last']['id']; closeTo($s['last']['normalEnergy'], 1);
    billTick($s, $c, 3000, sessionActive: false); billTick($s, $c, 4000, sessionActive: false);
    check($s['active'] === null); check($s['totals']['sessions'] === 1);
    billTick($s, $c, 5000); closeTo(Billing::summary($s)['active']['normalEnergy'], 0);
    billTick($s, $c, 6000, sessionActive: false);
    check($s['last']['id'] !== $first); closeTo($s['last']['normalEnergy'], 1); closeTo($s['totals']['normalEnergy'], 2);
});

test('Ladevorgang: nur Stopp erzeugt ein PDF, wiederholte Aktionen erzeugen keine Duplikate', function () {
    [$m, $ids] = fixture(1, ['BillingEnabled' => true, 'BillingNormalPrice' => .30, 'EnergyMaxGap' => 3600], false);
    $m->ApplyChanges(); Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->RequestAction('W1Manual', true);
    $s = runtimeOf($m); $session = $s['billing'][1]['active']['id'];
    $now = microtime(true); $c = Schema::unpack($m->properties)['boxes'][1];
    $s['billing'][1]['active']['started'] = $now - 1000;
    billTick($s['billing'][1], $c, $now - 1000); setRuntime($m, $s);
    Kernel::update($ids[1]['power'], 3600.0, $now); $m->Control();
    check(Kernel::$media === []); $m->RequestAction('W1Manual', true);
    check(runtimeOf($m)['billing'][1]['active']['id'] === $session);
    $m->RequestAction('W1Manual', false); check(count(Kernel::$media) === 1);
    $s = runtimeOf($m); closeTo($s['billing'][1]['last']['totalEnergy'], 1.0, .001);
    check($s['billing'][1]['active'] === null); check($m->GetBillingPDF(1) !== '');
    $m->RequestAction('W1Manual', false); $m->Control(); check(count(Kernel::$media) === 1);
    Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->RequestAction('W1Manual', true);
    check(runtimeOf($m)['billing'][1]['active']['id'] !== $session);
    closeTo(Billing::summary(runtimeOf($m)['billing'][1])['active']['totalEnergy'], 0);
});

test('Ladevorgang: Formular entfernt den automatischen Abschluss und ignoriert alte Pausenwerte', function () {
    $flat = Schema::defaults(); $flat['BillingEnabled'] = true; $flat['BillingIdleSeconds'] = 0;
    check(Validation::properties($flat) === []);
    $form = json_encode(Form::build($flat, [], ''), JSON_UNESCAPED_UNICODE);
    check(!str_contains($form, 'BillingIdleSeconds')); check(str_contains($form, 'Ladepausen und Moduswechsel'));
});


test('Ladevorgang: externe Sperre unterbricht Leistung und erhält den manuellen Vorgang', function () {
    [$m, $ids] = fixture(1, ['BillingEnabled' => true], false); $external = Kernel::variable(true);
    $m->properties['W1ExternalVar'] = $external; $m->ApplyChanges();
    Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->RequestAction('W1Manual', true);
    $session = runtimeOf($m)['billing'][1]['active']['id'];
    Kernel::update($external, false); $m->Control(); closeTo(GetValue($ids[1]['setpoint']), 0);
    check($m->GetValue('W1Manual')); check(runtimeOf($m)['billing'][1]['active']['id'] === $session);
    Kernel::update($external, true); $m->Control(); check(GetValue($ids[1]['setpoint']) > 0);
    check(runtimeOf($m)['billing'][1]['active']['id'] === $session); check(Kernel::$media === []);
});

test('Ladevorgang: Migration schließt eine alte aktive Abrechnung ab und erhält ihre Energie', function () {
    [$m, $ids] = billingFixture(); $now = microtime(true); $s = runtimeOf($m);
    $c = Schema::unpack($m->properties)['boxes'][1]; $s['billing'][1] = [];
    billTick($s['billing'][1], $c, $now - 1000); billTick($s['billing'][1], $c, $now, 0.0);
    $session = $s['billing'][1]['active']['id']; setRuntime($m, $s);
    unset($m->attributes['SessionControl']); $m->Create(); $m->ApplyChanges();
    $s = runtimeOf($m); check(!$m->GetValue('W1Manual')); check($s['billing'][1]['active'] === null);
    check($s['billing'][1]['last']['id'] === $session); closeTo($s['billing'][1]['last']['totalEnergy'], 1);
    check(count(Kernel::$media) === 1); closeTo(GetValue($ids[1]['setpoint']), 0);
});
