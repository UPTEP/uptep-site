<?php
declare(strict_types=1);
define('UPTEP_LOGIN_TEST_CONFIG', sys_get_temp_dir().'/'.basename(__DIR__).'-test-config.json');
require __DIR__.'/discord-login.php';
function check(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);}
function reject(callable $fn):void{try{$fn();}catch(DomainException $e){check($e->getCode()===403,'wrong error');return;}throw new RuntimeException('Expected rejection');}
function hashOf(array $result):string{return hash('sha256',parse_url($result['url'],PHP_URL_FRAGMENT));}
foreach (['https://uptep.top', 'null', ''] as $origin) {
 check(discordLoginRequestAllowed(['HTTP_ORIGIN'=>$origin,'HTTP_SEC_FETCH_SITE'=>'same-origin'],true),'privacy-compatible origin');
 check(!discordLoginRequestAllowed(['HTTP_ORIGIN'=>$origin],false),'CSRF always required');
 check(!discordLoginRequestAllowed(['HTTP_ORIGIN'=>$origin,'HTTP_SEC_FETCH_SITE'=>'cross-site'],true),'cross-site rejected');
 check(!discordLoginRequestAllowed(['HTTP_ORIGIN'=>$origin,'HTTP_SEC_FETCH_SITE'=>'same-site'],true),'sibling origin rejected');
}
check(discordLoginRequestAllowed([],true),'legacy browser with valid CSRF');
check(!discordLoginRequestAllowed(['HTTP_ORIGIN'=>'https://evil.example'],true),'foreign origin rejected');
check(discordLoginPayloadAllowed(['discord_id'=>'222222222222222222','guild_id'=>'guild'],'guild'),'any member payload accepted');
foreach ([null, [], ['discord_id'=>222,'guild_id'=>'guild'], ['discord_id'=>'0','guild_id'=>'guild'], ['discord_id'=>'9223372036854775808','guild_id'=>'guild'], ['discord_id'=>'123','guild_id'=>'other'], ['discord_id'=>'123','guild_id'=>'guild','user_id'=>1]] as $payload) {
 check(!discordLoginPayloadAllowed($payload,'guild'),'invalid identity, guild or extra fields rejected');
}
check(!discordLoginPayloadAllowed(['discord_id'=>'123','guild_id'=>''],''),'empty guild denied');
$acceptance=['accept_membership'=>'1','accept_terms'=>'1','privacy_notice'=>'1','terms_version'=>DISCORD_REGISTRATION_TERMS_VERSION];
check(registrationAcceptedVersion($acceptance)===DISCORD_REGISTRATION_TERMS_VERSION,'all three declarations required');
foreach(['accept_membership','accept_terms','privacy_notice'] as $key){
 $missing=$acceptance;unset($missing[$key]);check(registrationAcceptedVersion($missing)===null,'missing checkbox rejected');
 $missing[$key]=['1'];check(registrationAcceptedVersion($missing)===null,'array checkbox rejected');
}
$p=new PDO('pgsql:dbname=uptep','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if (($argv[1] ?? '') === 'redeem') {
 check((bool)preg_match('/\Aqa_discord_login_[a-f0-9]+\z/', $argv[2]), 'worker schema');
 $p->exec('SET search_path TO '.$argv[2]);
 try { $u=discordLoginConsume($p,$argv[3],DISCORD_REGISTRATION_TERMS_VERSION); echo 'created:'.$u['role']; exit(0); }
 catch (DomainException $e) { echo 'refused'; exit(2); }
}
$schema='qa_discord_login_'.bin2hex(random_bytes(6));$p->exec('CREATE SCHEMA '.$schema);$p->exec('SET search_path TO '.$schema);
try{
 $p->exec("CREATE TABLE users (LIKE public.users INCLUDING ALL); CREATE TABLE chaine_members(server text,player_id bigint,iddiscord bigint,pseudo text); INSERT INTO users(id,username,display_name,password_hash,game_player_id,status,session_version,role,must_change_password) OVERRIDING SYSTEM VALUE VALUES(1,'existingowner','Owner','test',1530,'active',1,'admin',false); INSERT INTO chaine_members VALUES('s5',1530,1544667516364652659,'Test Player')");
 $sql=preg_replace('/^ALTER TABLE .* OWNER TO uptep;$/m','',file_get_contents(__DIR__.'/discord-login.sql'));$p->exec($sql);
 $migration=file_get_contents(__DIR__.'/discord-login-registration.sql');$p->exec($migration);$p->exec($migration);$p->exec(file_get_contents(__DIR__.'/discord-login-test.sql'));$p->exec(file_get_contents(__DIR__.'/discord-login-password.sql'));
 $p->exec(file_get_contents(__DIR__.'/discord-login-terms.sql'));
 $p->exec('UPDATE users SET discord_login_id=1544667516364652659 WHERE id=1');
 reject(fn()=>discordLoginIssue($p,'123'));
 $link=discordLoginIssue($p,DISCORD_LOGIN_USER);$hash=hashOf($link);check($link['expires_in']===120,'TTL');check(strlen(parse_url($link['url'],PHP_URL_FRAGMENT))===64,'256-bit token');
 check($p->query('SELECT token_hash FROM discord_login_tokens')->fetchColumn()===$hash,'stored hash only');
 check(discordLoginLookup($p,$hash)['id']===1,'preview');check(discordLoginLookup($p,$hash)['id']===1,'preview never consumes');
 check(discordLoginConsume($p,$hash)['role']==='admin','existing role unchanged');
 $existing=$p->query('SELECT * FROM users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
 check(legalAcceptanceRequired($existing),'existing user requires real acceptance');
 reject(fn()=>legalAcceptExisting($p,$existing,null));
 reject(fn()=>legalAcceptExisting($p,$existing,'old-version'));
 legalAcceptExisting($p,$existing,DISCORD_REGISTRATION_TERMS_VERSION);
 $accepted=$p->query('SELECT * FROM users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
 check(!legalAcceptanceRequired($accepted),'existing acceptance recorded');
 legalAcceptExisting($p,$accepted,DISCORD_REGISTRATION_TERMS_VERSION);
 check($p->query('SELECT terms_accepted_at FROM users WHERE id=1')->fetchColumn()===$accepted['terms_accepted_at'],'repeat preserves timestamp');reject(fn()=>discordLoginConsume($p,$hash));reject(fn()=>discordLoginLookup($p,str_repeat('0',64)));reject(fn()=>discordLoginLookup($p,'invalid'));
 $first=hashOf(discordLoginIssue($p,DISCORD_LOGIN_USER));$second=hashOf(discordLoginIssue($p,DISCORD_LOGIN_USER));reject(fn()=>discordLoginConsume($p,$first));discordLoginConsume($p,$second);
 $expired=hashOf(discordLoginIssue($p,DISCORD_LOGIN_USER));$p->exec("UPDATE discord_login_tokens SET expires_at=now()-interval '1 second'");reject(fn()=>discordLoginConsume($p,$expired));
 foreach(['disabled','pending'] as $status){$h=hashOf(discordLoginIssue($p,DISCORD_LOGIN_USER));$p->exec("UPDATE users SET status='$status'");reject(fn()=>discordLoginConsume($p,$h));reject(fn()=>discordLoginIssue($p,DISCORD_LOGIN_USER));$p->exec("UPDATE users SET status='active'");}
 $h=hashOf(discordLoginIssue($p,DISCORD_LOGIN_USER));$p->exec('UPDATE users SET session_version=2');reject(fn()=>discordLoginConsume($p,$h));
 $h=hashOf(discordLoginIssue($p,DISCORD_LOGIN_USER));$p->exec('UPDATE users SET game_player_id=999');reject(fn()=>discordLoginConsume($p,$h));reject(fn()=>discordLoginIssue($p,DISCORD_LOGIN_USER));$p->exec('UPDATE users SET game_player_id=1530');
 // Another user's editable profile can never replace the pinned login account.
 $p->exec("INSERT INTO users(id,username,display_name,password_hash,game_player_id) OVERRIDING SYSTEM VALUE VALUES(2,'othermember','Other','test',1530)");
 $h=hashOf(discordLoginIssue($p,DISCORD_LOGIN_USER));check(discordLoginConsume($p,$h)['id']===1,'profile impersonation cannot select account');$p->exec('DELETE FROM users WHERE id=2');
 $h=hashOf(discordLoginIssue($p,DISCORD_LOGIN_USER));$p->exec("INSERT INTO chaine_members VALUES('s5',999,1544667516364652659,'Other')");reject(fn()=>discordLoginConsume($p,$h));reject(fn()=>discordLoginIssue($p,DISCORD_LOGIN_USER));$p->exec('DELETE FROM chaine_members WHERE player_id=999');
 $h=hashOf(discordLoginIssue($p,DISCORD_LOGIN_USER));$p->exec('UPDATE chaine_members SET player_id=999');reject(fn()=>discordLoginConsume($p,$h));$p->exec('UPDATE chaine_members SET player_id=1530');
 $p->exec('UPDATE users SET discord_login_id=NULL');reject(fn()=>discordLoginIssue($p,DISCORD_LOGIN_USER));
 // New-account fixture: only the administrator's chain/Discord association exists.
 $p->exec('DELETE FROM discord_login_tokens; DELETE FROM users');
 $h=hashOf(discordLoginIssue($p,DISCORD_LOGIN_USER));$draft=discordLoginLookup($p,$h);check($draft['id']===null,'registration preview');check((int)$p->query('SELECT count(*) FROM users')->fetchColumn()===0,'issue and preview never create');
 $p->exec("UPDATE chaine_members SET iddiscord=NULL");reject(fn()=>discordLoginConsume($p,$h));reject(fn()=>discordLoginIssue($p,DISCORD_LOGIN_USER));$p->exec('UPDATE chaine_members SET iddiscord=1544667516364652659');
 $p->exec('UPDATE chaine_members SET player_id=999');reject(fn()=>discordLoginConsume($p,$h));$p->exec('UPDATE chaine_members SET player_id=1530');
 // Expired registration cannot leave an account behind.
 $p->exec("UPDATE discord_login_tokens SET expires_at=now()-interval '1 second'");reject(fn()=>discordLoginConsume($p,$h));check((int)$p->query('SELECT count(*) FROM users')->fetchColumn()===0,'expired creation rollback');
 $h=hashOf(discordLoginIssue($p,DISCORD_LOGIN_USER));
 // A concurrent manually created account must not be silently linked/taken over.
 $p->exec("INSERT INTO users(id,username,display_name,password_hash,game_player_id) OVERRIDING SYSTEM VALUE VALUES(2,'manualuser','Manual','test',1530)");reject(fn()=>discordLoginConsume($p,$h));$p->exec('DELETE FROM users WHERE id=2');
 $p->exec("INSERT INTO users(id,username,display_name,password_hash,game_player_id) OVERRIDING SYSTEM VALUE VALUES(2,'test player','Collision','test',999)");reject(fn()=>discordLoginConsume($p,$h));$p->exec('DELETE FROM users WHERE id=2');
 // Missing or stale acceptance must not create an account or consume the token.
 reject(fn()=>discordLoginConsume($p,$h));
 reject(fn()=>discordLoginConsume($p,$h,'old-version'));
 check((int)$p->query('SELECT count(*) FROM users')->fetchColumn()===0,'no account without current acceptance');
 check(discordLoginLookup($p,$h)['id']===null,'rejected acceptance leaves token available');
 $workers=[];
 for($i=0;$i<2;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'redeem',$schema,$h],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);check(is_resource($proc),'worker started');fclose($pipes[0]);$workers[]=[$proc,$pipes];}
 $codes=[];foreach($workers as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$codes[]=proc_close($proc);check($err==='','worker error');}sort($codes);check($codes===[0,2],'one concurrent registration winner');
 check((int)$p->query('SELECT count(*) FROM users')->fetchColumn()===1,'one account created');$u=$p->query('SELECT * FROM users')->fetch(PDO::FETCH_ASSOC);
 check($u['terms_accepted_at']!==null && $u['terms_version']===DISCORD_REGISTRATION_TERMS_VERSION && $u['privacy_notice_version']===DISCORD_REGISTRATION_TERMS_VERSION,'acceptance recorded atomically');
 check($u['role']==='member'&&$u['status']==='active'&&!$u['is_chef']&&!$u['is_chaine']&&!$u['must_change_password'],'least privileges and no forced unknown password');
 check($u['username']==='Test Player'&&$u['display_name']==='Test Player'&&$u['password_setup_required'],'name and password prompt');
 check((string)$u['discord_login_id']===DISCORD_LOGIN_USER&&(int)$u['game_player_id']===1530,'dedicated binding');check(password_get_info($u['password_hash'])['algoName']==='argon2id','random password hashed');
 $h=hashOf(discordLoginIssue($p,DISCORD_LOGIN_USER));check(discordLoginConsume($p,$h)['id']===$u['id'],'subsequent login reuses account');check((int)$p->query('SELECT count(*) FROM users')->fetchColumn()===1,'no duplicate');
 // A second ordinary member can register and then reconnect to their own account.
 $member='222222222222222222';
 $p->exec("INSERT INTO chaine_members VALUES('s5',777,222222222222222222,'Ordinary Member')");
 $memberLink=hashOf(discordLoginIssue($p,$member));$ownerLink=hashOf(discordLoginIssue($p,DISCORD_LOGIN_USER));
 check(discordLoginLookup($p,$memberLink)['discord_login_id']===$member,'member preview identity');
 $newMember=discordLoginConsume($p,$memberLink,DISCORD_REGISTRATION_TERMS_VERSION);
 check($newMember['role']==='member'&&!$newMember['is_chef']&&!$newMember['is_chaine']&&$newMember['password_setup_required'],'ordinary member registration has no elevated rights');
 check((string)$newMember['discord_login_id']===$member,'member binding');
 check(discordLoginConsume($p,$ownerLink)['id']===$u['id'],'member request does not invalidate another user token');
 check(discordLoginConsume($p,hashOf(discordLoginIssue($p,$member)))['id']===$newMember['id'],'member reconnects to own account');
 $p->exec("UPDATE users SET status='disabled' WHERE discord_login_id=222222222222222222");reject(fn()=>discordLoginIssue($p,$member));
 reject(fn()=>discordLoginIssue($p,'333333333333333333'));
 // Legacy simulation still requires the explicit temporary configuration.
 $settings=['login_test_target'=>DISCORD_LOGIN_TEST_TARGET,'login_test_enabled'=>true,'login_test_until'=>time()+300];
 file_put_contents(UPTEP_LOGIN_TEST_CONFIG,json_encode($settings));
 $p->exec("INSERT INTO chaine_members VALUES('s5',360,757857297903910954,'Hardware')");
 check(discordLoginLookup($p,hashOf(discordLoginIssue($p,DISCORD_LOGIN_TEST_TARGET)))['id']===null,'associated target can request normal registration');
 reject(fn()=>discordLoginIssue($p,DISCORD_LOGIN_USER,true));
 reject(fn()=>discordLoginIssue($p,'123',true));
 $h=hashOf(discordLoginIssue($p,DISCORD_LOGIN_TEST_TARGET,true));
 $draft=discordLoginLookup($p,$h);check($draft['id']===null&&$draft['_login_test']&&$draft['display_name']==='Hardware','simulation preview');
 $settings['login_test_enabled']=false;file_put_contents(UPTEP_LOGIN_TEST_CONFIG,json_encode($settings));reject(fn()=>discordLoginConsume($p,$h));
 $settings['login_test_enabled']=true;$settings['login_test_until']=time()-1;file_put_contents(UPTEP_LOGIN_TEST_CONFIG,json_encode($settings));reject(fn()=>discordLoginConsume($p,$h));
 $settings['login_test_until']=time()+300;file_put_contents(UPTEP_LOGIN_TEST_CONFIG,json_encode($settings));
 $created=discordLoginConsume($p,$h,DISCORD_REGISTRATION_TERMS_VERSION);check($created['username']==='Hardware'&&$created['display_name']==='Hardware'&&$created['password_setup_required']&&$created['role']==='member'&&(string)$created['discord_login_id']===DISCORD_LOGIN_TEST_TARGET,'fixed-target member registration');
 reject(fn()=>discordLoginIssue($p,DISCORD_LOGIN_TEST_TARGET,true));check(discordLoginConsume($p,hashOf(discordLoginIssue($p,DISCORD_LOGIN_TEST_TARGET)))['id']===$created['id'],'associated target can reconnect normally');reject(fn()=>discordLoginConsume($p,$h));
 check(discordLoginConsume($p,hashOf(discordLoginIssue($p,DISCORD_LOGIN_USER)))['id']===$u['id'],'normal owner login unchanged');
 unlink(UPTEP_LOGIN_TEST_CONFIG);
 echo "OK: request security, pinned identity, existing-account protection, preview without creation, least-privilege registration, concurrent single creation, expiry, replay and revocation\n";
}finally{if(is_file(UPTEP_LOGIN_TEST_CONFIG))unlink(UPTEP_LOGIN_TEST_CONFIG);$p->exec('DROP SCHEMA '.$schema.' CASCADE');}
