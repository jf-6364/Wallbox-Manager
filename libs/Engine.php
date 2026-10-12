<?php
declare(strict_types=1);
namespace WBM;

final class Engine
{
    public static function plan(array $cfg, array $in, array &$state, float $now): array
    {
        $g = $cfg['global'];
        $state += ['boxes' => [], 'battery' => [], 'overSince' => null];
        $errors = $in['errors'] ?? [];
        $manager = ($in['manager'] ?? false) && !$errors;
        $base = $in['budget'] ?? null;
        if ($base === null || !is_finite($base)) {
            $errors[] = 'Grundbudget ungültig: alle Wallboxen werden gestoppt';
            $manager = false;
            $base = 0.0;
        }
        $base = max(0.0, $base);
        $hard = $g['HardLimitW'] > 0 ? $g['HardLimitW'] : INF;
        $base = min($base, $hard);
        $bat = Battery::available($cfg['battery'], $in['battery'] ?? [], $state['battery'], $manager, $now, $g['Interval']);
        if (str_starts_with($bat['reason'], 'Fehler:')) $errors[] = $bat['reason'];
        $theoreticalBudget = min($hard, $base + $bat['theoretical']);
        $normalBudget = min($hard, $base + $bat['confirmed']);
        $mode = $in['mode'] ?? 0;
        $globalH = Setpoint::watts($g['Hysteresis'], $g['HysteresisUnit']);
        $potential = [];
        $eligible = [];
        $rows = [];
        foreach ($cfg['boxes'] as $id => $c) {
            $state['boxes'][$id] ??= [];
            $s = &$state['boxes'][$id];
            $b = $in['boxes'][$id] ?? [];
            $s += ['power' => 0.0, 'fault' => '', 'lastServed' => 0.0];
            $reason = !$c['Enabled'] ? 'Deaktiviert' : (!$manager || !($b['manual'] ?? true) || !($b['external'] ?? true) ? 'Gesperrt' : (!($b['demand'] ?? true) ? 'Kein Ladebedarf' : ''));
            $fault = ($b['error'] ?? '') ?: $s['fault'];
            if ($fault !== '') $reason = 'Fehler';
            $phases = $c['PhaseMode'] === 'dynamic' ? ($b['phases'] ?? 0) : (int)$c['PhaseMode'];
            if ($c['Enabled'] && !in_array($phases, [1, 3], true)) {
                $fault = 'Phasenrückmeldung ungültig';
                $reason = 'Fehler';
            }
            $eligible[$id] = $reason === '';
            $rows[$id] = ['id' => $id, 'name' => $c['Name'], 'enabled' => $c['Enabled'], 'manual' => $b['manual'] ?? true, 'external' => $b['external'] ?? true,
                'priority' => $b['priority'] ?? $c['Priority'], 'measured' => $b['power'] ?? null, 'phases' => $phases,
                'power' => 0.0, 'command' => $c['Stop'], 'unit' => $c['SetpointUnit'], 'status' => $reason ?: 'Warten auf Leistung',
                'reason' => $fault ?: $reason, 'remaining' => 0, 'phaseCommand' => null, 'fault' => $fault];
            if (!$eligible[$id]) continue;
            $p = Setpoint::limits($c, $c['PhaseMode'] === 'dynamic' ? 3 : $phases);
            if ($c['PhaseMode'] === 'dynamic') {
                $one = Setpoint::limits($c, 1);
                if (!$p['valid']) $p = $one;
                elseif ($one['valid']) {
                    $p['min'] = min($p['min'], $one['min']);
                    $p['quantum'] = min($p['quantum'], $one['quantum']);
                }
            }
            $potential[$id] = $p + self::item($id, $c, $s, $b, $globalH);
            unset($s);
        }
        $shares = Allocator::distribute($theoreticalBudget, $potential, $mode, $now, $g['Rotation']);
        $items = [];
        foreach ($cfg['boxes'] as $id => $c) {
            $s = &$state['boxes'][$id];
            $b = $in['boxes'][$id] ?? [];
            if ($c['Enabled'] && $c['PhaseMode'] === 'dynamic' && $rows[$id]['fault'] === '') {
                $s['phase'] ??= [];
                $phase = PhaseSwitch::step($c, $s['phase'], $b['phases'] ?? 0, $b['power'] ?? null, $b['powerUpdated'] ?? 0, $shares[$id] ?? 0, $eligible[$id] && (($s['power'] ?? 0) <= 0 || $now - ($s['started'] ?? $now) >= $c['MinRun']), $now, $b['phaseUpdated'] ?? 0);
                $rows[$id]['phases'] = $s['phase']['phases'] ?? 0;
                $rows[$id]['phaseCommand'] = $phase['command'];
                if ($phase['blocked']) {
                    $eligible[$id] = false;
                    $rows[$id]['status'] = $phase['fault'] ? 'Fehler' : 'Phasenwechsel';
                    $rows[$id]['reason'] = $phase['fault'] ?: 'Phasenwechsel: ' . $s['phase']['stage'];
                    $rows[$id]['remaining'] = $phase['remaining'];
                    $rows[$id]['fault'] = $phase['fault'];
                }
            }
            if (!$eligible[$id]) {
                Timing::ready($c, $s, false, 0, 1, 0, $now);
                if ($rows[$id]['fault']) $errors[] = $c['Name'] . ': ' . $rows[$id]['fault'];
                continue;
            }
            $l = Setpoint::limits($c, $rows[$id]['phases']);
            if (!$l['valid']) {
                $rows[$id]['reason'] = 'Kein zulässiger aktiver Sollwert im Raster';
                continue;
            }
            $items[$id] = $l + self::item($id, $c, $s, $b, $globalH);
            unset($s);
        }
        $forecast = Allocator::distribute($theoreticalBudget, $items, $mode, $now, $g['Rotation']);
        $current = Allocator::distribute($normalBudget, $items, $mode, $now, $g['Rotation']);
        $ready = [];
        foreach ($items as $id => $item) {
            $s = &$state['boxes'][$id];
            $offer = $item['running'] ? ($current[$id] ?? 0) : ($forecast[$id] ?? 0);
            $t = Timing::ready($cfg['boxes'][$id], $s, true, $offer, $item['min'], 0, $now);
            $rows[$id]['status'] = $t['status'];
            $rows[$id]['remaining'] = $t['remaining'];
            $rows[$id]['reason'] = $t['status'];
            if ($t['ready']) {
                $item['protected'] = $t['protected'];
                $ready[$id] = $item;
            }
            unset($s);
        }
        $wanted = Allocator::distribute($theoreticalBudget, $ready, $mode, $now, $g['Rotation']);
        $batteryRequest = min($bat['theoretical'], max(0.0, array_sum($wanted) - $base));
        $budget = $normalBudget;
        $protectedMinimum = 0.0;
        foreach ($ready as $item) if ($item['running'] && $item['protected']) $protectedMinimum += $item['min'];
        if ($protectedMinimum <= $normalBudget) $state['overSince'] = null;
        if (!$g['StrictBudget'] && $g['OverrunW'] > 0) {
            if ($protectedMinimum > $normalBudget && ($state['overSince'] === null || $now - $state['overSince'] < $g['OverrunSeconds'])) {
                $budget = min($hard, $normalBudget + $g['OverrunW'], $protectedMinimum);
                $ready = array_filter($ready, static fn($i) => $i['running']);
            }
        }
        $assigned = Allocator::distribute($budget, $ready, $mode, $now, $g['Rotation']);
        $sum = array_sum($assigned);
        if ($sum > $normalBudget + 1e-7) {
            if ($state['overSince'] === null) $state['overSince'] = $now;
        }
        foreach ($rows as $id => &$row) {
            $power = $assigned[$id] ?? 0.0;
            $row['power'] = $power;
            $row['command'] = Setpoint::command($power, $cfg['boxes'][$id], in_array($row['phases'], [1, 3], true) ? $row['phases'] : 3);
            if ($power > 0) {
                if ($row['status'] !== 'Nachlauf') $row['status'] = 'Laden';
                $row['reason'] = $row['status'];
            } elseif ($eligible[$id] && isset($ready[$id])) {
                $row['status'] = 'Warten auf Leistung';
                $row['reason'] = 'Budget reicht nicht für die zulässige Mindestladung';
            }
        }
        unset($row);
        return ['manager' => $manager, 'mode' => $mode, 'base' => $base, 'normalBudget' => $normalBudget, 'budget' => $budget,
            'assigned' => $sum, 'overrun' => max(0.0, $sum - $normalBudget), 'battery' => $bat + ['requested' => $batteryRequest],
            'boxes' => $rows, 'errors' => array_values(array_unique($errors)), 'time' => $now];
    }

    private static function item(int $id, array $c, array $s, array $b, float $globalH): array
    {
        return ['id' => $id, 'running' => ($s['power'] ?? 0) > 0, 'protected' => false, 'started' => $s['started'] ?? 0,
            'lastServed' => $s['lastServed'] ?? 0, 'priority' => $b['priority'] ?? $c['Priority'],
            'hysteresis' => $c['HysteresisW'] < 0 ? $globalH : $c['HysteresisW']];
    }
}