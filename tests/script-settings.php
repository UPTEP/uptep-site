<?php
require '/home/uptep/private/app/bootstrap.php';require '/root/uptep-site/app/script-settings.php';
function ok($v,$m){if(!$v)throw new RuntimeException($m);}
$q=db()->query("SELECT id,game_player_id FROM users WHERE username='qa_chaine_editor'");$actor=$q->fetch(PDO::FETCH_ASSOC);$actor['game_player_id']=1;db()->prepare('UPDATE users SET game_player_id=1 WHERE id=?')->execute([$actor['id']]);
$s=scriptSettings((int)$actor['id']);ok(count($s['preferences'])===7,'Options');
db()->prepare("INSERT INTO user_stats(user_id,data) VALUES (?, '{\"resources\":{\"materiaux\":\"42\"},\"research\":{\"armes\":\"3\"}}')")->execute([$actor['id']]);
$r=scriptSettingsAction($actor,['action'=>'collect','group'=>'buildings','values'=>['couveuse'=>0,'dome'=>5]]);$stats=loadStats((int)$actor['id']);ok($stats['data']['resources']['materiaux']==='42'&&$stats['data']['research']['armes']==='3'&&$stats['data']['buildings']['couveuse']==='0','Merge preserves other stats and zero');ok($r['synced']['buildings']!==null,'Date');
$prefs=$s['preferences'];$prefs['buildings']=false;$s=scriptSettingsAction($actor,['action'=>'save-settings','preferences'=>$prefs,'version'=>$s['version']]);
try{scriptSettingsAction($actor,['action'=>'collect','group'=>'buildings','values'=>['dome'=>6]]);throw new RuntimeException('Disabled collection accepted');}catch(DomainException $e){ok($e->getCode()===409,'Disabled');}
ok(loadStats((int)$actor['id'])['data']['buildings']['dome']==='5','Disabled does not write');
try{scriptSettingsAction($actor,['action'=>'collect','group'=>'research','values'=>['unknown'=>1]]);throw new RuntimeException('Unknown key accepted');}catch(DomainException $e){ok($e->getCode()===422,'Validation');}
try{scriptSettingsAction($actor,['action'=>'save-settings','preferences'=>$prefs,'version'=>0]);throw new RuntimeException('Stale version accepted');}catch(DomainException $e){ok($e->getCode()===409,'Conflict');}
$prefs['buildings']=true;scriptSettingsAction($actor,['action'=>'save-settings','preferences'=>$prefs,'version'=>$s['version']]);
echo "OK : paramètres, fusion des niveaux, zéro, dates, refus des collectes désactivées et conflits.\n";
