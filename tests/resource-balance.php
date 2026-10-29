<?php
declare(strict_types=1);
require __DIR__.'/../app/resource-balance.php';
require __DIR__.'/../app/resource-activity.php';
require __DIR__.'/../app/convoy-consumption.php';
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
CREATE TABLE user_stats(user_id bigint,data jsonb,resources_synced_at timestamptz);
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
INSERT INTO users VALUES(10,'active',1),(11,'active',2),(12,'active',3),(13,'disabled',2);
-- Readings: Alpha 22:40 and Beta 23:05 (recent), Gamma 08/10 (older than 48 h).
INSERT INTO user_stats VALUES(10,'{\"buildings\":{\"etable_pucerons\":\"5\"},\"resources\":{\"materiaux\":\"1000\"}}','2026-10-10T22:40:00Z'),
 (11,'{\"resources\":{\"materiaux\":\"500\"}}','2026-10-10T23:05:00Z'),(12,'{\"resources\":{\"materiaux\":\"9999\"}}','2026-10-08T00:00:00Z'),(13,'{\"resources\":{\"materiaux\":\"1\"}}','2026-10-10T23:59:00Z');
INSERT INTO demandes_convois VALUES(1,'s5',1),(2,'s5',4),(3,'s1',1);
INSERT INTO convois_effectues VALUES
 (1,1,2,0,200,'2026-10-10T23:10:00Z',false),(2,1,2,0,999,'2026-10-10T23:20:00Z',true),(3,1,2,0,999,'2026-10-10T21:00:00Z',false),
 (4,2,3,0,50,'2026-10-10T23:40:00Z',false),(5,3,2,0,999,'2026-10-10T23:40:00Z',false),(6,1,2,500,0,'2026-10-10T23:40:00Z',false),
 (7,1,2,0,999,'2026-10-11T00:10:00Z',false);
ALTER TABLE convois_effectues ADD COLUMN date_arrivee timestamptz;UPDATE convois_effectues SET date_arrivee='2026-10-10T23:50:00Z' WHERE id=1;
CREATE TABLE chaine_members(server text,player_id bigint,grade text);INSERT INTO chaine_members VALUES('s5',1,'Grenier');
CREATE TABLE floods(id bigint,sender_id bigint,target_id bigint,location text,troops text,arrival_at timestamptz);
INSERT INTO floods VALUES(1,1,2,'Terrain de chasse','15 888 Jeunes Soldates Naines, 627 Soldates Naines.','2026-10-10T23:00:00Z'),
 (2,1,2,'Terrain de chasse',E'407 Jeunes Soldates Naines.\nArrivée demain à 00h21','2026-10-10T23:30:00Z'),(3,2,1,'Loge',NULL,'2026-10-10T23:40:00Z'),
 (4,4,3,'Terrain de chasse','371 917 193 000 Jeunes Soldates Naines.','2026-10-10T23:45:00Z'),(5,1,3,'Terrain de chasse','10 Soldates.','2026-10-11T01:00:00Z'),
 (6,1,2,'Terrain de chasse','99 Soldates.','2026-10-10T20:00:00Z');
CREATE TABLE script_hunts(id bigint,player_id bigint,area bigint,arrival_at timestamptz,cancelled_at timestamptz);
INSERT INTO script_hunts VALUES(1,1,1000,'2026-10-10T22:30:00Z',NULL),(2,1,3000,'2026-10-10T23:30:00Z',NULL),(3,1,500,'2026-10-10T23:00:00Z','2026-10-10T22:00:00Z'),
 (4,1,700,'2026-10-11T02:00:00Z',NULL),(5,2,800,'2026-10-09T10:00:00Z',NULL),(6,4,9999,'2026-10-10T23:00:00Z',NULL);");
