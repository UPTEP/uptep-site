<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function botReply(int $status,array $data): never {
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    exit;
}
// Only the hosted API documentation may call the API from a browser.
$origin=$_SERVER['HTTP_ORIGIN']??'';
header('Vary: Origin');
if($origin==='https://api.uptep.top'){
    header('Access-Control-Allow-Origin: https://api.uptep.top');
    header('Access-Control-Expose-Headers: Idempotency-Replayed');
}
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){
    if($origin!=='https://api.uptep.top')botReply(403,['ok'=>false,'error'=>'origin_not_allowed']);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 600');
    http_response_code(204);exit;
}
try {
    $config=json_decode(file_get_contents('/home/uptep/private/bot-api.json'),true,16,JSON_THROW_ON_ERROR);
    $authorization=$_SERVER['HTTP_AUTHORIZATION']??'';
    $token=preg_match('/\ABearer (uptep_bot_[a-f0-9]{64})\z/i',$authorization,$matches)?$matches[1]:'';
    if(!$token || !($config['enabled']??false) || (isset($config['expires_at']) && time()>=$config['expires_at']) || !hash_equals($config['token_hash']??'',hash('sha256',$token))) {
        header('WWW-Authenticate: Bearer realm="UPTEP bot"');
        botReply(401,['ok'=>false,'error'=>'unauthorized']);
    }
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
    if($path==='/api/bot/v1/health'){
        if($method!=='GET'){header('Allow: GET');botReply(405,['ok'=>false,'error'=>'method_not_allowed']);}
        if(!in_array('health:read',$config['scopes']??[],true))botReply(403,['ok'=>false,'error'=>'forbidden']);
        botReply(200,['ok'=>true,'service'=>'uptep-bot-api','version'=>'1','status'=>'healthy','time'=>gmdate('Y-m-d\TH:i:s\Z')]);
    }
    if(preg_match('~\A/api/bot/v1/terrain/([1-9][0-9]{0,19})/([1-9][0-9]{0,19})(?:/(lots|publication))?\z~',$path,$tm)){
        $action=$tm[3]??'state';$required=$action==='state'?'GET':'POST';
        if($method!==$required){header('Allow: '.$required);botReply(405,['ok'=>false,'error'=>'method_not_allowed']);}
        if(!in_array($required==='GET'?'terrain:read':'terrain:write',$config['scopes']??[],true))botReply(403,['ok'=>false,'error'=>'forbidden']);
        if($required==='POST'){
            if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')botReply(415,['ok'=>false,'error'=>'application_json_required']);
            $raw=file_get_contents('php://input',false,null,0,2000001);if(strlen($raw)>2000000)botReply(413,['ok'=>false,'error'=>'Lot trop volumineux.']);
            $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        }else $input=$_GET;
        if(!is_array($input)||($input['discord_id']??null)!=='1544667516364652659'||$tm[1]!==($config['terrain_guild_id']??null))botReply(403,['ok'=>false,'error'=>'Suivi terrain réservé au compte et au serveur autorisés.']);
        define('UPTEP_SCRIPT_API',true);require '/home/uptep/private/app/bootstrap.php';require '/home/uptep/private/app/bot-terrain.php';
        botReply(200,$action==='state'?terrainState(db(),$tm[1],$tm[2]):($action==='lots'?terrainCommit(db(),$tm[1],$tm[2],$input):terrainPublished(db(),$tm[1],$tm[2],$input)));
    }
    if($path==='/api/bot/v1/demandes/statuts'){
        if($method!=='GET')botReply(405,['ok'=>false,'error'=>'method_not_allowed']);
        if(!in_array('demandes:read',$config['scopes']??[],true))botReply(403,['ok'=>false,'error'=>'forbidden']);
        $ids=$_GET['ids']??'';
        if(!is_string($ids)||!preg_match('/\A[1-9][0-9]{0,18}(?:,[1-9][0-9]{0,18}){0,99}\z/',$ids))botReply(422,['ok'=>false,'error'=>'Liste de demandes invalide (100 maximum).']);
        define('UPTEP_SCRIPT_API',true);require '/home/uptep/private/app/bootstrap.php';require '/home/uptep/private/app/bot-convoys.php';
        $ids=array_map(fn($id)=>botNumber($id,true),explode(',',$ids));
        $q=db()->prepare('SELECT id::text,fini,annulee FROM demandes_convois WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);
        botReply(200,['ok'=>true,'demandes'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if(in_array($path,['/api/bot/v1/floods','/api/bot/v1/floods/preview'],true)){
        $preview=str_ends_with($path,'/preview');
        if(!in_array($method,$preview?['POST']:['GET','POST'],true))botReply(405,['ok'=>false,'error'=>'method_not_allowed']);
        if(!in_array($method==='GET'?'floods:read':'floods:write',$config['scopes']??[],true))botReply(403,['ok'=>false,'error'=>'forbidden']);
        if($method==='POST'){
            if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')botReply(415,['ok'=>false,'error'=>'application_json_required']);
            $raw=file_get_contents('php://input',false,null,0,16001);if(strlen($raw)>16000)botReply(413,['ok'=>false,'error'=>'Déclaration trop longue.']);
            $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($input))botReply(422,['ok'=>false,'error'=>'Déclaration invalide.']);
        }else $input=$_GET;
        define('UPTEP_SCRIPT_API',true);require '/home/uptep/private/app/bootstrap.php';require '/home/uptep/private/app/bot-convoys.php';require '/home/uptep/private/app/bot-floods.php';
        if($preview){botActor(db(),db(true),botNumber($input['discord_id']??null,true),false);botReply(200,['ok'=>true,'floods'=>floodResolve(db(),db(true),$input)]);}
        botReply(200,$method==='GET'?floodList(db(),db(true),$input):floodCreate(db(),db(true),$input));
    }
    if($path==='/api/bot/v1/chaine'){
        if($method!=='GET'){header('Allow: GET');botReply(405,['ok'=>false,'error'=>'method_not_allowed']);}
        if(!in_array('membres:read',$config['scopes']??[],true))botReply(403,['ok'=>false,'error'=>'forbidden']);
        define('UPTEP_SCRIPT_API',true);require '/home/uptep/private/app/bootstrap.php';require '/home/uptep/private/app/bot-convoys.php';require '/home/uptep/private/app/bot-chain.php';
        botReply(200,botChain(db(),db(true),$_GET));
    }
    if($path==='/api/bot/v1/livraisons'){
        if($method!=='GET'){header('Allow: GET');botReply(405,['ok'=>false,'error'=>'method_not_allowed']);}
        if(!in_array('demandes:read',$config['scopes']??[],true))botReply(403,['ok'=>false,'error'=>'forbidden']);
        define('UPTEP_SCRIPT_API',true);require '/home/uptep/private/app/bootstrap.php';require '/home/uptep/private/app/bot-convoys.php';require '/home/uptep/private/app/bot-deliveries.php';
        botReply(200,botDeliveries(db(),db(true),$_GET));
    }
    $id=null;$reportKind=null;
    if(preg_match('~\A/api/bot/v1/rapports/(demandes|convois|statistiques)(?:/([1-9][0-9]{0,18}))?\z~',$path,$reportMatch)){
        $reportKind=$reportMatch[1];$id=$reportMatch[2]??null;
        if($id!==null&&$reportKind!=='demandes')botReply(404,['ok'=>false,'error'=>'not_found']);
        if($method!=='GET'){header('Allow: GET');botReply(405,['ok'=>false,'error'=>'method_not_allowed']);}
        if(!in_array('rapports:read',$config['scopes']??[],true))botReply(403,['ok'=>false,'error'=>'forbidden']);
        define('UPTEP_SCRIPT_API',true);
        require '/home/uptep/private/app/bootstrap.php';
        require '/home/uptep/private/app/bot-convoys.php';
        require '/home/uptep/private/app/bot-reports.php';
        botReply(200,botReports(db(),db(true),$reportKind,$_GET,$id));
    }
    if($path==='/api/bot/v1/demandes'){$allowed=['GET','POST'];$action=$method==='GET'?'lecture':'demande';}
    elseif($path==='/api/bot/v1/convois'){$allowed=['GET'];$action='convois_annulables';}
    elseif(preg_match('~\A/api/bot/v1/convois/([1-9][0-9]{0,18})/annulation\z~',$path,$match)){$allowed=['POST'];$id=$match[1];$action='annulation_convoi';}
    elseif(preg_match('~\A/api/bot/v1/membres/([1-9][0-9]{0,18})\z~',$path,$match)){$allowed=['GET'];$id=$match[1];$action='membre';}
    elseif(preg_match('~\A/api/bot/v1/demandes/([1-9][0-9]{0,18})/(validation|annulation|terminaison|ajustement|convois)\z~',$path,$match)){$allowed=$match[2]==='convois'?['GET','POST']:['POST'];$id=$match[1];$action=$match[2]!=='convois'?$match[2]:($method==='GET'?'lecture_convois':'convoi');}
    else botReply(404,['ok'=>false,'error'=>'not_found']);
    if(!in_array($method,$allowed,true)){header('Allow: '.implode(', ',$allowed));botReply(405,['ok'=>false,'error'=>'method_not_allowed']);}
    $scope=['ajustement'=>'demandes:adjust','terminaison'=>'demandes:finish','annulation_convoi'=>'convois:cancel','convois_annulables'=>'convois:read','membre'=>'membres:read','annulation'=>'demandes:cancel','lecture'=>'demandes:read','lecture_convois'=>'convois:read','demande'=>'demandes:create','validation'=>'demandes:validate','convoi'=>'convois:create'][$action];
    if(!in_array($scope,$config['scopes']??[],true))botReply(403,['ok'=>false,'error'=>'forbidden']);
    define('UPTEP_SCRIPT_API',true);
    require '/home/uptep/private/app/bootstrap.php';
    require '/home/uptep/private/app/bot-convoys.php';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if($id!==null)$id=botNumber($id,true);
    if($action==='convois_annulables')botReply(200,botCancelableConvoys(db(),db(true),$_GET));
    if($action==='membre')botReply(200,botMember(db(),db(true),$id));
    if($action==='lecture_convois')botReply(200,botListConvoys(db(),$id,$_GET));
    if($action==='lecture')botReply(200,botListDemands(db(),$_GET));
    if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')botReply(415,['ok'=>false,'error'=>'application_json_required']);
    $raw=file_get_contents('php://input');
    $body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($body)||!str_starts_with(ltrim($raw),'{'))botReply(400,['ok'=>false,'error'=>'json_object_required']);
    [$status,$out,$replayed]=$action==='annulation_convoi'?botCancelConvoy(db(),db(true),$id,$body):botConvoyAction(db(),db(true),$action,$id,$body);
    header('Idempotency-Replayed: '.($replayed?'true':'false'));
    botReply($status,$out);
}catch(DomainException $e){botReply($e->getCode(),['ok'=>false,'error'=>$e->getMessage()]);}
catch(JsonException $e){botReply(400,['ok'=>false,'error'=>'invalid_json']);}
catch(Throwable $e){error_log('UPTEP bot API: '.get_class($e));botReply(503,['ok'=>false,'error'=>'service_unavailable']);}
