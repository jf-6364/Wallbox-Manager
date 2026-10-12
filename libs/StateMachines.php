<?php
declare(strict_types=1);
namespace WBM;

final class Timing
{
    public static function ready(array $c, array &$s, bool $eligible, float $offer, float $minimum, float $hysteresis, float $now): array
    {
        $running = ($s['power'] ?? 0) > 0;
        if (!$eligible) {
            $s['startSince'] = null;
            $s['deficitSince'] = null;
            return ['ready' => false, 'protected' => false, 'status' => 'Gesperrt', 'remaining' => 0];
        }
        if ($running) {
            $s['startSince'] = null;
            if ($offer + 1e-7 >= $minimum) $s['deficitSince'] = null;
            elseif (($s['deficitSince'] ?? null) === null) $s['deficitSince'] = $now;
            $minRun = max(0, ($s['started'] ?? $now) + $c['MinRun'] - $now);
            $after = ($s['deficitSince'] ?? null) === null ? 0 : max(0, $s['deficitSince'] + $c['StopDelay'] - $now);
            return ['ready' => true, 'protected' => max($minRun, $after) > 0, 'status' => $after > 0 ? 'Nachlauf' : 'Laden', 'remaining' => (int)ceil(max($minRun, $after))];
        }
        $pause = max(0, ($s['stopped'] ?? -1e9) + $c['MinPause'] - $now);
        if ($offer + 1e-7 < $minimum || ($s['startSince'] ?? null) === null && $offer + 1e-7 < $minimum + $hysteresis) {
            $s['startSince'] = null;
            return ['ready' => false, 'protected' => false, 'status' => $pause > 0 ? 'Mindestpause' : 'Warten auf Leistung', 'remaining' => (int)ceil($pause)];
        }
        // Offer selection already includes start hysteresis; continuity requires it too.
        if ($offer + $hysteresis + 1e-7 < $minimum + $hysteresis) $s['startSince'] = null;
        if (($s['startSince'] ?? null) === null) $s['startSince'] = $now;
        $delay = max(0, $s['startSince'] + $c['StartDelay'] - $now);
        return ['ready' => max($pause, $delay) <= 0, 'protected' => false, 'status' => $pause > 0 ? 'Mindestpause' : ($delay > 0 ? 'Einschaltverzögerung' : 'Warten auf Leistung'), 'remaining' => (int)ceil(max($pause, $delay))];
    }
}

final class PhaseSwitch
{
    public static function feedback(array $c, ?float $value): int
    {
        if ($value === null || !is_finite($value)) return 0;
        if (abs($value - $c['Phase1Feedback']) < 1e-6) return 1;
        if (abs($value - $c['Phase3Feedback']) < 1e-6) return 3;
        return 0;
    }

