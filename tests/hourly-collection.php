<?php
require '/home/uptep/private/app/bootstrap.php';require '/root/uptep-site/app/script-settings.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);}
$a=db()->query("SELECT id,game_player_id FROM users WHERE username='qa_chaine_editor'")->fetch(PDO::FETCH_ASSOC);
$body=['action'=>'collect','group'=>'economy','values'=>['nourriture'=>'11','materiaux'=>'13006','terrain'=>'3700','ouvrieres'=>'4193']];
$before=loadStats((int)$a['id']);$r=scriptSettingsAction($a,$body);check($r['collected']===true,'First collection');$first=loadStats((int)$a['id']);check($first['data']['workers']['total']==='4193'&&$first['synced']['resources']===$first['synced']['workers'],'Data/dates');check($first['synced']['buildings']===$before['synced']['buildings'],'Other date preserved');
$body['values']['materiaux']='999';$r=scriptSettingsAction($a,$body);check($r['collected']===false,'Hourly limit');$second=loadStats((int)$a['id']);check($first===$second,'Rate limited write changes nothing');
db()->prepare("UPDATE user_stats SET resources_synced_at=now()-interval '61 minutes' WHERE user_id=?")->execute([$a['id']]);check(scriptSettingsAction($a,$body)['collected']===true,'After one hour');
$s=scriptSettings((int)$a['id']);$prefs=$s['preferences'];$prefs['economy']=false;scriptSettingsAction($a,['action'=>'save-settings','preferences'=>$prefs,'version'=>$s['version']]);
try{scriptSettingsAction($a,$body);throw new RuntimeException('Disabled accepted');}catch(DomainException $e){check($e->getCode()===409,'Disabled error');}
$s=scriptSettings((int)$a['id']);$prefs['economy']=true;scriptSettingsAction($a,['action'=>'save-settings','preferences'=>$prefs,'version'=>$s['version']]);db()->prepare('UPDATE user_stats SET resources_synced_at=NULL,workers_synced_at=NULL WHERE user_id=?')->execute([$a['id']]);echo "OK : collecte horaire, second envoi ignoré, reprise après une heure et désactivation.\n";
