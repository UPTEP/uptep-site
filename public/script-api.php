<?php
declare(strict_types=1);
define('UPTEP_SCRIPT_API',true);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/userscript.php';
require '/home/uptep/private/app/chaine.php';
require '/home/uptep/private/app/script-settings.php';
require '/home/uptep/private/app/script-traffic.php';
require '/home/uptep/private/app/member-report.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
try{
 if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');throw new DomainException('Méthode refusée.',405);}
 $body=json_decode(file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);
 if(!is_array($body) || !is_string($body['pseudo']??null) || !is_string($body['alliance']??null))throw new DomainException('Requête invalide.',400);
 $header=$_SERVER['HTTP_AUTHORIZATION']??'';$token=str_starts_with($header,'Bearer ')?substr($header,7):'';
 $actor=authorizeScript($token,$body['pseudo'],$body['alliance']);
 // Compatibility with installed loaders: never remount an active page. Authorization remains checked.
 if(is_string($body['revision']??null)&&preg_match('/\A[a-f0-9]{64}\z/',$body['revision'])){echo json_encode(['ok'=>true,'revision'=>$body['revision']],JSON_THROW_ON_ERROR);exit;}
 $q=db()->query("SELECT player_id,grade,sort_order FROM chaine_members WHERE server='s5' ORDER BY player_id");$assignments=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$assignments[(string)$row['player_id']]=$row;
 $players=[];foreach(chainePlayers() as $player){$saved=$assignments[(string)$player['player_id']]??[];$players[]=['player_id'=>(string)$player['player_id'],'pseudo'=>$player['pseudo'],'x'=>isset($player['x'])?(int)$player['x']:null,'y'=>isset($player['y'])?(int)$player['y']:null,'grade'=>$saved['grade']??null,'sort_order'=>isset($saved['sort_order'])?(int)$saved['sort_order']:null,'on_holiday'=>$player['on_holiday'],'is_banned'=>$player['is_banned']];}
 $q=db()->prepare("SELECT data->'research'->>'vitesse_attaque' AS speed,research_synced_at FROM user_stats WHERE user_id=?");$q->execute([(int)$actor['id']]);$research=$q->fetch(PDO::FETCH_ASSOC);
 $speed=$research['speed']??null;$speed=is_string($speed)&&preg_match('/\A[0-9]{1,3}\z/',$speed)?(int)$speed:null;
 $chainCode='const uptepChaineData='.json_encode(['self'=>(string)$actor['game_player_id'],'players'=>$players,'attack_speed'=>$speed,'research_synced_at'=>$research['research_synced_at']??null],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).';';
 $code=file_get_contents('/home/uptep/private/app/script-runtime.min.js');if($code===false)throw new RuntimeException('Runtime missing');
 $prefs=scriptSettings((int)$actor['id'])['preferences'];
 $traffic=$prefs['traffic']?scriptTraffic(db(),$actor):null;
 $code='const uptepTrafficData='.json_encode($traffic,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).';'.$code;
 $lab=loadStats((int)$actor['id']);
 $labData=$prefs['laboratory']?['data'=>array_intersect_key($lab['data'],array_flip(['buildings','research','army'])),'synced'=>array_intersect_key($lab['synced'],array_flip(['buildings','research','army']))]:null;
 $code='const uptepLaboratoryData='.json_encode($labData,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).';'.$code;
 $code='const uptepMemberReportAccess='.json_encode(memberReportAllowed(db(),$actor)).';'.$code;
 $code='const uptepHourlyDue='.json_encode($prefs['economy']&&hourlyCollectionDue((int)$actor['id'])).';const uptepPreferences='.json_encode($prefs,JSON_THROW_ON_ERROR).';'.$chainCode.$code;
 // The current time seeds local countdowns but must not change the revision every poll.
 $revision=hash('sha256',$code);$out=['ok'=>true,'revision'=>$revision];if(($body['revision']??'')!==$revision)$out['code']='const uptepTrafficServerTime='.json_encode(gmdate('Y-m-d\TH:i:s\Z')).';const uptepPortalAuth='.json_encode(['token'=>$token,'pseudo'=>$body['pseudo']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).';'.$code;
 echo json_encode($out,JSON_THROW_ON_ERROR);
}catch(DomainException $e){http_response_code($e->getCode());echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
catch(JsonException $e){http_response_code(400);echo '{"ok":false,"error":"Requête invalide."}';}
catch(Throwable $e){error_log('UPTEP script API: '.$e->getMessage());http_response_code(503);echo '{"ok":false,"error":"Service indisponible."}';}
