<?php
require '/home/uptep/private/app/bootstrap.php';
require '/root/uptep-site/app/observatory.php';
function check($v,$message){if(!$v)throw new RuntimeException($message);}
function snapshot($hour,$pacts,$wars=[]){return ['exported_at'=>'2026-10-08 '.$hour.':00:00','diplomacy'=>json_encode(['pacts'=>$pacts,'wars'=>$wars])];}
$a=['tag'=>'TEST','name'=>'PNA','description'=>'Test'];$b=$a;$b['description']='Modifié';
$r=diplomacyDates([snapshot('10',[$a]),snapshot('11',[$a]),snapshot('12',[$b],['WAR']),snapshot('13',[]),snapshot('14',[$a])]);
check(count($r['events'])===5,'Modification, guerre, fins et retour');check($r['dates']['pacts:TEST']['at_least']===false,'Retour du pacte daté depuis son nouvel épisode');check(!isset($r['dates']['wars:WAR']),'Guerre retirée');
$first=diplomacyDates([snapshot('10',[$a])]);check($first['events']===[],'Pas de création inventée au premier relevé');check($first['dates']['pacts:TEST']['at_least']===true,'Date minimale explicite');check($first['dates']['pacts:TEST']['datetime']==='08/10/2026 à 12:00','Conversion Paris');
foreach(['UPTEP','OUAF'] as $tag){$data=observatory(['action'=>'stats','kind'=>'alliances','q'=>$tag]);if(!$data['entity'])continue;check(count($data['map'])>0,'Carte présente');$previous=PHP_INT_MAX;foreach($data['movements'] as $m){check($m['before']!==$m['after'],'Changement réel');check($m['before']===$tag||$m['after']===$tag,'Alliance concernée');check($m['timestamp']<=$previous,'Ordre décroissant');$previous=$m['timestamp'];}echo $tag.': '.count($data['movements']).' mouvements, '.count($data['map']).' coordonnées'.PHP_EOL;}
echo "OK : diplomatie datée, historique et carte.\n";
