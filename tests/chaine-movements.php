<?php
require '/root/uptep-site/app/chaine.php';
function member(int $id,?string $grade,?int $order,?int $field):array{return ['player_id'=>$id,'chaine_grade'=>$grade,'sort_order'=>$order,'field'=>$field];}
function expect(array $players,array $kinds):void{$result=chaineMovements($players);foreach($kinds as $id=>$kind)if($result[$id]['kind']!==$kind)throw new RuntimeException('Unexpected direction for '.$id);}
expect([],[]);
expect([member(1,'Grenier',1,100),member(2,'Grenier',2,200),member(3,'Passeur',1,300),member(4,'Chasseur',1,400)],[1=>'up',2=>'up',3=>'down',4=>'down']);
expect([member(1,'Grenier',1,400),member(2,'Grenier',2,300),member(3,'Passeur',1,200),member(4,'Chasseur',1,100)],[1=>'aligned',2=>'aligned',3=>'aligned',4=>'aligned']);
expect([member(1,'Grenier',10,100),member(2,'Grenier',20,200),member(3,'A flooder',1,999),member(4,'Ne pas toucher',1,999),member(5,null,null,999)],[1=>'up',2=>'down',3=>'excluded',4=>'excluded',5=>'excluded']);
expect([member(1,'Grenier',1,200),member(2,'Passeur',1,200)],[1=>'aligned',2=>'aligned']);
expect([member(1,'Passeur',1,100),member(2,'Passeur',1,200)],[1=>'aligned',2=>'aligned']);
expect([member(1,'Chasseur',1,100),member(2,'Chasseur',null,200),member(3,'Grenier',1,null)],[1=>'up',2=>'down',3=>'excluded']);
echo "OK : grades prioritaires, ordres internes, positions correctes, égalités, grades exclus et données manquantes.\n";
expect([member(1,'Grenier',1,100),member(2,'Hors chaîne',1,999)],[1=>'aligned',2=>'excluded']);

expect([member(1,'Passeur',5,300),member(2,'Passeur',4,200),member(3,'Passeur',1,100)],[1=>'aligned',2=>'aligned',3=>'aligned']);
if(chaineOrderCompare(member(1,'Passeur',null,1),member(2,'Passeur',5,1))!==1)throw new RuntimeException('Unnumbered last');
