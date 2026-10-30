<?php
declare(strict_types=1);
namespace WBM;

final class Validation
{
    public static function properties(array $flat): array
    {
        $errors = [];
        foreach (Schema::definitions() as $key => $d) {
            $v = $flat[$key] ?? $d[0];
            if (is_float($d[0]) && (!is_numeric($v) || !is_finite((float)$v))) $errors[] = $key . ': endliche Zahl erforderlich';
            if (isset($d[3]) && is_array($d[3])) {
                $choices = array_is_list($d[3]) && is_string($d[0]) ? array_values($d[3]) : array_keys($d[3]);
                // PHP casts numeric string keys; normalize enums for comparison.
                if (!in_array((string)$v, array_map('strval', $choices), true)) $errors[] = $key . ': ungültige Auswahl';
            }
        }
        $cfg = Schema::unpack($flat);
        $g = $cfg['global'];
        if (!in_array($g['BillingTimezone'], \DateTimeZone::listIdentifiers(), true) && $g['BillingTimezone'] !== 'UTC') $errors[] = 'Abrechnung: ungültige Zeitzone';
        if ($g['Interval'] < 1 || $g['Interval'] > 3600) $errors[] = 'Regelintervall muss zwischen 1 und 3600 s liegen';
        foreach (['Hysteresis', 'HardLimitW', 'BudgetMaxAge', 'Rotation', 'OverrunW', 'Watchdog', 'FeedbackToleranceW', 'MeterJumpSlack'] as $k) {
            if ($g[$k] < 0) $errors[] = $k . ': darf nicht negativ sein';
        }
        foreach (['OverrunSeconds', 'FeedbackTimeout', 'EnergyMaxGap', 'MeterJumpFactor'] as $k) {
            if ($g[$k] <= 0) $errors[] = $k . ': muss positiv sein';
        }
        foreach ($cfg['boxes'] as $id => $c) {
            if (!$c['Enabled']) continue;
            $prefix = 'Wallbox ' . $id . ': ';
            if (trim($c['Name']) === '') $errors[] = $prefix . 'Name fehlt';
            if ($c['Priority'] < 1 || $c['Priority'] > 999) $errors[] = $prefix . 'Priorität muss 1 bis 999 sein';
            if ($c['BillingEnabled']) {
                foreach (['BillingPVPrice', 'BillingNormalPrice', 'BillingMinPowerW'] as $key) {
                    if ($c[$key] < 0) $errors[] = $prefix . $key . ' darf nicht negativ sein';
                }
                foreach (['BillingPVPrice', 'BillingNormalPrice'] as $key) {
                    if (abs($c[$key] - round($c[$key], 2)) > 1e-8) $errors[] = $prefix . $key . ' erfordert höchstens zwei Nachkommastellen';
                }
                if ($c['BillingIdleSeconds'] < 1 || $c['BillingIdleSeconds'] > 604800) $errors[] = $prefix . 'Abrechnung: Ladepause muss 1 bis 604800 s betragen';
            }
            if ($c['Voltage'] < 100 || $c['Voltage'] > 300) $errors[] = $prefix . 'Spannung muss 100 bis 300 V sein';
            if ($c['MinA'] <= 0 || $c['MaxA'] < $c['MinA'] || $c['MinKW'] <= 0 || $c['MaxKW'] < $c['MinKW'] || $c['Step'] <= 0) $errors[] = $prefix . 'Min-/Max-Grenzen oder Raster ungültig';
            foreach (['StartDelay', 'MinRun', 'StopDelay', 'MinPause', 'CommandGap', 'MaxAge', 'PhaseDelay', 'PhaseDwell', 'PhaseZeroSeconds', 'PhaseBefore', 'PhaseAfter', 'PhaseRestW', 'PhaseHysteresisW'] as $k) {
                if ($c[$k] < 0) $errors[] = $prefix . $k . ' darf nicht negativ sein';
            }
            if ($c['HysteresisW'] < 0 && $c['HysteresisW'] !== -1.0) $errors[] = $prefix . 'Hysterese muss -1 oder nichtnegativ sein';
            if ($c['PhaseMode'] === 'dynamic') {
                if ($c['Phase1Command'] === $c['Phase3Command'] || $c['Phase1Feedback'] === $c['Phase3Feedback']) $errors[] = $prefix . 'Phasenwerte müssen unterscheidbar sein';
                if ($c['PhaseDownW'] < 0 || $c['PhaseUpW'] <= $c['PhaseDownW'] || $c['PhaseTimeout'] < 1) $errors[] = $prefix . 'Phasenschwellen oder Timeout ungültig';
            }
            if (in_array($c['SetpointUnit'], ['A', 'W', 'kW'], true) && $c['Step'] > 0 && is_finite((float)$c['Step']) && $c['Voltage'] > 0
                && is_finite((float)$c['Voltage']) && is_finite((float)$c['MinA']) && is_finite((float)$c['MaxA']) && is_finite((float)$c['MinKW']) && is_finite((float)$c['MaxKW'])) {
                $valid = false;
                foreach ($c['PhaseMode'] === 'dynamic' ? [1, 3] : [(int)$c['PhaseMode']] as $phases) {
                    if (!in_array($phases, [1, 3], true)) continue;
                    $l = Setpoint::limits($c, $phases);
                    $valid = $valid || $l['valid'];
                    $stopW = $c['Stop'] * $l['factor'];
                    if ($l['valid'] && $stopW >= $l['min'] - 1e-7 && $stopW <= $l['max'] + 1e-7 && abs($c['Stop'] / $c['Step'] - round($c['Stop'] / $c['Step'])) < 1e-7) $errors[] = $prefix . 'Stoppwert kollidiert mit einem aktiven Sollwert';
                }
                if (!$valid) $errors[] = $prefix . 'Kein zulässiger aktiver Sollwert nach Rundung';
            }
        }
        $b = $cfg['battery'];
        if ($b['Enabled']) {
            if ($b['CapacityKWh'] <= 0 || $b['MaxPower'] <= 0) $errors[] = 'Speicherkapazität und Entladeleistung müssen positiv sein';
            if ($b['MinSoc'] < 0 || $b['MinSoc'] >= 100 || $b['SocHysteresis'] < 0 || $b['MinSoc'] + $b['SocHysteresis'] > 100) $errors[] = 'SoC-Reserve / Hysterese ungültig';
            if ($b['NoFeedbackFraction'] < 0 || $b['NoFeedbackFraction'] > 1 || $b['StartDelay'] < 1 || $b['ResponseTimeout'] < 1 || $b['MaxAge'] < 0 || $b['ToleranceW'] < 0) $errors[] = 'Speicher-Anlaufparameter oder Überwachung ungültig';
        }
        return array_values(array_unique($errors));
    }

