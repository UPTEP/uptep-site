<?php
declare(strict_types=1);

// All writes reuse the bot's transactional services. The userscript deliberately
// uses the database chain permission, without the bot's Discord admin allowlist.
function scriptConvoyManagement(PDO $p,PDO $game,array $member,array $body):array {
    $action=$body['action'];
    $fields=[
        'convoy-list'=>['scope','after','state','player_id'],
        'convoy-detail'=>['demand_id','after'],
        'convoy-manage'=>['demand_id','convoy_id','operation','request_key','confirmed','apples','wood','object','level','wanted_date','version','expected_apples','expected_wood','auto_production'],
    ][$action];
    if(array_diff(array_keys($body),array_merge(['action','pseudo','alliance'],$fields)))throw new DomainException('Champ inconnu.',422);
    $permissions=['is_chaine'=>(bool)$member['is_chaine'],'player_id'=>(string)$member['id']];
    if($action==='convoy-list'){
        $scope=$body['scope']??'mine';
        if(!in_array($scope,['mine','history','chain'],true))throw new DomainException('Liste inconnue.',422);
        if($scope==='chain'&&!$permissions['is_chaine'])throw new DomainException('Le droit chaîne est nécessaire.',403);
        $state=$body['state']??($scope==='chain'?'open':'all');
        $filter=['all'=>'','open'=>' AND NOT fini AND NOT annulee','attente'=>' AND NOT valide AND NOT fini AND NOT annulee','en_cours'=>' AND valide AND NOT fini AND NOT annulee','terminee'=>' AND fini AND NOT annulee','annulee'=>' AND annulee'];
        if(!is_string($state)||!isset($filter[$state]))throw new DomainException('État inconnu.',422);
        $after=botNumber($body['after']??'0');$descending=in_array($scope,['mine','history'],true);
        $cursor=$descending?($after==='0'?'':' AND id<?'):' AND id>?';
        $params=(!$descending||$after!=='0')?[$after]:[];
        $where='';if($scope==='mine'){$where=' AND iddemandeur=?';$params[]=$member['id'];}
        if(isset($body['player_id'])){$where.=' AND iddemandeur=?';$params[]=botNumber($body['player_id'],true);}
        $q=$p->prepare('SELECT id::text FROM demandes_convois WHERE serveur=\'s5\''.$cursor.$where.$filter[$state].' ORDER BY demandes_convois.id '.($descending?'DESC':'ASC').' LIMIT 21');$q->execute($params);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
        $more=count($ids)>20;$ids=array_slice($ids,0,20);
        $rows=array_map(fn($id)=>botDemand($p,$id),$ids);
        $players=[];
        if($scope!=='mine'){
            // Choices cover all pages/states, but only current S5 alliance members.
            $requesters=$p->query("SELECT DISTINCT iddemandeur::text FROM demandes_convois WHERE serveur='s5'")->fetchAll(PDO::FETCH_COLUMN);
            if($requesters){$q=$game->prepare("SELECT player_id::text,pseudo FROM players WHERE server='s5' AND upper(alliance_tag)='UPTEP' AND is_present AND player_id IN (".implode(',',array_fill(0,count($requesters),'?')).") ORDER BY lower(pseudo),player_id");$q->execute($requesters);$players=$q->fetchAll(PDO::FETCH_ASSOC);}
        }
        return ['demands'=>$rows,'next'=>$more?end($ids):null,'permissions'=>$permissions,'players'=>$players];
    }
    if($action==='convoy-detail'){
        $id=botNumber($body['demand_id']??null,true);$d=botDemand($p,$id);
        if($d['serveur']!=='s5')throw new DomainException('Demande S5 introuvable.',404);
        $page=botListConvoys($p,$id,['apres'=>botNumber($body['after']??'0'),'limite'=>'20']);
        return ['demand'=>$d,'convoys'=>$page['convois'],'next'=>$page['suite_apres'],'permissions'=>$permissions];
    }
    $operation=$body['operation']??null;
    if(!in_array($operation,['validation','modification','ajustement','annulation','terminaison','suspension','annulation_convoi'],true))throw new DomainException('Opération inconnue.',422);
    if(($body['confirmed']??false)!==true)throw new DomainException('Confirme cette opération.',422);
    $key=$body['request_key']??null;
    if(!is_string($key)||!preg_match('/\A[a-f0-9-]{36}\z/',$key))throw new DomainException('Identifiant de commande invalide.',422);
    $payload=['commande_id'=>'script-manage:'.$member['id'].':'.$key,'discord_id'=>$member['discord']];
    if($operation==='annulation_convoi'){
        $id=botNumber($body['convoy_id']??null,true);
        // botCancelConvoy checks ownership/chain rights again inside its transaction.
        return botCancelConvoy($p,$game,$id,$payload,true)[1];
    }
    $id=botNumber($body['demand_id']??null,true);$d=botDemand($p,$id);
    if($d['serveur']!=='s5')throw new DomainException('Demande S5 introuvable.',404);
    if(in_array($operation,['validation','modification'],true))$payload['version_attendue']=$body['version']??null;
    if($operation==='modification')$payload+=['nbpomme'=>botNumber($body['apples']??null),'nbmateriaux'=>botNumber($body['wood']??null),'objet'=>$body['object']??'','niveau'=>$body['level']??'','date_souhaitee'=>$body['wanted_date']??null];
    if($operation==='ajustement')$payload+=['nbpomme_restant'=>botNumber($body['apples']??null),'nbmateriaux_restant'=>botNumber($body['wood']??null),'nbpomme_attendu'=>botNumber($body['expected_apples']??null),'nbmateriaux_attendu'=>botNumber($body['expected_wood']??null)];
    if(array_key_exists('auto_production',$body)&&in_array($operation,['modification','ajustement'],true)){$payload['auto_production']=$body['auto_production'];$payload['version_attendue']=$body['version']??null;}
    return botConvoyAction($p,$game,$operation,$id,$payload,'scriptConvoyEvent',true)[1];
}
