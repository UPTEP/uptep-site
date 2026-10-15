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
$f=json_decode(file_get_contents(__DIR__.'/fixtures/combat-report.json'),true,512,JSON_THROW_ON_ERROR);
$analysis=combatAnalyze($f['raw'],$f['location']);check($analysis['sides'][0]['arms']===12,'PHP subprocess analysis');
$player=$players->query("SELECT player_id,pseudo FROM players WHERE server='s5' ORDER BY player_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);check((bool)$player,'S5 fixture player');
$u=['id'=>1,'session_version'=>1];$draft=['raw'=>$f['raw'],'analysis'=>$analysis];
$_POST=['combat_at'=>'2026-10-15T12:34','target_side'=>'defender','defender_id'=>(string)$player['player_id'],'attacker_name'=>'<script>test</script>'];
$id=combatSave($u,$draft);
check((int)$database->query('SELECT count(*) FROM combat_reports')->fetchColumn()===1,'one report');
check((int)$database->query('SELECT count(*) FROM combat_observations')->fetchColumn()===2,'two observations');
check((int)$database->query('SELECT count(*) FROM combat_observation_units')->fetchColumn()===28,'14 units each');
check((int)$database->query('SELECT count(*) FROM combat_observation_candidates')->fetchColumn()===count($analysis['candidates']),'candidate count');
check($database->query("SELECT shield FROM combat_observations WHERE side='defender'")->fetchColumn()===null,'ambiguous shield NULL');
check((float)$database->query("SELECT hp_bonus FROM combat_observations WHERE side='defender'")->fetchColumn()===189000.0,'exact HP despite ambiguous shield');
try{combatSave($u,$draft);throw new RuntimeException('duplicate accepted');}catch(DomainException $e){check(str_contains($e->getMessage(),'déjà'),'duplicate refused');}
$draft['raw'].="\nManual test";$_POST['defender_shield']='15';$_POST['defender_dome']='2';combatSave($u,$draft);
check((float)$database->query("SELECT hp_bonus FROM combat_observations WHERE side='defender' ORDER BY id DESC LIMIT 1")->fetchColumn()===189000.0,'manual recomputation');
foreach([['id'=>2,'session_version'=>1],['id'=>1,'session_version'=>2],['id'=>1,'session_version'=>1,'_preview'=>[]]] as $actor){try{combatSave($actor,$draft);throw new RuntimeException('authorization bypass');}catch(DomainException $e){check(str_contains($e->getMessage(),'administrateur'),'authorization refused');}}
$_POST['defender_id']='';try{combatSave($u,$draft);throw new RuntimeException('missing target accepted');}catch(DomainException $e){}
try{combatDate('2026-02-30T12:34');throw new RuntimeException('invalid date accepted');}catch(DomainException $e){}
check(combatDate('2026-10-15T12:34')->format('P')==='+02:00','Paris timezone');
check(count(combatPlayers(mb_substr($player['pseudo'],0,3)))>0,'autocomplete');
check((int)$database->query('SELECT count(*) FROM combat_reports')->fetchColumn()===2,'rejected writes rollback');
echo "Storage OK: migration, subprocess, two observations, 28 units, candidates, unknowns, manual values, duplicate, permissions, dates, autocomplete and rollbacks.\n";
}finally{if($database->inTransaction())$database->rollBack();$database->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');}