    /** Returned command is emitted by adapter; commandSent() advances only on success. */
    public static function step(array $c, array &$s, int $feedback, ?float $power, float $sampleTime, float $share, bool $allowed, float $now, ?float $feedbackTime = null): array
    {
        $s += ['stage' => 'idle', 'phases' => $feedback, 'changed' => $now, 'fault' => '', 'candidateSince' => null];
        $result = ['blocked' => false, 'command' => null, 'remaining' => 0, 'fault' => ''];
        if ($s['fault'] !== '') return array_replace($result, ['blocked' => true, 'fault' => $s['fault']]);
        if ($feedback === 0) return self::fault($s, 'Phasenrückmeldung fehlt oder ist widersprüchlich');
        if ($power === null) return self::fault($s, 'Ladeleistung für sicheren Phasenwechsel ungültig');
        if ($s['stage'] === 'idle') {
            if ($s['phases'] !== $feedback) return self::fault($s, 'Phasen ohne angeforderten Wechsel geändert');
            $target = $feedback;
            if ($feedback === 1 && $share >= $c['PhaseUpW'] + $c['PhaseHysteresisW']) $target = 3;
            if ($feedback === 3 && $share <= $c['PhaseDownW'] - $c['PhaseHysteresisW']) $target = 1;
            if (!$allowed || $target === $feedback || $now - $s['changed'] < $c['PhaseDwell']) {
                $s['candidateSince'] = null;
                return $result;
            }
            if (($s['candidate'] ?? null) !== $target || $s['candidateSince'] === null) {
                $s['candidate'] = $target;
                $s['candidateSince'] = $now;
            }
            if ($now - $s['candidateSince'] < $c['PhaseDelay']) return $result;
            $s['target'] = $target;
            $s['stage'] = 'stopping';
            $s['since'] = $now;
            $s['zeroSince'] = null;
        }
        $result['blocked'] = true;
        if (!$allowed) {
            // A sent switch still has to be confirmed. No new switch when locked.
            if (in_array($s['stage'], ['stopping', 'before', 'send'], true)) {
                $s['stage'] = 'idle';
                $s['candidateSince'] = null;
                return $result;
            }
        }
        if (in_array($s['stage'], ['stopping', 'before', 'send'], true) && $feedback !== $s['phases']) return self::fault($s, 'Unerwartete Rückmeldung vor Phasenbefehl');
        if ($s['stage'] === 'stopping') {
            // Ignore samples from before the stop request; actual low load must be fresh.
            if ($power <= $c['PhaseRestW'] && $sampleTime >= floor($s['since'])) {
                if ($s['zeroSince'] === null) $s['zeroSince'] = $now;
                if ($now - $s['zeroSince'] >= $c['PhaseZeroSeconds']) {
                    $s['stage'] = 'before';
                    $s['since'] = $now;
                }
            } else $s['zeroSince'] = null;
            if ($s['stage'] === 'stopping' && $now - $s['since'] >= $c['PhaseTimeout']) return self::fault($s, 'Timeout: Stillstand nicht bestätigt');
        }
        if ($s['stage'] === 'before') {
            if ($power > $c['PhaseRestW']) return self::fault($s, 'Last während Wartezeit vor Phasenwechsel');
            $result['remaining'] = (int)ceil(max(0, $s['since'] + $c['PhaseBefore'] - $now));
            if ($result['remaining'] === 0) $s['stage'] = 'send';
        }
        if ($s['stage'] === 'send' && $allowed) {
            if ($power > $c['PhaseRestW']) return self::fault($s, 'Phasenbefehl unter Last verhindert');
            $result['command'] = $s['target'] === 1 ? $c['Phase1Command'] : $c['Phase3Command'];
        }
        if ($s['stage'] === 'feedback') {
            if ($power > $c['PhaseRestW']) return self::fault($s, 'Last während Phasenwechsel');
            if ($feedback === $s['target'] && ($feedbackTime ?? $sampleTime) >= floor($s['sent'])) {
                $s['phases'] = $feedback;
                $s['stage'] = 'after';
                $s['since'] = $now;
                $s['changed'] = $now;
            } elseif ($now - $s['sent'] >= $c['PhaseTimeout']) return self::fault($s, 'Timeout: Zielphase nicht bestätigt');
        }
        if ($s['stage'] === 'after') {
            if ($feedback !== $s['target'] || $power > $c['PhaseRestW']) return self::fault($s, 'Rückmeldung oder Last nach Wechsel widersprüchlich');
            $result['remaining'] = (int)ceil(max(0, $s['since'] + $c['PhaseAfter'] - $now));
            if ($result['remaining'] === 0) {
                $s['stage'] = 'idle';
                $s['candidateSince'] = null;
                $result['blocked'] = !$allowed;
            }
        }
        return $result;
    }

    public static function commandSent(array &$s, float $now): void
    {
        $s['stage'] = 'feedback';
        $s['sent'] = $now;
    }

    private static function fault(array &$s, string $reason): array
    {
        $s['fault'] = $reason;
        $s['stage'] = 'fault';
        return ['blocked' => true, 'command' => null, 'remaining' => 0, 'fault' => $reason];
    }
}

