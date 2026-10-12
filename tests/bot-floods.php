<?php
require __DIR__.'/bot-convoys.php';require __DIR__.'/bot-floods.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);}
function rejects($fn,$status){try{$fn();throw new RuntimeException('Expected refusal');}catch(DomainException $e){check($e->getCode()===$status,'Wrong status');}}
$p=new PDO('pgsql:dbname=uptep','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$schema='qa_floods_'.bin2hex(random_bytes(5));$p->exec('CREATE SCHEMA '.$schema);$p->exec('GRANT USAGE ON SCHEMA '.$schema.' TO uptep');$p->exec('SET search_path TO '.$schema);
try{
 $p->exec(file_get_contents(__DIR__.'/bot-floods.sql'));
 $p->exec("CREATE TABLE chaine_members(server text,player_id bigint,iddiscord bigint);CREATE TABLE players(server text,player_id bigint,pseudo text,alliance_tag text,is_present boolean);INSERT INTO chaine_members VALUES('s5',1,123),('s5',2,456);INSERT INTO players VALUES('s5',1,'Sender','UPTEP',true),('s5',2,'l-o-l','UPTEP',true);");
 $row=['pseudo'=>'L-O-L','location'=>'Terrain de chasse','troops'=>'2 213 Jeunes Soldates Naines.','arrival_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600)];
 $body=['discord_id'=>'123','commande_id'=>'test:1','captured_at'=>gmdate('Y-m-d\TH:i:s\Z'),'floods'=>[$row,$row]];
 $body['floods']=floodResolve($p,$p,$body);check($body['floods'][0]['target_discord']==='456','mapped Discord');check($body['floods'][0]['pseudo']==='l-o-l','canonical pseudo');
 check($p->query('SELECT count(*) FROM floods')->fetchColumn()===0,'preview no writes');
 $r=floodCreate($p,$p,$body);check(count($r['floods'])===2,'identical lines remain distinct');check(floodCreate($p,$p,$body)==$r,'replay');check((int)$p->query('SELECT count(*) FROM floods')->fetchColumn()===2,'no repeated inserts');
 $changed=$body;$changed['floods'][0]['troops']='Different';rejects(fn()=>floodCreate($p,$p,$changed),409);
 check(floodList($p,$p,['discord_id'=>'456','scope'=>'self'])['total']===2,'recipient list');check(floodList($p,$p,['discord_id'=>'123','scope'=>'self'])['total']===0,'sender not recipient');
 $unknown=$body;$unknown['commande_id']='test:2';$unknown['floods']=[array_replace($row,['pseudo'=>'Unknown'])];$unknown['floods']=floodResolve($p,$p,$unknown);check($unknown['floods'][0]['target_id']===null&&$unknown['floods'][0]['warning']!==null,'unknown visible');floodCreate($p,$p,$unknown);
 $p->exec("UPDATE chaine_members SET iddiscord=789 WHERE player_id=2");$changed=$body;$changed['commande_id']='test:3';rejects(fn()=>floodCreate($p,$p,$changed),409);check((int)$p->query('SELECT count(*) FROM floods')->fetchColumn()===3,'mapping conflict rollback');
 $bad=$body;$bad['commande_id']='test:4';$bad['floods'][1]['arrival_at']='wrong';rejects(fn()=>floodCreate($p,$p,$bad),422);check((int)$p->query('SELECT count(*) FROM bot_flood_batches')->fetchColumn()===2,'batch rollback');
 rejects(fn()=>floodList($p,$p,['discord_id'=>'999']),403);
 $p->exec("UPDATE floods SET arrival_at=now()-interval '1 second'");check(floodList($p,$p,['discord_id'=>'123'])['total']===0,'expired absent');check((int)$p->query('SELECT count(*) FROM floods')->fetchColumn()===3,'expired retained');
 echo "OK floods: resolution, preview, duplicate attacks, idempotence, rollback, association changes, own list, expiration\n";
}finally{$p->exec('DROP SCHEMA '.$schema.' CASCADE');}