    public static function links(array $flat, callable $inspect, int $instance, array $otherTargets = []): array
    {
        $errors = [];
        $writes = [];
        $reads = [];
        foreach (Schema::links($flat) as $name => $link) {
            $id = $link['id'];
            if ($id === 0 && !$link['required']) continue;
            $v = $inspect($id);
            if ($v === null) {
                $errors[] = $name . ': Variable fehlt (' . $id . ')';
                continue;
            }
            if (!in_array($v['type'], $link['types'], true)) $errors[] = $name . ': falscher Variablentyp';
            if ($v['owner'] === $instance) $errors[] = $name . ': Selbstreferenz ist unzulässig';
            if ($link['write']) {
                if (!$v['action'] && !$flat['Simulation']) $errors[] = $name . ': keine Geräteaktion vorhanden';
                if ($v['locked']) $errors[] = $name . ': Variable nicht beschreibbar';
                if (isset($writes[$id])) $errors[] = $name . ': doppeltes Steuerziel mit ' . $writes[$id];
                if (in_array($id, $otherTargets, true)) $errors[] = $name . ': bereits von anderer Managerinstanz gesteuert';
                $writes[$id] = $name;
                if ($v['type'] === 1) {
                    $keys = [];
                    if (preg_match('/^(W[1-4])SetpointVar$/', $name, $m)) $keys = [$m[1] . 'Step', $m[1] . 'Stop'];
                    if (preg_match('/^(W[1-4])PhaseCommandVar$/', $name, $m)) $keys = [$m[1] . 'Phase1Command', $m[1] . 'Phase3Command'];
                    if ($name === 'BSetpointVar') $keys = ['BStop'];
                    foreach ($keys as $key) if (abs($flat[$key] - round($flat[$key])) > 1e-7) $errors[] = $key . ': Integerziel erfordert ganzzahlige Werte';
                }
            } else $reads[$id][] = $name;
        }
        foreach ($writes as $id => $name) if (isset($reads[$id])) $errors[] = $name . ': Rückkopplung mit ' . implode(', ', $reads[$id]);
        return array_values(array_unique($errors));
    }
}

