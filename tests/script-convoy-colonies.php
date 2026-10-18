<?php
require __DIR__.'/../app/script-convoy-projection.php';
function colonyCheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
$players=['1'=>['field'=>'1000','master_player_id'=>'2','alliance_tag'=>'UPTEP'],'2'=>['field'=>'2000','master_player_id'=>null,'alliance_tag'=>'UPTEP'],'3'=>['field'=>'500','master_player_id'=>'1','alliance_tag'=>'UPTEP'],'4'=>['field'=>'400','master_player_id'=>'2','alliance_tag'=>'OTHER']];
$a=scriptConvoyColonyCapacity('1',$players,['1'=>'0','2'=>'2']);$b=scriptConvoyColonyCapacity('2',$players,['1'=>'0','2'=>'2']);
colonyCheck($a['loss']['percent']===22&&$a['gains']===[['terrain'=>'500','percent'=>20]],'loss uses master stable; gains use own stable');
colonyCheck($b['gains']===[['terrain'=>'1000','percent'=>22]],'external colonies ignored');
$x=$players;$x['2']['alliance_tag']='OTHER';colonyCheck(scriptConvoyColonyCapacity('1',$x,['1'=>'0','2'=>'2'])['loss']===null,'external master ignored');
$x=$players;$x['1']['alliance_tag']='OTHER';$c=scriptConvoyColonyCapacity('1',$x,[]);colonyCheck($c['loss']===null&&$c['gains']===[],'outside target has no internal transfer');
$c=scriptConvoyColonyCapacity('1',$players,['1'=>'0']);colonyCheck($c['loss']['percent']===20&&$c['assumed_rates'],'unknown stable explicitly uses approximate base rate');
colonyCheck(scriptConvoyColonyCapacity('1',$players,['1'=>'0'],false)===null,'strict unknown-level mode available');
$base=array_intersect_key($players,array_flip(['1','2']));$away=$base;$away['1']['alliance_tag']='OTHER';
$timeline=scriptConvoyColonyTimeline('1',[
 ['observed_at'=>'2026-10-18T09:00:00Z','players'=>$base],['observed_at'=>'2026-10-18T11:00:00Z','players'=>$away],['observed_at'=>'2026-10-18T12:00:00Z','players'=>$base]
],[['observed_at'=>'2026-10-18T08:00:00Z','player_id'=>'2','level'=>'0'],['observed_at'=>'2026-10-18T10:45:00Z','player_id'=>'2','level'=>'2']]);
$fold=scriptConvoyHarvestSegments('2026-10-18T10:00:00Z','2026-10-18T12:15:00Z',$timeline);
colonyCheck(array_column($fold['segments'],'cycles')===['1','2','1'],'alliance changes and stable observations keep cycle chronology');
colonyCheck($fold['segments'][0]['capacity_per_cycle']['loss']['percent']===20&&$fold['segments'][1]['capacity_per_cycle']['loss']===null&&$fold['segments'][2]['capacity_per_cycle']['loss']['percent']===22,'internal status applied at each historical period');
// A stable update exactly at the snapshot must be applied before that cycle.
$timeline=scriptConvoyColonyTimeline('1',[['observed_at'=>'2026-10-18T10:00:00Z','players'=>$base]],[['observed_at'=>'2026-10-18T10:00:00Z','player_id'=>'2','level'=>'2']]);
colonyCheck(count($timeline)===1&&$timeline[0]['value']['loss']['percent']===22,'simultaneous observations merged');
echo "Internal colonies OK: master stable, debit/credit symmetry, external exclusion, unknown/zero level, historical membership and level changes.\n";
