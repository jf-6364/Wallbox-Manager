<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/bootstrap.php';
use WBM\{Schema, Engine, Setpoint};
$cfg = Schema::unpack(Schema::defaults());
$cfg['global']['Hysteresis'] = 0.0;
for ($i = 1; $i <= 2; $i++) $cfg['boxes'][$i] = array_replace($cfg['boxes'][$i], ['Enabled' => true, 'Name' => $i === 1 ? 'Garage' : 'Stellplatz', 'StartDelay' => 0, 'MinPause' => 0]);
$cfg['battery']['Enabled'] = true; $cfg['battery']['PowerVar'] = 100;
$time = time();
$in = ['manager' => true, 'mode' => 0, 'budget' => 12000.0, 'battery' => ['soc' => 76.0, 'power' => 4000.0, 'manual' => true, 'external' => true], 'boxes' => []];
for ($i = 1; $i <= 2; $i++) $in['boxes'][$i] = ['manual' => true, 'external' => true, 'demand' => true, 'power' => 5500.0, 'powerUpdated' => $time, 'phases' => 3, 'priority' => $i];
$state = ['battery' => ['requested' => 4000.0, 'requestAt' => $time - 60]];
$plan = Engine::plan($cfg, $in, $state, $time);
$plan['managerEnabled'] = true; $plan['measured'] = 11000.0; $plan['energy'] = 124.312;
$plan['status'] = 'Automatische Regelung aktiv'; $plan['strict'] = true; $plan['batteryEnabled'] = true; $plan['batteryConfigured'] = true;
$plan['battery']['capacity'] = 10.0; $plan['battery']['commanded'] = 4000.0; $plan['simulation'] = true;
foreach ($plan['boxes'] as $id => &$row) { $row['energy'] = $id === 1 ? 82.183 : ($id === 2 ? 42.129 : 0.0); $row['archivedEnergy'] = 0.0; $row['energyMethod'] = 'berechnet'; $row['gap'] = ''; }
unset($row);
$html = file_get_contents(__DIR__ . '/../WallboxManager/tile.html');
$mock = <<<'JS'
window.WBM_PREVIEW_ACTIONS = [];
window.requestAction = function (ident, value) {
    window.WBM_PREVIEW_ACTIONS.push({ident: ident, value: value});
    if (ident === 'ManagerEnabled') window.WBM_INITIAL.managerEnabled = value;
    if (ident === 'DistributionMode') window.WBM_INITIAL.mode = value;
    if (ident === 'BatteryEnabled') window.WBM_INITIAL.batteryEnabled = value;
    var match = ident.match(/^W([1-4])(Manual|Priority)$/);
    if (match) window.WBM_INITIAL.boxes[match[1]][match[2] === 'Manual' ? 'manual' : 'priority'] = value;
    if (typeof window.handleMessage === 'function') window.handleMessage(window.WBM_INITIAL);
};
JS;
$html = str_replace(['/*__CSS__*/', '/*__JS__*/', '/*__DATA__*/'],
    [file_get_contents(__DIR__ . '/../WallboxManager/tile.css'), $mock . "\n" . file_get_contents(__DIR__ . '/../WallboxManager/tile.js'),
        json_encode($plan, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)], $html);
file_put_contents(__DIR__ . '/../docs/preview.html', $html);
echo "Lokale Kachelvorschau: docs/preview.html\n";
