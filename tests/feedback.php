<?php
declare(strict_types=1);

function feedbackFixture(int $count = 1, array $properties = [], ?callable $configure = null): array
{
    [$m, $ids] = fixture($count, array_replace(['FeedbackTimeout' => 30], $properties));
    if ($configure) $configure($m, $ids);
    $m->ApplyChanges();
    foreach (range(1, $count) as $id) Kernel::update($ids[$id]['power'], 0.0, microtime(true));
    $m->Control();
    return [$m, $ids];
}

function seedLoadTimeout(WallboxManager $m, array $ids, int $id = 1, bool $pending = true, string $fault = 'Timeout: Lastabbau nicht bestätigt'): float
{
    $since = microtime(true) - 90;
    $s = runtimeOf($m);
    $s['boxes'][$id] = array_replace($s['boxes'][$id], ['power' => 0.0, 'stopped' => $since, 'lastCommand' => $since, 'fault' => $fault]);
    if ($pending) $s['boxes'][$id]['reduction'] = ['target' => 0.0, 'since' => $since];
    else unset($s['boxes'][$id]['reduction']);
    $stop = $m->properties['W' . $id . 'Stop'];
    $s['commands'][$ids[$id]['setpoint']] = ['value' => $stop, 'time' => $since];
    Kernel::update($ids[$id]['setpoint'], $stop, $since);
    setRuntime($m, $s);
    return $since;
}

test('Lastabbau: reale Zeitüberschreitung stoppt, späte Nullmeldung hebt den Timeout automatisch auf', function () {
    [$m, $ids] = feedbackFixture(1, ['W1SetpointUnit' => 'A', 'W1Stop' => 1.0, 'W1MinA' => 6.0,
        'W1MaxA' => 16.0, 'W1MinKW' => 4.14, 'W1MaxKW' => 11.04, 'W1Step' => .5]);
    closeTo(GetValue($ids[1]['setpoint']), 16);
    Kernel::update($ids[1]['power'], 11040.0, microtime(true)); $m->RequestAction('W1Manual', false);
    $s = runtimeOf($m); $past = microtime(true) - 31;
    $s['boxes'][1]['reduction']['since'] = $s['boxes'][1]['stopped'] = $s['boxes'][1]['lastCommand'] = $past;
    setRuntime($m, $s); $m->Control();
    check($m->status === 201); closeTo(GetValue($ids[1]['setpoint']), 1);
    check(runtimeOf($m)['boxes'][1]['fault'] === 'Timeout: Lastabbau nicht bestätigt');
    Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->Control();
    check($m->status === 102, $m->GetValue('Status')); check(runtimeOf($m)['boxes'][1]['fault'] === '');
    check(!isset(runtimeOf($m)['boxes'][1]['reduction'])); check(telemetryOf($m)['errors'] === []);
    closeTo(GetValue($ids[1]['setpoint']), 1); check(!$m->GetValue('W1Manual'));
});

test('Lastabbau: auch bereits gespeicherte Timeouts ohne offenen Auftrag erholen sich vor der Verteilung', function () {
    foreach ([false, true] as $pending) {
        [$m, $ids] = feedbackFixture(2); seedLoadTimeout($m, $ids, 1, $pending);
        Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->Control();
        check(runtimeOf($m)['boxes'][1]['fault'] === ''); check(telemetryOf($m)['errors'] === []);
        closeTo(GetValue($ids[1]['setpoint']), 6000); closeTo(GetValue($ids[2]['setpoint']), 6000);
        check(telemetryOf($m)['assigned'] <= 12000); check($m->GetValue('W1Manual'));
    }
});

test('Lastabbau: alte, zukünftige, fehlende und ungültige Nullrückmeldungen quittieren keinen Timeout', function () {
    foreach (['old', 'future', 'missing', 'nan', 'negative', 'stale'] as $case) {
        [$m, $ids] = feedbackFixture(1, ['W1MaxAge' => $case === 'stale' ? 30 : 0]);
        $since = seedLoadTimeout($m, $ids);
        if ($case === 'missing') unset(Kernel::$variables[$ids[1]['power']]);
        else Kernel::update($ids[1]['power'], $case === 'nan' ? NAN : ($case === 'negative' ? -1.0 : 0.0),
            $case === 'old' ? $since - 5 : ($case === 'future' ? microtime(true) + 60 : ($case === 'stale' ? microtime(true) - 60 : microtime(true))));
        $m->Control(); check(runtimeOf($m)['boxes'][1]['fault'] === 'Timeout: Lastabbau nicht bestätigt', $case);
        closeTo(GetValue($ids[1]['setpoint']), 0); check($m->status >= 200, $case);
    }
});