final class Battery
{
    public static function available(array $c, array $in, array &$s, bool $manager, float $now, int $interval): array
    {
        $s += ['reserve' => false, 'requested' => 0.0, 'requestAt' => $now, 'fault' => '', 'shortSince' => null];
        $soc = $in['soc'] ?? null;
        $result = ['theoretical' => 0.0, 'confirmed' => 0.0, 'energy' => 0.0, 'soc' => $soc, 'reason' => 'Deaktiviert', 'external' => $in['external'] ?? true, 'measured' => $in['power'] ?? null];
        if (!$c['Enabled'] || !$manager || !($in['manual'] ?? true) || !($in['external'] ?? true)) {
            $result['reason'] = $c['Enabled'] ? 'Gesperrt' : 'Deaktiviert';
            return $result;
        }
        if ($s['fault'] !== '' || ($in['error'] ?? '') !== '') {
            $result['reason'] = 'Fehler: ' . ($s['fault'] ?: $in['error']);
            return $result;
        }
        if ($soc === null || !is_finite($soc) || $soc < 0 || $soc > 100) {
            $result['reason'] = 'Fehler: SoC ungültig';
            return $result;
        }
        if ($soc <= $c['MinSoc']) $s['reserve'] = true;
        if ($s['reserve'] && $soc >= $c['MinSoc'] + $c['SocHysteresis'] && $soc > $c['MinSoc']) $s['reserve'] = false;
        $result['energy'] = max(0.0, $c['CapacityKWh'] * ($soc - $c['MinSoc']) / 100.0);
        if ($s['reserve']) {
            $result['reason'] = 'SoC-Reserve / Hysterese';
            return $result;
        }
        $limit = $in['limit'] ?? INF;
        if (!is_finite($limit) && $limit !== INF || $limit < 0) {
            $result['reason'] = 'Fehler: Entladeleistungsgrenze ungültig';
            return $result;
        }
        $horizon = max(1, $interval, $c['StartDelay'], $c['ResponseTimeout']);
        $max = min(Setpoint::watts($c['MaxPower'], $c['MaxPowerUnit']), $limit, $result['energy'] * 3600000.0 / $horizon);
        if (!$c['PowerVar']) $max *= $c['NoFeedbackFraction'];
        $result['theoretical'] = max(0.0, $max);
        $requested = min($max, $s['requested']);
        if ($c['PowerVar']) {
            $measured = $in['power'] ?? null;
            if ($measured === null || !is_finite($measured)) {
                $result['theoretical'] = 0;
                $result['reason'] = 'Fehler: Speichermessung ungültig';
                return $result;
            }
            // Measurement is confirmation, never added to theoretical capacity again.
            $result['confirmed'] = min($requested, max(0.0, $measured));
            if ($measured + $c['ToleranceW'] < $s['requested']) {
                if ($s['shortSince'] === null) $s['shortSince'] = $now;
                if ($now - $s['shortSince'] >= $c['ResponseTimeout']) {
                    $s['fault'] = 'Angeforderte Speicherleistung dauerhaft nicht geliefert';
                    $result['theoretical'] = $result['confirmed'] = 0.0;
                    $result['reason'] = 'Fehler: ' . $s['fault'];
                    return $result;
                }
            } else $s['shortSince'] = null;
        } elseif ($now - $s['requestAt'] >= $c['StartDelay']) $result['confirmed'] = $requested;
        $result['reason'] = $result['confirmed'] > 0 ? 'Unterstützung verfügbar' : ($s['requested'] > 0 ? 'Warten auf Speicher' : 'Bereit');
        return $result;
    }

    public static function command(float $watts, array $c): float
    {
        return $watts <= 0 ? (float)$c['Stop'] : $c['Sign'] * $watts / ($c['SetpointUnit'] === 'kW' ? 1000.0 : 1.0);
    }
}