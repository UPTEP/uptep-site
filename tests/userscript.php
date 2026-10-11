<?php
require '/home/uptep/private/app/bootstrap.php';
require '/root/uptep-site/app/userscript.php';
function ok($v,$m){if(!$v)throw new RuntimeException($m);}
function denied($f,$code=403){try{$f();}catch(DomainException $e){ok($e->getCode()===$code,'Wrong error code');return;}throw new RuntimeException('Expected rejection');}
$p=db();$p->exec('CREATE TEMP TABLE users (LIKE public.users INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');$p->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS script_enabled boolean NOT NULL DEFAULT true');
$p->exec('CREATE TEMP TABLE script_tokens(token_hash char(64) PRIMARY KEY,user_id bigint,player_id bigint)');
$player=db(true)->query("SELECT player_id,pseudo FROM players WHERE server='s5' AND upper(alliance_tag)='UPTEP' AND is_present=true ORDER BY player_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);ok((bool)$player,'Need roster');
$q=$p->prepare("INSERT INTO users(id,username,display_name,password_hash,role,game_player_id,status,must_change_password) VALUES (901,'scriptmember','Member','test','member',?,'active',false),(902,'scriptadmin','Admin','test','admin',?,'active',false)");$q->execute([$player['player_id'],$player['player_id']]);
$a=['id'=>901,'session_version'=>1];$admin=['id'=>902,'session_version'=>1];$token=issueScriptToken($a);
ok((int)authorizeScript($token,$player['pseudo'],'UPTEP')['id']===901,'Owner match');
denied(fn()=>authorizeScript(str_repeat('0',64),$player['pseudo'],'UPTEP'));denied(fn()=>authorizeScript($token,'WrongPlayer','UPTEP'));denied(fn()=>authorizeScript($token,$player['pseudo'],'OTHER'));
denied(fn()=>setScriptAccess($a,902,1,false));setScriptAccess($admin,901,1,false);denied(fn()=>authorizeScript($token,$player['pseudo'],'UPTEP'));denied(fn()=>issueScriptToken($a));
setScriptAccess($admin,901,2,true);ok((int)authorizeScript($token,$player['pseudo'],'UPTEP')['id']===901,'Enable without reinstall');denied(fn()=>setScriptAccess($admin,901,1,false),409);
$p->exec("UPDATE users SET status='pending' WHERE id=901");denied(fn()=>authorizeScript($token,$player['pseudo'],'UPTEP'));
$p->exec("UPDATE users SET status='active',game_player_id=NULL WHERE id=901");denied(fn()=>authorizeScript($token,$player['pseudo'],'UPTEP'));
echo "OK : clé personnelle, pseudo, alliance, compte validé, ID associé, désactivation/réactivation, contrôle admin et concurrence.\n";