test('Lastabbau: Teilreduzierung nach Timeout verlangt bestätigten Stillstand statt nur den alten Zielwert', function () {
    [$m, $ids] = feedbackFixture(); Kernel::update($ids[1]['power'], 11000.0, microtime(true));
    Kernel::update($ids['budget'], 6000.0); $m->Control();
    $s = runtimeOf($m); $past = microtime(true) - 31;
    $s['boxes'][1]['reduction']['since'] = $s['boxes'][1]['lastCommand'] = $past; setRuntime($m, $s);
    $m->Control(); closeTo(GetValue($ids[1]['setpoint']), 0);
    check(runtimeOf($m)['boxes'][1]['fault'] === 'Timeout: Lastabbau nicht bestätigt');
    closeTo(runtimeOf($m)['boxes'][1]['reduction']['target'], 0);
    Kernel::update($ids[1]['power'], 2500.0, microtime(true)); $m->Control();
    check(runtimeOf($m)['boxes'][1]['fault'] === 'Timeout: Lastabbau nicht bestätigt'); closeTo(GetValue($ids[1]['setpoint']), 0);
    Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->Control();
    check($m->status === 102, $m->GetValue('Status')); closeTo(GetValue($ids[1]['setpoint']), 6000);
});

test('Lastabbau: eine frische, verknüpfte Heartbeat-Meldung bestätigt unveränderte Nullleistung', function () {
    $heartbeat = 0;
    [$m, $ids] = feedbackFixture(1, ['W1MaxAge' => 30], function ($m) use (&$heartbeat) {
        $heartbeat = Kernel::variable(0); $m->properties['W1HeartbeatVar'] = $heartbeat;
    });
    $since = seedLoadTimeout($m, $ids); Kernel::update($ids[1]['power'], 0.0, $since - 10);
    Kernel::update($heartbeat, 0, microtime(true)); $m->Control();
    check(runtimeOf($m)['boxes'][1]['fault'] === ''); check($m->status === 102, $m->GetValue('Status'));
    closeTo(GetValue($ids[1]['setpoint']), 11000);
});

test('Lastabbau: fehlender, alter oder zukünftiger Heartbeat gilt nicht als neue Bestätigung', function () {
    foreach (['old', 'future', 'missing'] as $case) {
        $heartbeat = 0;
        [$m, $ids] = feedbackFixture(1, ['W1MaxAge' => 30], function ($m) use (&$heartbeat) {
            $heartbeat = Kernel::variable(0); $m->properties['W1HeartbeatVar'] = $heartbeat;
        });
        seedLoadTimeout($m, $ids); Kernel::update($ids[1]['power'], 0.0, microtime(true));
        if ($case === 'missing') unset(Kernel::$variables[$heartbeat]);
        else Kernel::update($heartbeat, 0, microtime(true) + ($case === 'future' ? 60 : -60));
        $m->Control(); check(runtimeOf($m)['boxes'][1]['fault'] === 'Timeout: Lastabbau nicht bestätigt', $case);
        closeTo(GetValue($ids[1]['setpoint']), 0);
    }
});

test('Lastabbau: Heartbeat bestätigt auch eine laufende Reduzierung vor Ablauf des Timeouts', function () {
    $heartbeat = 0;
    [$m, $ids] = feedbackFixture(1, ['W1MaxAge' => 30], function ($m) use (&$heartbeat) {
        $heartbeat = Kernel::variable(0); $m->properties['W1HeartbeatVar'] = $heartbeat;
    });
    $since = microtime(true) - 5; $s = runtimeOf($m);
    $s['boxes'][1]['reduction'] = ['target' => 0.0, 'since' => $since];
    $s['boxes'][1]['power'] = 0.0; $s['boxes'][1]['lastCommand'] = $since; setRuntime($m, $s);
    Kernel::update($ids[1]['power'], 0.0, $since - 10); Kernel::update($heartbeat, 0, microtime(true));
    $m->SetValue('W1Manual', false); $m->Control();
    check(!isset(runtimeOf($m)['boxes'][1]['reduction'])); check(runtimeOf($m)['boxes'][1]['fault'] === '');
});

