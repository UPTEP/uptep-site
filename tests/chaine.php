<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/root/uptep-site/app/chaine.php';
$p=db();
// Session-local tables shadow UPTEP tables. FZZZ remains read-only.
$p->exec('CREATE TEMP TABLE users (LIKE public.users INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');
$p->exec('CREATE TEMP TABLE chaine_members (LIKE public.chaine_members INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING INDEXES)');
$p->exec("INSERT INTO users (id,username,display_name,password_hash,role,is_chaine,status,must_change_password) VALUES (901,'testeditor','Editor','test','member',true,'active',false),(902,'testadmin','Admin','test','admin',false,'active',false)");
function check(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
function denied(callable $call,int $code):void {try{$call();}catch(DomainException $e){check($e->getCode()===$code,'Unexpected failure');return;}throw new RuntimeException('Expected rejection');}
$players=chainePlayers();check(count($players)>0,'Source roster empty');$id=(int)$players[0]['player_id'];
$actor=['id'=>901,'session_version'=>1];
denied(fn()=>saveChaine(['id'=>902,'session_version'=>1],$id,0,'Grenier',1),403);
denied(fn()=>saveChaine($actor,$id,0,'Invalid',1),422);
denied(fn()=>saveChaine($actor,$id,0,'Grenier',0),422);
denied(fn()=>saveChaine($actor,PHP_INT_MAX,0,'Grenier',1),409);
saveChaine($actor,$id,0,'Grenier',2);
$row=$p->query('SELECT * FROM chaine_members')->fetch(PDO::FETCH_ASSOC);
check($row['grade']==='Grenier' && (int)$row['sort_order']===2 && (int)$row['version']===1,'Save grade/order');
denied(fn()=>saveChaine($actor,$id,0,'Passeur',3),409);
foreach(array_slice(CHAINE_GRADES,1) as $i=>$grade)saveChaine($actor,$id,$i+1,$grade,$i+3);
saveChaine($actor,$id,count(CHAINE_GRADES),'',null);
$row=$p->query('SELECT * FROM chaine_members')->fetch(PDO::FETCH_ASSOC);
check($row['grade']===null && $row['sort_order']===null && (int)$row['version']===count(CHAINE_GRADES)+1,'Clear values');
$p->exec('UPDATE users SET is_chaine=false WHERE id=901');
denied(fn()=>saveChaine($actor,$id,count(CHAINE_GRADES)+1,'Chasseur',3),403);
check(count(chainePlayers())===count($players),'Roster remains intact');
echo 'OK: lecture FZZZ, six grades, ordre, remise à zéro, concurrence, joueur hors alliance et contrôle du droit chaine (Admin seul refusé).'.PHP_EOL;
