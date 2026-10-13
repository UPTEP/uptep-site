<?php
declare(strict_types=1);
require __DIR__.'/script-traffic.php.service';
require __DIR__.'/userscript.php';
function check(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
$p=new PDO('pgsql:dbname=uptep',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function db(bool $game=false): PDO {global $p;return $p;}
// TEMP tables shadow the production tables for this connection only.
$p->exec("CREATE TEMP TABLE users(id int,game_player_id bigint,status text,script_enabled bool,must_change_password bool);
CREATE TEMP TABLE script_tokens(token_hash text,user_id int,player_id bigint);
CREATE TEMP TABLE players(server text,player_id bigint,pseudo text,alliance_tag text,is_present bool);
INSERT INTO users VALUES(1,1,'active',true,false),(2,2,'active',true,false);
INSERT INTO players VALUES('s5',1,'Me','UPTEP',true),('s5',2,'Recipient','UPTEP',true);
CREATE TEMP TABLE floods(id bigint,sender_id bigint,sender_pseudo text,target_id bigint,target_pseudo text,location text,arrival_at timestamptz);
CREATE TEMP TABLE demandes_convois(id bigint,serveur text,iddemandeur bigint,pseudo_demandeur text,fini bool,annulee bool);
CREATE TEMP TABLE convois_effectues(id bigint,iddemande bigint,idenvoyeur bigint,pseudo_envoyeur text,nbpomme bigint,nbmateriaux bigint,date_arrivee timestamptz,annule bool);
INSERT INTO demandes_convois VALUES(1,'s5',2,'Recipient',true,false),(2,'s5',1,'Me',false,true),(3,'s5',3,'Unrelated',false,false),(4,'s4',1,'Other server',false,false);
INSERT INTO floods VALUES
(1,1,'Me',2,'Recipient','Terrain de chasse',now()+interval '20 minutes'),
(2,2,'Recipient',1,'Me','Dôme',now()+interval '5 minutes'),
(3,2,'Recipient',3,'Unrelated','Terrain de chasse',now()+interval '5 minutes'),
(4,1,'Me',2,'Recipient','Terrain de chasse',now()-interval '1 second'),
(5,2,'Recipient',1,'Me','Terrain de chasse',now()-interval '1 second'),
(6,1,'Me',NULL,'Unknown','Terrain de chasse',now()+interval '30 minutes'),
(7,1,'Me',2,'Recipient','Terrain de chasse',now()+interval '20 minutes');
INSERT INTO convois_effectues VALUES
(1,1,1,'Me',9007199254740993,200,now()+interval '10 minutes',false),
(2,2,2,'Recipient',10,20,now()+interval '15 minutes',false),
(3,1,1,'Me',1,0,now()+interval '10 minutes',true),
(4,1,1,'Me',1,0,now()-interval '1 second',false),
(5,2,2,'Recipient',1,0,NULL,false),
(6,3,2,'Recipient',1,0,now()+interval '10 minutes',false),
(7,4,2,'Recipient',1,0,now()+interval '10 minutes',false),
(8,2,2,'Recipient',1,0,now()+interval '10 minutes',true);");
$p->exec(file_get_contents(__DIR__.'/script-traffic.sql'));
$p->exec(file_get_contents(__DIR__.'/script-traffic.sql'));
$data=scriptTraffic($p,['game_player_id'=>'1']);
check(array_column($data['outgoing'],'id')===['1','1','7','6'],'Own outgoing only, sorted, duplicate attacks preserved');
check(array_column($data['incoming'],'id')===['2','2'],'Own incoming only; cancelled, undated, expired, other server excluded');
check($data['outgoing'][0]['food']==='9007199254740993','Exact large resource quantity');
check($data['outgoing'][0]['kind']==='convoy'&&$data['outgoing'][0]['pseudo']==='Recipient','Convoy stays active on completed resource request');
check($data['incoming'][1]['kind']==='convoy','Request cancellation does not cancel a travelling convoy');
check($data['incoming'][0]['location']==='Dôme'&&str_ends_with($data['incoming'][0]['arrival_at'],'Z'),'Flood location and UTC time');
$other=scriptTraffic($p,['game_player_id'=>'3']);check(count($other['incoming'])===2&&count($other['outgoing'])===0,'Different actor receives only their own traffic');
check(scriptTraffic($p,['game_player_id'=>'999'])===['outgoing'=>[],'incoming'=>[]],'No traffic is a successful empty list');
foreach([[],['game_player_id'=>'1 OR 1=1'],['game_player_id'=>'9223372036854775808']] as $actor){try{scriptTraffic($p,$actor);throw new RuntimeException('Invalid actor accepted');}catch(DomainException $e){check($e->getCode()===403,'Invalid actor refused');}}
$token=str_repeat('a',64);$q=$p->prepare('INSERT INTO script_tokens VALUES(?,1,1)');$q->execute([hash('sha256',$token)]);
check(scriptTraffic($p,authorizeScript($token,'Me','UPTEP'))===$data,'Personal token determines the player');
foreach([[$token,'Recipient','UPTEP'],[str_repeat('b',64),'Me','UPTEP'],[$token,'Me','OTHER']] as $args){try{authorizeScript(...$args);throw new RuntimeException('Unauthorised access accepted');}catch(DomainException $e){check($e->getCode()===403,'Wrong identity/token/alliance refused');}}
$p->exec('UPDATE users SET script_enabled=false WHERE id=1');
try{authorizeScript($token,'Me','UPTEP');throw new RuntimeException('Revoked access accepted');}catch(DomainException $e){check($e->getCode()===403,'Revoked access refused');}
echo "OK: personal scope, directions, arrivals, cancellations, closed requests, exact quantities, duplicates and indexes.\n";
