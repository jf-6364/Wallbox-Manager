<?php
declare(strict_types=1);
namespace WBM;

/** Standalone HTTP presentation; it never chooses or writes a device setpoint. */
final class WebView
{
    public const HOOK_MODULE = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
    public const HISTORY_SECONDS = 172800;
    public const SAMPLE_SECONDS = 60;

    public static function errors(array $flat): array
    {
        if (!$flat['WebEnabled']) return [];
        $out = [];
        if (!preg_match('~^/hook/[A-Za-z0-9][A-Za-z0-9_/-]{0,110}$~D', $flat['WebPath'])
            || str_contains($flat['WebPath'], '//') || str_ends_with($flat['WebPath'], '/')) $out[] = 'Pfad muss mit /hook/ beginnen, z. B. /hook/wallboxen; nur Buchstaben, Zahlen, Bindestrich, Unterstrich und Unterpfade.';
        if (!preg_match('/^[^:\r\n]{1,64}$/uD', $flat['WebUser']) || trim($flat['WebUser']) === '') $out[] = 'Benutzername ist erforderlich (maximal 64 Zeichen, ohne Doppelpunkt).';
        if (strlen($flat['WebPassword']) < 8 || strlen($flat['WebPassword']) > 256) $out[] = 'Passwort muss 8 bis 256 Zeichen enthalten.';
        return $out;
    }

    public static function authenticated(array $server, array $flat): bool
    {
        $user = $server['PHP_AUTH_USER'] ?? ''; $password = $server['PHP_AUTH_PW'] ?? '';
        if (isset($server['HTTP_AUTHORIZATION'])) {
            if (!preg_match('/^Basic ([A-Za-z0-9+\/=]+)$/iD', $server['HTTP_AUTHORIZATION'], $m)) return false;
            $decoded = base64_decode($m[1], true);
            if ($decoded === false || !str_contains($decoded, ':')) return false;
            [$user, $password] = explode(':', $decoded, 2);
        }
        return is_string($user) && is_string($password) && hash_equals($flat['WebUser'], $user)
            && hash_equals($flat['WebPassword'], $password);
    }

    public static function token(array $flat, string $secret, int $instance): string
    {
        return hash_hmac('sha256', $instance . "\0" . $flat['WebPath'] . "\0" . $flat['WebUser'] . "\0" . $flat['WebPassword'], $secret);
    }

    public static function reply(int $status, string $body, string $type = 'application/json; charset=utf-8', array $headers = []): array
    {
        return ['status' => $status, 'headers' => $headers + [
            'Content-Type' => $type, 'Cache-Control' => 'no-store', 'Vary' => 'Authorization',
            'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN', 'Referrer-Policy' => 'no-referrer'
        ], 'body' => $body];
    }

    public static function json(int $status, array $value, array $headers = []): array
    {
        return self::reply($status, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'application/json; charset=utf-8', $headers);
    }

    private static function take(array $source, array $keys): array { return array_intersect_key($source, array_flip($keys)); }

    /** Addresses, bank data, configuration and embedded PDF bytes are not page telemetry. */
    public static function telemetry(array $source, string $base): array
    {
        $data = self::take($source, ['manager', 'managerEnabled', 'mode', 'base', 'normalBudget', 'budget', 'pvActive', 'budgetSource',
            'assigned', 'overrun', 'measured', 'energy', 'status', 'errors', 'time', 'simulation', 'strict', 'batteryEnabled', 'batteryConfigured']);
        $data['battery'] = self::take($source['battery'] ?? [], ['confirmed', 'theoretical', 'soc', 'capacity', 'energy', 'commanded', 'measured', 'reason', 'external']);
        $data['boxes'] = [];
        foreach ($source['boxes'] ?? [] as $id => $row) {
            $box = self::take($row, ['id', 'name', 'enabled', 'manual', 'external', 'priority', 'measured', 'phases', 'power', 'command', 'unit',
                'status', 'reason', 'remaining', 'fault', 'energy', 'archivedEnergy', 'energyMethod', 'gap', 'phaseStage']);
            $bill = $row['billing'] ?? [];
            $box['billing'] = self::take($bill, ['enabled', 'pvPrice', 'normalPrice', 'mode', 'pending', 'error', 'manualSession']);
            $box['billing']['totals'] = self::take($bill['totals'] ?? [], ['pvEnergy', 'normalEnergy', 'unassignedEnergy', 'pvCost', 'normalCost', 'totalCost', 'sessions']);
            foreach (['active', 'last'] as $key) $box['billing'][$key] = empty($bill[$key]) ? null : self::receipt($bill[$key], (int)$id);
            $last = $box['billing']['last'];
            $box['billing']['pdfURL'] = !empty($last['mediaID']) ? $base . '/pdf?box=' . (int)$id . '&document=' . (int)$last['mediaID'] : '';
            $data['boxes'][$id] = $box;
        }
        return $data;
    }

