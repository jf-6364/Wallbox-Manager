<?php
declare(strict_types=1);
namespace WBM;

/** Session accounting uses measured power, independently of lifetime meter/reset state. */
final class Billing
{
    public static function note(array &$session, string $message): void
    {
        $session['warnings'] ??= [];
        if ($message !== '' && !in_array($message, $session['warnings'], true) && count($session['warnings']) < 12) $session['warnings'][] = $message;
    }

    public static function totals(): array
    {
        return ['pvEnergy' => 0.0, 'normalEnergy' => 0.0, 'unassignedEnergy' => 0.0,
            'pvCost' => 0.0, 'normalCost' => 0.0, 'totalCost' => 0.0, 'sessions' => 0];
    }

    public static function observe(int $id, array $c, array &$s, ?float $power, float $sample, float $now, int $maxGap,
        ?float $fresh, ?bool $pv, string $timezone, array $invoiceProfile = []): void
    {
        $s += ['active' => null, 'integrator' => [], 'pending' => [], 'totals' => self::totals(), 'idleSince' => null, 'last' => null, 'error' => ''];
        if ($s['active'] && $invoiceProfile) $s['active']['invoiceProfile'] = $invoiceProfile;
        $identity = ($c['DeviceKey'] ?: 'auto') . ':' . $c['SetpointVar'] . ':' . $c['PowerVar'];
        $validSettings = is_finite($c['BillingPVPrice']) && is_finite($c['BillingNormalPrice']) && is_finite($c['BillingMinPowerW'])
            && $c['BillingPVPrice'] >= 0 && $c['BillingNormalPrice'] >= 0 && $c['BillingMinPowerW'] >= 0
            && $c['BillingIdleSeconds'] >= 1 && $c['BillingIdleSeconds'] <= 604800;
        if (!$c['Enabled'] || !$c['BillingEnabled'] || !$validSettings || ($s['identity'] ?? $identity) !== $identity) {
            self::finish($s, $now, ($s['identity'] ?? $identity) !== $identity ? 'Gerätewechsel' : 'Abrechnung deaktiviert oder umkonfiguriert');
            $s['integrator'] = []; $s['context'] = null; $s['idleSince'] = null;
            $s['identity'] = $identity;
            if (!$c['Enabled'] || !$c['BillingEnabled'] || !$validSettings) return;
        }
        $s['identity'] = $identity;
        $context = ['mode' => $pv === null ? 'unassigned' : ($pv ? 'pv' : 'normal'),
            'price' => $pv === null ? 0.0 : round($pv ? $c['BillingPVPrice'] : $c['BillingNormalPrice'], 2)];
        $previousContext = $s['context'] ?? $context;
        $s['context'] = $context;
        $validPower = $power !== null && is_finite($power) && $power >= 0 && is_finite($sample) && $sample <= $now + 1;
        // A stale positive sample cannot start a session or bridge a telemetry outage.
        if ($validPower && $power > $c['BillingMinPowerW'] && $now - ($fresh ?? $sample) > $maxGap) $validPower = false;
        $watts = $validPower ? ($power > $c['BillingMinPowerW'] ? $power : 0.0) : null;
        $wasCharging = ($s['integrator']['power'] ?? 0.0) > 0;
        $result = Energy::power($s['integrator'], 'billing:' . $identity . ':' . $c['PowerUnit'], $watts, $sample, $now, $maxGap, $fresh);
        // A late positive sample after a completed idle timeout starts a new session.
        if ($s['active'] && $s['idleSince'] !== null && $now >= $s['idleSince'] + $c['BillingIdleSeconds']
            && $watts !== null && $watts > 0 && $sample >= $s['idleSince'] + $c['BillingIdleSeconds']) {
            self::finish($s, $s['idleSince'], 'Eingestellte Ladepause erreicht');
        }
        if (!$s['active'] && ($result['delta'] > 0 || ($watts !== null && $watts > 0))) {
            $started = $result['segments'] ? $result['segments'][0]['from'] : $now;
            $s['active'] = ['id' => bin2hex(random_bytes(10)), 'wallbox' => $id, 'name' => $c['Name'], 'device' => $identity,
                'started' => $started, 'timezone' => $timezone, 'lines' => [], 'warnings' => [], 'method' => 'berechnet', 'invoiceProfile' => $invoiceProfile];
        }
        if ($s['active']) {
            if ($result['gap'] !== '' && ($wasCharging || $watts === null || $watts > 0) && $result['gap'] !== 'Neue Messbasis') self::note($s['active'], $result['gap']);
            if ($result['delta'] > 0) {
                $mode = $previousContext['mode'];
                $key = $mode . ':' . number_format($previousContext['price'], 2, '.', '');
                $s['active']['lines'][$key] ??= ['mode' => $mode, 'price' => $previousContext['price'], 'energy' => 0.0];
                $s['active']['lines'][$key]['energy'] += $result['delta'];
                $s['totals'][$mode . 'Energy'] += $result['delta'];
                if ($mode === 'unassigned') self::note($s['active'], 'PV-Boolvariable ungültig: unzugeordnete Energie wird ohne Preis ausgewiesen und muss geprüft werden.');
            }
            if ($watts === null) {
                // Missing telemetry is never evidence that charging has ended.
                $s['idleSince'] = null;
            } elseif ($watts > 0) $s['idleSince'] = null;
            else {
                if ($s['idleSince'] === null || $s['idleSince'] > $now) $s['idleSince'] = $now;
                if ($now >= $s['idleSince'] + $c['BillingIdleSeconds']) self::finish($s, $s['idleSince'], 'Eingestellte Ladepause erreicht');
            }
        }
    }

