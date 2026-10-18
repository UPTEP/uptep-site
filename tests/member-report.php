<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/member-report.php';require dirname(__DIR__).'/app/bot-convoys.php';require dirname(__DIR__).'/app/userscript.php';
function db(bool $game=false):PDO{return $GLOBALS['test_db'];}
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejects(callable $fn,int $status):void{try{$fn();}catch(DomainException $e){check($e->getCode()===$status,$e->getMessage());return;}throw new RuntimeException('Expected rejection');}
$p=new PDO('pgsql:dbname=uptep',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$GLOBALS['test_db']=$p;$schema='qa_member_report_'.bin2hex(random_bytes(6));$p->exec('CREATE SCHEMA '.$schema);$p->exec('SET search_path TO '.$schema);
try{
 $p->exec("CREATE TABLE users(id bigint PRIMARY KEY,game_player_id bigint,role text,is_chef boolean,is_chaine boolean,status text,script_enabled boolean,must_change_password boolean,created_at timestamptz DEFAULT now(),approved_at timestamptz,last_login_at timestamptz);
 CREATE TABLE players(server text,player_id bigint,pseudo text,alliance_tag text,is_present boolean,on_holiday boolean DEFAULT false,is_banned boolean DEFAULT false);
 CREATE TABLE script_tokens(token_hash text,user_id bigint,player_id bigint,created_at timestamptz DEFAULT now());CREATE TABLE script_settings(user_id bigint,preferences jsonb);
 CREATE TABLE user_stats(user_id bigint PRIMARY KEY,data jsonb,updated_at timestamptz DEFAULT now(),buildings_synced_at timestamptz,research_synced_at timestamptz,army_synced_at timestamptz,resources_synced_at timestamptz,workers_synced_at timestamptz);
 CREATE TABLE chaine_members(server text,player_id bigint,iddiscord bigint);
 INSERT INTO users(id,game_player_id,role,is_chef,is_chaine,status,script_enabled,must_change_password) VALUES(1,1,'member',true,true,'active',true,false),(2,2,'member',false,false,'active',true,false),(5,5,'admin',false,true,'active',true,false);
 INSERT INTO players(server,player_id,pseudo,alliance_tag,is_present) VALUES('s5',1,'Chief','UPTEP',true),('s5',2,'Target','UPTEP',true),('s5',3,'Outsider','OTHER',true),('s5',4,'Former','UPTEP',false),('s5',5,'Admin','UPTEP',true),('s5',6,'Unregistered','UPTEP',true);
 INSERT INTO chaine_members VALUES('s5',1,101),('s5',2,102);
 INSERT INTO script_settings VALUES(2,'{\"economy\":true,\"buildings\":false}');
 INSERT INTO user_stats(user_id,data,resources_synced_at,workers_synced_at) VALUES(2,'{\"resources\":{\"nourriture\":\"100\",\"materiaux\":\"50\",\"terrain\":\"1000\"},\"workers\":{\"total\":\"500\"},\"army\":{\"jsn\":\"9007199254740993\"},\"buildings\":{\"couveuse\":\"5\"},\"research\":{\"technique_ponte\":\"2\"}}',now()-interval '2 days',now()-interval '2 days')");
 foreach(['bot-convoys.sql','bot-ui.sql','bot-convoy-cancel.sql','bot-demand-adjustments.sql','bot-floods.sql'] as $file)$p->exec(preg_replace('/^(ALTER TABLE .* OWNER TO|GRANT ).*;$/m','',file_get_contents(dirname(__DIR__).'/app/'.$file)));
 $migration=file_get_contents(dirname(__DIR__).'/app/member-report.sql');$p->exec($migration);$p->exec($migration);check((int)$p->query('SELECT count(*) FROM member_stat_history')->fetchColumn()===5,'idempotent baseline');
 $p->exec("UPDATE member_stat_history SET observed_at=now()-interval '2 days' WHERE stat_group='resources'");
 $p->exec("UPDATE user_stats SET data=jsonb_set(jsonb_set(data,'{resources,nourriture}','\"200\"'),'{resources,materiaux}','\"400\"'),resources_synced_at=now() WHERE user_id=2");
 check((int)$p->query("SELECT count(*) FROM member_stat_history WHERE stat_group='resources'")->fetchColumn()===2,'new resource observation captured');check((int)$p->query("SELECT count(*) FROM member_stat_history WHERE stat_group='buildings'")->fetchColumn()===1,'unchanged construction not duplicated');
 $p->exec("UPDATE user_stats SET data=jsonb_set(data,'{buildings,couveuse}','\"6\"'),updated_at=now() WHERE user_id=2");check($p->query("SELECT source FROM member_stat_history WHERE stat_group='buildings' ORDER BY id DESC LIMIT 1")->fetchColumn()==='manual','manual edit tracked');
 $p->beginTransaction();$p->exec("UPDATE user_stats SET data=jsonb_set(data,'{workers,total}','\"600\"') WHERE user_id=2");$p->rollBack();check((int)$p->query("SELECT count(*) FROM member_stat_history WHERE stat_group='workers'")->fetchColumn()===1,'history transaction rolls back too');
 $seq=0;$act=function($action,$id,$data=[],$discord='102')use($p,&$seq){return botConvoyAction($p,$p,$action,$id,['commande_id'=>'member-test-'.++$seq,'discord_id'=>$discord]+$data)[1];};
 $d=$act('demande',null,['nbpomme'=>'1000','nbmateriaux'=>'1000','auto_production'=>true])['demande'];$act('validation',$d['id'],[],'101');$act('convoi',$d['id'],['nbpomme'=>'400','nbmateriaux'=>'500','auto_production_confirmed'=>true],'101');
 $act('ajustement',$d['id'],['nbpomme_attendu'=>'600','nbmateriaux_attendu'=>'500','nbpomme_restant'=>'500','nbmateriaux_restant'=>'400']);
 $other=$act('demande',null,['nbpomme'=>'1000','nbmateriaux'=>'1000'],'101')['demande'];$act('validation',$other['id'],[],'101');$act('convoi',$other['id'],['nbpomme'=>'100','nbmateriaux'=>'200']);
 $c=$act('convoi',$other['id'],['nbpomme'=>'30','nbmateriaux'=>'40']);botCancelConvoy($p,$p,$c['convoi']['id'],['commande_id'=>'cancel-member','discord_id'=>'102']);
 $p->exec("INSERT INTO bot_flood_batches VALUES('fixture-flood','x','{}',now());INSERT INTO floods(commande_id,ordinal,sender_id,sender_pseudo,sender_discord,target_id,target_pseudo,location,arrival_at,captured_at) VALUES('fixture-flood',0,2,'Target',102,1,'Chief','terrain',now()+interval '1 hour',now()),('fixture-flood',1,1,'Chief',101,2,'Target','terrain',now()+interval '2 hours',now())");
 $chief=['id'=>1,'game_player_id'=>1];$body=['player_id'=>'2','period'=>'30'];
 rejects(fn()=>memberReport($p,$p,['id'=>2,'game_player_id'=>2],$body),403);rejects(fn()=>memberReport($p,$p,['id'=>5,'game_player_id'=>5],$body),403);rejects(fn()=>memberReport($p,$p,['id'=>1,'game_player_id'=>2],$body),403);
 foreach(['3','4'] as $id)rejects(fn()=>memberReport($p,$p,$chief,['player_id'=>$id]),404);
 $r=memberReport($p,$p,$chief,$body);check($r['target']['pseudo']==='Target'&&$r['account']['registered'],'target identity and site registration');
 check($r['convoys']['sent']['food']==='100'&&$r['convoys']['sent']['materials']==='200'&&$r['convoys']['canceled']===1,'canceled declaration excluded from quantities');check($r['convoys']['received']['materials']==='500','received actual resources');
 check($r['requests']['auto_production']===1&&$r['requests']['outstanding']['materials']==='400'&&$r['requests']['adjustments']['materials']==='100','auto demand, remaining and adjustment kept distinct');
 check($r['floods']['sent']['count']===1&&$r['floods']['received']['count']===1,'flood directions');check($r['stats']['data']['army']['jsn']==='9007199254740993','exact large integer');check($r['evolution']['resources']['delta']['nourriture']==='100'&&$r['evolution']['resources']['stock_change_per_day']['food']==='50','stock variation is exact and distinct from production');
 check(!array_key_exists('password_hash',$r['account']['details'])&&!array_key_exists('discord_login_id',$r['account']['details'])&&!array_key_exists('token_hash',$r['account']['script']),'no unrelated secrets');
 check(!memberReport($p,$p,$chief,['player_id'=>'6'])['account']['registered'],'unregistered member reported without fake zero stats');
 rejects(fn()=>memberReport($p,$p,$chief,array_replace($body,['period'=>'invalid'])),422); // explicit invalid period below
 $p->exec("UPDATE players SET alliance_tag='OTHER' WHERE player_id=1");rejects(fn()=>memberReport($p,$p,$chief,$body),403);$p->exec("UPDATE players SET alliance_tag='UPTEP' WHERE player_id=1");
 $p->exec('UPDATE users SET script_enabled=false WHERE id=1');rejects(fn()=>memberReport($p,$p,$chief,$body),403);$p->exec('UPDATE users SET script_enabled=true WHERE id=1');
 $p->exec('UPDATE users SET is_chef=false WHERE id=1');rejects(fn()=>memberReport($p,$p,$chief,$body),403);$p->exec('UPDATE users SET is_chef=true WHERE id=1');
 $p->exec("UPDATE players SET alliance_tag='OTHER' WHERE player_id=2");rejects(fn()=>memberReport($p,$p,$chief,$body),404);$p->exec("UPDATE players SET alliance_tag='UPTEP' WHERE player_id=2");
 $token=str_repeat('a',64);$q=$p->prepare('INSERT INTO script_tokens(token_hash,user_id,player_id) VALUES(?,2,2)');$q->execute([hash('sha256',$token)]);authorizeScript($token,'Target','UPTEP');$seen=$p->query('SELECT last_script_seen_at FROM users WHERE id=2')->fetchColumn();check((bool)$seen,'actual use recorded');authorizeScript($token,'Target','UPTEP');check($p->query('SELECT last_script_seen_at FROM users WHERE id=2')->fetchColumn()===$seen,'usage touch throttled');rejects(fn()=>authorizeScript($token,'Chief','UPTEP'),403);
 if(isset($argv[1]))file_put_contents($argv[1],json_encode(memberReport($p,$p,$chief,$body),JSON_THROW_ON_ERROR));
 echo "Member report OK: exact chief gate, revocation, current UPTEP membership, no admin bypass, exact quantities, cancellations, demands, floods, snapshots, rollback, averages, registration and verified script use.\n";
}finally{if($p->inTransaction())$p->rollBack();$p->exec('DROP SCHEMA '.$schema.' CASCADE');}
