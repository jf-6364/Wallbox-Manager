<?php
declare(strict_types=1);
use WBM\{WebView, Validation};
require_once __DIR__.'/WebFixture.php';

test('Webansicht: optional, eindeutige Registrierung und unveränderte fremde WebHooks', function () {
    [$m,$ids,$hooks] = webFixture(false); check(json_decode($hooks->properties['Hooks'],true) === []);
    check(webRequest($m)['status'] === 404); $m->properties['WebEnabled'] = true;
    $foreign = ['Hook'=>'/hook/fremd','TargetID'=>123]; $hooks->properties['Hooks'] = json_encode([$foreign]); $m->ApplyChanges();
    $entries = json_decode($hooks->properties['Hooks'],true);
    check($entries === [$foreign,['Hook'=>'/hook/wallboxen','TargetID'=>50]]);
    $applied = $hooks->applied; $m->ApplyChanges(); check($hooks->applied === $applied);
    $m->properties['WebPath'] = '/hook/ladeplatz'; $m->ApplyChanges();
    check(json_decode($hooks->properties['Hooks'],true) === [$foreign,['Hook'=>'/hook/ladeplatz','TargetID'=>50]]);
    $m->properties['WebEnabled'] = false; $m->ApplyChanges(); check(json_decode($hooks->properties['Hooks'],true) === [$foreign]);
});
test('Webansicht: Pfadkonflikte und ungültige Zugangsdaten sperren nicht die Laderegelung', function () {
    [$m,$ids,$hooks] = webFixture(); $m->properties['WebPath'] = '/hook/fremd';
    $hooks->properties['Hooks'] = json_encode([['Hook'=>'/hook/wallboxen','TargetID'=>50],['Hook'=>'/hook/fremd','TargetID'=>123]]);
    $before = $hooks->properties['Hooks']; $m->ApplyChanges(); check($hooks->properties['Hooks'] === $before);
    check(str_contains($m->GetConfigurationForm(),'anderen Instanz')); check($m->status === 102);
    foreach (['WebPassword'=>'kurz','WebPath'=>'/hook/../secret','WebUser'=>'a:b'] as $key=>$value) {
        $old=$m->properties[$key]; $m->properties[$key]=$value; $m->ApplyChanges(); check($m->status === 102);
        check(Validation::properties($m->properties) === []); check(WebView::errors($m->properties) !== []); $m->properties[$key]=$old;
    }
});
test('Webansicht: Destroy entfernt ausschließlich den eigenen registrierten Pfad', function () {
    [$m,$ids,$hooks]=webFixture(); $hooks->properties['Hooks']=json_encode([['Hook'=>'/hook/wallboxen','TargetID'=>123],['Hook'=>'/hook/anderer','TargetID'=>456]]);
    $m->Destroy(); check(count(json_decode($hooks->properties['Hooks'],true)) === 2);
    [$m,$ids,$hooks]=webFixture(); $m->Destroy(); check(json_decode($hooks->properties['Hooks'],true) === []);
});
test('Webansicht: HTML, Daten und PDFs erfordern Anmeldung, Methoden werden begrenzt', function () {
    [$m]=webFixture();
    foreach (['','/api/status','/api/history','/api/invoices','/pdf?box=1&document=1'] as $route) {
        $response=webRequest($m,$route,'GET',null,false); check($response['status']===401); check(isset($response['headers']['WWW-Authenticate']));
    }
    check(webRequest($m,'/api/action')['status']===405); check(webRequest($m,'/api/status','POST')['status']===405);
    foreach (['/api/invoices?box[]=1','/api/invoices?offset=abc','/pdf?box=1&document[]=1'] as $route) check(webRequest($m,$route)['status']===400);
    check(webRequest($m,'/unknown')['status']===404); check(webRequest($m,'','HEAD')['body']==='');
    $response=$m->http(['REQUEST_URI'=>'/hook/wallboxen/api/status','PHP_AUTH_USER'=>'wallbox','PHP_AUTH_PW'=>'wrong']); check($response['status']===401);
    $response=$m->http(['REQUEST_URI'=>'/hook/wallboxen/api/status','PHP_AUTH_USER'=>'wallbox','PHP_AUTH_PW'=>'local-test-password']); check($response['status']===200);
});
test('Webansicht: POST benötigt CSRF und erlaubt nur die vorhandenen Bedienaktionen', function () {
    [$m,$ids]=webFixture(); $before=count(Kernel::$calls);
    check(webRequest($m,'/api/action','POST',['ident'=>'W1Manual','value'=>true],true,false)['status']===403);
    foreach (['ResetEnergy','ManagerEnabled','W0Manual','SetValue','WebPassword'] as $ident) check(webRequest($m,'/api/action','POST',['ident'=>$ident,'value'=>true])['status']===400);
    check(webRequest($m,'/api/action','POST',['ident'=>'W1Manual','value'=>1])['status']===400);
    check(count(Kernel::$calls)===$before);
    check(webRequest($m,'/api/action','POST',['ident'=>'W1Manual','value'=>true])['status']===200); check($m->GetValue('W1Manual'));
    check(webRequest($m,'/api/action','POST',['ident'=>'W1Manual','value'=>false])['status']===200); check(!$m->GetValue('W1Manual'));
    closeTo(GetValue($ids[1]['setpoint']),0);
});
test('Webansicht: Seitenabrufe sind lesend und exportieren keine Zugangsdaten, Adressen oder PDF-Bytes', function () {
    [$m]=webFixture(); $t=telemetryOf($m); $t['boxes'][1]['billing']['pdf']='PRIVATE-PDF-BYTES'; $t['boxes'][1]['billing']['totals']['extra']='PRIVATE-TOTAL';
    $t['boxes'][1]['billing']['last']=['id'=>'test','started'=>1,'totalEnergy'=>2,'mediaID'=>1,'invoice'=>['number'=>'RE-1','RecipientName'=>'PRIVATE-NAME','IBAN'=>'PRIVATE-IBAN'],'invoiceProfile'=>['SenderName'=>'PRIVATE-SENDER']];
    $m->attributes['Telemetry']=json_encode($t); $before=Kernel::$calls;
    foreach (['','/api/status','/api/history','/api/invoices'] as $route) {
        $r=webRequest($m,$route); check($r['status']===200); check($r['headers']['Cache-Control']==='no-store');
        foreach (['PRIVATE-TOTAL','PRIVATE-PDF-BYTES','PRIVATE-NAME','PRIVATE-IBAN','PRIVATE-SENDER','local-test-password',$m->attributes['WebSecret']] as $secret) check(!str_contains($r['body'],$secret),$route);
    }
    check(Kernel::$calls===$before);
    $html=webRequest($m)['body']; check(substr_count($html,'id="web-history"')===1); check(substr_count($html,'id="web-invoices"')===1);
    check(str_contains($html,'window.WBM_WEB=')); check(!str_contains($html,'/*__'));
    check(str_contains(webRequest($m)['headers']['Content-Security-Policy'],"script-src 'nonce-"));
});
test('Webansicht: Passwortwechsel entwertet vorhandene CSRF-Tokens', function () {
    [$m]=webFixture(); $old=WebView::token($m->properties,$m->attributes['WebSecret'],50); $secret=$m->attributes['WebSecret'];
    $m->Create(); check($secret===$m->attributes['WebSecret']); $m->properties['WebPassword']='new-test-password';
    check($old!==WebView::token($m->properties,$m->attributes['WebSecret'],50));
});
test('Webansicht: PDFs bleiben auf Rechnungsordner dieser Instanz und Wallbox begrenzt', function () {
    [$m]=webFixture(); $pdf=addWebInvoice($m,1);
    $r=webRequest($m,'/pdf?box=1&document='.$pdf); check($r['status']===200); check(str_starts_with($r['body'],'%PDF-1.4'));
    check($r['headers']['Content-Type']==='application/pdf'); check(isset($r['headers']['Content-Disposition']));
    check(webRequest($m,'/pdf?box=2&document='.$pdf)['status']===404);
    $foreign=IPS_CreateMedia(5); IPS_SetIdent($foreign,'Bill_test'); IPS_SetMediaContent($foreign,base64_encode('PRIVATE-FOREIGN'));
    check(webRequest($m,'/pdf?box=1&document='.$foreign)['status']===404);
    check(webRequest($m,'/pdf?box=5&document='.$pdf)['status']===404);
});
test('Webansicht: Archiv enthält neue Detaildaten und ältere PDFs, filterbar und paginiert', function () {
    [$m]=webFixture(); $first=addWebInvoice($m,1); $second=addWebInvoice($m,2); $third=addWebInvoice($m,1);
    $index=json_decode($m->attributes['WebInvoices'],true); check(count($index)===3); check($index[$first]['invoice']['number']!==$index[$third]['invoice']['number']);
    unset($index[$first]); $m->attributes['WebInvoices']=json_encode($index);
    $page=json_decode(webRequest($m,'/api/invoices?limit=2')['body'],true); check($page['total']===3); check($page['hasMore']); check(count($page['items'])===2);
    $older=json_decode(webRequest($m,'/api/invoices?offset=2&limit=2')['body'],true); check(!$older['hasMore']); check(!$older['items'][0]['detailed']);
    $filtered=json_decode(webRequest($m,'/api/invoices?box=2')['body'],true); check($filtered['total']===1); check($filtered['items'][0]['mediaID']===$second);
    check(!str_contains(json_encode($page),'invoiceProfile'));
});
test('Webansicht: Leistungsverlauf beginnt bei Aktivierung, bleibt begrenzt und bewahrt Messlücken', function () {
    $h=[];
    foreach (range(0,3000) as $i) $h=WebView::appendHistory($h,['boxes'=>[1=>['enabled'=>true,'measured'=>1000.0],2=>['enabled'=>true,'measured'=>null]]],$i*60.0+100);
    check(count($h['samples'])===2881); check($h['samples'][0]['time']>=3000*60+100-WebView::HISTORY_SECONDS);
    check($h['samples'][0]['boxes'][2]===null); $last=end($h['samples']);
    $same=WebView::appendHistory($h,['boxes'=>[]],$last['time']+20); check($same===$h);
    [$m]=webFixture(false); check(json_decode($m->attributes['WebHistory'],true)===[]);
    $m->properties['WebEnabled']=true; $m->ApplyChanges(); $h=json_decode($m->attributes['WebHistory'],true); check(count($h['samples'])===1);
    $before=$m->attributes['WebHistory']; $m->Control(); check($m->attributes['WebHistory']===$before);
    $m->buffers=[]; $m->Create(); $m->ApplyChanges(); check(count(json_decode($m->attributes['WebHistory'],true)['samples'])===1);
});

