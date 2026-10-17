<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/combat-reports.php';
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function post(string $key):string{return is_string($_POST[$key]??null)?$_POST[$key]:'';}
function db(bool $fzzz=false):PDO {global $database,$players;return $fzzz?$players:$database;}
$database=new PDO('pgsql:dbname=uptep',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$players=new PDO('pgsql:dbname=fzzz',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$players->exec('SET default_transaction_read_only=on');
$schema='combat_test_'.bin2hex(random_bytes(6));
try{
$database->exec('CREATE SCHEMA '.$schema);$database->exec('SET search_path TO '.$schema);
$database->exec("CREATE TABLE users(id bigint PRIMARY KEY,role text,status text,must_change_password boolean,session_version int); INSERT INTO users VALUES(1,'admin','active',false,1),(2,'member','active',false,1)");
$database->exec(file_get_contents(dirname(__DIR__).'/database/combat-reports.sql'));
// Legacy two-camp fixtures: migrate both choices, preserving only the selected camp.
foreach(['attacker','defender'] as $choice){
 $q=$database->prepare("INSERT INTO combat_reports(raw_text,content_hash,combat_at,date_source,target_side) VALUES('legacy',?,'2026-10-15T12:00:00Z','manual',?) RETURNING id");$q->execute([hash('sha256',$choice),$choice]);$rid=$q->fetchColumn();$oids=[];
 foreach(['attacker','defender'] as $camp){$q=$database->prepare("INSERT INTO combat_observations(report_id,side,player_id,player_name,observed_at,attack_base,hp_base) VALUES(?,?,910,'Legacy','2026-10-15T12:00:00Z',10,20) RETURNING id");$q->execute([$rid,$camp]);$oids[]=$q->fetchColumn();$database->exec('INSERT INTO combat_observation_units SELECT '.end($oids).',generate_series(1,14),100');}
 $q=$database->prepare("INSERT INTO combat_observation_candidates(attacker_observation_id,defender_observation_id,attacker_shield,defender_shield,location,building_level) VALUES(?,?,1,2,'dome',3)");$q->execute($oids);
}
$migration=file_get_contents(dirname(__DIR__).'/database/combat-single-player.sql');
$database->exec($migration);$database->exec($migration);
check((int)$database->query('SELECT count(*) FROM combat_observations')->fetchColumn()===2,'migration keeps one camp per report');
check((int)$database->query('SELECT count(*) FROM combat_observation_units')->fetchColumn()===28,'migration preserves selected units');
check((int)$database->query('SELECT count(*) FROM combat_observation_candidates')->fetchColumn()===2,'migration preserves hypotheses');
check((int)$database->query('SELECT count(*) FROM combat_observations o JOIN combat_reports r ON r.id=o.report_id WHERE o.side<>r.target_side')->fetchColumn()===0,'migration selected camp');
check($database->query("SELECT building_level FROM combat_observation_candidates c JOIN combat_observations o ON o.id=c.observation_id WHERE o.side='attacker'")->fetchColumn()===null,'attacker has no observed building');
$database->exec('TRUNCATE combat_reports CASCADE');
$f=json_decode(file_get_contents(__DIR__.'/fixtures/combat-report.json'),true,512,JSON_THROW_ON_ERROR);
$analysis=combatAnalyze($f['raw'],$f['location']);check($analysis['sides'][0]['arms']===12,'PHP subprocess analysis');
$player=$players->query("SELECT player_id,pseudo FROM players WHERE server='s5' ORDER BY player_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);check((bool)$player,'S5 fixture player');
$u=['id'=>1,'session_version'=>1];$draft=['raw'=>$f['raw'],'analysis'=>$analysis];
$_POST=['combat_at'=>'2026-10-15T12:34','target_side'=>'defender','defender_id'=>(string)$player['player_id'],'attacker_name'=>'<script>test</script>'];
$id=combatSave($u,$draft);
check((int)$database->query('SELECT count(*) FROM combat_reports')->fetchColumn()===1,'one report');
check((int)$database->query('SELECT count(*) FROM combat_observations')->fetchColumn()===1,'one selected observation');
check((int)$database->query('SELECT count(*) FROM combat_observation_units')->fetchColumn()===14,'14 selected units');
check((int)$database->query('SELECT count(*) FROM combat_observation_candidates')->fetchColumn()===count($analysis['candidates']),'candidate count');
check($database->query("SELECT shield FROM combat_observations WHERE side='defender'")->fetchColumn()===null,'ambiguous shield NULL');
check((float)$database->query("SELECT hp_bonus FROM combat_observations WHERE side='defender'")->fetchColumn()===189000.0,'exact HP despite ambiguous shield');
try{combatSave($u,$draft);throw new RuntimeException('duplicate accepted');}catch(DomainException $e){check(str_contains($e->getMessage(),'déjà'),'duplicate refused');}
$draft['raw'].="\nManual test";$_POST['defender_shield']='15';$_POST['defender_dome']='2';combatSave($u,$draft);
check((float)$database->query("SELECT hp_bonus FROM combat_observations WHERE side='defender' ORDER BY id DESC LIMIT 1")->fetchColumn()===189000.0,'manual recomputation');
foreach([['id'=>2,'session_version'=>1],['id'=>1,'session_version'=>2],['id'=>1,'session_version'=>1,'_preview'=>[]]] as $actor){try{combatSave($actor,$draft);throw new RuntimeException('authorization bypass');}catch(DomainException $e){check(str_contains($e->getMessage(),'administrateur'),'authorization refused');}}
$oldProfile=combatProfile((string)$player['player_id']);check($oldProfile['known']['shield']['value']==='15','manual known value');
$draft['raw'].="\nNew incomplete levels";$_POST['combat_at']='2026-10-16T12:34';unset($_POST['defender_shield'],$_POST['defender_dome']);$newId=combatSave($u,$draft);
$profile=combatProfile((string)$player['player_id']);check((int)$profile['latest']['report_id']===$newId,'latest army by observed date');check($profile['known']['shield']['value']==='15'&&(int)$profile['known']['shield']['report_id']!==$newId,'unknown new value preserves older known source');check($profile['known']['shield']['manual']===true,'manual provenance');
check(count($profile['units'])===14&&count($profile['history'])===3,'profile units and history');
$draft['raw'].="\nAttacker only";$_POST['target_side']='attacker';$_POST['attacker_id']=(string)$player['player_id'];$_POST['defender_id']='invalid ignored';$_POST['defender_shield']='invalid ignored';$_POST['combat_at']='2026-10-14T12:34';$aid=combatSave($u,$draft);
check((int)$database->query("SELECT count(*) FROM combat_observations WHERE report_id=$aid AND side='attacker'")->fetchColumn()===1,'attacker selection ignores other camp');
check((int)combatProfile((string)$player['player_id'])['latest']['report_id']===$newId,'older import does not replace newer observation');
check(count(combatTrackedPlayers((string)$player['player_id'],1))===1,'search groups player');
$_POST['target_side']='defender';unset($_POST['defender_shield']);
$_POST['defender_id']='';try{combatSave($u,$draft);throw new RuntimeException('missing target accepted');}catch(DomainException $e){}
try{combatDate('2026-02-30T12:34');throw new RuntimeException('invalid date accepted');}catch(DomainException $e){}
check(combatDate('2026-10-15T12:34')->format('P')==='+02:00','Paris timezone');
check(count(combatPlayers(mb_substr($player['pseudo'],0,3)))>0,'autocomplete');
check((int)$database->query('SELECT count(*) FROM combat_reports')->fetchColumn()===4,'rejected writes rollback');
echo "Storage OK: migration, subprocess, one observation, 14 units, migration and dated player profiles, candidates, unknowns, manual values, duplicate, permissions, dates, autocomplete and rollbacks.\n";
// Separate life values, including ambiguous levels with known total HP.
$sample=['hp_base'=>1000,'shield'=>10,'dome'=>0,'loge'=>0,'side'=>'defender','location'=>'dome','hp_bonus'=>2100];
$life=combatLifeValues($sample);check(abs($life['hp_terrain']-2000)<0.001&&abs($life['hp_dome']-2100)<0.001&&abs($life['hp_loge']-2300)<0.001,'three locations and level zero');
$sample['shield']=null;$sample['dome']=null;$sample['loge']=null;
$life=combatLifeValues($sample);check($life['hp_terrain']===null&&$life['hp_dome']===2100&&$life['hp_loge']===null,'known combined HP remains associated with actual place');
$sample['side']='attacker';$life=combatLifeValues($sample);check($life['hp_terrain']===2100&&$life['hp_dome']===null,'attacker receives no building bonus');
$database->exec("UPDATE combat_observations SET shield=NULL,dome=NULL,loge=NULL,hp_bonus=NULL,hp_base=1000");
$database->exec("UPDATE combat_observations SET shield=10,dome=0,loge=0 WHERE report_id=$id");
$database->exec("UPDATE combat_observations SET hp_bonus=2500 WHERE report_id=$newId");
$profile=combatProfile((string)$player['player_id']);$row=combatTrackedPlayers((string)$player['player_id'],1)[0];
foreach(['hp_terrain'=>2000,'hp_dome'=>2100,'hp_loge'=>2300,'dome'=>0,'loge'=>0] as $key=>$expected){check(abs((float)$profile['known'][$key]['value']-$expected)<0.001,'profile '.$key);check(abs((float)$row['known'][$key]['value']-$expected)<0.001,'list '.$key);}
check((int)$row['known']['hp_terrain']['report_id']===$newId&&(int)$row['known']['hp_dome']['report_id']===$newId,'all summary stats use latest army');
check((int)$profile['known']['hp_dome']['levels']['dome']['report_id']===$id,'older building source preserved');
$current=combatCurrentStats(['hp_base'=>127231,'attack_base'=>52161,'observed_at'=>'2026-10-15T20:20:00+02:00','report_id'=>10],['shield'=>['value'=>3,'report_id'=>2,'observed_at'=>'2026-10-12'],'arms'=>['value'=>5,'report_id'=>10,'observed_at'=>'2026-10-15'],'hp_dome'=>['value'=>999]]);
check(abs($current['hp_terrain']['value']-165400.3)<0.001,'reported player regression');
check(abs($current['attack_bonus']['value']-78241.5)<0.001,'attack recomputed on latest army');
check(!isset($current['hp_dome'],$current['hp_loge']),'unknown buildings erase stale derived totals');
check($current['hp_terrain']['levels']['shield']['report_id']===2&&$current['hp_terrain']['report_id']===10,'separate army and level sources');
echo "Life OK: three places, level zero, unknowns, ambiguous combined HP, attacker, list/profile and source consistency.\n";
}finally{if($database->inTransaction())$database->rollBack();$database->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');}
