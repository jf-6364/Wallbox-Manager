<?php
declare(strict_types=1);
use WBM\{Billing, BillingPdf, Schema, Validation};

function billingConfig(array $changes = []): array {
    return box(array_replace(['Name' => 'Garage Süd', 'BillingEnabled' => true, 'BillingPVPrice' => 0.10, 'BillingNormalPrice' => 0.30, 'BillingIdleSeconds' => 60], $changes));
}
function billTick(array &$s, array $c, float $at, ?float $power = 3600.0, ?bool $pv = false, ?float $sample = null): void {
    Billing::observe(1, $c, $s, $power, $sample ?? $at, $at, 3600, $sample ?? $at, $pv, 'Europe/Berlin');
}
function finishedBill(): array {
    $s = []; $c = billingConfig();
    billTick($s, $c, 0); billTick($s, $c, 1000);
    billTick($s, $c, 1000, 3600.0, true); billTick($s, $c, 2000, 3600.0, true);
    billTick($s, $c, 2000, 3600.0, false); billTick($s, $c, 3000, 0.0);
    billTick($s, $c, 3060, 0.0);
    return $s;
}

test('Abrechnung: PV- und Normalenergie werden bei mehreren Moduswechseln getrennt', function () {
    $s = finishedBill(); $r = $s['last'];
    closeTo($r['pvEnergy'], 1.0); closeTo($r['normalEnergy'], 2.0); closeTo($r['totalEnergy'], 3.0);
    closeTo($r['pvCost'], 0.10); closeTo($r['normalCost'], 0.60); closeTo($r['totalCost'], 0.70);
    check(count($r['lines']) === 2); check(count($s['pending']) === 1); check($s['active'] === null);
    closeTo($r['ended'], 3000); check($s['totals']['sessions'] === 1);
});
test('Abrechnung: unveränderte Ereignisse und Timer zählen Zeitintervalle nur einmal', function () {
    $s = []; $c = billingConfig(); billTick($s, $c, 0);
    for ($i = 0; $i < 5; $i++) billTick($s, $c, 1000);
    closeTo(Billing::summary($s)['active']['totalEnergy'], 1.0);
    billTick($s, $c, 1000, 3600.0, true); billTick($s, $c, 1000, 3600.0, false);
    closeTo(Billing::summary($s)['active']['normalEnergy'], 1.0);
});
test('Abrechnung: Preisänderungen gelten nur für kommende Energieintervalle', function () {
    $s = []; $c = billingConfig(); billTick($s, $c, 0); billTick($s, $c, 1000);
    $c['BillingNormalPrice'] = 0.40; billTick($s, $c, 1000); billTick($s, $c, 2000, 0.0); billTick($s, $c, 2060, 0.0);
    $r = $s['last']; check(count($r['lines']) === 2);
    closeTo($r['lines'][0]['price'], 0.30); closeTo($r['lines'][1]['price'], 0.40);
    closeTo($r['normalEnergy'], 2.0); closeTo($r['totalCost'], 0.70);
});
test('Abrechnung: kurze Ladepause bleibt Teil desselben Ladegangs', function () {
    $s = []; $c = billingConfig(); billTick($s, $c, 0); $id = $s['active']['id'];
    billTick($s, $c, 1000, 0.0); billTick($s, $c, 1030);
    check($s['active']['id'] === $id); check(count($s['pending']) === 0);
    billTick($s, $c, 2030, 0.0); billTick($s, $c, 2089, 0.0); check($s['active'] !== null);
    billTick($s, $c, 2090, 0.0); closeTo($s['last']['totalEnergy'], 2.0); check($s['totals']['sessions'] === 1);
});
test('Abrechnung: späte positive Messung nach langer Pause beginnt neuen Ladegang', function () {
    $s = []; $c = billingConfig(); billTick($s, $c, 0); billTick($s, $c, 1000, 0.0); $old = $s['active']['id'];
    billTick($s, $c, 1100, 3600.0, false, 1080);
    check($s['last']['id'] === $old); check($s['active']['id'] !== $old);
    closeTo($s['last']['normalEnergy'], 1.0); closeTo(Billing::summary($s)['active']['normalEnergy'], 0.02);
});
test('Abrechnung: Standby unter Leistungsschwelle erzeugt keine leeren PDFs', function () {
    $s = []; $c = billingConfig(); billTick($s, $c, 0, 30.0); billTick($s, $c, 1000, 0.0); billTick($s, $c, 2000, 0.0);
    check($s['active'] === null); check(count($s['pending']) === 0); check($s['totals']['sessions'] === 0);
    billTick($s, $c, 3000); billTick($s, $c, 3000, null); $c['BillingEnabled'] = false; billTick($s, $c, 3000);
    check(count($s['pending']) === 0);
});
test('Abrechnung: Messwertausfall gilt nicht als Ladeende und erfindet keine Energie', function () {
    $s = []; $c = billingConfig(); billTick($s, $c, 0); billTick($s, $c, 1000, 0.0);
    billTick($s, $c, 1040, null); billTick($s, $c, 2000, null); check($s['active'] !== null); check($s['idleSince'] === null);
    billTick($s, $c, 2000, 0.0); billTick($s, $c, 2059, 0.0); check($s['active'] !== null);
    billTick($s, $c, 2060, 0.0); closeTo($s['last']['totalEnergy'], 1.0); check(count($s['last']['warnings']) > 0);
});
test('Abrechnung: Neustart-Basis zählt eine Unterbrechung nicht als Ladeenergie', function () {
    $s = []; $c = billingConfig(); billTick($s, $c, 0); billTick($s, $c, 1000);
    unset($s['integrator']['cursor']); Billing::note($s['active'], 'Neustart');
    billTick($s, $c, 5000); closeTo(Billing::summary($s)['active']['totalEnergy'], 1.0);
    billTick($s, $c, 6000, 0.0); billTick($s, $c, 6060, 0.0); closeTo($s['last']['totalEnergy'], 2.0);
});
test('Abrechnung: rückwärts laufende Uhr erzeugt weder negative Energie noch verfrühten Abschluss', function () {
    $s = []; $c = billingConfig(); billTick($s, $c, 1000); billTick($s, $c, 2000, 0.0);
    billTick($s, $c, 1500, 0.0); check($s['active'] !== null); closeTo($s['idleSince'], 1500);
    billTick($s, $c, 1560, 0.0); closeTo($s['last']['totalEnergy'], 1.0);
});
test('Abrechnung: deaktivieren und Gerätetausch bewahren bisherige Energie und PDFs', function () {
    $s = []; $c = billingConfig(); billTick($s, $c, 0); billTick($s, $c, 1000);
    $old = $s['active']['id']; $c['DeviceKey'] = 'neues-gerät'; billTick($s, $c, 1000);
    check($s['last']['id'] === $old); check($s['last']['reason'] === 'Gerätewechsel'); check($s['active']['id'] !== $old);
    billTick($s, $c, 2000); $c['BillingEnabled'] = false; billTick($s, $c, 2000); billTick($s, $c, 3000);
    check(count($s['pending']) === 2); closeTo($s['totals']['normalEnergy'], 2.0); check($s['totals']['sessions'] === 2);
});
test('Abrechnung: ungültige PV-Boolvariable wird nicht heimlich zum Normaltarif', function () {
    $s = []; $c = billingConfig(); billTick($s, $c, 0, 3600.0, null); billTick($s, $c, 1000, 3600.0, null);
    billTick($s, $c, 1000, 3600.0, false); billTick($s, $c, 2000, 0.0); billTick($s, $c, 2060, 0.0);
    closeTo($s['last']['unassignedEnergy'], 1.0); closeTo($s['last']['normalEnergy'], 1.0); closeTo($s['last']['totalCost'], 0.30);
    check(count($s['last']['warnings']) > 0);
});
test('Abrechnung: vier Wallboxen mit gemeinsamem PV-Signal bleiben unabhängig', function () {
    $states = [];
    foreach ([1, 2, 3, 4] as $id) {
        $c = billingConfig(); $states[$id] = [];
        foreach ([0, 1000] as $at) Billing::observe($id, $c, $states[$id], 3600.0 * $id, $at, $at, 3600, $at, true, 'Europe/Berlin');
        closeTo(Billing::summary($states[$id])['active']['pvEnergy'], (float)$id);
    }
});
test('Abrechnung: kostenloser PV-Tarif bleibt kostenlos und Gesamtsumme entspricht Centpositionen', function () {
    $s = []; $c = billingConfig(); $c['BillingPVPrice'] = 0.0;
    billTick($s, $c, 0, 3600.0, true); billTick($s, $c, 1000, 3600.0, true); billTick($s, $c, 1000, 3600.0, false);
    billTick($s, $c, 2000, 0.0); billTick($s, $c, 2060, 0.0);
    closeTo($s['last']['pvCost'], 0.0); closeTo($s['last']['normalCost'], 0.30); closeTo($s['last']['totalCost'], 0.30);
    closeTo(array_sum(array_column($s['last']['lines'], 'cost')), $s['last']['totalCost']);
});
test('Abrechnung: Formularvalidierung prüft Preise, Pause, Booltypen und Zeitzone', function () {
    $flat = Schema::defaults(); $flat['W1Enabled'] = true; $flat['W1BillingEnabled'] = true;
    check(Validation::properties($flat) === []);
    foreach (['W1BillingPVPrice' => -0.01, 'W1BillingNormalPrice' => 0.123, 'W1BillingIdleSeconds' => 0, 'W1BillingMinPowerW' => -1.0, 'BillingTimezone' => 'Falsche/Zone'] as $key => $value) {
        check(count(Validation::properties(array_replace($flat, [$key => $value]))) > 0, $key);
    }
    $flat['W1BillingPVVar'] = 987; check(isset(Schema::links($flat)['W1BillingPVVar']));
    $flat['W1BillingEnabled'] = false; check(!isset(Schema::links($flat)['W1BillingPVVar']));
});
test('Abrechnung: PDF enthält echte Seiten, deutsche Zeichen, Centbeträge und gültige Byte-Offsets', function () {
    $record = finishedBill()['last']; $record['name'] = 'Garage (Süd) \\ Anschluss';
    $pdf = BillingPdf::create($record);
    check(str_starts_with($pdf, '%PDF-1.4')); check(str_contains($pdf, 'Garage \\(S' . chr(252) . 'd\\) \\\\ Anschluss'));
    check(str_contains($pdf, '0,70 ' . chr(128))); check(str_contains($pdf, '/MediaBox [0 0 595 842]'));
    preg_match('/startxref\n([0-9]+)\n%%EOF/', $pdf, $m); check(substr($pdf, (int)$m[1], 4) === 'xref');
    preg_match('/xref\n0 ([0-9]+)\n(.*?)trailer/s', $pdf, $xref);
    $lines = explode("\n", trim($xref[2]));
    foreach (array_slice($lines, 1) as $i => $row) check(str_starts_with(substr($pdf, (int)substr($row, 0, 10)), ($i + 1) . ' 0 obj'));
    check(count($lines) === (int)$xref[1]);
});
test('Abrechnung: viele Preispositionen erzeugen mehrere gültige PDF-Seiten', function () {
    $record = finishedBill()['last']; $record['lines'] = [];
    for ($i = 0; $i < 100; $i++) $record['lines'][] = ['mode' => $i % 2 ? 'pv' : 'normal', 'price' => $i / 100, 'energy' => 1.0, 'cost' => $i / 100];
    $pdf = BillingPdf::create($record); preg_match('/\/Type \/Pages \/Count ([0-9]+)/', $pdf, $m);
    check((int)$m[1] >= 4); check(str_contains($pdf, 'Seite 4 /'));
});

