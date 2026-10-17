<?php
declare(strict_types=1);
define('UPTEP_SCRIPT_API',true);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/userscript.php';
require '/home/uptep/private/app/observatory.php';
require '/home/uptep/private/app/script-settings.php';
require '/home/uptep/private/app/script-convoys.php';
require '/home/uptep/private/app/script-floods.php';
header('Content-Type: application/json; charset=utf-8');
try{
 if($_SERVER['REQUEST_METHOD']!=='POST')throw new DomainException('Méthode refusée.',405);
 $body=json_decode(file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);
 if(!is_array($body)||!is_string($body['pseudo']??null)||!is_string($body['alliance']??null))throw new DomainException('Requête invalide.',400);
 $header=$_SERVER['HTTP_AUTHORIZATION']??'';$token=str_starts_with($header,'Bearer ')?substr($header,7):'';
 $actor=authorizeScript($token,$body['pseudo'],$body['alliance']);
 if(in_array($body['action']??'', SCRIPT_CONVOY_ACTIONS,true))$result=scriptConvoys(db(),db(true),$actor,$body);
 elseif(in_array($body['action']??'', ['flood-check','flood-declare'],true))$result=scriptFloods(db(),db(true),$actor,$body);
 elseif(in_array($body['action']??'', ['settings','save-settings','collect'],true))$result=scriptSettingsAction($actor,$body);
 else{if(!scriptSettings((int)$actor['id'])['preferences']['statistics'])throw new DomainException('Statistiques désactivées dans tes paramètres.',409);$result=observatory($body);}
 echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(DomainException $e){http_response_code($e->getCode());echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
catch(JsonException $e){http_response_code(400);echo '{"ok":false,"error":"Requête invalide."}';}
catch(Throwable $e){error_log('UPTEP observatory: '.$e->getMessage());http_response_code(503);echo '{"ok":false,"error":"Service UPTEP momentanément indisponible."}';}
