<?php
require __DIR__.'/../app/script-convoy-projection.php';
function checkProjection($yes,$why){if(!$yes)throw new RuntimeException($why);}
function sample($time,$value){return ['observed_at'=>'2026-10-18T'.$time.'Z','value'=>$value];}
$a='2026-10-18T10:00:00Z';$b='2026-10-18T12:15:00Z';
$r=scriptConvoyHarvestSegments($a,$b,[sample('09:00:00','100'),sample('11:00:00','200')]);
checkProjection($r['elapsed_cycles']==='4'&&$r['segments']===[['cycles'=>'1','capacity_per_cycle'=>'100'],['cycles'=>'3','capacity_per_cycle'=>'200']],'terrain changes apply at the next cycle, including exact boundaries');
$r=scriptConvoyHarvestSegments($a,$b,[sample('09:00:00','80'),sample('11:01:00','30')]);
checkProjection($r['segments']===[['cycles'=>'2','capacity_per_cycle'=>'80'],['cycles'=>'2','capacity_per_cycle'=>'30']],'terrain alone sets each cycle rate');
$r=scriptConvoyHarvestSegments($a,$b,[sample('11:00:00','100')]);checkProjection(!$r['history_complete']&&$r['segments'][0]['capacity_per_cycle']===null,'missing seed never backfilled from current terrain');
$r=scriptConvoyHarvestSegments($a,'2026-10-18T10:29:59Z',[sample('09:00:00','100')]);checkProjection($r['elapsed_cycles']==='0'&&$r['segments']===[],'no partial-cycle harvest');
$r=scriptConvoyHarvestSegments('2026-10-18T11:15:00Z',$b,[sample('09:00:00','100')]);checkProjection($r['elapsed_cycles']==='2','manual adjustment resets the baseline');
$r=scriptConvoyHarvestSegments('2026-10-18T10:00:00.500Z','2026-10-18T10:30:00.499Z',[sample('09:00:00','100')]);checkProjection($r['elapsed_cycles']==='0','fractional validation timestamps preserved');
$r=scriptConvoyHarvestSegments($a,$b,[sample('09:00:00','0')]);checkProjection($r['segments']===[['cycles'=>'4','capacity_per_cycle'=>'0']],'zero terrain preserved');
echo "Historical cycles OK: changing terrain, exact boundaries, fractional timestamps, missing history, adjustment baseline and zero capacity.\n";