function billingFixture(): array {
    [$m, $ids] = fixture(1, ['W1BillingEnabled' => true, 'W1BillingPVPrice' => 0.10, 'W1BillingNormalPrice' => 0.30,
        'W1BillingIdleSeconds' => 60, 'EnergyMaxGap' => 3600]);
    $m->ApplyChanges(); return [$m, $ids];
}
function seedIdleBill(WallboxManager $m): void {
    $now = microtime(true); $s = runtimeOf($m); $c = Schema::unpack($m->properties)['boxes'][1]; $bill = [];
    billTick($bill, $c, $now - 1100); billTick($bill, $c, $now - 100, 0.0);
    $bill['idleSince'] = $now - 100; $s['billing'][1] = $bill; setRuntime($m, $s);
}
test('Adapter Abrechnung: Ladeende erstellt genau ein PDF-Medienobjekt pro Ladegang', function () {
    [$m, $ids] = billingFixture(); seedIdleBill($m); Kernel::update($ids[1]['power'], 0.0, microtime(true)); $m->Control();
    $s = runtimeOf($m); check(count(Kernel::$media) === 1); check(count($s['billing'][1]['pending']) === 0);
    check($s['billing'][1]['last']['reason'] === 'Eingestellte Ladepause erreicht');
    $media = $s['billing'][1]['last']['mediaID']; check($media > 0); check(IPS_GetMedia($media)['MediaType'] === 5);
    $pdf = base64_decode($m->GetBillingPDF(1), true); check(str_starts_with($pdf, '%PDF-1.4')); check(str_contains($pdf, '0,30 ' . chr(128)));
    check($m->GetValue('W1BillingLastPDF') === $media); closeTo($m->GetValue('W1BillingNormalEnergy'), 1.0);
    closeTo($m->GetValue('W1BillingCost'), 0.30); check($m->GetValue('W1BillingSessions') === 1);
    $m->Control(); check(count(Kernel::$media) === 1); check(runtimeOf($m)['billing'][1]['totals']['sessions'] === 1);
    check(telemetryOf($m)['boxes'][1]['billing']['pdf'] === $m->GetBillingPDF(1));
});
test('Adapter Abrechnung: PDF-Schreibfehler sperrt keine Wallbox und wird ohne Duplikat wiederholt', function () {
    [$m, $ids] = billingFixture(); seedIdleBill($m); Kernel::$failures['media'] = 'Datenträger voll'; $m->Control();
    $s = runtimeOf($m); check(count($s['billing'][1]['pending']) === 1); check(str_contains($s['billing'][1]['error'], 'Datenträger voll'));
    check($m->status === 102, $m->GetValue('Status')); check(count(Kernel::$media) === 1);
    unset(Kernel::$failures['media']); $m->Control();
    $s = runtimeOf($m); check(count(Kernel::$media) === 1); check(count($s['billing'][1]['pending']) === 0); check($s['billing'][1]['error'] === '');
    closeTo($s['billing'][1]['totals']['totalCost'], 0.30); check($s['billing'][1]['totals']['sessions'] === 1);
});
test('Adapter Abrechnung: PDF-Versandwarteschlange und Ladegang überleben Neustart', function () {
    [$m] = billingFixture(); seedIdleBill($m); Kernel::$failures['media'] = 'Dateifehler'; $m->Control();
    $lastID = runtimeOf($m)['billing'][1]['last']['id']; $m->buffers = []; $m->Create(); unset(Kernel::$failures['media']); $m->ApplyChanges();
    $s = runtimeOf($m); check($s['billing'][1]['last']['id'] === $lastID); check(count(Kernel::$media) === 1);
    check(count($s['billing'][1]['pending']) === 0); check($m->GetBillingPDF(1) !== '');
});
test('Adapter Abrechnung: Energie-Reset lässt Ladeabrechnungen und PDFs erhalten', function () {
    [$m] = billingFixture(); seedIdleBill($m); $m->Control(); $pdf = $m->GetBillingPDF(1);
    $m->ResetEnergy('ENERGIE ZURÜCKSETZEN');
    check($m->GetBillingPDF(1) === $pdf); check(count(Kernel::$media) === 1);
    closeTo($m->GetValue('TotalEnergy'), 0.0); closeTo($m->GetValue('W1BillingNormalEnergy'), 1.0);
});
test('Adapter Abrechnung: PV-Boolvariable wird verknüpft und verarbeitet Moduswechsel sofort', function () {
    [$m, $ids] = billingFixture(); $pv = Kernel::variable(false); $m->properties['W1BillingPVVar'] = $pv; $m->ApplyChanges();
    check(in_array($pv, $m->GetReferenceList(), true)); check(in_array(VM_UPDATE, $m->GetMessageList()[$pv], true));
    $now = microtime(true); $c = billingConfig(); $c['SetpointVar'] = $m->properties['W1SetpointVar']; $c['PowerVar'] = $m->properties['W1PowerVar'];
    $s = runtimeOf($m); $s['billing'][1] = []; billTick($s['billing'][1], $c, $now - 1000);
    setRuntime($m, $s); Kernel::update($ids[1]['power'], 3600.0, $now); Kernel::update($pv, true, $now);
    $m->MessageSink(12345, $pv, VM_UPDATE, [true, true, false]); $s = runtimeOf($m);
    closeTo(Billing::summary($s['billing'][1])['active']['normalEnergy'], 1.0, .001);
    closeTo(Billing::summary($s['billing'][1])['active']['pvEnergy'], 0.0);
    check($s['billing'][1]['context']['mode'] === 'pv');
});

