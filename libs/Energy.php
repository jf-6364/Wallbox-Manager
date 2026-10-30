<?php
declare(strict_types=1);
namespace WBM;

final class Energy
{
    /**
     * Zero-order hold: old power until the new sample time, new power thereafter.
     * Cursor is shared by events and timer, so each interval is counted once.
     */
    public static function power(array &$s, string $source, ?float $power, float $sample, float $now, int $maxGap, ?float $fresh = null): array
    {
        $s += ['energy' => 0.0];
        $delta = 0.0;
        $segments = [];
        $gap = '';
        if (($s['source'] ?? '') !== $source || !isset($s['cursor'])) {
            $s = array_replace($s, ['source' => $source, 'cursor' => $now, 'sample' => $sample, 'power' => $power]);
            return ['delta' => 0.0, 'gap' => 'Neue Messbasis', 'method' => 'berechnet', 'segments' => []];
        }
        $cursor = $s['cursor'];
        if ($now < $cursor || $now - $cursor > $maxGap || $power === null || !is_finite($power) || $power < 0 || $sample > $now + 1 || $now - ($fresh ?? $sample) > $maxGap || ($s['power'] ?? null) === null) {
            $gap = 'Messlücke – Zeitabschnitt nicht integriert';
        } else {
            $change = max($cursor, min($now, $sample));
            $delta = (max(0.0, $s['power']) * ($change - $cursor) + $power * ($now - $change)) / 3600000.0;
            foreach ([[$cursor, $change, max(0.0, $s['power'])], [$change, $now, $power]] as [$from, $to, $watts]) {
                if ($to > $from && $watts > 0) $segments[] = ['from' => $from, 'to' => $to, 'energy' => $watts * ($to - $from) / 3600000.0];
            }
        }
        $s['energy'] += max(0.0, $delta);
        $s['cursor'] = $now;
        $s['sample'] = $sample;
        $s['power'] = $power;
        return ['delta' => max(0.0, $delta), 'gap' => $gap, 'method' => 'berechnet', 'segments' => $segments];
    }

    public static function meter(array &$s, string $source, ?float $value, float $now, float $maxPower, float $factor, float $slack): array
    {
        $s += ['energy' => 0.0];
        $delta = 0.0;
        $gap = '';
        $valid = $value !== null && is_finite($value) && $value >= 0;
        $newBasis = !$valid || ($s['source'] ?? '') !== $source || ($s['meter'] ?? null) === null;
        if (!$valid) {
            $gap = 'Energiezähler ungültig';
            // Next valid sample is a new baseline, not a fabricated consumption.
            $s['meter'] = null;
        } elseif (($s['source'] ?? '') !== $source || ($s['meter'] ?? null) === null) $gap = 'Neue Zählerbasis';
        else {
            $diff = $value - $s['meter'];
            $allowed = max(0.0, $now - ($s['meterTime'] ?? $s['cursor'] ?? $now)) * $maxPower * $factor / 3600000.0 + $slack;
            if ($diff < 0) { $gap = 'Zähler zurückgesetzt – neue Basis'; $newBasis = true; }
            elseif ($diff > $allowed + 1e-9) { $gap = 'Unplausibler Zählersprung – neue Basis'; $newBasis = true; }
            else $delta = $diff;
        }
        $s['energy'] += max(0.0, $delta);
        if ($newBasis || $value !== ($s['meter'] ?? null)) $s['meterTime'] = $now;
        $s['source'] = $source;
        $s['meter'] = $valid ? $value : null;
        $s['cursor'] = $now;
        return ['delta' => max(0.0, $delta), 'gap' => $gap, 'method' => 'Gerätezähler'];
    }

    /** Device identity differs from metering source; archive previous slot lifetime. */
    public static function identify(array &$s, string $identity): bool
    {
        if (!isset($s['identity'])) {
            $s['identity'] = $identity;
            return false;
        }
        if ($s['identity'] === $identity) return false;
        $archive = $s['archive'] ?? [];
        $archive[] = ['identity' => $s['identity'], 'energy' => $s['energy'] ?? 0.0];
        $s = ['identity' => $identity, 'energy' => 0.0, 'archive' => $archive];
        return true;
    }
}
