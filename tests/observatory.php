<?php
require '/home/uptep/private/app/bootstrap.php';require '/root/uptep-site/app/observatory.php';
function ok($v,$m){if(!$v)throw new RuntimeException($m);}
$a=observatory(['action'=>'stats','kind'=>'alliances','q'=>'UPTEP']);ok($a['entity']['tag']==='UPTEP','Alliance');ok(count($a['metrics'])===5,'Alliance metrics');
$s=observatory(['action'=>'suggest','kind'=>'players','q'=>'Noob']);ok(count($s['suggestions'])>0,'Suggestions');
$p=observatory(['action'=>'stats','kind'=>'players','q'=>$s['suggestions'][0]['value']]);ok(count($p['metrics'])===4,'Player metrics');ok(count($p['history'])>0,'History');
$times=array_column($p['history'],'timestamp');$ordered=$times;sort($ordered);ok($times===$ordered,'Chronological order');ok(count(array_unique(array_column($p['history'],'export_version')))===count($p['history']),'No duplicates');
foreach(['players','alliances'] as $kind){$x=observatory(['action'=>'suggest','kind'=>$kind,'q'=>"%'_!?notfound"]);ok($x['suggestions']===[],'Wildcards escaped');}
try{observatory(['action'=>'stats','kind'=>'users','q'=>'test']);throw new RuntimeException('Invalid type accepted');}catch(DomainException $e){ok($e->getCode()===422,'Invalid type');}
$overview=observatory(['action'=>'overview']);ok($overview['counts']['players']>0,'Counts');
echo 'OK : lecture FZZZ via UPTEP, autocomplétion, joueurs/alliances, métriques, historique chronologique sans doublons et validation. Relevés test : '.count($p['history']).'/'.count($a['history']).PHP_EOL;
