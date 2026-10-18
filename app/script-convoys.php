<?php
declare(strict_types=1);
require_once __DIR__.'/bot-convoys.php';
require_once __DIR__.'/script-convoy-projection.php';
require_once __DIR__.'/script-convoy-management.php';
const SCRIPT_CONVOY_ACTIONS=['convoy-demands','convoy-pending','convoy-create','convoy-check','convoy-declare','convoy-list','convoy-detail','convoy-manage'];
function scriptConvoyIdentity(PDO $p,PDO $game,array $actor):array {
    $q=$p->prepare('SELECT discord_login_id::text,game_player_id::text,is_chaine FROM users WHERE id=? AND status=\'active\' AND script_enabled AND NOT must_change_password');$q->execute([$actor['id']]);$u=$q->fetch(PDO::FETCH_ASSOC);
    if(!$u||!$u['discord_login_id']||(string)$u['game_player_id']!==(string)$actor['game_player_id'])throw new DomainException('Ton compte UPTEP doit être associé à ton joueur et à ton compte Discord pour déclarer un convoi.',403);
    $member=botActor($p,$game,$u['discord_login_id'],false);
    if((string)$member['id']!==(string)$u['game_player_id'])throw new DomainException('Les associations joueur et Discord ne correspondent pas. Contacte un administrateur.',403);
    $member['is_chaine']=(bool)$u['is_chaine'];
    return $member;
}
function scriptConvoyEvent(PDO $p,string $kind,array $payload):void {
    $q=$p->prepare('INSERT INTO convoy_web_events(kind,payload) VALUES(?,?::jsonb)');$q->execute([$kind,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
function scriptConvoyResult(PDO $p,string $command,array $member,string $target,string $apple,string $wood,?string $demand):?array {
    $q=$p->prepare('SELECT reponse FROM bot_commandes WHERE commande_id=? AND action=\'convoi\'');$q->execute([$command]);$raw=$q->fetchColumn();if($raw===false)return null;
    $prior=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$c=$prior['convoi'];$d=$prior['demande'];
    if((string)$c['idenvoyeur']!==(string)$member['id']||(string)$d['iddemandeur']!==$target||(string)$c['nbpomme']!==$apple||(string)$c['nbmateriaux']!==$wood||($demand!==null&&(string)$c['iddemande']!==$demand))throw new DomainException('Ce convoi du jeu a déjà été déclaré avec une autre demande ou d’autres quantités.',409);
    $q=$p->prepare('SELECT annule FROM convois_effectues WHERE id=?');$q->execute([$c['id']]);$cancelled=$q->fetchColumn();
    return ['declared'=>true,'convoy_id'=>(string)$c['id'],'demand_id'=>(string)$c['iddemande'],'cancelled'=>(bool)$cancelled];
}
// Public terrain plus a capacity estimate; never expose private resource stocks or army.
function scriptConvoyProduction(array $player): array {
    $v=$player['field']??null;$tdc=is_string($v)&&preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/',$v)&&strlen($v)<=strlen((string)PHP_INT_MAX)&&(strlen($v)<strlen((string)PHP_INT_MAX)||strcmp($v,(string)PHP_INT_MAX)<=0)?$v:null;
    return ['tdc'=>$tdc,'tdc_at'=>$player['imported_at']??null,'capacity_per_cycle'=>$tdc];
}

function scriptConvoys(PDO $p,PDO $game,array $actor,array $body):array {
    $action=$body['action']??'';if(!in_array($action,SCRIPT_CONVOY_ACTIONS,true))throw new DomainException('Action inconnue.',422);
    $member=scriptConvoyIdentity($p,$game,$actor);
    if(in_array($action,['convoy-list','convoy-detail','convoy-manage'],true))return scriptConvoyManagement($p,$game,$member,$body);
    if(array_diff(array_keys($body),['action','pseudo','alliance','game_id','target','apples','wood','arrival','demand_id','confirmed','sent_at','request_key','object','level','wanted_date','auto_production']))throw new DomainException('Champ inconnu.',422);
    if($action==='convoy-create'){
        $key=$body['request_key']??null;if(!is_string($key)||!preg_match('/\A[a-f0-9-]{36}\z/',$key))throw new DomainException('Identifiant de demande invalide.',422);
        if(($body['confirmed']??false)!==true)throw new DomainException('Confirme la création de la demande.',422);
        $payload=['commande_id'=>'script-demand:'.$member['id'].':'.$key,'discord_id'=>$member['discord'],'nbpomme'=>botNumber($body['apples']??null),'nbmateriaux'=>botNumber($body['wood']??null),'objet'=>$body['object']??'','niveau'=>$body['level']??'','date_souhaitee'=>$body['wanted_date']??null];
        if(array_key_exists('auto_production',$body))$payload['auto_production']=$body['auto_production'];
        $q=$p->prepare("SELECT reponse FROM bot_commandes WHERE commande_id=? AND action='demande'");$q->execute([$payload['commande_id']]);$prior=$q->fetchColumn();
        if($prior!==false){
            $d=json_decode($prior,true,512,JSON_THROW_ON_ERROR)['demande'];
            if(!is_bool($payload['auto_production']??false)||(bool)($d['auto_production']??false)!==($payload['auto_production']??false)||!is_string($payload['objet'])||$d['nbpomme_original']!==$payload['nbpomme']||$d['nbmateriaux_original']!==$payload['nbmateriaux']||$d['objet']!==trim($payload['objet'])||(string)$d['niveau']!==$payload['niveau']||$d['date_souhaitee']!==$payload['date_souhaitee'])throw new DomainException('Cette création est déjà enregistrée avec un autre contenu.',409);
            return ['demand_id'=>$d['id'],'state'=>$d['etat']];
        }
        $out=botConvoyAction($p,$game,'demande',null,$payload)[1];
        return ['demand_id'=>$out['demande']['id'],'state'=>$out['demande']['etat']];
    }
    if(in_array($action,['convoy-demands','convoy-pending'],true)){
        $validation=$action==='convoy-pending'?'NOT valide':'valide';
        $rows=$p->query("SELECT id::text,iddemandeur::text,pseudo_demandeur,objet,niveau,auto_production,nbpomme_restant::text,nbmateriaux_restant::text,date_validation,(SELECT max(date_ajustement) FROM demandes_ajustements a WHERE a.iddemande=demandes_convois.id) AS adjusted_at FROM demandes_convois WHERE ".$validation." AND NOT fini AND NOT annulee ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $projectionNow=gmdate('c');$colonyPlayers=[];$colonyLevels=[];if($action==='convoy-demands'){$colonyLevels=scriptConvoyCurrentColonyLevels($p);foreach($game->query("SELECT player_id::text,field::text,master_player_id::text,alliance_tag FROM players WHERE server='s5' AND is_present AND upper(alliance_tag)='UPTEP'")->fetchAll(PDO::FETCH_ASSOC) as $cp)$colonyPlayers[$cp['player_id']]=$cp;}
        $q=$game->prepare("SELECT pseudo,field::text,imported_at,alliance_tag FROM players WHERE server='s5' AND player_id=? AND is_present");$production=[];
        foreach($rows as &$row){$id=(string)$row['iddemandeur'];$q->execute([$id]);$player=$q->fetch(PDO::FETCH_ASSOC)?:[];$row['target']=$player['pseudo']??null;$row['self']=$id===(string)$member['id'];
            if($action==='convoy-demands'){$production[$id]??=scriptConvoyProduction($player);$row['production']=$production[$id];$cp=$colonyPlayers;if(!isset($cp[$id]))$cp[$id]=$player;$row['production']['capacity_per_cycle']=scriptConvoyColonyCapacity($id,$cp,$colonyLevels);$row['production']['colonies_included']=true;$row['production']['projection']=scriptConvoyProjection($p,$game,$row,$projectionNow);}
        }unset($row);
        return ['demands'=>$rows,'permissions'=>['is_chaine'=>$member['is_chaine'],'player_id'=>(string)$member['id']]];
    }
    if($action==='convoy-check'){
        $d=botDemand($p,botNumber($body['demand_id']??null,true));$apple=botNumber($body['apples']??null);$wood=botNumber($body['wood']??null);
        if(!$d['valide']||$d['fini']||$d['annulee']||(string)$d['iddemandeur']===(string)$member['id'])throw new DomainException('Cette demande ne peut plus recevoir ce convoi.',409);
        if($apple==='0'&&$wood==='0')throw new DomainException('Quantités nulles.',422);
        $q=$game->prepare("SELECT pseudo FROM players WHERE server='s5' AND player_id=? AND is_present");$q->execute([$d['iddemandeur']]);$target=$q->fetchColumn();
        if(!$target||$target!==($body['target']??null))throw new DomainException('Le destinataire a changé. Actualise les demandes.',409);
        return ['ready'=>true,'auto_production'=>(bool)$d['auto_production'],'overage'=>botConvoyOverage($d,$apple,$wood)];
    }
    $gameId=botNumber($body['game_id']??null,true);$apple=botNumber($body['apples']??null);$wood=botNumber($body['wood']??null);
    if($apple==='0'&&$wood==='0')throw new DomainException('Le convoi ne contient aucune ressource.',422);
    $pseudo=$body['target']??null;if(!is_string($pseudo)||trim($pseudo)===''||mb_strlen($pseudo)>100)throw new DomainException('Destinataire invalide.',422);
    $q=$game->prepare('SELECT player_id::text FROM players WHERE server=\'s5\' AND lower(pseudo)=lower(?) AND is_present');$q->execute([trim($pseudo)]);$targets=$q->fetchAll(PDO::FETCH_COLUMN);
    if(count($targets)!==1)throw new DomainException('Le destinataire n’est pas reconnu dans les joueurs S5. Réessaie après la mise à jour des joueurs.',422);
    $target=(string)$targets[0];if($target===(string)$member['id'])throw new DomainException('Un joueur ne peut pas s’envoyer un convoi à lui-même.',422);
    $command='script-convoy:s5:'.$member['id'].':'.$gameId;
    $demand=$action==='convoy-declare'?botNumber($body['demand_id']??null,true):null;
    if($action==='convoy-declare'&&($body['confirmed']??false)!==true)throw new DomainException('Confirme la déclaration du convoi.',422);
    $prior=scriptConvoyResult($p,$command,$member,$target,$apple,$wood,$demand);if($prior)return $prior;
    $d=botDemand($p,$demand);if((string)$d['iddemandeur']!==$target)throw new DomainException('Cette demande ne correspond pas au destinataire du convoi.',422);
    $arrival=botTimestamp($body['arrival']??null);$stamp=(new DateTimeImmutable($arrival))->getTimestamp();
    if($stamp>time()+366*86400)throw new DomainException('Heure d’arrivée expirée ou invalide. Recharge la page du jeu.',422);
    // The send time is persisted before the game POST; recovery preserves it.
    $sent=botTimestamp($body['sent_at']??null);$sentStamp=(new DateTimeImmutable($sent))->getTimestamp();
    if($sentStamp>time()+60||$sentStamp<time()-30*86400||$stamp<$sentStamp)throw new DomainException('Dates du convoi invalides.',422);
    $payload=['date_convoi'=>$sent,'commande_id'=>$command,'discord_id'=>$member['discord'],'nbpomme'=>$apple,'nbmateriaux'=>$wood,'date_arrivee'=>$arrival,'auto_production_confirmed'=>true,'overage_confirmed'=>['pommes'=>$apple,'materiaux'=>$wood]];
    try{botConvoyAction($p,$game,'convoi',$demand,$payload,'scriptConvoyEvent');}
    catch(DomainException $e){
        // Concurrent tab/reload can calculate a slightly different arrival for the
        // same game ID. Return the committed declaration, without another debit/event.
        if($e->getCode()!==409)throw $e;
        $prior=scriptConvoyResult($p,$command,$member,$target,$apple,$wood,$demand);if($prior)return $prior;throw $e;
    }
    return scriptConvoyResult($p,$command,$member,$target,$apple,$wood,$demand);
}