$now=new DateTimeImmutable('2026-10-11T00:00:01Z');
$data=resourceBalanceData($p,$p,now:$now);$rows=array_column($data['rows'],null,'pseudo');
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
// Alpha: 1000 + 3 cycles × (100 + 75 tax) + 200 arrived at 23:50. Beta: 500 + 2 cycles × (300 − 75) − 200 sent at 23:10.
check(near($rows['Alpha']['stock'],1725.0)&&$rows['Alpha']['stock_cycles']===3&&near($rows['Alpha']['stock_recu'],200.0),'stock: reading + cycles with colony tax + arrived convoy');
check(near($rows['Beta']['stock'],750.0)&&near($rows['Beta']['stock_envoye'],200.0)&&$rows['Beta']['releve']['materiaux']===500.0,'stock: convoy sent after the reading, disabled duplicate account ignored');
check($rows['<b>Gamma</b>']['stock']===null&&$rows['<b>Gamma</b>']['releve']!==null,'stock: reading older than 48 h not estimated');
$transit=array_column(resourceBalanceData($p,$p,now:new DateTimeImmutable('2026-10-10T23:45:00Z'))['rows'],null,'pseudo');
check(near($transit['Alpha']['stock'],1350.0)&&near($transit['Alpha']['stock_recu'],0.0)&&near($transit['Beta']['stock'],525.0),'stock: convoy still travelling not received');
check(array_column(resourceBalanceSort($data['rows'],'stock',false),'pseudo')===['Alpha','Beta','<b>Gamma</b>'],'stock sort, unknown last');
// Period 23:00 → 23:30: one cycle on export 4 and convoy 1 only; the stock stays as of now.
$period=resourceBalanceData($p,$p,new DateTimeImmutable('2026-10-10T23:00:00Z'),new DateTimeImmutable('2026-10-10T23:30:00Z'),$now);$pr=array_column($period['rows'],null,'pseudo');
check($period['cycles']===1&&near($period['total'],600.0)&&near($pr['Alpha']['production'],100.0)&&near($pr['Alpha']['part'],200.0)&&near($pr['Alpha']['taxe_recue'],75.0),'period: cycles inside [start, end) only');
check($period['convoys']===1&&near($pr['Alpha']['recu'],200.0)&&near($pr['<b>Gamma</b>']['envoye'],0.0)&&$period['hours']===[],'period: convoys by departure inside it, cut hour not charted');
check(near($pr['Alpha']['stock'],1725.0)&&near($pr['Beta']['stock'],750.0),'period: estimated stock unchanged');
$paris=new DateTimeZone('Europe/Paris');$clock=new DateTimeImmutable('2026-10-20T12:00:00',$paris);
[$f,$e]=resourceBalanceRange([],$clock);check($f->format('Y-m-d H:i')==='2026-10-11 00:00'&&$e==$clock,'range: default from the start to now');
[$f,$e]=resourceBalanceRange(['debut'=>'2026-10-12','fin'=>'2026-10-13'],$clock);check($f->format('c')==='2026-10-12T00:00:00+02:00'&&$e->format('c')==='2026-10-14T00:00:00+02:00','range: end date included');
[$f,$e]=resourceBalanceRange(['debut'=>'2026-10-19','fin'=>'2026-10-25'],$clock);check($e==$clock,'range: end capped at now');
foreach([['debut'=>'2026-10-10'],['debut'=>'2026-10-14','fin'=>'2026-10-13'],['debut'=>'2026-10-21'],['debut'=>'12/10/2026'],['fin'=>'2026-02-30']] as $bad){
    try{resourceBalanceRange($bad,$clock);$ok=false;}catch(DomainException $ex){$ok=$ex->getCode()===422;}check($ok,'range rejected: '.json_encode($bad));}
check(array_column(resourceBalanceSort($data['rows'],'ecart',false),'pseudo')===['Alpha','<b>Gamma</b>','Beta'],'default sort: largest gap first');
check(array_column(resourceBalanceSort($data['rows'],'unknown',true),'pseudo')===['Alpha','<b>Gamma</b>','Beta'],'unknown sort key falls back');
check(array_column(resourceBalanceSort($data['rows'],'pseudo',true),'pseudo')===['<b>Gamma</b>','Alpha','Beta'],'name sort');
$svg=resourceBalanceSvg($data);check(str_contains($svg,'<svg')&&!str_contains($svg,'style='),'chart without inline style');
$p->exec('DELETE FROM user_stats');$assumed=resourceBalanceData($p,$p,now:$now);$beta=array_column($assumed['rows'],null,'pseudo')['Beta'];
check($assumed['assumed_rates']&&near($beta['taxe_payee'],300.0),'unknown stable level: 20 %');
$early=resourceBalanceData($p,$p,now:new DateTimeImmutable('2026-10-10T20:00:00Z'));check($early['cycles']===0&&!$early['rows'],'nothing before the start');
foreach(['1,09G'=>1093071255.0,'−1,5k'=>-1500.0,'42'=>42.0,'400k'=>400000.0,'120k'=>119969.0,'100M'=>1e8,'46,5M'=>46512628.0,'1M'=>999950.0,'1k'=>999.6,'2,5T'=>2.5e12,'1 500T'=>1.5e15,'0'=>-0.2]
    as $want=>$v)check(resourceCompact($v)===(string)$want,'compact '.$v.' = '.$want.', got '.resourceCompact($v));