test('Abrechnung: veraltete positive Messwerte starten und überbrücken keinen Ladegang', function () {
    $c = billingConfig(); $s = [];
    billTick($s, $c, 5000, 3600.0, false, 0); check($s['active'] === null);
    billTick($s, $c, 6000); billTick($s, $c, 7000); closeTo(Billing::summary($s)['active']['totalEnergy'], 1.0);
    billTick($s, $c, 11000, 3600.0, false, 7000); billTick($s, $c, 11001);
    closeTo(Billing::summary($s)['active']['totalEnergy'], 1.0);
    billTick($s, $c, 12001, 0.0); billTick($s, $c, 12061, 0.0); closeTo($s['last']['totalEnergy'], 2.0);
});
test('Adapter Abrechnung: kW-Leistungsmessung wird trotz seltenem Gerätezähler tarifgenau integriert', function () {
    [$m, $ids] = billingFixture(); $meter = Kernel::variable(100.0);
    $m->properties['W1PowerUnit'] = 'kW'; $m->properties['W1EnergyVar'] = $meter; $m->ApplyChanges();
    $now = microtime(true); $c = Schema::unpack($m->properties)['boxes'][1]; $s = runtimeOf($m); $s['billing'][1] = [];
    billTick($s['billing'][1], $c, $now - 1000); setRuntime($m, $s);
    Kernel::update($ids[1]['power'], 3.6, $now); $m->MessageSink(999, $ids[1]['power'], VM_UPDATE, [3.6, true, 0.0]);
    $s = runtimeOf($m); closeTo(Billing::summary($s['billing'][1])['active']['normalEnergy'], 1.0, .001);
    closeTo($s['energy'][1]['energy'], 0.0); closeTo(GetValue($meter), 100.0);
});
test('Adapter Abrechnung: Übernehmen einer Preisänderung bewahrt alte Tarifpositionen', function () {
    [$m, $ids] = billingFixture(); $now = microtime(true); $c = Schema::unpack($m->properties)['boxes'][1];
    $s = runtimeOf($m); $s['billing'][1] = []; billTick($s['billing'][1], $c, $now - 1000); setRuntime($m, $s);
    Kernel::update($ids[1]['power'], 3600.0, $now); $m->properties['W1BillingNormalPrice'] = 0.40; $m->ApplyChanges();
    $s = runtimeOf($m); $r = Billing::summary($s['billing'][1])['active'];
    closeTo($r['normalEnergy'], 1.0, .001); closeTo($r['lines'][0]['price'], 0.30);
    closeTo($s['billing'][1]['context']['price'], 0.40);
    $s['billing'][1]['integrator']['cursor'] = microtime(true) - 1000;
    setRuntime($m, $s); Kernel::update($ids[1]['power'], 3600.0, microtime(true)); $m->Control();
    $r = Billing::summary(runtimeOf($m)['billing'][1])['active'];
    closeTo($r['normalEnergy'], 2.0, .001); closeTo($r['totalCost'], 0.70, .001);
});
test('Adapter Abrechnung: Unterbrechung nach PDF-Schreiben erzeugt nach Neustart kein Doppel-Dokument', function () {
    [$m] = billingFixture(); seedIdleBill($m); Kernel::$failures['mediaAfterWrite'] = true; $m->Control();
    $s = runtimeOf($m); check(count($s['billing'][1]['pending']) === 1); check(count(Kernel::$media) === 1);
    $pdf = array_values(Kernel::$media)[0]['content']; check($pdf !== '');
    $m->buffers = []; $m->Create(); $m->ApplyChanges();
    check(count(Kernel::$media) === 1); check($m->GetBillingPDF(1) === $pdf);
    check(runtimeOf($m)['billing'][1]['totals']['sessions'] === 1);
});

test('Adapter Abrechnung: native Medienwarnungen und false bleiben lokal; Fehlerhandler wird wiederhergestellt', function () {
    foreach (['warning', 'false'] as $failure) {
        [$m] = billingFixture(); seedIdleBill($m); Kernel::$failures['media'] = $failure;
        $messages = [];
        set_error_handler(static function ($severity, $message) use (&$messages) { $messages[] = $message; return true; });
        try {
            $m->Control(); trigger_error('Aufrufer-Handler', E_USER_WARNING);
        } finally { restore_error_handler(); }
        check($messages === ['Aufrufer-Handler']); check($m->status === 102);
        $s = runtimeOf($m); check(count($s['billing'][1]['pending']) === 1); check($s['billing'][1]['error'] !== '');
        unset(Kernel::$failures['media']); $m->Control(); check(count(Kernel::$media) === 1); check($m->GetBillingPDF(1) !== '');
    }
});
