<?php
declare(strict_types=1);
use WBM\{WebView, Billing, BillingPdf, Invoice};

class TestWebModule extends WallboxManager
{
    public function http(array $server, string $body = ''): array { return $this->webResponse($server, $body); }
    public function serve(): void { $this->ProcessHookData(); }
}
class TestHookControl extends IPSModule
{
    public string $moduleID = WebView::HOOK_MODULE;
    public int $applied = 0;
    public function ApplyChanges() {
        foreach (json_decode($this->properties['Hooks'], true, 512, JSON_THROW_ON_ERROR) as $hook) {
            if (!is_int($hook['TargetID'] ?? null)) { trigger_error('Expected value of type int for TargetID', E_USER_WARNING); return false; }
        }
        if ((Kernel::$failures['webHookApply'] ?? '') === 'warning') { trigger_error('WebHook-Konfiguration konnte nicht übernommen werden', E_USER_WARNING); return false; }
        if ((Kernel::$failures['webHookApply'] ?? '') === 'false') return false;
        $this->applied++;
        return true;
    }
}
function webFixture(bool $enabled = true): array
{
    [$original, $ids] = fixture(4, [], false);
    $m = new TestWebModule(50); $m->Create();
    $m->properties = array_replace($original->properties, ['WebEnabled' => $enabled, 'WebUser' => 'wallbox', 'WebPassword' => 'local-test-password',
        'BillingEnabled' => true, 'BillingNormalPrice' => .30, 'BillingPVPrice' => .13]);
    $hooks = new TestHookControl(900); $hooks->properties['Hooks'] = '[]';
    $m->ApplyChanges();
    foreach (range(1,4) as $box) Kernel::update($ids[$box]['power'], 0.0, microtime(true)); $m->Control();
    return [$m,$ids,$hooks];
}
function webRequest(TestWebModule $m, string $route = '', string $method = 'GET', ?array $action = null, bool $auth = true, bool $csrf = true): array
{
    $server = ['REQUEST_METHOD'=>$method,'REQUEST_URI'=>$m->properties['WebPath'].$route,'CONTENT_TYPE'=>'application/json'];
    if ($auth) $server['HTTP_AUTHORIZATION'] = 'Basic '.base64_encode($m->properties['WebUser'].':'.$m->properties['WebPassword']);
    if ($csrf) $server['HTTP_X_WBM_CSRF'] = WebView::token($m->properties,$m->attributes['WebSecret'],$m->InstanceID);
    return $m->http($server,$action === null ? '' : json_encode($action,JSON_THROW_ON_ERROR));
}
function addWebInvoice(TestWebModule $m, int $box, float $energy = 2.0): int
{
    $now = microtime(true); $s = runtimeOf($m);
    $session = ['id'=>bin2hex(random_bytes(10)),'wallbox'=>$box,'name'=>'Wallbox '.$box,'device'=>'test','started'=>$now-3600,'timezone'=>'Europe/Berlin',
        'method'=>'berechnet','warnings'=>[],'lines'=>[['mode'=>'pv','price'=>.13,'energy'=>$energy/2],['mode'=>'normal','price'=>.30,'energy'=>$energy/2]]];
    $record = Billing::record($session) + ['ended'=>$now,'reason'=>'Ladevorgang manuell gestoppt','mediaID'=>0,'fileName'=>'Abrechnung.pdf'];
    $s['billing'][$box] ??= ['totals'=>Billing::totals()];
    $s['billing'][$box]['pending'][$record['id']] = $record; $s['billing'][$box]['last'] = $record;
    setRuntime($m,$s); $m->Control();
    return runtimeOf($m)['billing'][$box]['last']['mediaID'];
}
