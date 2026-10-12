<?php
declare(strict_types=1);
namespace WBM;

final class Setpoint
{
    public static function watts(float $value, string $unit): float
    {
        return $value * ($unit === 'kW' ? 1000.0 : 1.0);
    }

    public static function factor(array $c, int $phases): float
    {
        return match ($c['SetpointUnit']) {
            'A' => $phases * $c['Voltage'],
            'W' => 1.0,
            'kW' => 1000.0,
            default => throw new \InvalidArgumentException('Ungültige Sollwerteinheit')
        };
    }

    public static function limits(array $c, int $phases): array
    {
        if (!in_array($phases, [1, 3], true)) throw new \InvalidArgumentException('Unbestätigte Phasenanzahl');
        $factor = self::factor($c, $phases);
        $q = $c['Step'] * $factor;
        if (!is_finite($q) || $q <= 0) throw new \InvalidArgumentException('Schrittweite muss positiv sein');
        $min = max($c['MinKW'] * 1000.0, $phases * $c['Voltage'] * $c['MinA']);
        $max = min($c['MaxKW'] * 1000.0, $phases * $c['Voltage'] * $c['MaxA']);
        $min = ceil(($min - 1e-7) / $q) * $q;
        $max = floor(($max + 1e-7) / $q) * $q;
        return ['min' => $min, 'max' => $max, 'quantum' => $q, 'factor' => $factor, 'valid' => $min > 0 && $min <= $max + 1e-7];
    }

    public static function floorPower(float $budget, array $limits): float
    {
        $p = min($limits['max'], floor(($budget + 1e-7) / $limits['quantum']) * $limits['quantum']);
        return $limits['valid'] && $p + 1e-7 >= $limits['min'] ? max(0.0, $p) : 0.0;
    }

    public static function command(float $power, array $c, int $phases): float
    {
        return $power <= 0.0 ? (float)$c['Stop'] : $power / self::factor($c, $phases);
    }
}

/** Pure deterministic allocator; all numbers are watts, max. four consumers. */
final class Allocator
{
    public static function distribute(float $budget, array $items, int $mode, float $now, int $rotation): array
    {
        $out = array_fill_keys(array_keys($items), 0.0);
        if (!is_finite($budget) || $budget <= 0) return $out;
        $items = array_filter($items, static fn($i) => $i['valid'] && $i['max'] >= $i['min'] && $i['min'] > 0);
        // Protected running consumers reserve their legal minimum in either mode.
        foreach ($items as $id => $item) {
            if (($item['protected'] ?? false) && ($item['running'] ?? false) && $item['min'] <= $budget + 1e-7) {
                $out[$id] = $item['min'];
                $budget -= $item['min'];
            }
        }
        $groups = [];
        foreach ($items as $id => $item) $groups[$mode === 1 ? $item['priority'] : 0][$id] = $item;
        ksort($groups, SORT_NUMERIC);
        foreach ($groups as $group) {
            $selected = [];
            uasort($group, static function ($a, $b) use ($now, $rotation) {
                $keepA = ($a['running'] ?? false) && ($rotation === 0 || $now - ($a['started'] ?? $now) < $rotation);
                $keepB = ($b['running'] ?? false) && ($rotation === 0 || $now - ($b['started'] ?? $now) < $rotation);
                $keyA = [($a['protected'] ?? false) ? 0 : 1, $keepA ? 0 : 1, $a['lastServed'] ?? 0, $a['id']];
                $keyB = [($b['protected'] ?? false) ? 0 : 1, $keepB ? 0 : 1, $b['lastServed'] ?? 0, $b['id']];
                return $keyA <=> $keyB;
            });
            foreach ($group as $id => $item) {
                if ($out[$id] > 0) {
                    $selected[$id] = $item;
                    continue;
                }
                $threshold = $item['min'] + (($item['running'] ?? false) ? 0 : ($item['hysteresis'] ?? 0));
                if ($threshold <= $budget + 1e-7) {
                    $out[$id] = $item['min'];
                    $budget -= $item['min'];
                    $selected[$id] = $item;
                }
            }
            if (!$selected) continue;
            $pool = $budget + array_sum(array_intersect_key($out, $selected));
            // Max-min fairness with unequal legal minima and saturation limits.
            $lo = 0.0;
            $hi = max(array_column($selected, 'max'));
            for ($j = 0; $j < 64; $j++) {
                $level = ($lo + $hi) / 2;
                $used = 0.0;
                foreach ($selected as $item) $used += min($item['max'], max($item['min'], $level));
                if ($used <= $pool) $lo = $level; else $hi = $level;
            }
            foreach ($selected as $id => $item) $out[$id] = Setpoint::floorPower(max($item['min'], $lo), $item);
            $left = $pool - array_sum(array_intersect_key($out, $selected));
            // Quantization remnants can buy at most sum(quantum) after water filling.
            for ($j = 0; $j < 32; $j++) {
                $best = null;
                foreach ($selected as $id => $item) {
                    if ($item['quantum'] > $left + 1e-7 || $out[$id] + $item['quantum'] > $item['max'] + 1e-7) continue;
                    if ($best === null || [$out[$id], $item['lastServed'] ?? 0, $id] < [$out[$best], $selected[$best]['lastServed'] ?? 0, $best]) $best = $id;
                }
                if ($best === null) break;
                $out[$best] += $selected[$best]['quantum'];
                $left -= $selected[$best]['quantum'];
            }
            $budget = max(0.0, $left);
        }
        return $out;
    }
}