final class Form
{
    public static function build(array $flat, array $errors, string $diagnostic): array
    {
        $form = json_decode(file_get_contents(__DIR__ . '/../WallboxManager/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $form['elements'][] = ['type' => 'Label', 'caption' => $errors ? implode("\n", $errors) : 'Konfiguration geprüft. Laufzeitfehler und Messlücken siehe Diagnose.'];
        $groups = [];
        foreach (Schema::global() as $name => $d) $groups[$d[2]][] = self::field($name, $d, $flat);
        foreach ($groups as $caption => $fields) $form['elements'][] = ['type' => 'ExpansionPanel', 'caption' => $caption, 'items' => $fields];
        for ($i = 1; $i <= 4; $i++) {
            $sections = [];
            foreach (Schema::wallbox() as $key => $d) $sections[$d[2]][] = self::field('W' . $i . $key, $d, $flat);
            $fields = [];
            foreach ($sections as $caption => $items) {
                if ($caption === 'Abrechnung') $items[] = ['type' => 'Label', 'caption' => 'Energie wird aus der gemessenen Ladeleistung berechnet. Ohne PV-Boolvariable gilt der Normalpreis. 0,00 EUR/kWh bedeutet kostenlos. PDFs werden nach der Ladepause unter dieser Instanz im Ordner „Abrechnung“ gespeichert.'];
                if ($caption === 'Dynamische Phasenumschaltung') $caption .= ' (nur bei dynamischem Phasenbetrieb)';
                $fields[] = ['type' => 'ExpansionPanel', 'caption' => $caption, 'items' => $items];
            }
            $title = 'Wallbox ' . $i;
            if ($flat['W' . $i . 'Name'] !== $title) $title .= ' – ' . $flat['W' . $i . 'Name'];
            $form['elements'][] = ['type' => 'ExpansionPanel', 'caption' => $title, 'items' => $fields];
        }
        $fields = [];
        foreach (Schema::battery() as $key => $d) $fields[] = self::field('B' . $key, $d, $flat);
        $fields[] = ['type' => 'Label', 'caption' => 'Ohne Leistungsrückmeldung: nur der konfigurierte Anteil nach Anlaufwartezeit; reale Entladung bleibt ungeprüft.'];
        $form['elements'][] = ['type' => 'ExpansionPanel', 'caption' => 'Batteriespeicher', 'items' => $fields];
        $form['elements'][] = ['type' => 'ExpansionPanel', 'caption' => 'Aktuelle Diagnose', 'items' => [['type' => 'Label', 'caption' => $diagnostic ?: 'Noch kein Regeldurchlauf']]];
        return $form;
    }

    public static function decimalDigits(string $name, array $flat): int
    {
        if (preg_match('/^W[1-4](MinA|MaxA)$/', $name)) return 1;
        if (preg_match('/^(W[1-4])(Step|Stop)$/', $name, $match) && $flat[$match[1] . 'SetpointUnit'] === 'A') return 1;
        return 2;
    }

    public static function decimalValue(string $name, string $value, array $flat): ?float
    {
        $definition = Schema::definitions()[$name] ?? null;
        if (!$definition || !is_float($definition[0])) throw new \InvalidArgumentException('Unbekannte Dezimaleinstellung');
        $digits = self::decimalDigits($name, $flat);
        if (!preg_match('/^-?[0-9]+(?:,[0-9]{1,' . $digits . '})?$/D', trim($value))) return null;
        $number = (float)str_replace(',', '.', trim($value));
        return is_finite($number) ? $number : null;
    }

    private static function field(string $name, array $d, array $flat): array
    {
        $field = ['name' => $name, 'caption' => $d[1]];
        if (isset($d[4])) return $field + ['type' => 'SelectVariable', 'validVariableTypes' => $d[4], 'requiredAction' => $d[3] === 'write' && !$flat['Simulation'] ? 1 : 0];
        if (isset($d[3]) && is_array($d[3])) {
            $options = [];
            $list = array_is_list($d[3]) && is_string($d[0]);
            foreach ($d[3] as $value => $caption) $options[] = ['caption' => (string)$caption, 'value' => $list ? $caption : (is_string($d[0]) ? (string)$value : $value)];
            return $field + ['type' => 'Select', 'options' => $options];
        }
        if (is_bool($d[0])) return $field + ['type' => 'CheckBox'];
        if (is_int($d[0])) return $field + ['type' => 'NumberSpinner', 'digits' => 0];
        if (is_float($d[0])) {
            $digits = self::decimalDigits($name, $flat);
            $editor = 'Decimal' . $name;
            // Keep the original numeric property bound to a hidden native field.
            // UpdateFormField changes its pending value; saving still uses Übernehmen.
            return ['type' => 'RowLayout', 'items' => [
                $field + ['type' => 'NumberSpinner', 'digits' => $digits, 'visible' => false],
                ['type' => 'ValidationTextBox', 'name' => $editor, 'caption' => $d[1],
                    'value' => number_format((float)$flat[$name], $digits, ',', ''),
                    'validate' => '^-?[0-9]+(?:,[0-9]{1,' . $digits . '})?$',
                    'onChange' => 'WBM_UpdateDecimalField($id, "' . $name . '", $' . $editor . ');']
            ]];
        }
        return $field + ['type' => 'ValidationTextBox'];
    }
}
