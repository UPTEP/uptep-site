<?php
// Only TEMP tables are modified; real accounts and sessions are never used.
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
session_start();$p=db();
$p->exec("CREATE TEMP TABLE users(id bigint PRIMARY KEY,username text,display_name text,game_player_id bigint,role text,is_chef boolean,is_chaine boolean,script_enabled boolean,status text,session_version int,must_change_password boolean,password_setup_required boolean,terms_accepted_at timestamptz,terms_version text,privacy_notice_version text,last_login_at timestamptz,last_site_seen_at timestamptz);
INSERT INTO users VALUES(999999,'fixture','Fixture',1,'member',true,false,true,'active',1,false,false,now(),'1','1',NULL,NULL)");
$u=userRecord(999999);signIn($u);
if(!$p->query('SELECT last_login_at IS NOT NULL AND last_site_seen_at IS NOT NULL FROM users')->fetchColumn())throw new RuntimeException('Login activity missing');
$p->exec("UPDATE users SET last_site_seen_at=now()-interval '1 hour'");authenticatedUser();
if(!$p->query("SELECT last_site_seen_at>now()-interval '1 minute' FROM users")->fetchColumn())throw new RuntimeException('Site activity missing');
$stamp=$p->query('SELECT last_site_seen_at FROM users')->fetchColumn();authenticatedUser();if($p->query('SELECT last_site_seen_at FROM users')->fetchColumn()!==$stamp)throw new RuntimeException('Site activity not throttled');
$_SESSION['preview']=[];$p->exec("UPDATE users SET last_site_seen_at=now()-interval '1 hour'");authenticatedUser();if(!$p->query("SELECT last_site_seen_at<now()-interval '30 minutes' FROM users")->fetchColumn())throw new RuntimeException('Preview changes activity');unset($_SESSION['preview']);
$p->exec("UPDATE users SET status='disabled'");if(authenticatedUser()!==null)throw new RuntimeException('Disabled session accepted');
echo "Site activity OK: login, observed use, throttling, preview and revoked session. TEMP tables only.\n";
