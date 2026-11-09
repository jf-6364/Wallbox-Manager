<?php
declare(strict_types=1);
// Local integration fixture only. Never loaded by the installed module or its WebHook.
function test(string $name, callable $fn): void {}
require_once __DIR__.'/../libs/bootstrap.php';
require_once __DIR__.'/adapter.php';
require_once __DIR__.'/WebFixture.php';

$stateFile = getenv('WBM_WEB_TEST_STATE');
if (!$stateFile) { http_response_code(500); exit('Missing fixture state path'); }
$lock = fopen($stateFile.'.lock','c'); flock($lock,LOCK_EX);
if (is_file($stateFile)) {
    $saved=json_decode(file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);
    foreach (['variables','objects','profiles','calls','reads','failures','media','next'] as $name) Kernel::${$name}=$saved['kernel'][$name];
    $m=new TestWebModule(50); $hooks=new TestHookControl(900);
    foreach ($saved['module'] as $name=>$value) $m->$name=$value;
    $hooks->properties=$saved['hooks'];
} else {
    [$m,$ids,$hooks]=webFixture();
    foreach ([1,2,3,4] as $id) addWebInvoice($m,$id,1.0+$id/2);
    $state=runtimeOf($m); $telemetry=telemetryOf($m); $now=microtime(true);
    foreach ([1,2,3,4] as $id) {
        $totals=\WBM\Billing::totals(); $totals['pvEnergy']=$id*1.25; $totals['normalEnergy']=$id*2.0;
        $totals['pvCost']=round($totals['pvEnergy']*.13,2); $totals['normalCost']=round($totals['normalEnergy']*.3,2);
        $totals['totalCost']=$totals['pvCost']+$totals['normalCost']; $totals['sessions']=$id;
        $state['billing'][$id]['totals']=$totals; $telemetry['boxes'][$id]['billing']['totals']=$totals;
        $state['energy'][$id]['energy']=$id*3.5; $telemetry['boxes'][$id]['energy']=$id*3.5;
    }
    $state['totalEnergy']=35.0; setRuntime($m,$state); $telemetry['energy']=35.0;
    $m->attributes['Telemetry']=json_encode($telemetry);
    $history=['started'=>$now-7200,'samples'=>[]];
    foreach (range(0,120) as $step) {
        $powers=[]; foreach ([1,2,3,4] as $id) $powers[$id]=round(max(0,sin(($step+$id*12)/18))*$id*1400,1);
        if ($step>30 && $step<36) $powers[2]=null;
        $history['samples'][]=['time'=>$now-7200+$step*60,'boxes'=>$powers];
    }
    $m->attributes['WebHistory']=json_encode($history);
    $m->SetBuffer('WebHistoryLast',(string)$now);
}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); $found=false;
foreach (json_decode($hooks->properties['Hooks'],true) as $hook) {
    if (($path===$hook['Hook'] || str_starts_with($path,$hook['Hook'].'/')) && (int)$hook['TargetID']===50) { $found=true; break; }
}
if ($found) $m->serve(); else { http_response_code(404); echo 'Not found'; }
$module=[]; foreach (['properties','attributes','buffers','references','messages','timers','idents','status','visualType','visualUpdates','debug'] as $name) $module[$name]=$m->$name;
// Do not retain an unbounded test log across HTTP requests.
$module['visualUpdates']=array_slice($module['visualUpdates'],-4); $module['debug']=array_slice($module['debug'],-20);
$kernel=[]; foreach (['variables','objects','profiles','calls','reads','failures','media','next'] as $name) $kernel[$name]=Kernel::${$name};
file_put_contents($stateFile.'.next',json_encode(['module'=>$module,'kernel'=>$kernel,'hooks'=>$hooks->properties],JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
rename($stateFile.'.next',$stateFile);
flock($lock,LOCK_UN); fclose($lock);
