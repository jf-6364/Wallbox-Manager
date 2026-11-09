<?php
declare(strict_types=1);
namespace WBM;

/** Optional WebHook transport, isolated from device configuration and allocation. */
trait WebAccess
{
    private function webStatus(): string
    {
        $flat = $this->configuration();
        if (!$flat['WebEnabled']) return 'Webansicht ist ausgeschaltet. Nach Aktivierung: http://<Symcon-IP>:3777' . $flat['WebPath'];
        $error = $this->jsonAttribute('WebHookState')['error'] ?? '';
        return $error !== '' ? 'Webansicht: ' . $error : 'Webansicht bereit: http://<Symcon-IP>:3777' . $flat['WebPath'] . ' – Anmeldung mit den hier eingestellten Zugangsdaten.';
    }

    private function syncWebHook(bool $remove = false): void
    {
        $flat = $this->configuration(); $old = $this->jsonAttribute('WebHookState');
        $enabled = !$remove && $flat['WebEnabled'];
        $errors = $enabled ? WebView::errors($flat) : [];
        if ($errors) { $this->WriteAttributeString('WebHookState', $this->encode(array_replace($old, ['error' => implode(' ', $errors)]))); return; }
        if (!IPS_SemaphoreEnter('WBM_WebHooks', 1000)) {
            $this->WriteAttributeString('WebHookState', $this->encode(array_replace($old, ['error' => 'WebHook-Verwaltung beschäftigt; Einstellungen erneut übernehmen.']))); return;
        }
        set_error_handler(static function ($severity, $message, $file, $line) { throw new \ErrorException($message, 0, $severity, $file, $line); });
        try {
            $controllers = IPS_GetInstanceListByModuleID(WebView::HOOK_MODULE);
            if (!$controllers) {
                if (!$enabled) return;
                throw new \RuntimeException('Die Symcon-Instanz WebHook Control fehlt.');
            }
            $controller = (int)$controllers[0];
            $hooks = json_decode(IPS_GetProperty($controller, 'Hooks'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($hooks)) throw new \RuntimeException('WebHook-Liste ist ungültig.');
            // Build 20 used Target instead of the native TargetID. SetProperty may
            // have saved that invalid draft even though ApplyChanges rejected it.
            $prepared = [];
            foreach ($hooks as $hook) {
                if (!isset($hook['TargetID']) && ($hook['Target'] ?? null) === $this->InstanceID) {
                    if (!$enabled || $hook['Hook'] !== $flat['WebPath']) continue;
                    $hook['TargetID'] = $this->InstanceID;
                    unset($hook['Target']);
                }
                $prepared[] = $hook;
            }
            if ($enabled) foreach ($prepared as $hook) {
                if ($hook['Hook'] === $flat['WebPath'] && (int)($hook['TargetID'] ?? 0) !== $this->InstanceID) throw new \RuntimeException('Der URL-Pfad wird bereits von einer anderen Instanz verwendet. Bitte einen anderen Pfad wählen.');
            }
            $ownedPaths = array_values(array_unique(array_filter(array_merge([$old['path'] ?? '', $flat['WebPath']], $old['pendingPaths'] ?? []))));
            $next = [];
            foreach ($prepared as $hook) {
                if (in_array($hook['Hook'], $ownedPaths, true) && (int)($hook['TargetID'] ?? 0) === $this->InstanceID
                    && (!$enabled || $hook['Hook'] !== $flat['WebPath'])) continue;
                $next[] = $hook;
            }
            if ($enabled) {
                $exists = false;
                foreach ($next as $hook) if ($hook['Hook'] === $flat['WebPath'] && (int)($hook['TargetID'] ?? 0) === $this->InstanceID) $exists = true;
                if (!$exists) $next[] = ['Hook' => $flat['WebPath'], 'TargetID' => $this->InstanceID];
            }
            if ($next !== $hooks || !empty($old['pending']) || !empty($old['error'])) {
                $old['pending'] = true;
                $old['pendingPaths'] = $ownedPaths;
                $this->WriteAttributeString('WebHookState', $this->encode($old));
                if (IPS_SetProperty($controller, 'Hooks', $this->encode($next)) === false || IPS_ApplyChanges($controller) === false) throw new \RuntimeException('WebHook konnte nicht registriert werden.');
            }
            $this->WriteAttributeString('WebHookState', $this->encode(['path' => $enabled ? $flat['WebPath'] : '', 'error' => '']));
        } catch (\Throwable $error) {
            $this->WriteAttributeString('WebHookState', $this->encode(array_replace($old, ['error' => $error->getMessage()])));
            $this->SendDebug('Webansicht', $error->getMessage(), 0);
        } finally { restore_error_handler(); IPS_SemaphoreLeave('WBM_WebHooks'); }
    }

    protected function ProcessHookData()
    {
        $response = $this->webResponse($_SERVER, file_get_contents('php://input') ?: '');
        http_response_code($response['status']);
        foreach ($response['headers'] as $name => $value) header($name . ': ' . $value);
        echo $response['body'];
    }

    protected function webResponse(array $server, string $body = ''): array
    {
        $flat = $this->configuration();
        if (!$flat['WebEnabled']) return WebView::json(404, ['error' => 'Webansicht ausgeschaltet.']);
        if (WebView::errors($flat)) return WebView::json(503, ['error' => 'Webansicht ist noch nicht vollständig konfiguriert.']);
        $base = $flat['WebPath'];
        $path = parse_url($server['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if ($path !== $base && !str_starts_with((string)$path, $base . '/')) return WebView::json(404, ['error' => 'Seite nicht gefunden.']);
        if (!WebView::authenticated($server, $flat)) return WebView::json(401, ['error' => 'Anmeldung erforderlich.'],
            ['WWW-Authenticate' => 'Basic realm="Wallbox Manager ' . $this->InstanceID . '", charset="UTF-8"']);
        $secret = $this->ReadAttributeString('WebSecret');
        if (strlen($secret) < 32) return WebView::json(503, ['error' => 'Webansicht wird initialisiert. Bitte Modul neu laden.']);
        $csrf = WebView::token($flat, $secret, $this->InstanceID);
        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $route = substr($path, strlen($base));
        parse_str((string)(parse_url($server['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?: ''), $query);
        try {
            foreach (['box', 'document', 'offset', 'limit'] as $key) {
                if (isset($query[$key]) && (!is_string($query[$key]) || !preg_match('/^[0-9]{1,10}$/D', $query[$key]))) return WebView::json(400, ['error' => 'Ungültiger Zahlenparameter.']);
            }
            if ($route === '/api/action') {
                if ($method !== 'POST') return WebView::json(405, ['error' => 'POST erforderlich.'], ['Allow' => 'POST']);
                if (!hash_equals($csrf, (string)($server['HTTP_X_WBM_CSRF'] ?? ''))) return WebView::json(403, ['error' => 'Sitzung abgelaufen. Seite bitte neu laden.']);
                if (strtolower(trim(explode(';', $server['CONTENT_TYPE'] ?? '')[0])) !== 'application/json' || strlen($body) > 4096) return WebView::json(400, ['error' => 'JSON-Bedienaktion erwartet.']);
                $action = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($action) || !is_string($action['ident'] ?? null) || !array_key_exists('value', $action)
                    || !preg_match('/^(DistributionMode|BatteryEnabled|W[1-4](Manual|Priority))$/D', $action['ident'])) return WebView::json(400, ['error' => 'Unbekannte Bedienaktion.']);
                $this->RequestAction($action['ident'], $action['value']);
                return WebView::json(200, ['telemetry' => WebView::telemetry($this->jsonAttribute('Telemetry'), $base)]);
            }
            if ($method !== 'GET' && $method !== 'HEAD') return WebView::json(405, ['error' => 'GET erforderlich.'], ['Allow' => 'GET, HEAD']);
            if ($route === '' || $route === '/') {
                $nonce = bin2hex(random_bytes(18));
                $html = WebView::page(WebView::telemetry($this->jsonAttribute('Telemetry'), $base),
                    ['base' => $base, 'csrf' => $csrf, 'instance' => $this->InstanceID, 'timezone' => $flat['BillingTimezone'], 'interval' => $flat['Interval']], $nonce);
                $response = WebView::reply(200, $html, 'text/html; charset=utf-8', ['Content-Security-Policy' => "default-src 'self'; script-src 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'self'; form-action 'self'"]);
            } elseif ($route === '/api/status') $response = WebView::json(200, ['telemetry' => WebView::telemetry($this->jsonAttribute('Telemetry'), $base)]);
            elseif ($route === '/api/history') {
                $history = $this->jsonAttribute('WebHistory') + ['started' => null, 'samples' => []];
                $response = WebView::json(200, $history + ['sampleSeconds' => WebView::SAMPLE_SECONDS]);
            } elseif ($route === '/api/invoices') {
                $documents = $this->webInvoices($base);
                $box = isset($query['box']) && is_string($query['box']) && preg_match('/^[0-9]+$/D', $query['box']) ? (int)$query['box'] : 0;
                if ($box) $documents = array_values(array_filter($documents, static fn($row) => $row['wallbox'] === $box));
                $offset = max(0, min(1000000, (int)($query['offset'] ?? 0)));
                $limit = max(1, min(100, (int)($query['limit'] ?? 50)));
                $response = WebView::json(200, ['items' => array_slice($documents, $offset, $limit), 'total' => count($documents), 'offset' => $offset, 'hasMore' => $offset + $limit < count($documents)]);
            } elseif ($route === '/pdf') {
                $document = $this->webDocument((int)($query['box'] ?? 0), (int)($query['document'] ?? 0));
                if (!$document) return WebView::json(404, ['error' => 'Abrechnung nicht gefunden.']);
                $bytes = base64_decode($this->readBillingMedia($document['mediaID']), true);
                if ($bytes === false) return WebView::json(500, ['error' => 'PDF ist nicht lesbar.']);
                $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $document['fileName']);
                $response = WebView::reply(200, $bytes, 'application/pdf', ['Content-Disposition' => 'attachment; filename="' . substr($name, 0, 180) . '"']);
            } else return WebView::json(404, ['error' => 'Seite nicht gefunden.']);
            if ($method === 'HEAD') $response['body'] = '';
            return $response;
        } catch (\JsonException | \InvalidArgumentException $error) {
            return WebView::json(400, ['error' => 'Ungültige Bedienaktion oder Anfrage.']);
        } catch (\Throwable $error) {
            $this->SendDebug('Webansicht', $error->getMessage(), 0);
            return WebView::json(503, ['error' => 'Anfrage vorübergehend nicht möglich. Bitte erneut versuchen.']);
        }
    }

    private function webDocument(int $box, int $document): ?array
    {
        if ($box < 1 || $box > 4 || $document <= 0 || !IPS_MediaExists($document)) return null;
        $object = IPS_GetObject($document);
        if ($object['ParentID'] <= 0 || $object['ObjectType'] !== 5 || !str_starts_with($object['ObjectIdent'], 'Bill_')) return null;
        $folder = IPS_GetObject($object['ParentID']);
        if ($folder['ParentID'] !== $this->InstanceID || $folder['ObjectType'] !== 0 || $folder['ObjectIdent'] !== 'W' . $box . 'BillingDocuments') return null;
        return ['wallbox' => $box, 'mediaID' => $document, 'fileName' => preg_replace('/[^a-zA-Z0-9._-]/', '_', $object['ObjectName'] ?? 'Abrechnung') . '.pdf'];
    }

    private function webInvoices(string $base): array
    {
        $index = $this->jsonAttribute('WebInvoices');
        $runtime = $this->runtime();
        foreach ($runtime['billing'] as $box => $billing) if (!empty($billing['last']['mediaID'])) {
            $index[$billing['last']['mediaID']] = WebView::receipt($billing['last'], (int)$box);
        }
        $out = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $folderID) {
            $folder = IPS_GetObject($folderID);
            if ($folder['ObjectType'] !== 0 || !preg_match('/^W([1-4])BillingDocuments$/D', $folder['ObjectIdent'], $match)) continue;
            $box = (int)$match[1];
            foreach (IPS_GetChildrenIDs($folderID) as $mediaID) {
                $document = $this->webDocument($box, $mediaID);
                if (!$document) continue;
                $row = ($index[$mediaID] ?? []) + $document;
                $row['detailed'] = isset($row['totalEnergy']);
                $row['name'] ??= $this->ReadPropertyString('W' . $box . 'Name');
                $row['title'] = IPS_GetObject($mediaID)['ObjectName'] ?? $document['fileName'];
                $row['url'] = $base . '/pdf?box=' . $box . '&document=' . $mediaID;
                $out[] = $row;
            }
        }
        usort($out, static fn($a, $b) => $b['mediaID'] <=> $a['mediaID']);
        return $out;
    }

    private function rememberWebInvoice(array $record, int $box, int $media): void
    {
        try {
            $index = $this->jsonAttribute('WebInvoices');
            $record['mediaID'] = $media;
            $index[$media] = WebView::receipt($record, $box);
            $index = array_slice($index, -2000, null, true);
            $this->WriteAttributeString('WebInvoices', $this->encode($index));
        } catch (\Throwable $error) { $this->SendDebug('Webansicht', 'Rechnungsindex: ' . $error->getMessage(), 0); }
    }

    private function recordWebHistory(array $telemetry): void
    {
        if (!$this->ReadPropertyBoolean('WebEnabled')) return;
        $now = $telemetry['time']; $last = (float)$this->GetBuffer('WebHistoryLast');
        if ($last && $now - $last < WebView::SAMPLE_SECONDS) return;
        try {
            $history = WebView::appendHistory($this->jsonAttribute('WebHistory'), $telemetry, $now);
            $this->WriteAttributeString('WebHistory', $this->encode($history));
            if ($history['samples']) $this->SetBuffer('WebHistoryLast', (string)end($history['samples'])['time']);
        } catch (\Throwable $error) { $this->SendDebug('Webansicht', 'Leistungsverlauf: ' . $error->getMessage(), 0); }
    }
}