    public static function receipt(array $record, int $box): array
    {
        $out = self::take($record, ['id', 'name', 'started', 'ended', 'timezone', 'pvEnergy', 'normalEnergy', 'unassignedEnergy',
            'totalEnergy', 'pvCost', 'normalCost', 'totalCost', 'method', 'warnings', 'reason', 'mediaID', 'fileName']);
        $out['wallbox'] = $box;
        $out['invoice'] = isset($record['invoice']['number']) ? ['number' => $record['invoice']['number']] : null;
        return $out;
    }

    public static function appendHistory(array $history, array $telemetry, float $now): array
    {
        $history += ['started' => $now, 'samples' => []];
        $last = $history['samples'] ? end($history['samples'])['time'] : 0;
        if ($now - $last < self::SAMPLE_SECONDS) return $history;
        $point = ['time' => $now, 'boxes' => []];
        foreach ($telemetry['boxes'] ?? [] as $id => $box) {
            $point['boxes'][$id] = $box['enabled'] && isset($box['measured']) && is_finite($box['measured']) ? max(0.0, $box['measured']) : null;
        }
        $history['samples'][] = $point;
        $history['samples'] = array_values(array_filter($history['samples'], static fn($row) => $row['time'] >= $now - self::HISTORY_SECONDS));
        $history['samples'] = array_slice($history['samples'], -2881);
        return $history;
    }

    public static function page(array $telemetry, array $context, string $nonce): string
    {
        $root = __DIR__ . '/../WallboxManager/';
        $html = file_get_contents($root . 'tile.html');
        $contextJSON = json_encode($context, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $dataJSON = json_encode($telemetry, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $scripts = 'window.WBM_WEB=' . $contextJSON . ";\n" . file_get_contents($root . 'web-bridge.js') . "\n" . file_get_contents($root . 'tile.js') . "\n" . file_get_contents($root . 'web.js');
        $html = str_replace(['/*__CSS__*/', '/*__DATA__*/', '/*__JS__*/'], [file_get_contents($root . 'tile.css') . "\n" . file_get_contents($root . 'web.css'), $dataJSON, $scripts], $html);
        $html = str_replace('<body id="wbm-page">', '<body id="wbm-page" class="web-page">' . file_get_contents($root . 'web-header.html'), $html);
        $html = str_replace('<div class="dashboard-scroll">', '<div class="dashboard-scroll">' . file_get_contents($root . 'web-summary.html'), $html);
        $extra = file_get_contents($root . 'web-details.html');
        $marker = "  </div>\n</section>\n</main>";
        $html = str_replace($marker, $extra . $marker, $html);
        $html = str_replace('<h2 id="dashboard-title">Energieverteilung</h2>', '<h2 id="dashboard-title">Energie &amp; Abrechnung</h2>', $html);
        $html = str_replace('<script>', '<script nonce="' . $nonce . '">', $html);
        return str_replace('<title>Wallbox Manager</title>', '<title>Wallbox-Manager – Webansicht</title>', $html);
    }
}
