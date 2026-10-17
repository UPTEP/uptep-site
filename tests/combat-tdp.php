<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/combat-reports.php';
require dirname(__DIR__).'/app/combat-tdp.php';
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function denied(callable $fn,string $why):void{try{$fn();}catch(DomainException $e){return;}throw new RuntimeException($why);}
function db():PDO{global $database;return $database;}
$counts=array_fill_keys(array_keys(COMBAT_LAYING_SECONDS),'0');$counts[1]='100000';$seconds=(int)round(30000000*pow(.9,50));
$calc=combatTdpCalculation($counts,$seconds);check($calc['compatible_levels']===[50],'exact known TDP');
$mixed=$counts;$mixed[1]='0';$mixed[7]='1000';$mixed[11]='2000';$mixedBase=1440000+5480000;
check(combatTdpCalculation($mixed,(int)round($mixedBase*pow(.9,40)))['compatible_levels']===[40],'different unit speeds and IDs');
check(in_array(50,combatTdpCalculation($counts,$seconds+59)['compatible_levels'],true),'minute precision');
denied(fn()=>combatTdpCalculation($counts,60),'short interval');
foreach(['-1','1.2','1e3','1000000000001',[]] as $bad){$c=$counts;$c[1]=$bad;denied(fn()=>combatTdpCalculation($c,$seconds),'bad counts');}
denied(fn()=>combatTdpCalculation(array_fill_keys(array_keys(COMBAT_LAYING_SECONDS),'0'),$seconds),'zero growth');
check(!isset(COMBAT_LAYING_SECONDS[13],COMBAT_LAYING_SECONDS[14]),'non recruitable elites');
$database=new PDO('pgsql:dbname=uptep',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$schema='tdp_test_'.bin2hex(random_bytes(6));
try{
$database->exec('CREATE SCHEMA '.$schema);$database->exec('SET search_path TO '.$schema);
$database->exec("CREATE TABLE users(id bigint PRIMARY KEY,role text,status text,must_change_password boolean,session_version int,display_name text);INSERT INTO users VALUES(1,'admin','active',false,1,'Author'),(2,'admin','active',false,1,'Other'),(3,'member','active',false,1,'Member')");
foreach(['combat-reports','combat-single-player','combat-tdp','combat-tdp'] as $migration)$database->exec(file_get_contents(dirname(__DIR__).'/database/'.$migration.'.sql'));
$f=json_decode(file_get_contents(__DIR__.'/fixtures/combat-report.json'),true);
$ids=[];foreach([0,$seconds,$seconds+86400] as $i=>$offset){$date=(new DateTimeImmutable('2026-10-01T12:00:00Z'))->modify('+'.$offset.' seconds')->format(DateTimeInterface::ATOM);$q=$database->prepare("INSERT INTO combat_reports(raw_text,content_hash,combat_at,date_source,target_side,created_by,location) VALUES(?, ?,?,'manual','defender',1,?) RETURNING id");$q->execute([$f['raw'],hash('sha256','fixture'.$i),$date,$f['location']]);$rid=$q->fetchColumn();$q=$database->prepare("INSERT INTO combat_observations(report_id,side,player_id,player_name,observed_at,attack_base,hp_base) VALUES(?,'defender',123,'Target',?,0,0) RETURNING id");$q->execute([$rid,$date]);$ids[]=(string)$q->fetchColumn();$database->exec('INSERT INTO combat_observation_units SELECT '.end($ids).',generate_series(1,14),100000');}
$u=['id'=>1,'session_version'=>1];
$pair=combatTdpPair($ids[0],$ids[1],$u);check($pair['elapsed']===$seconds,'chronology');
$comparison=combatTdpCompare($pair);check(count($comparison)===14,'comparison14');check($comparison[1]['before']<=8000,'casualties deducted');
foreach([[$ids[1],$ids[0],$u],[$ids[0],$ids[0],$u],[$ids[0],$ids[1],['id'=>2]]] as $args)denied(fn()=>combatTdpPair(...$args),'pair rejection');
$database->exec('UPDATE combat_observations SET player_id=999 WHERE id='.$ids[1]);denied(fn()=>combatTdpPair($ids[0],$ids[1],$u),'other player');$database->exec('UPDATE combat_observations SET player_id=123 WHERE id='.$ids[1]);
check(combatTdpLatest([123])===[],'unknown until confirmation');
$draft=['first'=>$ids[0],'second'=>$ids[1],'actor'=>1,'token'=>bin2hex(random_bytes(24)),'expires'=>time()+3600,'calculation'=>$calc,'comparison'=>$comparison];
denied(fn()=>combatTdpConfirm($u,$draft,$draft['token'],'50',false),'checkbox enforced');
denied(fn()=>combatTdpConfirm($u,$draft,'forged','50',true),'token enforced');
denied(fn()=>combatTdpConfirm($u,$draft,$draft['token'],'40',true),'compatible levels enforced');
foreach([['id'=>2,'session_version'=>1],['id'=>1,'session_version'=>2],['id'=>1,'session_version'=>1,'_preview'=>[]]] as $actor)denied(fn()=>combatTdpConfirm($actor,$draft,$draft['token'],'50',true),'authorization');
$expired=$draft;$expired['expires']=time()-1;denied(fn()=>combatTdpConfirm($u,$expired,$expired['token'],'50',true),'expiry');
$database->exec("UPDATE users SET role='member' WHERE id=1");denied(fn()=>combatTdpConfirm($u,$draft,$draft['token'],'50',true),'role revoked');$database->exec("UPDATE users SET role='admin' WHERE id=1");
check(combatTdpLatest([123])===[],'no rejected write');
check(combatTdpConfirm($u,$draft,$draft['token'],'50',true)==='123','confirmed');combatTdpConfirm($u,$draft,$draft['token'],'50',true);
check((int)$database->query('SELECT count(*) FROM combat_tdp_confirmations')->fetchColumn()===1,'replay idempotent');
$known=combatTdpLatest([123])[123];check((int)$known['total']===50&&$known['author']==='Author','total and author');check((string)$known['second_observation_id']===$ids[1],'newer unconfirmed report preserves value');
$database->exec("INSERT INTO combat_tdp_confirmations(player_id,first_observation_id,second_observation_id,total,observed_at,confirmed_by,evidence,token) SELECT player_id,first_observation_id,second_observation_id,49,observed_at-interval '1 day',1,evidence,'older' FROM combat_tdp_confirmations");check((int)combatTdpLatest([123])[123]['total']===50,'older confirmation does not override recent evidence');
echo "TDP OK: formula, units, precision, losses, source pair, ownership, permissions, expiry, explicit confirmation, idempotency, provenance and retention.\n";
}finally{if($database->inTransaction())$database->rollBack();$database->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');}