// Floods and hunts over [22:00, now): current members only, troops from the declared text.
foreach(["407 Jeunes Soldates Naines.\nArrivée demain à 00h21"=>407.0,'15 888 Jeunes Soldates Naines, 627 Soldates Naines.'=>16515.0,'9 999 Jeunes Soldates Naines'=>9999.0,'bla'=>null,'371 917 193 000 Jeunes Soldates Naines.'=>null] as $text=>$want)
    check(resourceTroops((string)$text)===$want,'troops '.$text);
check(resourceTroops(null)===null,'troops unknown');
$names=['1'=>'Alpha','2'=>'Beta','3'=>'<b>Gamma</b>'];$window=[new DateTimeImmutable('2026-10-10T22:00:00Z'),$now];
$fl=resourceFloodStats($p,$names,...[...$window,$now]);$fr=array_column($fl['rows'],null,'pseudo');
check($fl['total']['floods']===4&&near($fl['total']['troupes'],16922.0)&&$fl['total']['inconnues']===2&&$fl['total']['loge']===1&&$fl['total']['en_route']===1,'flood totals: period, unreadable and unknown troops, in transit');
check($fr['Alpha']['envoyes_connus']===2&&$fr['Beta']['envoyes_connus']===0&&$fr['Alpha']['recus']===1&&near($fr['Alpha']['troupes_envoyees'],16922.0)&&$fr['Beta']['recus']===2&&$fr['Beta']['envoyes']===1&&$fr['<b>Gamma</b>']['recus']===1,'floods per member, departed sender left out');
check($fr['Alpha']['dernier']->setTimezone(new DateTimeZone('UTC'))->format('H:i')==='23:30'&&$fl['rows'][0]['pseudo']==='Alpha','last flood and order');
$hu=resourceHuntStats($p,$names,...[...$window,$now]);$hr=array_column($hu['rows'],null,'pseudo');
check(array_keys($hr)===['Alpha','Beta']&&$hu['total']['suivis']===2,'hunts: followed members only');
check($hr['Alpha']['chasses']===2&&near($hr['Alpha']['terrain'],4000.0)&&near($hr['Alpha']['moyenne'],2000.0)&&$hr['Alpha']['en_cours']===1&&near($hr['Alpha']['terrain_en_cours'],700.0),'hunts: period, cancelled excluded, in progress now');
check($hr['Beta']['chasses']===0&&$hr['Beta']['moyenne']===null&&$hr['Beta']['derniere']->format('Y-m-d')==='2026-10-09'&&near($hu['total']['terrain'],4000.0),'hunts: followed without hunt in the period');
// Consumption chart with materials convoys only: the apples-only convoy 6 no longer counts.
$all=array_column(convoyConsumptionData($p,$p,...$window)['rows'],null,'pseudo');$mat=array_column(convoyConsumptionData($p,$p,...[...$window,true])['rows'],null,'pseudo');
check($all['Alpha']['conv_in']>$mat['Alpha']['conv_in']&&near($mat['Alpha']['conv_in']*($now->getTimestamp()-$window[0]->getTimestamp())/86400,200.0),'consumption: materials only');
echo "OK: current members only, deduplicated exports, cycles, equal shares, internal colony tax, materials convoys, gaps, periods, sorting, estimated stocks, floods, hunts, materials consumption and chart.\n";
}finally{$p->rollBack();}
