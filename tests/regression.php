<?php
use WBM\{Schema, Form};
test('Adapter: alter Sollwert wird nach Geräteabweichung erneut ausgegeben', function () {
    [$m, $ids] = fixture(); $m->ApplyChanges();
    $s = runtimeOf($m); $s['commands'][$ids[1]['setpoint']]['time'] = microtime(true) - 60; setRuntime($m, $s);
    Kernel::update($ids[1]['setpoint'], 18000.0); $m->Control(); closeTo(GetValue($ids[1]['setpoint']), 11000);
});
test('Adapter: Watchdog wiederholt erst nach konfiguriertem Abstand', function () {
    [$m, $ids] = fixture(1, ['Watchdog' => 60]); $m->ApplyChanges(); $n = count(Kernel::$calls);
    $m->Control(); check(count(Kernel::$calls) === $n);
    $s = runtimeOf($m); $s['commands'][$ids[1]['setpoint']]['time'] = microtime(true) - 61; setRuntime($m, $s); $m->Control();
    check(count(Kernel::$calls) === $n + 1);
});
test('Adapter: dauerhaft zu hohe Istleistung stoppt das Gerät', function () {
    [$m, $ids] = fixture(); $m->ApplyChanges();
    $s = runtimeOf($m); $s['boxes'][1]['lastCommand'] = microtime(true) - 60; setRuntime($m, $s);
    Kernel::update($ids[1]['power'], 15000.0, microtime(true)); $m->Control();
    closeTo(GetValue($ids[1]['setpoint']), 0); check($m->status === 201);
});
test('Adapter: Integer-Speicherziel rundet konservativ nach unten', function () {
    [$m, $ids] = fixture(); Kernel::update($ids['budget'], 6500.0);
    $soc = Kernel::variable(80.0); $target = Kernel::variable(0, true);
    $m->properties = array_replace($m->properties, ['BEnabled' => true, 'BSocVar' => $soc, 'BSetpointVar' => $target, 'BSetpointUnit' => 'kW', 'BNoFeedbackFraction' => 1.0]);
    $m->ApplyChanges(); check(GetValue($target) === 4); closeTo(runtimeOf($m)['battery']['requested'], 4000);
    $s = runtimeOf($m); $s['battery']['requestAt'] = microtime(true) - 60; setRuntime($m, $s); $m->Control();
    check(GetValue($ids[1]['setpoint']) <= 10500);
});
test('Adapter: Zähler in Wh wird als kWh gezählt, Batterie nicht addiert', function () {
    [$m] = fixture(); $meter = Kernel::variable(100000.0); $m->properties['W1EnergyVar'] = $meter; $m->properties['W1EnergyUnit'] = 'Wh';
    $m->ApplyChanges(); $s = runtimeOf($m); $s['energy'][1]['cursor'] = microtime(true) - 60; $s['energy'][1]['meterTime'] = microtime(true) - 60; setRuntime($m, $s);
    Kernel::update($meter, 100100.0); $m->Control(); closeTo($m->GetValue('TotalEnergy'), 0.1); closeTo($m->GetValue('W1Energy'), 0.1);
    $m->Create(); $m->ApplyChanges(); closeTo($m->GetValue('TotalEnergy'), 0.1);
});
test('Adapter: Fehler einer zweiten Instanz bleibt nach Zielkorrektur behebbar', function () {
    [$m, $ids] = fixture(); $m->ApplyChanges();
    $second = new WallboxManager(60); $second->Create(); $second->properties = $m->properties; $second->ApplyChanges();
    $new = Kernel::variable(0.0, true); $second->properties['W1SetpointVar'] = $new; $second->properties['W1PowerVar'] = Kernel::variable(0.0);
    $second->ApplyChanges(); $second->ClearFaults(); check($second->status === 102, $second->GetValue('Status')); closeTo(GetValue($ids[1]['setpoint']), 11000);
});
test('Adapter: eigene Ausgaben sind keine Ereignis-Rückkopplung', function () {
    [$m, $ids] = fixture(); $m->ApplyChanges();
    check(!isset($m->messages[$ids[1]['setpoint']][VM_UPDATE]));
    check(isset($m->messages[$ids[1]['power']][VM_UPDATE]));
});
test('Formular enthält jede Eigenschaft genau einmal und passende Auswahlwerte', function () {
    $flat = Schema::defaults(); $form = Form::build($flat, [], '');
    $fields = [];
    $walk = function ($items) use (&$walk, &$fields) {
        foreach ($items as $item) {
            if (isset($item['name'])) $fields[$item['name']][] = $item;
            if (isset($item['items'])) $walk($item['items']);
        }
    };
    $walk($form['elements']);
    foreach (Schema::definitions() as $key => $def) check(count($fields[$key] ?? []) === 1, 'Formularfeld fehlt/doppelt: ' . $key);
    check($fields['Mode'][0]['options'][0]['value'] === 0);
    check($fields['W1PhaseMode'][0]['options'][0]['value'] === '1');
    check($fields['DecimalW1MinA'][0]['value'] === '6,0');
    check($fields['DecimalW1MaxKW'][0]['value'] === '11,04');
    foreach ($fields as $items) foreach ($items as $field) if ($field['type'] === 'NumberSpinner') check($field['digits'] <= 2);
});test('Ungültige Einheit wird verständlich validiert statt Exception', function () {
    $flat = \WBM\Schema::defaults(); $flat['W1Enabled'] = true; $flat['W1SetpointUnit'] = 'invalid';
    check(count(\WBM\Validation::properties($flat)) > 0);
});
test('Formular zeigt kurze Wallbox- und Speicherüberschriften ohne Budget-Hinweis', function () {
    $flat = Schema::defaults();
    $form = Form::build($flat, [], '');
    $captions = array_column($form['elements'], 'caption');
    check(in_array('Wallbox 1', $captions, true));
    check(in_array('Batteriespeicher', $captions, true));
    $json = json_encode($form);
    check(!str_contains($json, 'Einstellungen gelten nur'));
    check(!str_contains($json, 'weitere Felder nur'));
    check(!str_contains($json, 'Grundbudget ='));
    $flat['W1Name'] = 'Garage';
    check(in_array('Wallbox 1 – Garage', array_column(Form::build($flat, [], '')['elements'], 'caption'), true));
});
test('Dezimalfelder akzeptieren Komma und beachten die Stellen je Einheit', function () {
    $flat = Schema::defaults();
    closeTo(Form::decimalValue('W1MinA', '6,5', $flat), 6.5);
    check(Form::decimalValue('W1MinA', '6,55', $flat) === null);
    closeTo(Form::decimalValue('W1MaxKW', '11,04', $flat), 11.04);
    check(Form::decimalValue('W1MaxKW', '11,044', $flat) === null);
    check(Form::decimalValue('W1MaxKW', '11.04', $flat) === null);
    check(Form::decimalValue('W1MaxKW', 'NaN', $flat) === null);
    closeTo(Form::decimalValue('W1HysteresisW', '-1,00', $flat), -1);
    check(Form::decimalDigits('W1Step', $flat) === 1);
    $flat['W1SetpointUnit'] = 'W';
    check(Form::decimalDigits('W1Step', $flat) === 2);
});
test('Komma-Eingabe ändert nur den Formularentwurf bis Übernehmen', function () {
    [$m, $ids] = fixture();
    $original = $m->properties['W1MinA'];
    $m->UpdateDecimalField('W1MinA', '8,5');
    check($m->properties['W1MinA'] === $original);
    check(Kernel::$calls === []);
    check($m->formUpdates[0] === ['field' => 'W1MinA', 'parameter' => 'value', 'value' => 8.5]);
    // Emulate the console saving the numeric field updated via UpdateFormField.
    $m->properties['W1MinA'] = $m->formUpdates[0]['value'];
    $m->ApplyChanges();
    closeTo(json_decode($m->attributes['Configuration'], true)['W1MinA'], 8.5);
});
test('Ungültige Dezimaleingaben verändern weder Konfiguration noch Geräte', function () {
    [$m] = fixture();
    $original = $m->properties;
    foreach (['6,55', '6.5', '6,', 'NaN', '1e3'] as $value) $m->UpdateDecimalField('W1MinA', $value);
    check($m->formUpdates === []);
    check($m->properties === $original);
    check(Kernel::$calls === []);
    try { $m->UpdateDecimalField('Enabled', '1'); check(false); }
    catch (InvalidArgumentException) {}
});
test('Anzeigeprofile begrenzen Nachkommastellen ohne Messwerte zu runden', function () {
    [$m] = fixture();
    check(Kernel::$profiles['WBM.Energy']['digits'] === 2);
    check(Kernel::$profiles['WBM.Setpoint.A']['digits'] === 1);
    foreach (Kernel::$profiles as $profile) check(($profile['digits'] ?? 0) <= 2);
    $m->SetValue('TotalEnergy', 1.234567);
    closeTo($m->GetValue('TotalEnergy'), 1.234567);
});
test('Energiearchiv bleibt bei anschließender Leistungsintegration erhalten', function () {
    $s = []; \WBM\Energy::identify($s, 'a'); \WBM\Energy::power($s, 'power:a', 1000, 0, 0, 120);
    \WBM\Energy::power($s, 'power:a', 1000, 10, 10, 120); $old = $s['energy'];
    \WBM\Energy::identify($s, 'b'); \WBM\Energy::power($s, 'power:b', 2000, 10, 10, 120);
    check($s['identity'] === 'b'); closeTo($s['archive'][0]['energy'], $old); closeTo($s['energy'], 0);
});
test('Adapter: ungültiger Laufzeitmodus stoppt definierte Ziele', function () {
    [$m, $ids] = fixture(); $m->ApplyChanges(); $m->SetValue('DistributionMode', 7); $m->Control();
    closeTo(GetValue($ids[1]['setpoint']), 0); check($m->status === 201);
});
test('Adapter: vier Geräte funktionieren innerhalb einer Instanz', function () {
    [$m, $ids] = fixture(4); $m->ApplyChanges();
    for ($i = 1; $i <= 4; $i++) closeTo(GetValue($ids[$i]['setpoint']), 3000);
    closeTo($m->GetValue('AssignedPower'), 12000);
});test('Selten aktualisierte Zähler nutzen Zeit seit letzter Zählerbasis', function () {
    $s = []; \WBM\Energy::meter($s, 'm', 100.0, 0, 11000, 2, 0.02);
    for ($t = 5; $t < 1800; $t += 5) closeTo(\WBM\Energy::meter($s, 'm', 100.0, $t, 11000, 2, 0.02)['delta'], 0);
    closeTo(\WBM\Energy::meter($s, 'm', 101.0, 1800, 11000, 2, 0.02)['delta'], 1.0);
    closeTo(\WBM\Energy::meter($s, 'm', 101.0, 1800, 11000, 2, 0.02)['delta'], 0);
});
