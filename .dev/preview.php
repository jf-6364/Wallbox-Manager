<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/bootstrap.php';
use WBM\{Schema, Engine, Setpoint, Billing, BillingPdf, Invoice};
$cfg = Schema::unpack(Schema::defaults());
$cfg['global']['Hysteresis'] = 0.0;
for ($i = 1; $i <= 4; $i++) $cfg['boxes'][$i] = array_replace($cfg['boxes'][$i], ['Enabled' => true, 'Name' => 'Wallbox ' . $i, 'Stop' => 1.0, 'StartDelay' => 0, 'MinPause' => 0]);
$cfg['battery']['Enabled'] = false; $cfg['battery']['PowerVar'] = 100;
$time = time();
$in = ['manager' => true, 'mode' => 0, 'budget' => 0.0, 'battery' => ['soc' => 76.0, 'power' => 4000.0, 'manual' => true, 'external' => true], 'boxes' => []];
for ($i = 1; $i <= 4; $i++) $in['boxes'][$i] = ['manual' => $i !== 1, 'external' => true, 'demand' => true, 'power' => 0.0, 'powerUpdated' => $time, 'phases' => 3, 'priority' => $i];
$state = ['battery' => ['requested' => 4000.0, 'requestAt' => $time - 60]];
$plan = Engine::plan($cfg, $in, $state, $time);
$plan['managerEnabled'] = true; $plan['measured'] = 0.0; $plan['energy'] = 28.31;
$plan['status'] = 'Automatische Regelung aktiv'; $plan['strict'] = true; $plan['batteryEnabled'] = true; $plan['batteryConfigured'] = false;
$plan['battery']['capacity'] = 10.0; $plan['battery']['commanded'] = 4000.0; $plan['simulation'] = true;
foreach ($plan['boxes'] as $id => &$row) { $row['energy'] = [1 => 7.59, 2 => 3.98, 3 => 6.50, 4 => 10.24][$id]; $row['archivedEnergy'] = 0.0; $row['energyMethod'] = 'berechnet'; $row['gap'] = ''; }
unset($row);
$previewInvoiceSequence = 0;
foreach ([1, 2, 3, 4] as $id) {
    $session = ['id' => 'beispiel-w' . $id, 'wallbox' => $id, 'name' => $plan['boxes'][$id]['name'], 'device' => 'vorschau',
        'started' => $time - 7800, 'timezone' => 'Europe/Berlin', 'method' => 'berechnet', 'warnings' => [],
        'lines' => [['mode' => 'normal', 'energy' => [1 => 1.46, 2 => 1.38, 3 => 1.57, 4 => .57][$id], 'price' => 0.30]]];
    $record = Billing::record($session) + ['ended' => $time - 600, 'reason' => 'Ladevorgang manuell gestoppt', 'mediaID' => 0, 'fileName' => 'Wallbox-' . $id . '-Beispiel.pdf'];
    $profile = array_replace(Invoice::profile([], []), ['SenderName' => 'Solarservice Muster', 'SenderStreet' => 'Sonnenstraße 12',
        'SenderZip' => '90443', 'SenderCity' => 'Nürnberg', 'RecipientName' => 'Max Beispiel',
        'RecipientStreet' => 'Musterstraße 14', 'RecipientZip' => '90443', 'RecipientCity' => 'Nürnberg',
        'Prefix' => 'MUSTER', 'TaxMode' => 'included', 'LogoText' => 'SM']);
    $record = Invoice::issue($record, $profile, $previewInvoiceSequence, $time);
    $record['fileName'] = $record['invoice']['number'] . '.pdf';
    $plan['boxes'][$id]['billing'] = ['enabled' => true, 'active' => $id === 1 ? null : Billing::record($session), 'last' => $record, 'totals' => Billing::totals(),
        'idleSince' => null, 'mode' => 'normal', 'pvPrice' => 0.13, 'normalPrice' => 0.30,
        'pending' => 0, 'error' => '', 'pdf' => base64_encode(BillingPdf::create($record))];
}
$html = file_get_contents(__DIR__ . '/../WallboxManager/tile.html');
$mock = <<<'JS'
// Standalone preview only: simulate the header normally provided by Symcon.
var previewHeader = document.createElement('header');
previewHeader.innerHTML = '<span style="display:flex;align-items:center;justify-content:center;width:44px;height:44px;flex-shrink:0;border-radius:15px;background:var(--wb-default-accent);color:white"><svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><use href="#wb-i-bolt"/></svg></span><span>Wallbox-Manager</span>';
previewHeader.setAttribute('aria-label', 'Symcon-Kopfzeile (Vorschau)');
previewHeader.style.cssText = 'position:absolute;inset:0 0 auto;height:64px;padding:8px clamp(12px,2vw,32px);box-sizing:border-box;display:flex;align-items:center;gap:18px;font-size:clamp(18px,2.5vw,36px);font-weight:700;letter-spacing:-.03em;z-index:5;background:color-mix(in srgb,var(--wb-default-surface) 94%,#7796be);';
document.body.prepend(previewHeader);
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
