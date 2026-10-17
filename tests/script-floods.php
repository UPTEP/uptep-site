<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/script-floods.php';
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
function rejects($fn,$code){try{$fn();}catch(DomainException $e){check($e->getCode()===$code,$e->getMessage());return;}throw new RuntimeException('Expected rejection');}
$p=new PDO('pgsql:dbname=uptep',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='qa_floods_'.bin2hex(random_bytes(5));$p->exec('CREATE SCHEMA '.$schema);$p->exec('SET search_path TO '.$schema);
try{
foreach(['bot-floods.sql','convoy-events.sql','script-floods.sql'] as $file){$sql=file_get_contents(dirname(__DIR__).'/app/'.$file);$sql=preg_replace('/^ALTER TABLE .* OWNER TO .*;$/m','',$sql);$sql=preg_replace('/DO \$\$.*?END \$\$;/s','',$sql);$p->exec($sql);}
$p->exec("CREATE TABLE chaine_members(server text,player_id bigint,iddiscord bigint);CREATE TABLE players(server text,player_id bigint,pseudo text,alliance_tag text,is_present boolean);CREATE TABLE users(id bigint,game_player_id bigint,discord_login_id bigint,status text,script_enabled boolean,must_change_password boolean,is_chaine boolean DEFAULT false);
INSERT INTO chaine_members VALUES('s5',1,101),('s5',2,102),('s5',3,103);
INSERT INTO players VALUES('s5',1,'Target','UPTEP',true),('s5',2,'Sender','UPTEP',true),('s5',3,'Other','UPTEP',true);
INSERT INTO users VALUES(2,2,102,'active',true,false,false),(3,3,103,'active',true,false,false)");
$actor=['id'=>2,'game_player_id'=>2];$now=gmdate('Y-m-d\TH:i:s\Z');$arrival=gmdate('Y-m-d\TH:i:s\Z',time()+3600);
$f=['game_id'=>'33359','pseudo'=>'Target','location'=>'Terrain de chasse','troops'=>'2 204 Jeunes Soldates Naines, 9 Naines d’Elites.','arrival_at'=>$arrival];
$b=['action'=>'flood-check','captured_at'=>$now,'floods'=>[$f]];
check(scriptFloods($p,$p,$actor,$b)['floods'][0]['state']==='new','new flood');
check((int)$p->query('SELECT count(*) FROM floods')->fetchColumn()===0,'check read only');
$create=array_replace($b,['action'=>'flood-declare','confirmed'=>true]);
rejects(fn()=>scriptFloods($p,$p,$actor,array_replace($create,['confirmed'=>false])),422);
rejects(fn()=>scriptFloods($p,$p,$actor,$create+['discord_id'=>'103']),422);
$r=scriptFloods($p,$p,$actor,$create);check($r['state']==='declared','created');
check(scriptFloods($p,$p,$actor,$create)===$r,'idempotent replay');
$drift=$create;$drift['floods'][0]['arrival_at']=gmdate('Y-m-d\TH:i:s\Z',time()+3605);check(scriptFloods($p,$p,$actor,$drift)===$r,'reload drift stable ID');
check((int)$p->query('SELECT count(*) FROM convoy_web_events')->fetchColumn()===1,'one notification');
check(scriptFloods($p,$p,['id'=>3,'game_player_id'=>3],$b)['floods'][0]['state']==='new','sender isolation');
$second=$create;$second['floods'][0]['game_id']='33360';$two=scriptFloods($p,$p,$actor,$second);check($two['id']!==$r['id'],'two identical game attacks remain distinct');
$legacy=$f;$legacy['troops']='9 Soldates';$legacy['game_id']='33361';$resolved=floodResolve($p,$p,['captured_at'=>$now,'floods'=>[$legacy]]);
$old=floodCreate($p,$p,['discord_id'=>'102','commande_id'=>'discord-test','captured_at'=>$now,'floods'=>$resolved]);
$lb=array_replace($b,['floods'=>[$legacy]]);check(scriptFloods($p,$p,$actor,$lb)['floods'][0]['state']==='existing','Discord match');
$twins=$lb;$twin=$legacy;$twin['game_id']='99999';$twins['floods'][]=$twin;check(array_column(scriptFloods($p,$p,$actor,$twins)['floods'],'state')===['ambiguous','ambiguous'],'one Discord report cannot identify two attacks');
$shift=$lb;$shift['floods'][0]['arrival_at']=gmdate('Y-m-d\TH:i:s\Z',strtotime($arrival)-70);
check(scriptFloods($p,$p,$actor,$shift)['floods'][0]['state']==='existing','legacy copy delay 70 seconds recognized with identical troops');
$shift['floods'][0]['troops']=null;check(scriptFloods($p,$p,$actor,$shift)['floods'][0]['state']==='new','no broad time match without troops');
$shift['floods'][0]['troops']='8 Soldates';check(scriptFloods($p,$p,$actor,$shift)['floods'][0]['state']==='new','different troops never match');
$shift['floods'][0]['troops']=$legacy['troops'];$shift['floods'][0]['arrival_at']=gmdate('Y-m-d\TH:i:s\Z',strtotime($arrival)-121);check(scriptFloods($p,$p,$actor,$shift)['floods'][0]['state']==='new','outside bounded tolerance');
$lc=array_replace($lb,['action'=>'flood-declare','confirmed'=>true]);check(scriptFloods($p,$p,$actor,$lc)['id']===$old['floods'][0]['id'],'link existing no duplicate');
check((int)$p->query('SELECT count(*) FROM floods')->fetchColumn()===3,'no duplicate rows');
$legacy['game_id']='33362';$legacy['troops']=null;$resolved=floodResolve($p,$p,['captured_at'=>$now,'floods'=>[$legacy]]);
foreach(['a','b'] as $suffix)floodCreate($p,$p,['discord_id'=>'102','commande_id'=>'legacy-'.$suffix,'captured_at'=>$now,'floods'=>$resolved]);
$amb=array_replace($create,['floods'=>[$legacy]]);rejects(fn()=>scriptFloods($p,$p,$actor,$amb),409);
check(!$p->inTransaction(),'rollback ambiguous');
$p->exec("UPDATE users SET script_enabled=false WHERE id=2");rejects(fn()=>scriptFloods($p,$p,$actor,$b),403);
echo "Floods: read-only check, creation, replay, same-time distinct attacks, Discord matching, ambiguity, notifications and identity OK.\n";
}finally{if($p->inTransaction())$p->rollBack();$p->exec('DROP SCHEMA '.$schema.' CASCADE');}
