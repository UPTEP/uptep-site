<?php
// Run in a disposable directory containing the three app PHP dependencies.
// TEMP tables shadow public tables; nothing is written to production tables.
declare(strict_types=1);
function db(): PDO {static $p;return $p??=new PDO('pgsql:dbname=uptep',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
require __DIR__.'/script-settings.php';
function check(bool $v,string $m): void {if(!$v)throw new RuntimeException($m);}
$p=db();
$p->exec("CREATE TEMP TABLE users(id int PRIMARY KEY, game_player_id int,status text,script_enabled bool,must_change_password bool);
CREATE TEMP TABLE script_settings(user_id int PRIMARY KEY,preferences jsonb DEFAULT '{\"buildings\":true,\"research\":true,\"army\":true,\"economy\":true,\"chaine\":true,\"clock\":true,\"statistics\":true}',version int DEFAULT 1,updated_at timestamptz DEFAULT now());
CREATE TEMP TABLE user_stats(user_id int,buildings_synced_at timestamptz,research_synced_at timestamptz,army_synced_at timestamptz,resources_synced_at timestamptz,workers_synced_at timestamptz);
INSERT INTO users VALUES(1,123,'active',true,false)");
$s=scriptSettings(1);check($s['preferences']['laboratory']===true,'Legacy settings get default');check(count($s['preferences'])===count(SCRIPT_OPTIONS),'All options exposed');
$prefs=$s['preferences'];$prefs['laboratory']=false;
$s=scriptSettingsAction(['id'=>1,'game_player_id'=>123],['action'=>'save-settings','preferences'=>$prefs,'version'=>$s['version']]);check($s['preferences']['laboratory']===false,'Disabled choice persists');
$p->exec(file_get_contents(__DIR__.'/laboratory-settings.sql'));$p->exec(file_get_contents(__DIR__.'/laboratory-settings.sql'));
check(scriptSettings(1)['preferences']['laboratory']===false,'Idempotent migration preserves disabled choice');
check(scriptSettings(2)['preferences']['laboratory']===true,'New user defaults');
echo "OK: legacy/new preferences, persistent opt-out and idempotent migration (TEMP tables only)\n";
