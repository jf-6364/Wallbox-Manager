<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/bootstrap.php';

use WBM\{Schema, Validation, Form, Setpoint, Allocator, Timing, PhaseSwitch, Battery, Energy, Engine};

$tests = [];
function test(string $name, callable $fn): void { global $tests; $tests[$name] = $fn; }
function check(bool $value, string $message = 'Bedingung verletzt'): void { if (!$value) throw new RuntimeException($message); }
function closeTo(float $actual, float $expected, float $tol = 1e-6): void { check(abs($actual - $expected) <= $tol, "$actual != $expected"); }
function box(array $overrides = []): array {
    return array_replace(Schema::unpack(Schema::defaults())['boxes'][1],
        ['Enabled' => true, 'SetpointUnit' => 'W', 'MinA' => 1.0, 'MaxA' => 100.0, 'MinKW' => 1.0, 'MaxKW' => 11.0, 'Step' => 1.0, 'StartDelay' => 0, 'MinRun' => 0, 'StopDelay' => 0, 'MinPause' => 0], $overrides);
}
function item(int $id, float $min = 1000, float $max = 11000, int $priority = 1, float $q = 1): array {
    return ['id' => $id, 'min' => $min, 'max' => $max, 'quantum' => $q, 'valid' => true, 'running' => false, 'protected' => false, 'priority' => $priority, 'hysteresis' => 0, 'lastServed' => 0, 'started' => 0];
}
function engineConfig(int $count = 2): array {
    $c = Schema::unpack(Schema::defaults());
    $c['global']['Hysteresis'] = 0.0;
    for ($i = 1; $i <= $count; $i++) $c['boxes'][$i] = box(['Name' => 'WB ' . $i]);
    return $c;
}
function snapshot(float $budget = 12000, int $count = 2): array {
    $in = ['manager' => true, 'mode' => 0, 'budget' => $budget, 'battery' => ['soc' => 80.0, 'manual' => true, 'external' => true]];
    for ($i = 1; $i <= $count; $i++) $in['boxes'][$i] = ['manual' => true, 'external' => true, 'demand' => true, 'power' => 0.0, 'powerUpdated' => 0.0, 'priority' => $i, 'phases' => 3];
    return $in;
}
test('Gleichmäßig: zwei Wallboxen', function () {
    $p = Allocator::distribute(12000, [1 => item(1), 2 => item(2)], 0, 0, 0);
    closeTo($p[1], 6000); closeTo($p[2], 6000);
});
test('Gleichmäßig: vier Wallboxen', function () {
    $p = Allocator::distribute(20000, [1 => item(1), 2 => item(2), 3 => item(3), 4 => item(4)], 0, 0, 0);
    foreach ($p as $v) closeTo($v, 5000);
});
test('Priorität und gleiche Prioritäten', function () {
    $p = Allocator::distribute(16000, [1 => item(1, 1000, 10000, 1), 2 => item(2, 1000, 10000, 2), 3 => item(3, 1000, 10000, 2)], 1, 0, 0);
    closeTo($p[1], 10000); closeTo($p[2], 3000); closeTo($p[3], 3000);
});
test('Sättigung und Restleistung', function () {
    $p = Allocator::distribute(12000, [1 => item(1, 1000, 3000), 2 => item(2)], 0, 0, 0);
    closeTo($p[1], 3000); closeTo($p[2], 9000);
});
test('Zulässige Teilmenge statt Unterminimum', function () {
    $p = Allocator::distribute(6000, [1 => item(1, 4000), 2 => item(2, 4000)], 0, 0, 0);
    closeTo($p[1], 6000); closeTo($p[2], 0);
});
test('Faire Rotation berücksichtigt laufende und wartende Geräte', function () {
    $a = item(1, 4000); $a['running'] = true; $a['started'] = 0; $a['lastServed'] = 1;
    $b = item(2, 4000); $b['lastServed'] = 0;
    $p = Allocator::distribute(5000, [1 => $a, 2 => $b], 0, 5, 10); closeTo($p[1], 5000);
    $p = Allocator::distribute(5000, [1 => $a, 2 => $b], 0, 11, 10); closeTo($p[2], 5000);
    $a['protected'] = true;
    $p = Allocator::distribute(5000, [1 => $a, 2 => $b], 0, 11, 10); closeTo($p[1], 5000);
});
test('Phasen, Ampere und 4,2 kW bei ganzzahligen A', function () {
    $c = box(['SetpointUnit' => 'A', 'MinA' => 6.0, 'MaxA' => 16.0, 'MinKW' => 4.2]);
    $l = Setpoint::limits($c, 3);
    closeTo($l['min'], 4830); closeTo(Setpoint::command($l['min'], $c, 3), 7);
    $c['MinKW'] = 1.38;
    closeTo(Setpoint::limits($c, 1)['min'], 1380);
    closeTo(Setpoint::limits($c, 3)['min'], 4140);
});
test('W/kW/A-Raster überschreitet kein Budget', function () {
    foreach (['A' => 1.0, 'W' => 100.0, 'kW' => 0.1] as $unit => $step) {
        $c = box(['SetpointUnit' => $unit, 'Step' => $step, 'MinA' => 6.0]);
        $l = Setpoint::limits($c, 3);
        $p = Setpoint::floorPower(5000, $l);
        check($p <= 5000 && $p >= $l['min']);
        closeTo(Setpoint::command($p, $c, 3) / $step, round(Setpoint::command($p, $c, 3) / $step));
    }
});
test('Kein zulässiger Rasterwert', function () {
    $c = box(['SetpointUnit' => 'A', 'Step' => 10.0, 'MinA' => 6.0, 'MaxA' => 9.0]);
    check(!Setpoint::limits($c, 3)['valid']); closeTo(Setpoint::floorPower(9999, Setpoint::limits($c, 3)), 0);
});
test('1 A ist Ausschaltbefehl und intern 0 W', function () {
    $c = box(['SetpointUnit' => 'A', 'Stop' => 1.0, 'MinA' => 6.0]);
    closeTo(Setpoint::command(0, $c, 3), 1);
    check(Setpoint::command(Setpoint::limits($c, 3)['min'], $c, 3) >= 6);
});
test('Einschaltverzögerung benötigt kontinuierliches Budget', function () {
    $c = box(['StartDelay' => 10]); $s = [];
    check(!Timing::ready($c, $s, true, 2000, 1000, 0, 0)['ready']);
    check(!Timing::ready($c, $s, true, 0, 1000, 0, 5)['ready']);
    check(!Timing::ready($c, $s, true, 2000, 1000, 0, 6)['ready']);
    check(Timing::ready($c, $s, true, 2000, 1000, 0, 16)['ready']);
});
test('Hysterese und Mindestpause', function () {
    $c = box(['StartDelay' => 0, 'MinPause' => 20]); $s = ['stopped' => 0];
    check(!Timing::ready($c, $s, true, 1099, 1000, 100, 21)['ready']);
    $s = ['stopped' => 0];
    check(!Timing::ready($c, $s, true, 1200, 1000, 100, 10)['ready']);
    check(Timing::ready($c, $s, true, 1200, 1000, 100, 20)['ready']);
});
test('Nachlauf und Mindestlaufzeit', function () {
    $c = box(['MinRun' => 30, 'StopDelay' => 10]); $s = ['power' => 2000, 'started' => 0];
    check(Timing::ready($c, $s, true, 0, 1000, 0, 20)['protected']);
    check(!Timing::ready($c, $s, true, 0, 1000, 0, 31)['protected']);
});
test('Externe Sperre übersteuert Mindestlaufzeit', function () {
    $cfg = engineConfig(1); $cfg['boxes'][1]['MinRun'] = 600;
    $in = snapshot(10000, 1); $in['boxes'][1]['external'] = false;
    $s = ['boxes' => [1 => ['power' => 6000, 'started' => 0]]];
    $p = Engine::plan($cfg, $in, $s, 1);
    closeTo($p['assigned'], 0); check($p['boxes'][1]['status'] === 'Gesperrt');
});
test('Striktes Budget stoppt trotz Mindestlaufzeit', function () {
    $cfg = engineConfig(1); $cfg['boxes'][1]['MinRun'] = 600;
    $s = ['boxes' => [1 => ['power' => 2000, 'started' => 0]]];
    $p = Engine::plan($cfg, snapshot(500, 1), $s, 1); closeTo($p['assigned'], 0);
});
test('Begrenzte Überschreitung, Timeout und harter Grenzwert', function () {
    $cfg = engineConfig(1);
    $cfg['boxes'][1]['MinRun'] = 600;
    $cfg['global']['StrictBudget'] = false; $cfg['global']['OverrunW'] = 500; $cfg['global']['OverrunSeconds'] = 10;
    $s = ['boxes' => [1 => ['power' => 2000, 'started' => 0]]];
    $p = Engine::plan($cfg, snapshot(500, 1), $s, 1); closeTo($p['assigned'], 1000); closeTo($p['overrun'], 500);
    $p = Engine::plan($cfg, snapshot(500, 1), $s, 12); closeTo($p['assigned'], 0);
    $p = Engine::plan($cfg, snapshot(500, 1), $s, 13); closeTo($p['assigned'], 0);
    $cfg['global']['HardLimitW'] = 800;
    $s = ['boxes' => [1 => ['power' => 2000, 'started' => 0]]];
    $p = Engine::plan($cfg, snapshot(500, 1), $s, 1); closeTo($p['assigned'], 0);
});
test('Phasenmix verteilt W statt gleiche Ampere', function () {
    $cfg = engineConfig(2);
    $cfg['boxes'][1] = box(['PhaseMode' => '1', 'SetpointUnit' => 'A', 'MinA' => 6.0, 'MaxA' => 16.0]);
    $cfg['boxes'][2] = box(['PhaseMode' => '3', 'SetpointUnit' => 'A', 'MinA' => 6.0, 'MaxA' => 16.0]);
    $s = []; $p = Engine::plan($cfg, snapshot(10000), $s, 0);
    check($p['assigned'] <= 10000); closeTo($p['boxes'][1]['power'], 3680);
    check($p['boxes'][2]['power'] >= 5520);
});
test('Speicherfreigaben, SoC-Reserve und Hysterese', function () {
    $b = array_replace(Schema::unpack(Schema::defaults())['battery'], ['Enabled' => true, 'PowerVar' => 10]);
    $s = []; $in = ['soc' => 20.0, 'power' => 0.0, 'manual' => true, 'external' => true];
    closeTo(Battery::available($b, $in, $s, true, 0, 5)['theoretical'], 0);
    $in['soc'] = 24; closeTo(Battery::available($b, $in, $s, true, 1, 5)['theoretical'], 0);
    $in['soc'] = 25; closeTo(Battery::available($b, $in, $s, true, 2, 5)['theoretical'], 4000);
    $in['external'] = false; closeTo(Battery::available($b, $in, $s, true, 3, 5)['confirmed'], 0);
    $in['external'] = true; $in['soc'] = null; closeTo(Battery::available($b, $in, $s, true, 4, 5)['theoretical'], 0);
});
test('Positive und negative Entladebefehle', function () {
    $b = Schema::unpack(Schema::defaults())['battery'];
    closeTo(Battery::command(2000, $b), 2000);
    $b['Sign'] = -1; $b['SetpointUnit'] = 'kW'; closeTo(Battery::command(2000, $b), -2);
    $b['Stop'] = 0.1; closeTo(Battery::command(0, $b), 0.1);
});
test('Keine doppelte Batterieanrechnung und nur nutzbare Entladung', function () {
    $cfg = engineConfig(1); $cfg['battery']['Enabled'] = true; $cfg['battery']['PowerVar'] = 10; $cfg['boxes'][1]['MaxKW'] = 8.0;
    $in = snapshot(6000, 1); $in['battery']['power'] = 4000;
    $s = ['battery' => ['requested' => 4000, 'requestAt' => 0]];
    for ($i = 1; $i <= 3; $i++) {
        $p = Engine::plan($cfg, $in, $s, $i);
        closeTo($p['normalBudget'], 10000); closeTo($p['assigned'], 8000); closeTo($p['battery']['requested'], 2000);
    }
});
test('Nicht bestätigte Batterie erhöht Wallboxen noch nicht', function () {
    $cfg = engineConfig(1); $cfg['battery']['Enabled'] = true; $cfg['battery']['PowerVar'] = 10;
    $in = snapshot(6000, 1); $in['battery']['power'] = 0.0;
    $s = [];
    $p = Engine::plan($cfg, $in, $s, 0); closeTo($p['assigned'], 6000); closeTo($p['battery']['requested'], 4000);
});
test('Fehlende Speicherleistung führt zum Timeout', function () {
    $b = array_replace(Schema::unpack(Schema::defaults())['battery'], ['Enabled' => true, 'PowerVar' => 10, 'ResponseTimeout' => 10]);
    $s = ['requested' => 4000, 'requestAt' => 0]; $in = ['soc' => 80, 'power' => 1000, 'manual' => true, 'external' => true];
    closeTo(Battery::available($b, $in, $s, true, 0, 5)['confirmed'], 1000);
    $p = Battery::available($b, $in, $s, true, 11, 5); closeTo($p['confirmed'], 0); check($s['fault'] !== '');
});
test('Konservatives Anlaufen ohne Speicherrückmeldung', function () {
    $b = array_replace(Schema::unpack(Schema::defaults())['battery'], ['Enabled' => true]);
    $s = ['requested' => 2000, 'requestAt' => 0]; $in = ['soc' => 80, 'manual' => true, 'external' => true];
    $p = Battery::available($b, $in, $s, true, 9, 5); closeTo($p['theoretical'], 2000); closeTo($p['confirmed'], 0);
    closeTo(Battery::available($b, $in, $s, true, 10, 5)['confirmed'], 2000);
});
test('Phasenwechsel wartet auf Stillstand, Vor-/Nachlauf und Rückmeldung', function () {
    $c = box(['PhaseMode' => 'dynamic', 'PhaseDelay' => 0, 'PhaseDwell' => 0, 'PhaseZeroSeconds' => 1, 'PhaseBefore' => 2, 'PhaseAfter' => 3]); $s = [];
    $p = PhaseSwitch::step($c, $s, 3, 2000, 0, 2000, true, 0); check($p['blocked'] && $p['command'] === null);
    PhaseSwitch::step($c, $s, 3, 0, 1, 2000, true, 1);
    PhaseSwitch::step($c, $s, 3, 0, 2, 2000, true, 2);
    $p = PhaseSwitch::step($c, $s, 3, 0, 3, 2000, true, 3); check($p['command'] === null);
    $p = PhaseSwitch::step($c, $s, 3, 0, 4, 2000, true, 4); closeTo($p['command'], 1);
    PhaseSwitch::commandSent($s, 4);
    $p = PhaseSwitch::step($c, $s, 1, 0, 5, 2000, true, 5); check($p['blocked']);
    $p = PhaseSwitch::step($c, $s, 1, 0, 8, 2000, true, 8); check(!$p['blocked']); check($s['phases'] === 1);
});
test('Phasenwechsel-Timeout, Fremdwechsel und Lastfehler stoppen', function () {
    $c = box(['PhaseDelay' => 0, 'PhaseDwell' => 0, 'PhaseTimeout' => 5]); $s = [];
    PhaseSwitch::step($c, $s, 3, 2000, 0, 2000, true, 0);
    check(PhaseSwitch::step($c, $s, 3, 2000, 6, 2000, true, 6)['fault'] !== '');
    $s = ['stage' => 'feedback', 'phases' => 3, 'target' => 1, 'sent' => 0];
    check(PhaseSwitch::step($c, $s, 3, 0, 6, 2000, true, 6)['fault'] !== '');
    $s = ['stage' => 'idle', 'phases' => 3];
    check(PhaseSwitch::step($c, $s, 1, 0, 1, 2000, true, 1)['fault'] !== '');
});
test('Leistungsintegration ohne doppelte Timer-/Ereignisabschnitte', function () {
    $s = [];
    Energy::power($s, 'power:1', 1000, 0, 0, 120);
    closeTo(Energy::power($s, 'power:1', 2000, 10, 10, 120)['delta'], 10000 / 3600000);
    closeTo(Energy::power($s, 'power:1', 2000, 10, 20, 120)['delta'], 20000 / 3600000);
    closeTo(Energy::power($s, 'power:1', 2000, 10, 20, 120)['delta'], 0);
    closeTo($s['energy'], 30000 / 3600000);
});
test('Integration verwirft Ausfälle und ungültige Messdaten', function () {
    $s = []; Energy::power($s, 'x', 1000, 0, 0, 120);
    $p = Energy::power($s, 'x', 1000, 500, 500, 120); closeTo($p['delta'], 0); check($p['gap'] !== '');
    closeTo(Energy::power($s, 'x', null, 510, 510, 120)['delta'], 0);
    closeTo(Energy::power($s, 'x', 1000, 520, 520, 120)['delta'], 0);
});
test('Zählerdifferenzen, Neustart, Reset, Sprung und neue Variable', function () {
    $s = [];
    Energy::meter($s, 'm1', 100, 0, 11000, 2, 0.02);
    closeTo(Energy::meter($s, 'm1', 100.5, 180, 11000, 2, 0.02)['delta'], 0.5);
    $s = json_decode(json_encode($s), true);
    closeTo(Energy::meter($s, 'm1', 100.6, 240, 11000, 2, 0.02)['delta'], 0.1);
    closeTo(Energy::meter($s, 'm1', 0, 241, 11000, 2, 0.02)['delta'], 0);
    closeTo(Energy::meter($s, 'm1', 1000, 242, 11000, 2, 0.02)['delta'], 0);
    closeTo(Energy::meter($s, 'm2', 5000, 243, 11000, 2, 0.02)['delta'], 0);
    closeTo($s['energy'], 0.6);
});
test('Neue Gerätegeneration archiviert Platzenergie', function () {
    $s = ['energy' => 12.5]; Energy::identify($s, 'a'); check(Energy::identify($s, 'b'));
    closeTo($s['energy'], 0); closeTo($s['archive'][0]['energy'], 12.5);
});
test('Validierung: ungültige Typen, fehlende Aktionen, Rückkopplung', function () {
    $flat = Schema::defaults(); $flat['W1Enabled'] = true; $flat['BudgetVar'] = 10; $flat['W1SetpointVar'] = 11; $flat['W1PowerVar'] = 12;
    $inspect = fn($id) => $id ? ['type' => 2, 'action' => $id !== 11, 'locked' => false, 'owner' => 0] : null;
    check(count(Validation::links($flat, $inspect, 50)) === 1);
    $flat['Simulation'] = true; check(Validation::links($flat, $inspect, 50) === []);
    $flat['W1SetpointVar'] = 10; check(count(Validation::links($flat, $inspect, 50)) === 1);
    $flat['BudgetVar'] = 0; check(count(Validation::links($flat, $inspect, 50)) === 1);
});
test('Negative und nichtendliche Budgets erhöhen niemals', function () {
    $c = engineConfig(1); $in = snapshot(-1, 1); $s = []; closeTo(Engine::plan($c, $in, $s, 0)['assigned'], 0);
    $in['budget'] = NAN; $p = Engine::plan($c, $in, $s, 0); closeTo($p['assigned'], 0); check(count($p['errors']) > 0);
});
test('1000 zufällige Verteilungen halten alle Grenzen ein', function () {
    mt_srand(20261012);
    for ($n = 0; $n < 1000; $n++) {
        $items = [];
        for ($id = 1; $id <= 4; $id++) {
            $q = (float)mt_rand(1, 1000); $min = $q * mt_rand(1, 6); $max = $min + $q * mt_rand(0, 20);
            $items[$id] = item($id, $min, $max, mt_rand(1, 3), $q);
            $items[$id]['running'] = (bool)mt_rand(0, 1); $items[$id]['protected'] = (bool)mt_rand(0, 1);
        }
        $budget = (float)mt_rand(0, 50000);
        $p = Allocator::distribute($budget, $items, $n % 2, 1000, 900);
        check(array_sum($p) <= $budget + 1e-6, 'Budget überschritten');
        foreach ($p as $id => $v) {
            check($v >= 0 && ($v === 0.0 || $v >= $items[$id]['min'] - 1e-6) && $v <= $items[$id]['max'] + 1e-6, 'Gerätegrenze verletzt');
            closeTo($v / $items[$id]['quantum'], round($v / $items[$id]['quantum']), 1e-5);
        }
    }
});

require __DIR__ . '/adapter.php';
require __DIR__ . '/regression.php';

$results = [];
foreach ($tests as $name => $fn) {
    try { $fn(); $results[] = ['name' => $name, 'passed' => true]; echo "OK  $name\n"; }
    catch (Throwable $e) { $results[] = ['name' => $name, 'passed' => false, 'error' => $e->getMessage()]; echo "FAIL $name: " . $e->getMessage() . "\n"; }
}
$failed = count(array_filter($results, fn($r) => !$r['passed']));
echo count($results) . " Tests, $failed Fehler\n";
file_put_contents(__DIR__ . '/results.local.json', json_encode(['php' => PHP_VERSION, 'date' => date('c'), 'tests' => $results, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($failed ? 1 : 0);