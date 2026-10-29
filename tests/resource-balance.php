<?php
declare(strict_types=1);
require __DIR__.'/../app/resource-balance.php';
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function near(float $a,float $b):bool{return abs($a-$b)<1e-6;}
$p=new PDO('pgsql:dbname=uptep',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='balance_test_'.bin2hex(random_bytes(5));
$p->beginTransaction();
try{
$p->exec('CREATE SCHEMA '.$schema);$p->exec('SET LOCAL search_path TO '.$schema);
$p->exec("CREATE TABLE players(server text,player_id bigint,pseudo text,alliance_tag text,field bigint,is_present boolean);
CREATE TABLE historique_exports(id bigint,server text,export_type text,export_version text,exported_at timestamp);
CREATE TABLE historique_players(export_id bigint,player_id bigint,alliance_tag text,field bigint,master_player_id bigint);
CREATE TABLE users(id bigint,status text,game_player_id bigint);
CREATE TABLE user_stats(user_id bigint,data jsonb);
CREATE TABLE demandes_convois(id bigint,serveur text,iddemandeur bigint);
CREATE TABLE convois_effectues(id bigint,iddemande bigint,idenvoyeur bigint,nbpomme bigint,nbmateriaux bigint,date_convoi timestamptz,annule boolean);
INSERT INTO players VALUES('s5',1,'Alpha','UPTEP',100,true),('s5',2,'Beta','uptep',300,true),('s5',3,'<b>Gamma</b>','UPTEP',200,true),
 ('s5',4,'Departed','OTHER',1000,true),('s5',5,'Gone','UPTEP',50,false),('s1',6,'Wrong server','UPTEP',50,true);
-- Start = 11/10 00:00 Paris = 10/10 22:00 UTC. Export 2 is in force at the start; 3 and 4 share a version (4 = correction).
INSERT INTO historique_exports VALUES(1,'s5','players','v1','2026-10-09 22:00'),(2,'s5','players','v2','2026-10-10 21:00'),
 (3,'s5','players','v3','2026-10-10 23:00'),(4,'s5','players','v3','2026-10-10 23:00'),(5,'s5','alliances','v3','2026-10-10 23:00'),(6,'s5','players','v4','2026-10-11 00:30');
INSERT INTO historique_players VALUES
 (1,1,'UPTEP',90,0),(1,2,'OTHER',250,0),(1,4,'UPTEP',900,0),
 (2,1,'UPTEP',100,0),(2,2,'UPTEP',300,1),(2,3,'OTHER',150,0),(2,4,'UPTEP',1000,0),
 (3,1,'UPTEP',999,0),(3,2,'UPTEP',999,1),(3,3,'OTHER',999,0),
 (4,1,'UPTEP',100,1),(4,2,'UPTEP',300,1),(4,3,'UPTEP',200,4),(4,4,'OTHER',1000,0),
 (6,1,'UPTEP',5000,0);
INSERT INTO users VALUES(10,'active',1);INSERT INTO user_stats VALUES(10,'{\"buildings\":{\"etable_pucerons\":\"5\"}}');
INSERT INTO demandes_convois VALUES(1,'s5',1),(2,'s5',4),(3,'s1',1);
INSERT INTO convois_effectues VALUES
 (1,1,2,0,200,'2026-10-10T23:10:00Z',false),(2,1,2,0,999,'2026-10-10T23:20:00Z',true),(3,1,2,0,999,'2026-10-10T21:00:00Z',false),
 (4,2,3,0,50,'2026-10-10T23:40:00Z',false),(5,3,2,0,999,'2026-10-10T23:40:00Z',false),(6,1,2,500,0,'2026-10-10T23:40:00Z',false),
 (7,1,2,0,999,'2026-10-11T00:10:00Z',false);");
$now=new DateTimeImmutable('2026-10-11T00:00:00Z');
$data=resourceBalanceData($p,$p,$now);$rows=array_column($data['rows'],null,'pseudo');
check(array_keys($rows)===['Alpha','Beta','<b>Gamma</b>'],'current UPTEP members only, case-insensitive tag');
// Cycles 22:00 and 22:30 use export 2 (Alpha 100, Beta 300 colony of Alpha; Departed left out), 23:00 to 00:00 use corrected export 4.
check($data['cycles']===5&&near($data['total'],2600.0)&&near($data['current'],1200.0),'five cycles, deduplicated exports, alliance total');
check(near($rows['Alpha']['production'],500.0)&&near($rows['Beta']['production'],1500.0)&&near($rows['<b>Gamma</b>']['production'],600.0),'production per cycle = terrain');
check(near($rows['Alpha']['part'],1000.0)&&near($rows['Beta']['part'],1000.0)&&near($rows['<b>Gamma</b>']['part'],600.0),'equal share between members present');
check(near($rows['Alpha']['taxe_recue'],375.0)&&near($rows['Beta']['taxe_payee'],375.0)&&near($rows['<b>Gamma</b>']['taxe_payee'],0.0)&&near($rows['Alpha']['taxe_payee'],0.0),'colony tax 25 %, internal only, never to oneself');
check($data['colonies']===1&&!$data['assumed_rates'],'one counted colony, known stable level');
check(near($rows['Alpha']['recu'],200.0)&&near($rows['Beta']['envoye'],200.0)&&near($rows['<b>Gamma</b>']['envoye'],50.0),'materials convoys: cancelled, early, late, other server and apples excluded');
check($data['convoys']===2&&near($data['materials'],250.0)&&near($data['outside'],50.0),'convoy totals, departed counterpart');
check(near($rows['Alpha']['ecart'],75.0)&&$rows['Alpha']['verdict']==='convoyer'&&near($rows['Beta']['ecart'],-75.0)&&$rows['Beta']['verdict']==='recevoir','gap and verdict');
check(near(array_sum(array_column($data['rows'],'part')),$data['total'])&&near(array_sum(array_column($data['rows'],'colonie')),0.0),'shares and taxes balance');
check($rows['Alpha']['entree']->format('Y-m-d H:i')==='2026-10-09 22:00'&&$rows['Beta']['entree']->format('Y-m-d H:i')==='2026-10-10 21:00'&&$rows['<b>Gamma</b>']['entree']->format('Y-m-d H:i')==='2026-10-10 23:00','entry = start of the current UPTEP streak');
check(array_column(resourceBalanceSort($data['rows'],'ecart',false),'pseudo')===['Alpha','<b>Gamma</b>','Beta'],'default sort: largest gap first');
check(array_column(resourceBalanceSort($data['rows'],'unknown',true),'pseudo')===['Alpha','<b>Gamma</b>','Beta'],'unknown sort key falls back');
check(array_column(resourceBalanceSort($data['rows'],'pseudo',true),'pseudo')===['<b>Gamma</b>','Alpha','Beta'],'name sort');
$svg=resourceBalanceSvg($data);check(str_contains($svg,'<svg')&&!str_contains($svg,'style='),'chart without inline style');
$p->exec('DELETE FROM user_stats');$assumed=resourceBalanceData($p,$p,$now);$beta=array_column($assumed['rows'],null,'pseudo')['Beta'];
check($assumed['assumed_rates']&&near($beta['taxe_payee'],300.0),'unknown stable level: 20 %');
$early=resourceBalanceData($p,$p,new DateTimeImmutable('2026-10-10T20:00:00Z'));check($early['cycles']===0&&!$early['rows'],'nothing before the start');
check(resourceCompact(1093071255.0)==='1,09G'&&resourceCompact(-1500.0)==='−1,5k'&&resourceCompact(42.0)==='42','compact numbers');
echo "OK: current members only, deduplicated exports, cycles, equal shares, internal colony tax, materials convoys, gaps, entry dates, sorting and chart.\n";
}finally{$p->rollBack();}
