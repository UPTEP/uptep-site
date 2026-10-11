<?php
require '/root/uptep-site/app/chaine-focus.php';
function p(int $id,?string $grade,?int $order,?int $field,bool $holiday=false,bool $banned=false):array{return ['player_id'=>$id,'chaine_grade'=>$grade,'sort_order'=>$order,'field'=>$field,'on_holiday'=>$holiday,'is_banned'=>$banned];}
function ok(bool $condition):void{if(!$condition)throw new RuntimeException('Focus test failed');}
$players=[p(1,'Grenier',2,100),p(2,'Grenier',1,100),p(3,'Grenier',3,50),p(4,'Passeur',1,300),p(5,'Chasseur',1,49),p(6,'Passeur',2,301),p(7,'Ne pas toucher',1,100),p(8,'A flooder',null,100),p(9,null,null,100),p(10,'Passeur',3,100,true),p(11,'Chasseur',1,100,false,true),p(12,'Grenier',2,100),p(13,'Grenier',null,100),p(14,'Hors chaîne',1,100)];
$f=chaineFocus($players,1);ok($f['self']===1);ok($f['targets']===[3,4,8]);
$reverse=chaineFocus(array_reverse($players),1);$ids=$reverse['targets'];sort($ids);ok($ids===[3,4,8]);
ok(chaineFocus($players,null)['reason']==='unlinked');ok(chaineFocus($players,999)['reason']==='absent');ok(chaineFocus($players,9)['reason']==='unranked');
ok(chaineFocus([p(1,'Passeur',1,101),p(2,'Passeur',2,50),p(3,'Passeur',3,51)],1)['targets']===[3]);
ok(chaineFocus([p(1,'Grenier',1,PHP_INT_MAX),p(2,'Passeur',1,PHP_INT_MAX)],1)['targets']===[2]);
ok(chaineFocus([p(1,'Grenier',1,100,true),p(2,'Passeur',1,100)],1)['targets']===[]);
echo "OK : position personnelle, bornes 50–300 %, grades/ordres, exclusions, égalités et indépendance du tri.\n";
ok(chaineFocus($players,14)['targets']===[]);