test('Webansicht: native WebHook-Struktur verlangt TargetID als Integer', function () {
    [$m,$ids,$hooks]=webFixture();
    check(json_decode($hooks->properties['Hooks'],true) === [['Hook'=>'/hook/wallboxen','TargetID'=>50]]);
    check($hooks->applied===1);
    check((json_decode($m->attributes['WebHookState'],true)['error'] ?? '')==='');
});
test('Webansicht: fehlgeschlagene Altregistrierung wird ohne Eingriff in fremde Hooks repariert', function () {
    foreach (['/hook/wallboxen','/hook/neu'] as $path) {
        [$m,$ids,$hooks]=webFixture(false); $m->properties['WebEnabled']=true; $m->properties['WebPath']=$path;
        $foreign=['Hook'=>'/hook/fremd','TargetID'=>123,'Extra'=>'bleibt'];
        $hooks->properties['Hooks']=json_encode([$foreign,['Hook'=>'/hook/wallboxen','Target'=>50]]);
        $m->attributes['WebHookState']='{}'; $m->ApplyChanges();
        check(json_decode($hooks->properties['Hooks'],true)===[$foreign,['Hook'=>$path,'TargetID'=>50]]);
        check($hooks->applied===1); check($m->status===102);
        $m->ApplyChanges(); check($hooks->applied===1);
        $m->properties['WebEnabled']=false; $m->ApplyChanges(); check(json_decode($hooks->properties['Hooks'],true)===[$foreign]);
    }
});
test('Webansicht: Abschalten oder Destroy bereinigen auch eine fehlgeschlagene Altregistrierung', function () {
    foreach (['disable','destroy'] as $action) {
        [$m,$ids,$hooks]=webFixture(false);
        $foreign=['Hook'=>'/hook/fremd','TargetID'=>123];
        $hooks->properties['Hooks']=json_encode([$foreign,['Hook'=>'/hook/wallboxen','Target'=>50]]);
        if ($action==='destroy') $m->Destroy(); else $m->ApplyChanges();
        check(json_decode($hooks->properties['Hooks'],true)===[$foreign]); check($hooks->applied===1);
    }
});
test('Webansicht: native Registrierungswarnungen bleiben lokal und unveränderte Entwürfe werden erneut übernommen', function () {
    foreach (['warning','false'] as $failure) foreach (['retry','rename','disable'] as $next) {
        [$m,$ids,$hooks]=webFixture(false); $m->properties['WebEnabled']=true; Kernel::$failures['webHookApply']=$failure;
        $warnings=[]; $handler=static function($severity,$message) use (&$warnings) { $warnings[]=$message; return true; };
        set_error_handler($handler);
        try {
            $m->ApplyChanges();
            $previous=set_error_handler($handler); check($previous===$handler); restore_error_handler();
        } finally { restore_error_handler(); }
        check($warnings===[]); check($m->status===102); check($hooks->applied===0);
        check((json_decode($m->attributes['WebHookState'],true)['error'] ?? '')!=='');
        if ($next==='rename') $m->properties['WebPath']='/hook/erneut';
        if ($next==='disable') $m->properties['WebEnabled']=false;
        unset(Kernel::$failures['webHookApply']); $m->ApplyChanges();
        $expected=$next==='disable' ? [] : [['Hook'=>$m->properties['WebPath'],'TargetID'=>50]];
        check(json_decode($hooks->properties['Hooks'],true)===$expected);
        check($hooks->applied===1); check((json_decode($m->attributes['WebHookState'],true)['error'] ?? '')==='');
        $m->ApplyChanges(); check($hooks->applied===1);
    }
});