test('Lastabbau: Erholung startet weder abgeschaltete noch extern gesperrte Wallboxen', function () {
    foreach (['manual', 'manager', 'external', 'budget'] as $case) {
        [$m, $ids] = feedbackFixture(); seedLoadTimeout($m, $ids);
        if ($case === 'manual') $m->SetValue('W1Manual', false);
        if ($case === 'manager') $m->SetValue('ManagerEnabled', false);
        if ($case === 'external') { $m->properties['W1ExternalVar'] = Kernel::variable(false); $m->ApplyChanges(); }
        if ($case === 'budget') Kernel::update($ids['budget'], 0.0);
        Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->Control();
        check(runtimeOf($m)['boxes'][1]['fault'] === ''); closeTo(GetValue($ids[1]['setpoint']), 0);
        if ($case === 'manual') check(!$m->GetValue('W1Manual'));
    }
});

test('Lastabbau: Geräteaktions-, Phasen- und Überschreitungsfehler bleiben manuell zu quittieren', function () {
    foreach (['Geräteaktion fehlgeschlagen (Sollwert/Freigabe)', 'Gemessene Ladeleistung überschreitet den bestätigten Sollwert', 'Phasenbefehl fehlgeschlagen'] as $fault) {
        [$m, $ids] = feedbackFixture(); seedLoadTimeout($m, $ids, 1, false, $fault);
        Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->Control();
        check(runtimeOf($m)['boxes'][1]['fault'] === $fault); closeTo(GetValue($ids[1]['setpoint']), 0);
    }
});

test('Lastabbau: Gerätefehlersignal und Phasenfehler verhindern automatische Wiederfreigabe', function () {
    foreach (['device', 'phase'] as $case) {
        [$m, $ids] = feedbackFixture(); seedLoadTimeout($m, $ids);
        if ($case === 'device') { $m->properties['W1ErrorVar'] = Kernel::variable(true); $m->ApplyChanges(); }
        else { $s = runtimeOf($m); $s['boxes'][1]['phase']['fault'] = 'Phasenrückmeldung fehlt'; setRuntime($m, $s); }
        Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->Control();
        check(runtimeOf($m)['boxes'][1]['fault'] === 'Timeout: Lastabbau nicht bestätigt'); closeTo(GetValue($ids[1]['setpoint']), 0);
    }
});

test('Lastabbau: vier Plätze werden unabhängig quittiert, laufende Rechnung und Freigaben bleiben erhalten', function () {
    [$m, $ids] = feedbackFixture(4, ['BillingEnabled' => true]); $session = runtimeOf($m)['billing'][1]['active']['id'];
    seedLoadTimeout($m, $ids, 1); seedLoadTimeout($m, $ids, 3);
    seedLoadTimeout($m, $ids, 2, false, 'Geräteaktion fehlgeschlagen (Sollwert/Freigabe)');
    foreach ([1, 2, 3, 4] as $id) Kernel::update($ids[$id]['power'], 0.0, microtime(true));
    $m->Control(); $s = runtimeOf($m);
    check($s['boxes'][1]['fault'] === ''); check($s['boxes'][3]['fault'] === '');
    check($s['boxes'][2]['fault'] === 'Geräteaktion fehlgeschlagen (Sollwert/Freigabe)');
    check($s['billing'][1]['active']['id'] === $session); check(Kernel::$media === []);
    foreach ([1, 2, 3, 4] as $id) check($m->GetValue('W' . $id . 'Manual'));
    check(telemetryOf($m)['assigned'] <= 12000);
});


test('Lastabbau: ein bestehender Geräteaktionsfehler wird bei ausstehendem Lastabbau nicht zum automatisch quittierbaren Timeout', function () {
    [$m, $ids] = feedbackFixture(); $fault = 'Geräteaktion fehlgeschlagen (Sollwert/Freigabe)';
    seedLoadTimeout($m, $ids, 1, true, $fault); Kernel::update($ids[1]['power'], 2000.0, microtime(true));
    $m->Control(); check(runtimeOf($m)['boxes'][1]['fault'] === $fault);
    Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->Control();
    check(runtimeOf($m)['boxes'][1]['fault'] === $fault); closeTo(GetValue($ids[1]['setpoint']), 0);
});

test('Lastabbau: ein fehlgeschlagener Reduzierungsbefehl erzeugt keine angenommene Reduzierung', function () {
    [$m, $ids] = feedbackFixture(); Kernel::$failures[$ids[1]['setpoint']] = 'false';
    $m->RequestAction('W1Manual', false); $s = runtimeOf($m);
    check(!isset($s['boxes'][1]['reduction'])); check($s['boxes'][1]['fault'] === 'Geräteaktion fehlgeschlagen (Sollwert/Freigabe)');
    unset(Kernel::$failures[$ids[1]['setpoint']]); Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->Control();
    check(runtimeOf($m)['boxes'][1]['fault'] === 'Geräteaktion fehlgeschlagen (Sollwert/Freigabe)');
    closeTo(GetValue($ids[1]['setpoint']), 0);
});
