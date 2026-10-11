<?php
require '/root/uptep-site/app/player-events.php';
function ok($v){if(!$v)throw new RuntimeException('Event test failed');}
function snapshot($changes=[]){return array_replace(['master_player_id'=>null,'on_holiday'=>false,'is_banned'=>false,'alliance_tag'=>null,'grade'=>null,'exported_at'=>'2026-10-08 19:00:00+00','export_version'=>'202610081900'],$changes);}
$a=snapshot();$b=snapshot(['master_player_id'=>834,'on_holiday'=>true,'is_banned'=>true,'alliance_tag'=>'UPTEP','grade'=>'Fou du bus','exported_at'=>'2026-10-08 20:00:00+00']);
ok(playerEvents([$b])===[]);ok(playerEvents([$a,$a])===[]);
$events=playerEvents([$a,$b]);ok(count($events)===5);ok($events[0]['datetime']==='08/10/2026 à 22:00');
$reverse=playerEvents([$b,snapshot(['exported_at'=>'2026-10-09 08:00:00+00'])]);ok(count($reverse)===5);foreach($reverse as $e)ok(in_array($e['after'],[null,false],true));
$changed=playerEvents([$b,array_replace($b,['master_player_id'=>835])]);ok(count($changed)===1&&$changed[0]['before']==='834'&&$changed[0]['after']==='835');
ok(playerEvents([$a,array_replace($a,['master_player_id'=>0,'grade'=>'','alliance_tag'=>''])])===[]);
ok(playerEvents([$a,array_replace($a,['on_holiday'=>null,'is_banned'=>null])])===[]);
echo "OK : 5 types de changements, événements inverses, changement de colonisateur, absence de faux événement initial et heures de Paris.\n";
