<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/bootstrap.php';

use WBM\{Schema, Validation, Form, Setpoint, Engine, PhaseSwitch, Battery, Energy};

class WallboxManager extends IPSModule
{
    private const MODULE_ID = '{8EDC0388-D880-4518-A44B-91A1A3CF7086}';
    // The Symcon SDK uses 1 for HTML tiles; it provides no global HTML constant.
    private const VISUALIZATION_TYPE_HTML = 1;

    public function Create()
    {
        parent::Create();
        foreach (Schema::definitions() as $key => $def) {
            $method = 'RegisterProperty' . (is_bool($def[0]) ? 'Boolean' : (is_int($def[0]) ? 'Integer' : (is_float($def[0]) ? 'Float' : 'String')));
            $this->$method($key, $def[0]);
        }
        foreach (['Runtime', 'Configuration', 'Telemetry', 'OwnedTargets'] as $key) $this->RegisterAttributeString($key, '{}');
        $this->RegisterTimer('Control', 0, 'WBM_Control($_IPS["TARGET"]);');
        $this->RegisterTimer('Deferred', 0, 'WBM_Control($_IPS["TARGET"]);');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->SetBuffer('ColdStart', '1');
        $this->profiles();
        $this->variables();
        $this->SetVisualizationType(self::VISUALIZATION_TYPE_HTML);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) return;
        if (!IPS_SemaphoreEnter($this->lockName(), 1000)) {
            $this->SetTimerInterval('Deferred', 200);
            return;
        }
        try {
            $flat = $this->configuration();
            $previous = $this->jsonAttribute('Configuration');
            $state = $this->runtime();
            $now = microtime(true);
            $cold = $this->GetBuffer('ColdStart') === '1';
            $this->profiles();
            $this->variables();
            foreach (['Enabled' => 'ManagerEnabled', 'Mode' => 'DistributionMode'] as $prop => $ident) {
                if (!$previous || $previous[$prop] !== $flat[$prop]) $this->SetValue($ident, $flat[$prop]);
            }
            for ($i = 1; $i <= 4; $i++) {
                if (!$previous || $previous['W' . $i . 'Priority'] !== $flat['W' . $i . 'Priority']) $this->SetValue('W' . $i . 'Priority', $flat['W' . $i . 'Priority']);
            }
            $cfg = Schema::unpack($flat);
            $old = $previous ? Schema::unpack($previous) : null;
            for ($i = 1; $i <= 4; $i++) {
                $c = $cfg['boxes'][$i];
                $oldC = $old['boxes'][$i] ?? null;
                $changed = $oldC !== null && $this->deviceIdentity($oldC) !== $this->deviceIdentity($c);
                if ($oldC && $oldC['Enabled'] && ($changed || !$c['Enabled'])) $this->retire($state, 'W' . $i . '-alt', $oldC, $previous['Simulation'], $now);
                if ($c['Enabled'] && ($cold || !$oldC || !$oldC['Enabled'] || $changed)) $this->retire($state, 'W' . $i . '-start', $c, $flat['Simulation'], $now);
                if ($cold || $changed || !$oldC || !$c['Enabled'] || !$oldC['Enabled']) {
                    $phase = $state['boxes'][$i]['phase'] ?? [];
                    $fault = $state['boxes'][$i]['fault'] ?? '';
                    $state['boxes'][$i] = ['power' => 0.0, 'stopped' => $now, 'startSince' => null, 'lastServed' => $changed ? 0.0 : ($state['boxes'][$i]['lastServed'] ?? 0.0), 'fault' => $changed ? '' : $fault, 'phase' => $changed ? [] : $phase];
                }
                // Integration never bridges a Symcon outage. Device meters can bridge it.
                if ($cold && !$c['EnergyVar']) unset($state['energy'][$i]['cursor']);
            }
            $b = $cfg['battery'];
            $oldB = $old['battery'] ?? null;
            if ($oldB && $oldB['Enabled'] && (!$b['Enabled'] || $oldB['SetpointVar'] !== $b['SetpointVar'] || $oldB['Stop'] !== $b['Stop'] || $oldB['Sign'] !== $b['Sign'] || $oldB['SetpointUnit'] !== $b['SetpointUnit'])) {
                $this->retireBattery($state, 'B-alt', $oldB, $previous['Simulation'], $now);
            }
            if ($b['Enabled'] && ($cold || !$oldB || !$oldB['Enabled'] || $oldB['SetpointVar'] !== $b['SetpointVar'])) $this->retireBattery($state, 'B-start', $b, $flat['Simulation'], $now);
            if ($cold || !$b['Enabled']) {
                $state['battery']['requested'] = 0.0;
                $state['battery']['requestAt'] = $now;
                $state['battery']['shortSince'] = null;
            }
            $this->WriteAttributeString('Configuration', $this->encode($flat));
            $this->saveRuntime($state);
            $this->SetBuffer('ColdStart', '0');
            $this->references($flat, $state);
            $this->SetTimerInterval('Control', max(1, min(3600, $flat['Interval'])) * 1000);
            $this->SetVisualizationType(self::VISUALIZATION_TYPE_HTML);
        } finally {
            IPS_SemaphoreLeave($this->lockName());
        }
        $this->Control();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE) {
            if (IPS_GetKernelRunlevel() === KR_READY) $this->ApplyChanges();
            return;
        }
        // The SDK timestamp is a sequence number, not a Unix timestamp.
        // VariableUpdated and real wall time are used when reading measurements.
        if (IPS_GetKernelRunlevel() !== KR_READY) return;
        if ($Message === VM_UPDATE) {
            // Process every measurement event, including unchanged updates.
            $flat = $this->configuration();
            $isMeasurement = false;
            for ($i = 1; $i <= 4; $i++) {
                if (in_array((int)$SenderID, [(int)$flat['W' . $i . 'PowerVar'], (int)$flat['W' . $i . 'EnergyVar']], true)) $isMeasurement = true;
            }
            if ($isMeasurement && IPS_SemaphoreEnter($this->lockName(), 0)) {
                try {
                    $state = $this->runtime();
                    $this->accountEnergy(Schema::unpack($flat), $state, microtime(true));
                    $this->saveRuntime($state);
                } finally { IPS_SemaphoreLeave($this->lockName()); }
            }
        }
        $this->SetTimerInterval('Deferred', 200);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Refresh') {
            $this->UpdateVisualizationValue($this->ReadAttributeString('Telemetry'));
            return;
        }
        $bools = ['ManagerEnabled', 'BatteryEnabled'];
        for ($i = 1; $i <= 4; $i++) $bools[] = 'W' . $i . 'Manual';
        if (in_array($Ident, $bools, true)) {
            if (!is_bool($Value)) throw new InvalidArgumentException('Freigabe muss boolesch sein');
        } elseif ($Ident === 'DistributionMode') {
            if (!is_int($Value) || !in_array($Value, [0, 1], true)) throw new InvalidArgumentException('Ungültiger Verteilungsmodus');
        } elseif (preg_match('/^W[1-4]Priority$/', $Ident)) {
            if (!is_int($Value) || $Value < 1 || $Value > 999) throw new InvalidArgumentException('Priorität muss 1 bis 999 sein');
        } else throw new InvalidArgumentException('Unbekannte Aktion: ' . $Ident);
        if (!IPS_SemaphoreEnter($this->lockName(), 1000)) throw new RuntimeException('Regelung beschäftigt; Aktion erneut ausführen');
        try { $this->SetValue($Ident, $Value); }
        finally { IPS_SemaphoreLeave($this->lockName()); }
        $this->Control();
    }

    public function Control(): void
    {
        $this->SetTimerInterval('Deferred', 0);
        if (IPS_GetKernelRunlevel() !== KR_READY) return;
        $flat = $this->configuration();
        if ($this->jsonAttribute('Configuration') !== $flat || $this->GetBuffer('ColdStart') === '1') {
            $this->ApplyChanges();
            return;
        }
        if (!IPS_SemaphoreEnter($this->lockName(), 0)) {
            $this->SetTimerInterval('Deferred', 200);
            return;
        }
        if (!IPS_SemaphoreEnter('WBM_DeviceOwnership', 0)) {
            IPS_SemaphoreLeave($this->lockName());
            $this->SetTimerInterval('Deferred', 200);
            return;
        }
        $state = $this->runtime();
        $cfg = Schema::unpack($flat);
        $now = microtime(true);
        try {
            $validation = $this->validate($flat);
            if (!$validation) $this->claimTargets($flat, $state);
            $retired = $this->processRetired($state, $now);
            $this->accountEnergy($cfg, $state, $now);
            $in = $this->inputs($cfg, $now);
            $in['errors'] = array_merge($in['errors'], $validation, $retired);
            $plan = Engine::plan($cfg, $in, $state, $now);
            $this->outputs($cfg, $in, $plan, $state, $now);
            $this->publish($cfg, $in, $plan, $state, $validation);
            $this->references($flat, $state);
            if (!$validation) $this->claimTargets($flat, $state);
            $this->saveRuntime($state);
        } catch (Throwable $e) {
            $state['lastException'] = $e->getMessage();
            // An unexpected failure must still attempt explicit configured stops.
            foreach ($cfg['boxes'] as $id => $c) if ($c['Enabled']) $this->writeBox($id, $c, 0, (int)$c['PhaseMode'] === 1 ? 1 : 3, $state, $now, true);
            if ($cfg['battery']['Enabled']) $this->writeBattery($cfg['battery'], 0, $state, $now, true);
            $this->SetValue('Status', 'Fehler: ' . $e->getMessage());
            $this->SetStatus(201);
            $this->saveRuntime($state);
            $telemetry = $this->jsonAttribute('Telemetry');
            $telemetry['errors'] = ['Regeldurchlauf fehlgeschlagen: ' . $e->getMessage()];
            $telemetry['status'] = 'Fehler';
            $this->WriteAttributeString('Telemetry', $this->encode($telemetry));
            $this->UpdateVisualizationValue($this->encode($telemetry));
            $this->SendDebug('Regelfehler', $e->getMessage(), 0);
        } finally { IPS_SemaphoreLeave('WBM_DeviceOwnership'); IPS_SemaphoreLeave($this->lockName()); }
    }

    public function ControlTargets(): string
    {
        return $this->ReadAttributeString('OwnedTargets');
    }

    public function GetConfigurationForm()
    {
        $flat = $this->configuration();
        return $this->encode(Form::build($flat, $this->validate($flat), $this->GetValue('Status')));
    }

    public function UpdateDecimalField(string $Name, string $Value): void
    {
        $flat = $this->configuration();
        $number = Form::decimalValue($Name, $Value, $flat);
        if ($number === null) return;
        $this->UpdateFormField($Name, 'value', $number);
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/tile.html');
        return str_replace(['/*__CSS__*/', '/*__JS__*/', '/*__DATA__*/'],
            [file_get_contents(__DIR__ . '/tile.css'), file_get_contents(__DIR__ . '/tile.js'),
                json_encode($this->jsonAttribute('Telemetry'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)], $html);
    }

    public function Diagnose(): string
    {
        return json_encode(['configurationErrors' => $this->validate($this->configuration()), 'telemetry' => $this->jsonAttribute('Telemetry'),
            'runtime' => $this->runtime()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public function ClearFaults(): void
    {
        if (!IPS_SemaphoreEnter($this->lockName(), 1000)) throw new RuntimeException('Regelung beschäftigt');
        try {
            $state = $this->runtime();
            foreach ($state['boxes'] as &$s) {
                $s['fault'] = '';
                // Stop handshake will be retried. Re-read phase after a fault.
                if (($s['phase']['fault'] ?? '') !== '') $s['phase'] = [];
            }
            unset($s);
            $state['battery']['fault'] = '';
            $state['battery']['shortSince'] = null;
            $state['lastException'] = '';
            $this->saveRuntime($state);
        } finally { IPS_SemaphoreLeave($this->lockName()); }
        $this->Control();
    }

    public function ResetEnergy(string $Confirmation): bool
    {
        if ($Confirmation !== 'ENERGIE ZURÜCKSETZEN') throw new InvalidArgumentException('Bestätigungstext stimmt nicht');
        if (!IPS_SemaphoreEnter($this->lockName(), 1000)) throw new RuntimeException('Regelung beschäftigt');
        try {
            $state = $this->runtime();
            $state['energy'] = [];
            $state['totalEnergy'] = 0.0;
            $this->saveRuntime($state);
        } finally { IPS_SemaphoreLeave($this->lockName()); }
        $this->Control();
        return true;
    }

    public function Destroy()
    {
        // Destroy is also called on library updates, so explicitly release devices.
        if (IPS_GetKernelRunlevel() === KR_READY && IPS_SemaphoreEnter($this->lockName(), 1000)) {
            try {
                $state = $this->runtime();
                $cfg = Schema::unpack($this->configuration());
                $now = microtime(true);
                foreach ($cfg['boxes'] as $id => $c) if ($c['Enabled']) $this->writeBox($id, $c, 0, 3, $state, $now, true);
                if ($cfg['battery']['Enabled']) $this->writeBattery($cfg['battery'], 0, $state, $now, true);
                $this->processRetired($state, $now);
                $this->saveRuntime($state);
            } finally { IPS_SemaphoreLeave($this->lockName()); }
        }
        parent::Destroy();
    }

    private function configuration(): array
    {
        $flat = [];
        foreach (Schema::definitions() as $key => $def) {
            $method = 'ReadProperty' . (is_bool($def[0]) ? 'Boolean' : (is_int($def[0]) ? 'Integer' : (is_float($def[0]) ? 'Float' : 'String')));
            $flat[$key] = $this->$method($key);
        }
        return $flat;
    }

    private function runtime(): array
    {
        return $this->jsonAttribute('Runtime') + ['boxes' => [], 'battery' => [], 'commands' => [], 'history' => [], 'retired' => [], 'energy' => [], 'totalEnergy' => 0.0];
    }
    private function jsonAttribute(string $key): array { return json_decode($this->ReadAttributeString($key), true, 512, JSON_THROW_ON_ERROR) ?: []; }
    private function encode(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
    private function saveRuntime(array $state): void { $this->WriteAttributeString('Runtime', $this->encode($state)); }
    private function lockName(): string { return 'WBM_' . $this->InstanceID; }

    private function inspect(int $id): ?array
    {
        if (!$id || !IPS_VariableExists($id)) return null;
        $v = IPS_GetVariable($id);
        $obj = IPS_GetObject($id);
        $owner = $obj['ParentID'];
        while ($owner && $owner !== $this->InstanceID) {
            $parent = IPS_GetObject($owner);
            if ($parent['ObjectType'] === 1) break;
            $owner = $parent['ParentID'];
        }
        // ObjectIsReadOnly protects object configuration, not RequestAction.
        return ['type' => $v['VariableType'], 'action' => HasAction($id), 'locked' => ($v['VariableIsLocked'] ?? false),
            'owner' => $owner, 'updated' => $v['VariableUpdated'], 'value' => GetValue($id)];
    }

    private function validate(array $flat): array
    {
        $other = $this->otherTargets();
        return array_merge(Validation::properties($flat), Validation::links($flat, fn($id) => $this->inspect($id), $this->InstanceID, $other));
    }

    private function otherTargets(): array
    {
        $out = [];
        foreach (IPS_GetInstanceListByModuleID(self::MODULE_ID) as $id) {
            if ($id === $this->InstanceID) continue;
            $targets = json_decode(WBM_ControlTargets($id), true, 512, JSON_THROW_ON_ERROR) ?: [];
            foreach ($targets as $target) $out[] = (int)$target;
        }
        return array_values(array_unique($out));
    }

    private function claimTargets(array $flat, array $state): void
    {
        $targets = [];
        foreach (Schema::links($flat) as $link) if ($link['write'] && $link['id']) $targets[] = $link['id'];
        foreach ($state['retired'] as $r) {
            $targets[] = $r['target'];
            if ($r['enable']) $targets[] = $r['enable'];
        }
        $this->WriteAttributeString('OwnedTargets', $this->encode(array_values(array_unique($targets))));
    }
    private function number(int $id, int $age, float $now, bool $nonnegative = true): array
    {
        $v = $this->inspect($id);
        if (!$v || !in_array($v['type'], [1, 2], true) || !is_numeric($v['value']) || !is_finite((float)$v['value'])) return [null, 0.0, 'Numerische Variable ungültig: ' . $id];
        if ($age > 0 && $now - $v['updated'] > $age) return [null, (float)$v['updated'], 'Messwert/Heartbeat veraltet: ' . $id];
        if ($nonnegative && $v['value'] < 0) return [null, (float)$v['updated'], 'Negativer Messwert: ' . $id];
        return [(float)$v['value'], (float)$v['updated'], ''];
    }
    private function boolean(int $id, bool $fallback = true): bool
    {
        if (!$id) return $fallback;
        $v = $this->inspect($id);
        return $v !== null && $v['type'] === 0 && $v['value'] === true;
    }

    private function inputs(array $cfg, float $now): array
    {
        $g = $cfg['global'];
        [$budget, , $budgetError] = $this->number($g['BudgetVar'], $g['BudgetMaxAge'], $now, false);
        $in = ['manager' => $this->GetValue('ManagerEnabled'), 'mode' => $this->GetValue('DistributionMode'),
            'budget' => $budget === null ? null : Setpoint::watts($budget, $g['BudgetUnit']), 'boxes' => [],
            'errors' => $budgetError ? [$budgetError] : []];
        if (!in_array($in['mode'], [0, 1], true)) $in['errors'][] = 'Laufzeit-Verteilungsmodus ungültig';
        foreach ($cfg['boxes'] as $id => $c) {
            $age = $c['HeartbeatVar'] ? 0 : $c['MaxAge'];
            [$power, $updated, $error] = $this->number($c['PowerVar'], $age, $now);
            if ($c['HeartbeatVar']) {
                $heartbeat = $this->inspect($c['HeartbeatVar']);
                if (!$heartbeat || $c['MaxAge'] > 0 && $now - $heartbeat['updated'] > $c['MaxAge']) $error = 'Geräte-Heartbeat fehlt / ist veraltet';
            }
            if ($this->boolean($c['ErrorVar'], false)) $error = 'Gerätefehler gemeldet';
            if ($this->GetValue('W' . $id . 'Priority') < 1 || $this->GetValue('W' . $id . 'Priority') > 999) $error = 'Laufzeitpriorität muss 1 bis 999 sein';
            $phaseTime = 0.0;
            $phases = (int)$c['PhaseMode'];
            if ($c['PhaseMode'] === 'dynamic') {
                [$feedback, $phaseTime, $phaseError] = $this->number($c['PhaseFeedbackVar'], $age, $now, false);
                $phases = PhaseSwitch::feedback($c, $feedback);
                $error = $error ?: $phaseError;
            }
            $in['boxes'][$id] = ['manual' => $this->GetValue('W' . $id . 'Manual'), 'priority' => $this->GetValue('W' . $id . 'Priority'),
                'external' => $this->boolean($c['ExternalVar']), 'demand' => $this->boolean($c['DemandVar']), 'phases' => $phases, 'phaseUpdated' => $phaseTime,
                'power' => $power === null ? null : Setpoint::watts($power, $c['PowerUnit']), 'powerUpdated' => $updated, 'error' => $c['Enabled'] ? $error : ''];
        }
        $b = $cfg['battery'];
        $bat = ['manual' => $this->GetValue('BatteryEnabled'), 'external' => $this->boolean($b['ExternalVar']), 'soc' => null, 'power' => null, 'error' => ''];
        if ($b['Enabled']) {
            [$bat['soc'], , $bat['error']] = $this->number($b['SocVar'], $b['MaxAge'], $now);
            if ($b['PowerVar']) {
                [$p, , $error] = $this->number($b['PowerVar'], $b['MaxAge'], $now, false);
                $bat['power'] = $p === null ? null : $b['PowerSign'] * Setpoint::watts($p, $b['PowerUnit']);
                $bat['error'] = $bat['error'] ?: $error;
            }
            if ($b['LimitVar']) {
                [$p, , $error] = $this->number($b['LimitVar'], $b['MaxAge'], $now);
                $bat['limit'] = $p === null ? 0.0 : Setpoint::watts($p, $b['LimitUnit']);
                $bat['error'] = $bat['error'] ?: $error;
            }
        }
        $in['battery'] = $bat;
        return $in;
    }
    private function outputs(array $cfg, array $in, array &$plan, array &$state, float $now): void
    {
        $failed = false;
        // 1. Reductions have no command-gap delay, especially explicit locks.
        foreach ($plan['boxes'] as $id => &$row) {
            $c = $cfg['boxes'][$id];
            if (!$c['Enabled']) continue;
            $old = $state['boxes'][$id]['power'] ?? 0.0;
            if ($row['power'] < $old - 1e-7 || $row['power'] === 0.0) {
                if (!$this->writeBox($id, $c, $row['power'], $row['phases'] ?: 3, $state, $now)) $failed = true;
                if ($old > $row['power'] + 1e-7) $state['boxes'][$id]['reduction'] = ['target' => $row['power'], 'since' => $now];
            }
        }
        unset($row);
        // 2. Establish storage support before allowing any new charging load.
        if ($cfg['battery']['Enabled']) {
            if (!$this->writeBattery($cfg['battery'], $plan['battery']['requested'], $state, $now)) {
                $failed = true;
                $plan['errors'][] = 'Speicherbefehl fehlgeschlagen';
                $plan['battery']['confirmed'] = 0;
                $plan['budget'] = min($plan['budget'], $plan['base']);
                foreach ($plan['boxes'] as $id => &$row) {
                    if (!$cfg['boxes'][$id]['Enabled']) continue;
                    $this->writeBox($id, $cfg['boxes'][$id], 0, $row['phases'] ?: 3, $state, $now, true);
                    $row['power'] = 0.0;
                }
                unset($row);
            }
        }
        // 3. Hold still until measured reductions are acknowledged.
        $reservations = [];
        foreach ($cfg['boxes'] as $id => $c) {
            $s = &$state['boxes'][$id];
            $measured = $in['boxes'][$id]['power'] ?? null;
            $reservation = max($s['power'] ?? 0.0, $measured ?? 0.0);
            if ($c['Enabled'] && $measured !== null && $measured > ($s['power'] ?? 0.0) + $cfg['global']['FeedbackToleranceW']
                && $now - ($s['lastCommand'] ?? $now) >= $cfg['global']['FeedbackTimeout']) {
                $s['fault'] = 'Gemessene Ladeleistung überschreitet den bestätigten Sollwert';
                $this->writeBox($id, $c, 0, $plan['boxes'][$id]['phases'] ?: 3, $state, $now, true);
                $plan['errors'][] = $c['Name'] . ': ' . $s['fault'];
                $failed = true;
            }
            if (isset($s['reduction'])) {
                $r = $s['reduction'];
                if ($measured !== null && $measured <= $r['target'] + $cfg['global']['FeedbackToleranceW'] && $in['boxes'][$id]['powerUpdated'] >= floor($r['since'])) unset($s['reduction']);
                elseif ($now - $r['since'] >= $cfg['global']['FeedbackTimeout']) {
                    $s['fault'] = 'Timeout: Lastabbau nicht bestätigt';
                    $this->writeBox($id, $c, 0, $plan['boxes'][$id]['phases'] ?: 3, $state, $now, true);
                    $plan['errors'][] = $c['Name'] . ': ' . $s['fault'];
                    $failed = true;
                }
            }
            if ($c['Enabled'] || $reservation > 0) $reservations[$id] = $reservation;
            unset($s);
        }
        $pending = false;
        foreach ($state['boxes'] as $s) if (isset($s['reduction'])) $pending = true;
        // 4. Phase commands are only emitted after a successful stop and verified zero load.
        foreach ($plan['boxes'] as $id => &$row) {
            if ($row['phaseCommand'] === null) continue;
            $c = $cfg['boxes'][$id];
            $s = &$state['boxes'][$id];
            if (!$failed && ($s['power'] ?? 0) <= 0 && $in['boxes'][$id]['power'] !== null && $in['boxes'][$id]['power'] <= $c['PhaseRestW']) {
                if ($this->command($c['PhaseCommandVar'], $row['phaseCommand'], $cfg['global']['Simulation'], $state, $now, true)) PhaseSwitch::commandSent($s['phase'], $now);
                else {
                    $s['phase']['fault'] = $s['fault'] = 'Phasenbefehl fehlgeschlagen';
                    $row['fault'] = $s['fault'];
                    $failed = true;
                }
            }
            unset($s);
        }
        unset($row);
        // 5. Increases cannot spend budget still consumed by previous allocations.
        foreach ($plan['boxes'] as $id => &$row) {
            $c = $cfg['boxes'][$id];
            if (!$c['Enabled']) continue;
            $s = &$state['boxes'][$id];
            $old = $s['power'] ?? 0.0;
            if ($row['power'] > $old + 1e-7) {
                $reservedElsewhere = array_sum($reservations) - ($reservations[$id] ?? 0.0);
                $room = max(0.0, $plan['budget'] - $reservedElsewhere);
                $limited = Setpoint::floorPower(min($row['power'], $room), Setpoint::limits($c, $row['phases']));
                if ($failed || $pending || $now - ($s['lastCommand'] ?? -1e9) < $c['CommandGap']) {
                    $limited = $old;
                    $row['reason'] = $failed ? 'Befehlsfehler: Erhöhung gesperrt' : ($pending ? 'Warten auf bestätigten Lastabbau' : 'Mindestabstand zum letzten Sollwertbefehl');
                }
                if ($limited > $old + 1e-7) {
                    if (!$this->writeBox($id, $c, $limited, $row['phases'], $state, $now)) $failed = true;
                    $reservations[$id] = max($state['boxes'][$id]['power'] ?? 0.0, $in['boxes'][$id]['power'] ?? 0);
                } elseif ($limited < $row['power']) $row['reason'] = 'Warten auf Lastabbau / freies bestätigtes Budget';
                $row['power'] = $state['boxes'][$id]['power'] ?? 0.0;
            } elseif ($row['power'] > 0 && !$failed) {
                // Only resend when needed, e.g. configured device watchdog.
                $this->writeBox($id, $c, $row['power'], $row['phases'], $state, $now);
            }
            if (($s['fault'] ?? '') !== '') {
                $row['fault'] = $s['fault'];
                $row['status'] = 'Fehler';
                $row['reason'] = $s['fault'];
                $plan['errors'][] = $c['Name'] . ': ' . $s['fault'];
            }
            $row['power'] = $s['power'] ?? 0.0;
            $row['command'] = Setpoint::command($row['power'], $c, $row['phases'] ?: 3);
            if ($row['power'] <= 0 && $row['status'] === 'Laden') $row['status'] = 'Warten auf Leistung';
            unset($s);
        }
        unset($row);
        $plan['assigned'] = array_sum(array_column($plan['boxes'], 'power'));
        $plan['overrun'] = max(0.0, $plan['assigned'] - $plan['normalBudget']);
        $plan['battery']['commanded'] = $state['battery']['requested'] ?? 0.0;
        $plan['errors'] = array_values(array_unique($plan['errors']));
    }

    private function writeBox(int $id, array $c, float $power, int $phases, array &$state, float $now, bool $force = false): bool
    {
        $s = &$state['boxes'][$id];
        $old = $s['power'] ?? 0.0;
        $command = Setpoint::command($power, $c, $phases);
        $simulation = $this->ReadPropertyBoolean('Simulation');
        $ok = true;
        if ($power <= 0 && $c['DeviceEnableVar']) $ok = $this->command($c['DeviceEnableVar'], !$c['EnablePolarity'], $simulation, $state, $now, $force);
        // Numeric-only boxes also receive their individual stop command.
        $ok = $this->command($c['SetpointVar'], $command, $simulation, $state, $now, $force) && $ok;
        if ($ok && $power > 0 && $c['DeviceEnableVar']) $ok = $this->command($c['DeviceEnableVar'], $c['EnablePolarity'], $simulation, $state, $now, $force);
        if (!$ok) {
            $s['fault'] = 'Geräteaktion fehlgeschlagen (Sollwert/Freigabe)';
            // Enabling failed after an accepted numeric value: cancel the numeric request.
            $this->command($c['SetpointVar'], (float)$c['Stop'], $simulation, $state, $now, true);
            if ($c['DeviceEnableVar']) $this->command($c['DeviceEnableVar'], !$c['EnablePolarity'], $simulation, $state, $now, true);
            return false;
        }
        $s['power'] = $power;
        if ($power > 0 && $old <= 0) {
            $s['started'] = $now;
            $s['lastServed'] = $now;
            $s['startSince'] = null;
        }
        if ($power <= 0 && $old > 0) {
            $s['stopped'] = $now;
            $s['deficitSince'] = null;
            $s['startSince'] = null;
        }
        if (abs($old - $power) > 1e-7) $s['lastCommand'] = $now;
        return true;
    }

    private function writeBattery(array $c, float $power, array &$state, float $now, bool $force = false): bool
    {
        $value = Battery::command($power, $c);
        $v = $this->inspect($c['SetpointVar']);
        if ($v && $v['type'] === 1 && $power > 0) {
            // Integer battery targets are conservatively rounded toward neutral.
            $value = $value < 0 ? ceil($value) : floor($value);
            $power = abs($value) * ($c['SetpointUnit'] === 'kW' ? 1000 : 1);
            if ($power <= 0 || abs($value - $c['Stop']) < 1e-7) {
                $power = 0.0;
                $value = $c['Stop'];
            }
        }
        if (!$this->command($c['SetpointVar'], $value, $this->ReadPropertyBoolean('Simulation'), $state, $now, $force)) {
            $state['battery']['fault'] = 'Entladebefehl fehlgeschlagen';
            $state['battery']['requested'] = 0.0;
            $this->command($c['SetpointVar'], $c['Stop'], $this->ReadPropertyBoolean('Simulation'), $state, $now, true);
            return false;
        }
        if (abs($power - ($state['battery']['requested'] ?? 0)) > 1e-7) {
            $state['battery']['requestAt'] = $now;
            if ($power <= 0 || ($state['battery']['requested'] ?? 0) <= 0) $state['battery']['shortSince'] = null;
        }
        $state['battery']['requested'] = $power;
        return true;
    }

    private function command(int $id, $value, bool $simulation, array &$state, float $now, bool $force = false): bool
    {
        $v = $this->inspect($id);
        $error = '';
        if (!$v || $v['owner'] === $this->InstanceID || $v['locked'] || in_array($id, $this->otherTargets(), true)) $error = 'Steuerziel fehlt, ist gesperrt oder gehört zum Manager';
        elseif (!in_array($v['type'], [0, 1, 2], true)) $error = 'Unzulässiger Steuerzieltyp';
        elseif (($v['type'] === 0) !== is_bool($value)) $error = 'Steuerziel und Befehl haben inkompatible Datentypen';
        elseif ($v['type'] === 1 && abs((float)$value - round((float)$value)) > 1e-7) $error = 'Nichtganzzahliger Befehl an Integerziel';
        elseif (!is_bool($value) && (!is_numeric($value) || !is_finite((float)$value))) $error = 'Befehlswert nicht endlich';
        if ($error === '') {
            $value = $v['type'] === 0 ? (bool)$value : ($v['type'] === 1 ? (int)round((float)$value) : (float)$value);
            $old = $state['commands'][$id] ?? null;
            $watchdog = $this->ReadPropertyInteger('Watchdog');
            if (!$force && $old && $old['value'] === $value && (!$watchdog || $now - $old['time'] < $watchdog)
                && ($v['value'] === $value || $now - $old['time'] < $this->ReadPropertyInteger('FeedbackTimeout'))) return true;
            try {
                set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
                try {
                    if ($v['action']) $ok = RequestAction($id, $value);
                    elseif ($simulation) $ok = SetValue($id, $value);
                    else throw new RuntimeException('Keine Aktion vorhanden; Simulation ist aus');
                    if ($ok === false) throw new RuntimeException('Geräteaktion liefert false');
                } finally { restore_error_handler(); }
                $state['commands'][$id] = ['value' => $value, 'time' => $now];
                $this->history($state, $now, $id, $value, '');
                return true;
            } catch (Throwable $e) { $error = $e->getMessage(); }
        }
        $this->history($state, $now, $id, $value, $error);
        $this->SendDebug('Befehlsfehler ' . $id, $error, 0);
        return false;
    }

    private function history(array &$state, float $now, int $id, $value, string $error): void
    {
        $state['history'][] = ['time' => $now, 'target' => $id, 'value' => $value, 'error' => $error];
        $state['history'] = array_slice($state['history'], -40);
    }

    private function deviceIdentity(array $c): string
    {
        return $this->encode(array_intersect_key($c, array_flip(['DeviceKey', 'SetpointVar', 'SetpointUnit', 'Stop', 'DeviceEnableVar', 'EnablePolarity', 'PowerVar', 'PhaseMode', 'PhaseCommandVar', 'PhaseFeedbackVar', 'Phase1Command', 'Phase3Command', 'Phase1Feedback', 'Phase3Feedback'])));
    }

    private function retire(array &$state, string $key, array $c, bool $simulation, float $now): void
    {
        if (!$c['SetpointVar']) return;
        $record = ['key' => $key, 'target' => $c['SetpointVar'], 'stop' => $c['Stop'], 'enable' => $c['DeviceEnableVar'], 'disable' => !$c['EnablePolarity'],
            'power' => $c['PowerVar'], 'powerUnit' => $c['PowerUnit'], 'simulation' => $simulation, 'created' => $now, 'sent' => null];
        $hash = hash('sha256', $this->encode(array_diff_key($record, ['created' => true, 'sent' => true])));
        $state['retired'][$hash] ??= $record;
    }

    private function retireBattery(array &$state, string $key, array $c, bool $simulation, float $now): void
    {
        if (!$c['SetpointVar']) return;
        $record = ['key' => $key, 'target' => $c['SetpointVar'], 'stop' => $c['Stop'], 'enable' => 0, 'disable' => false,
            'power' => 0, 'powerUnit' => 'W', 'simulation' => $simulation, 'created' => $now, 'sent' => null];
        $hash = hash('sha256', $this->encode(array_diff_key($record, ['created' => true, 'sent' => true])));
        $state['retired'][$hash] ??= $record;
    }

    private function processRetired(array &$state, float $now): array
    {
        $errors = [];
        foreach ($state['retired'] as $key => &$r) {
            // A rejected new configuration never acquired a foreign target.
            if (in_array($r['target'], $this->otherTargets(), true)) {
                unset($state['retired'][$key]);
                continue;
            }
            if ($r['sent'] === null) {
                $ok = !$r['enable'] || $this->command($r['enable'], $r['disable'], ($r['simulation'] || $this->ReadPropertyBoolean('Simulation')), $state, $now, true);
                $ok = $this->command($r['target'], $r['stop'], ($r['simulation'] || $this->ReadPropertyBoolean('Simulation')), $state, $now, true) && $ok;
                if ($ok) $r['sent'] = $now;
                else {
                    $errors[] = $r['key'] . ': bisheriges Steuerziel konnte nicht gestoppt werden (' . $r['target'] . ')';
                    continue;
                }
            }
            if ($r['power']) {
                [$p, $sample, $error] = $this->number($r['power'], 0, $now);
                if ($error || $p === null || Setpoint::watts($p, $r['powerUnit']) > $this->ReadPropertyFloat('FeedbackToleranceW') || $sample < floor($r['sent'])) {
                    $errors[] = $r['key'] . ': Warten auf bestätigten Stillstand des bisherigen Steuerziels';
                    continue;
                }
            }
            unset($state['retired'][$key]);
        }
        unset($r);
        return $errors;
    }

    private function references(array $flat, array $state): void
    {
        $ids = [];
        foreach (Schema::links($flat) as $l) if ($l['id'] && IPS_VariableExists($l['id'])) $ids[$l['id']] = !$l['write'];
        foreach ($state['retired'] as $r) foreach (['target', 'enable', 'power'] as $key) if ($r[$key] && IPS_VariableExists($r[$key])) $ids[$r[$key]] = $key === 'power';
        $current = $this->GetReferenceList();
        foreach ($current as $id) if (!array_key_exists($id, $ids)) $this->UnregisterReference($id);
        foreach ($ids as $id => $_) if (!in_array($id, $current, true)) $this->RegisterReference($id);
        // Output echoes are not subscribed: avoids feedback loops from own actions.
        $messages = $this->GetMessageList();
        foreach ($messages as $id => $types) {
            if ($id === 0) continue;
            foreach ($types as $type) {
                if (!isset($ids[$id]) || $type === VM_UPDATE && !$ids[$id]) $this->UnregisterMessage($id, $type);
            }
        }
        foreach ($ids as $id => $input) {
            $this->RegisterMessage($id, VM_DELETE);
            if ($input) $this->RegisterMessage($id, VM_UPDATE);
        }
    }

    private function accountEnergy(array $cfg, array &$state, float $now): void
    {
        foreach ($cfg['boxes'] as $id => $c) {
            if (!$c['Enabled']) continue;
            $state['energy'][$id] ??= [];
            if ((!in_array($c['PowerUnit'], ['W', 'kW'], true) && !$c['EnergyVar']) || !in_array($c['EnergyUnit'], ['Wh', 'kWh'], true)) {
                $state['energy'][$id]['gap'] = 'Energiezählung ausgesetzt: ungültige Einheit';
                continue;
            }
            $e = &$state['energy'][$id];
            $identity = ($c['DeviceKey'] ?: 'auto') . ':' . $c['SetpointVar'] . ':' . $c['PowerVar'];
            $changed = Energy::identify($e, $identity);
            if ($c['EnergyVar']) {
                [$value, , $error] = $this->number($c['EnergyVar'], 0, $now);
                if ($value !== null && $c['EnergyUnit'] === 'Wh') $value /= 1000.0;
                $result = Energy::meter($e, 'meter:' . $c['EnergyVar'] . ':' . $c['EnergyUnit'], $value, $now, $c['MaxKW'] * 1000,
                    $cfg['global']['MeterJumpFactor'], $cfg['global']['MeterJumpSlack']);
            } else {
                [$power, $sample, $error] = $this->number($c['PowerVar'], $c['HeartbeatVar'] ? 0 : $c['MaxAge'], $now);
                $fresh = $sample;
                if ($c['HeartbeatVar']) {
                    $heartbeat = $this->inspect($c['HeartbeatVar']);
                    $fresh = $heartbeat['updated'] ?? 0.0;
                    if (!$heartbeat || $c['MaxAge'] > 0 && $now - $fresh > $c['MaxAge']) $power = null;
                }
                $power = $power === null ? null : Setpoint::watts($power, $c['PowerUnit']);
                $result = Energy::power($e, 'power:' . $c['PowerVar'] . ':' . $c['PowerUnit'], $power, $sample, $now, $cfg['global']['EnergyMaxGap'], $fresh);
            }
            $state['totalEnergy'] += $result['delta'];
            $e['gap'] = $changed ? 'Gerätetausch: vorherige Platzenergie archiviert' : $result['gap'];
            $e['method'] = $result['method'];
            unset($e);
        }
    }

    private function publish(array $cfg, array $in, array $plan, array $state, array $validation): void
    {
        $measured = 0.0;
        foreach ($plan['boxes'] as $id => &$row) {
            $e = $state['energy'][$id] ?? [];
            $row['energy'] = $e['energy'] ?? 0.0;
            $row['archivedEnergy'] = array_sum(array_column($e['archive'] ?? [], 'energy'));
            $row['energyMethod'] = $e['method'] ?? ($cfg['boxes'][$id]['EnergyVar'] ? 'Gerätezähler' : 'berechnet');
            $row['gap'] = $e['gap'] ?? '';
            $row['phaseStage'] = $state['boxes'][$id]['phase']['stage'] ?? '';
            if ($cfg['boxes'][$id]['Enabled'] && $row['measured'] !== null) $measured += max(0.0, $row['measured']);
            $prefix = 'W' . $id;
            foreach (['Power' => $row['measured'] ?? 0.0, 'Assigned' => $row['power'], 'Setpoint' => $row['command'], 'Phases' => $row['phases'],
                'Energy' => $row['energy'], 'ExternalLocked' => !$row['external'], 'State' => $row['status'] . ($row['reason'] !== $row['status'] ? ': ' . $row['reason'] : ''),
                'Remaining' => $row['remaining'], 'EnergyMethod' => $row['energyMethod'], 'MeasurementGap' => $row['gap']] as $key => $value) $this->SetValue($prefix . $key, $value);
        }
        unset($row);
        $plan['measured'] = $measured;
        $plan['energy'] = $state['totalEnergy'];
        $plan['managerEnabled'] = $this->GetValue('ManagerEnabled');
        $plan['batteryEnabled'] = $this->GetValue('BatteryEnabled');
        $plan['batteryConfigured'] = $cfg['battery']['Enabled'];
        $plan['battery']['capacity'] = $cfg['battery']['CapacityKWh'];
        $plan['status'] = $plan['errors'] ? 'Fehler / Warten: ' . implode('; ', $plan['errors']) : ($plan['manager'] ? 'Automatische Regelung aktiv' : 'Manager ausgeschaltet');
        $plan['simulation'] = $cfg['global']['Simulation'];
        $plan['strict'] = $cfg['global']['StrictBudget'];
        foreach (['BaseBudget' => $plan['base'], 'BatteryAvailable' => $plan['battery']['theoretical'], 'BatteryConfirmed' => $plan['battery']['confirmed'],
            'BatteryRequested' => $plan['battery']['commanded'] ?? 0.0, 'BatteryMeasured' => max(0.0, $plan['battery']['measured'] ?? 0.0),
            'BatterySoc' => $plan['battery']['soc'] ?? 0.0, 'BatteryEnergy' => $plan['battery']['energy'], 'BatteryState' => $plan['battery']['reason'],
            'BatteryExternalLocked' => !$plan['battery']['external'], 'TotalPower' => $measured, 'AssignedPower' => $plan['assigned'], 'TotalEnergy' => $plan['energy'],
            'OverrunPower' => $plan['overrun'], 'Status' => $plan['status']] as $key => $value) $this->SetValue($key, $value);
        $this->SetStatus($validation ? 200 : ($plan['errors'] ? 201 : ($plan['manager'] ? 102 : 104)));
        $this->WriteAttributeString('Telemetry', $this->encode($plan));
        $this->UpdateVisualizationValue($this->encode($plan));
        $this->SendDebug('Regelung', $this->encode($plan), 0);
    }

    private function profiles(): void
    {
        $specs = ['WBM.Power' => [2, 1, ' W', 0, 0, 0], 'WBM.Energy' => [2, 2, ' kWh', 0, 0, 0],
            'WBM.Setpoint.A' => [2, 1, ' A', 0, 0, 0], 'WBM.Setpoint.W' => [2, 2, ' W', 0, 0, 0], 'WBM.Setpoint.kW' => [2, 2, ' kW', 0, 0, 0],
            'WBM.Soc' => [2, 1, ' %', 0, 100, 0], 'WBM.Priority' => [1, 0, '', 1, 999, 1], 'WBM.Mode' => [1, 0, '', 0, 1, 1]];
        foreach ($specs as $name => $p) {
            if (!IPS_VariableProfileExists($name)) IPS_CreateVariableProfile($name, $p[0]);
            IPS_SetVariableProfileDigits($name, $p[1]);
            IPS_SetVariableProfileText($name, '', $p[2]);
            IPS_SetVariableProfileValues($name, $p[3], $p[4], $p[5]);
        }
        IPS_SetVariableProfileAssociation('WBM.Mode', 0, 'Gleichmäßig', '', -1);
        IPS_SetVariableProfileAssociation('WBM.Mode', 1, 'Priorität', '', -1);
    }

    private function variables(): void
    {
        $existing = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) $existing[IPS_GetObject($id)['ObjectIdent']] = true;
        $defs = [
            'ManagerEnabled' => [0, 'Managerfreigabe', '~Switch', true, false],
            'DistributionMode' => [1, 'Verteilung', 'WBM.Mode', true, 0],
            'BatteryEnabled' => [0, 'Manuelle Speicherfreigabe', '~Switch', true, true],
            'Status' => [3, 'Managerstatus', '', false, ''],
            'TotalPower' => [2, 'Gesamte Ladeleistung', 'WBM.Power', false, 0.0],
            'AssignedPower' => [2, 'Zugewiesene Ladeleistung', 'WBM.Power', false, 0.0],
            'TotalEnergy' => [2, 'Gesamtenergie (alle Gerätegenerationen)', 'WBM.Energy', false, 0.0],
            'BaseBudget' => [2, 'Absolutes Grundbudget', 'WBM.Power', false, 0.0],
            'OverrunPower' => [2, 'Zeitweilige Budgetüberschreitung', 'WBM.Power', false, 0.0],
            'BatteryAvailable' => [2, 'Speicher theoretisch verfügbar', 'WBM.Power', false, 0.0],
            'BatteryConfirmed' => [2, 'Speicher bestätigt verfügbar', 'WBM.Power', false, 0.0],
            'BatteryRequested' => [2, 'Speicher angefordert', 'WBM.Power', false, 0.0],
            'BatteryMeasured' => [2, 'Speicher gemessene Entladung', 'WBM.Power', false, 0.0],
            'BatterySoc' => [2, 'Speicher SoC', 'WBM.Soc', false, 0.0],
            'BatteryEnergy' => [2, 'Speicherenergie oberhalb Reserve', 'WBM.Energy', false, 0.0],
            'BatteryState' => [3, 'Speicherzustand', '', false, ''],
            'BatteryExternalLocked' => [0, 'Externe Speichersperre', '~Switch', false, false]
        ];
        for ($i = 1; $i <= 4; $i++) {
            $name = $this->ReadPropertyString('W' . $i . 'Name');
            $unit = $this->ReadPropertyString('W' . $i . 'SetpointUnit');
            $setpointProfile = 'WBM.Setpoint.' . (in_array($unit, ['A', 'W', 'kW'], true) ? $unit : 'W');
            $wb = [
                'Manual' => [0, 'Manuelle Freigabe', '~Switch', true, true], 'Priority' => [1, 'Priorität', 'WBM.Priority', true, $i],
                'Power' => [2, 'Ladeleistung', 'WBM.Power', false, 0.0], 'Assigned' => [2, 'Zugewiesene Leistung', 'WBM.Power', false, 0.0],
                'Setpoint' => [2, 'Sollwert (Einheit laut Konfiguration)', $setpointProfile, false, 0.0], 'Phases' => [1, 'Bestätigte / feste Phasen', '', false, 0],
                'Energy' => [2, 'Geladene Energie', 'WBM.Energy', false, 0.0], 'ExternalLocked' => [0, 'Externe Sperre', '~Switch', false, false],
                'State' => [3, 'Zustand / Wartegrund', '', false, ''], 'Remaining' => [1, 'Zeitbedingung (s)', '', false, 0],
                'EnergyMethod' => [3, 'Energiezählverfahren', '', false, ''], 'MeasurementGap' => [3, 'Messlücke / Zählerhinweis', '', false, '']
            ];
            foreach ($wb as $key => $d) {
                $d[1] = $name . ': ' . $d[1];
                $defs['W' . $i . $key] = $d;
            }
        }
        $position = 0;
        foreach ($defs as $ident => $d) {
            $method = 'RegisterVariable' . ['Boolean', 'Integer', 'Float', 'String'][$d[0]];
            $this->$method($ident, $d[1], $d[2], $position++);
            if ($d[3]) $this->EnableAction($ident);
            if (!isset($existing[$ident])) $this->SetValue($ident, $d[4]);
        }
    }
}
