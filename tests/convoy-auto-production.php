<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/script-convoys.php';
require dirname(__DIR__).'/app/web-convoys.php';
function db(bool $game=false):PDO{return $GLOBALS['auto_test_db'];}
function post(string $key):string{return $_POST[$key]??'';}
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejects(callable $fn,int $status):void{try{$fn();}catch(DomainException $e){check($e->getCode()===$status,$e->getMessage());return;}throw new RuntimeException('Expected rejection');}
$p=new PDO('pgsql:dbname=uptep',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$GLOBALS['auto_test_db']=$p;
$schema='qa_auto_production_'.bin2hex(random_bytes(6));$p->exec('CREATE SCHEMA '.$schema);$p->exec('SET search_path TO '.$schema);
try{
 foreach(['bot-convoys.sql','bot-ui.sql','bot-convoy-cancel.sql','bot-demand-adjustments.sql','convoy-events.sql'] as $file){$sql=file_get_contents(dirname(__DIR__).'/app/'.$file);$sql=str_replace(" auto_production boolean NOT NULL DEFAULT false,\n",'',$sql);$p->exec(preg_replace('/^(ALTER TABLE .* OWNER TO|GRANT ).*;$/m','',$sql));}
 $p->exec("CREATE TABLE chaine_members(server text,player_id bigint,iddiscord bigint);CREATE TABLE players(server text,player_id bigint,pseudo text,alliance_tag text,is_present boolean);CREATE TABLE users(id bigint,game_player_id bigint,discord_login_id bigint,status text,script_enabled boolean,is_chaine boolean,must_change_password boolean);
 INSERT INTO chaine_members VALUES('s5',1,101),('s5',2,102),('s5',3,103);
 INSERT INTO players VALUES('s5',1,'Owner','UPTEP',true),('s5',2,'Sender','UPTEP',true),('s5',3,'Manager','UPTEP',true);
 INSERT INTO users VALUES(1,1,101,'active',true,false,false),(2,2,102,'active',true,false,false),(3,3,103,'active',true,true,false);
 INSERT INTO demandes_convois(iddemandeur,pseudo_demandeur,discord_demandeur,nbpomme_original,nbpomme_restant,nbmateriaux_original,nbmateriaux_restant) VALUES(1,'Owner',101,100,100,100,100)");
 $p->exec("ALTER TABLE players ADD COLUMN field bigint DEFAULT 100,ADD COLUMN master_player_id bigint,ADD COLUMN imported_at timestamp DEFAULT now();CREATE TABLE user_stats(user_id bigint,data jsonb);CREATE TABLE member_stat_history(id bigint,user_id bigint,stat_group text,data jsonb,observed_at timestamptz);CREATE TABLE historique_exports(id bigint,server text,export_type text,export_version text,exported_at timestamp);CREATE TABLE historique_players(export_id bigint,player_id bigint,field bigint,master_player_id bigint,alliance_tag text)");
 $migration=file_get_contents(dirname(__DIR__).'/app/convoy-auto-production.sql');$p->exec($migration);$p->exec($migration);
 check($p->query('SELECT auto_production FROM demandes_convois LIMIT 1')->fetchColumn()===false,'migration preserves legacy demand as non-auto');
 $seq=0;$act=function(string $action,?string $id,array $data=[],string $discord='101')use($p,&$seq){return botConvoyAction($p,$p,$action,$id,['commande_id'=>'auto-test-'.++$seq,'discord_id'=>$discord]+$data)[1]['demande'];};
 $create=['nbpomme'=>'100','nbmateriaux'=>'100','objet'=>'Solarium','niveau'=>'10'];
 rejects(fn()=>$act('demande',null,$create+['auto_production'=>'true']),422);
 $d=$act('demande',null,$create+['auto_production'=>true]);check($d['auto_production'],'creation flag stored');
 $edit=['nbpomme'=>'100','nbmateriaux'=>'100','objet'=>'Solarium','niveau'=>'10','date_souhaitee'=>null,'auto_production'=>false,'version_attendue'=>$d['version_modification']];
 rejects(fn()=>$act('modification',$d['id'],$edit,'102'),403);
 $normal=$act('modification',$d['id'],$edit);check(!$normal['auto_production'],'owner can change pending flag');
 rejects(fn()=>$act('validation',$d['id'],['version_attendue'=>$d['version_modification']],'103'),409);
 $d=$act('validation',$d['id'],['version_attendue'=>$normal['version_modification']],'103');
 $adjust=['nbpomme_restant'=>'100','nbmateriaux_restant'=>'100','nbpomme_attendu'=>'100','nbmateriaux_attendu'=>'100','auto_production'=>true,'version_attendue'=>$d['version_modification']];
 rejects(fn()=>$act('ajustement',$d['id'],$adjust,'102'),403);
 $auto=$act('ajustement',$d['id'],$adjust);check($auto['auto_production']&&$auto['nbmateriaux_restant']==='100','validated flag-only change preserves quantities');
 check(count($auto['ajustements'])===0,'flag-only change does not invent a resource adjustment');
 rejects(fn()=>$act('ajustement',$d['id'],array_replace($adjust,['auto_production'=>false])),409);
 $convoy=['commande_id'=>'auto-convoy','discord_id'=>'102','nbpomme'=>'10','nbmateriaux'=>'20'];
 rejects(fn()=>botConvoyAction($p,$p,'convoi',$d['id'],$convoy),409);
 check((int)$p->query('SELECT count(*) FROM convois_effectues')->fetchColumn()===0,'warning refusal has no debit');
 $convoy['auto_production_confirmed']=true;
 $sent=botConvoyAction($p,$p,'convoi',$d['id'],$convoy)[1];$replay=botConvoyAction($p,$p,'convoi',$d['id'],$convoy)[1];
 check($sent==$replay&&botDemand($p,$d['id'])['nbmateriaux_restant']==='80','confirmed convoy accepted and replayed once');
 check(botListDemands($p,['include'=>'convois'])['demandes'][1]['auto_production'],'bot listing carries flag');
 $u=['id'=>2,'game_player_id'=>2];$owner=['id'=>1,'game_player_id'=>1];
 $order=['demand_id'=>$d['id'],'target'=>'Owner','apples'=>'1','wood'=>'2'];
 check(scriptConvoys($p,$p,$u,['action'=>'convoy-check']+$order)['auto_production'],'preflight returns current auto status');
 check(scriptConvoys($p,$p,$u,['action'=>'convoy-demands'])['demands'][0]['auto_production'],'script list badge data');
 // Registration and recovery of an already-sent game convoy remain possible.
 $declare=['action'=>'convoy-declare','game_id'=>'9876','arrival'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'sent_at'=>gmdate('Y-m-d\TH:i:s\Z'),'confirmed'=>true]+$order;
 $out=scriptConvoys($p,$p,$u,$declare);check($out['declared'],'already-sent auto convoy can be registered');check(scriptConvoys($p,$p,$u,$declare)===$out,'declaration recovery remains idempotent');
 $new=['action'=>'convoy-create','request_key'=>'11111111-1111-4111-8111-111111111111','confirmed'=>true,'apples'=>'100','wood'=>'100','object'=>'Loge','level'=>'10','auto_production'=>true];
 $made=scriptConvoys($p,$p,$owner,$new);check(botDemand($p,$made['demand_id'])['auto_production'],'script creation');check(scriptConvoys($p,$p,$owner,$new)===$made,'script creation replay');
 rejects(fn()=>scriptConvoys($p,$p,$owner,array_replace($new,['auto_production'=>false])),409);
 $_POST=['operation'=>'demande','pommes'=>'100','materiaux'=>'200','objet'=>'Solarium','niveau'=>'10','auto_production'=>'1'];
 $web=convoyPrepare(['discord_id'=>'101']);check($web['body']['auto_production']===true,'website checked creation flag');
 unset($_POST['auto_production']);check(convoyPrepare(['discord_id'=>'101'])['body']['auto_production']===false,'website unchecked flag');
 $_POST=['operation'=>'convoi','id'=>$d['id'],'pommes'=>'1','materiaux'=>'2','duree'=>'1h'];
 $web=convoyPrepare(['discord_id'=>'102']);check($web['auto_warning']===true,'website confirmation requires warning');
 check(!isset($web['body']['auto_production_confirmed']),'website preparation does not pre-confirm warning');
 echo "Auto production OK: additive migration, creation/editing, rights, stale versions, badges, explicit convoy warning, exact accounting and safe recovery.\n";
}finally{if($p->inTransaction())$p->rollBack();$p->exec('DROP SCHEMA '.$schema.' CASCADE');}