    public static function record(array $session): array
    {
        $out = array_replace($session, ['pvEnergy' => 0.0, 'normalEnergy' => 0.0, 'unassignedEnergy' => 0.0,
            'pvCost' => 0.0, 'normalCost' => 0.0, 'totalEnergy' => 0.0, 'totalCost' => 0.0]);
        $out['lines'] = array_values($session['lines']);
        foreach ($out['lines'] as &$line) {
            $line['cost'] = round($line['energy'] * $line['price'], 2);
            $out[$line['mode'] . 'Energy'] += $line['energy'];
            $out['totalEnergy'] += $line['energy'];
            if ($line['mode'] !== 'unassigned') $out[$line['mode'] . 'Cost'] += $line['cost'];
            $out['totalCost'] += $line['cost'];
        }
        unset($line);
        $out['totalCost'] = round($out['totalCost'], 2);
        return $out;
    }

    public static function finish(array &$s, float $ended, string $reason): void
    {
        if (!($s['active'] ?? null)) return;
        $record = self::record($s['active']);
        $record['ended'] = max($record['started'], $ended);
        $record['reason'] = $reason;
        $record['mediaID'] = 0;
        // A baseline at positive power without any subsequent valid interval is not a bill.
        if ($record['totalEnergy'] > 0) {
            $s['pending'][$record['id']] = $record;
            $s['last'] = $record;
            foreach (['pvCost', 'normalCost', 'totalCost'] as $key) $s['totals'][$key] += $record[$key];
            $s['totals']['sessions']++;
        }
        $s['active'] = null; $s['idleSince'] = null;
    }

    public static function summary(array $s): array
    {
        $active = ($s['active'] ?? null) ? self::record($s['active']) : null;
        $totals = ($s['totals'] ?? []) + self::totals();
        if ($active) foreach (['pvCost', 'normalCost', 'totalCost'] as $key) $totals[$key] += $active[$key];
        return ['active' => $active, 'last' => $s['last'] ?? null, 'totals' => $totals,
            'pending' => count($s['pending'] ?? []), 'error' => $s['error'] ?? '', 'idleSince' => $s['idleSince'] ?? null,
            'mode' => $s['context']['mode'] ?? 'normal'];
    }
}
