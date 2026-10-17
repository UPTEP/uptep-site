<?php
declare(strict_types=1);
function botNumber(mixed $value,bool $positive=false): string {
    if(is_int($value))$value=(string)$value;
    if(!is_string($value)||!preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/',$value)||strlen($value)===19&&strcmp($value,'9223372036854775807')>0||($positive&&$value==='0'))throw new DomainException('Identifiant ou quantité invalide : utiliser un entier positif en chaîne JSON.',422);
    return $value;
}
// Read the private allowlist on every request, so removals apply without restarting the bot.
function botConfiguredAdmins(): array {
    $path=defined('UPTEP_BOT_ENV_FILE')?UPTEP_BOT_ENV_FILE:'/home/uptep/discord-bot/.env';
    if(!is_readable($path))return [];
    $contents=file_get_contents($path);if($contents===false)return [];
    if(!preg_match('/^\h*(?:export\h+)?ADMIN_DISCORD_IDS\h*=\h*(.*?)\h*$/m',$contents,$match))return [];
    $raw=trim($match[1]);
    if(strlen($raw)>=2 && (($raw[0]==='"' && str_ends_with($raw,'"')) || ($raw[0]==="'" && str_ends_with($raw,"'"))))$raw=substr($raw,1,-1);
    else $raw=preg_replace('/\h+#.*$/','',$raw);
    if(trim($raw)==='')return [];
    $ids=array_map('trim',explode(',',$raw));
    foreach($ids as $id){try{botNumber($id,true);}catch(DomainException $e){return [];}}
    return array_values(array_unique($ids));
}
function botActor(PDO $p,PDO $game,string $discord,bool $validate,bool $chainOnly=false): array {
    $q=$p->prepare("SELECT player_id::text FROM chaine_members WHERE server='s5' AND iddiscord=?".($p->inTransaction()?' FOR SHARE':''));$q->execute([$discord]);$rows=$q->fetchAll(PDO::FETCH_COLUMN);
    if(count($rows)===0)throw new DomainException('Ton compte Discord n’est pas encore associé à un joueur UPTEP. Contacte NoobEnCDI pour qu’il ajoute ton ID Discord à ton compte joueur.',403);
    if(count($rows)!==1)throw new DomainException('Ton ID Discord est associé à plusieurs joueurs. Contacte NoobEnCDI pour corriger cette association.',403);
    $q=$game->prepare("SELECT pseudo FROM players WHERE server='s5' AND player_id=? AND upper(alliance_tag)='UPTEP' AND is_present=true");$q->execute([$rows[0]]);$pseudo=$q->fetchColumn();
    if($pseudo===false)throw new DomainException('Le joueur ne fait plus partie de l’alliance UPTEP.',403);
    if($validate && ($chainOnly || !in_array($discord,botConfiguredAdmins(),true))){
        $q=$p->prepare('SELECT status,is_chaine,must_change_password'.($chainOnly?',script_enabled':'').' FROM users WHERE game_player_id=?'.($p->inTransaction()?' FOR SHARE':''));$q->execute([$rows[0]]);$users=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($users)!==1 || $users[0]['status']!=='active' || !$users[0]['is_chaine'] || $users[0]['must_change_password'] || ($chainOnly && !$users[0]['script_enabled']))throw new DomainException($chainOnly?'Le droit chaîne sur un compte UPTEP actif est nécessaire.':'Un droit administrateur du bot ou le droit chaîne sur un compte UPTEP validé est nécessaire.',403);
    }
    return ['id'=>$rows[0],'pseudo'=>$pseudo,'discord'=>$discord];
}
function botDemandVersion(array $d): string {
    $values=[];
    foreach(['id','nbpomme_original','nbmateriaux_original','objet','niveau','date_souhaitee'] as $key)$values[$key]=$d[$key]===null?null:(string)$d[$key];
    return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
}
function botDemand(PDO $p,string $id): array {
    $q=$p->prepare('SELECT * FROM demandes_convois WHERE id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r)throw new DomainException('Demande introuvable.',404);
    foreach(['id','iddemandeur','discord_demandeur','nbpomme_original','nbpomme_restant','nbmateriaux_original','nbmateriaux_restant','idvalidateur','discord_validateur','idannulateur','discord_annulateur','discord_cloture'] as $key)if($r[$key]!==null)$r[$key]=(string)$r[$key];
    $r['etat']=$r['annulee']?'annulee':($r['fini']?'terminee':($r['valide']?'en_cours':'attente'));
    $r['version_modification']=botDemandVersion($r);
    $q=$p->prepare('SELECT id::text,discord_acteur::text,pseudo_acteur,pommes_avant::text,pommes_apres::text,materiaux_avant::text,materiaux_apres::text,date_ajustement FROM demandes_ajustements WHERE iddemande=? ORDER BY id DESC LIMIT 10');$q->execute([$id]);$r['ajustements']=$q->fetchAll(PDO::FETCH_ASSOC);
    return $r;
}
function botConvoyAction(PDO $p,PDO $game,string $action,?string $id,array $body,?callable $eventSink=null,bool $chainOnly=false): array {
    $allowed=['suspension'=>['commande_id','discord_id'],'modification'=>['commande_id','discord_id','nbpomme','nbmateriaux','objet','niveau','date_souhaitee','version_attendue'],'ajustement'=>['commande_id','discord_id','nbpomme_restant','nbmateriaux_restant','nbpomme_attendu','nbmateriaux_attendu'],'terminaison'=>['commande_id','discord_id'],'demande'=>['commande_id','discord_id','nbpomme','nbmateriaux','objet','niveau','date_souhaitee'],'annulation'=>['commande_id','discord_id'],'validation'=>['commande_id','discord_id','version_attendue'],'convoi'=>['commande_id','discord_id','nbpomme','nbmateriaux','date_convoi','date_arrivee']][$action];
    if(array_diff(array_keys($body),$allowed))throw new DomainException('Champ inconnu dans la requête.',422);
    $command=$body['commande_id']??null;
    if(!is_string($command)||!preg_match('/\A[A-Za-z0-9_.:-]{1,100}\z/',$command))throw new DomainException('commande_id obligatoire : identifiant unique de la commande Discord.',422);
    $discord=botNumber($body['discord_id']??null,true);
    $payload=['action'=>$action,'id'=>$id,'discord_id'=>$discord];
    if($action==='modification'||($action==='validation'&&array_key_exists('version_attendue',$body))){
        $version=$body['version_attendue']??null;
        if(!is_string($version)||!preg_match('/\A[a-f0-9]{64}\z/',$version))throw new DomainException('Version attendue obligatoire : rouvre le formulaire depuis le tableau.',422);
        $payload['version_attendue']=$version;
    }
    if(in_array($action,['demande','modification','convoi'],true)){
        $apple=botNumber($body['nbpomme']??null);$wood=botNumber($body['nbmateriaux']??null);
        if($apple==='0'&&$wood==='0')throw new DomainException('Au moins une quantité doit être supérieure à zéro.',422);
        $payload+=['nbpomme'=>$apple,'nbmateriaux'=>$wood];
    }
    if($action==='ajustement')foreach(['nbpomme_restant','nbmateriaux_restant','nbpomme_attendu','nbmateriaux_attendu'] as $key)$payload[$key]=botNumber($body[$key]??null);
    $date=null;
    if($action==='convoi' && isset($body['date_convoi'])){
        $raw=$body['date_convoi'];
        if(!is_string($raw)||!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/',$raw))throw new DomainException('date_convoi doit être une date ISO 8601 avec fuseau horaire.',422);
        try{$parsed=new DateTimeImmutable($raw);$warnings=DateTimeImmutable::getLastErrors();}catch(Throwable $e){throw new DomainException('Date invalide.',422);}
        if($warnings && ($warnings['warning_count']||$warnings['error_count']))throw new DomainException('Date invalide.',422);
        if($parsed->getTimestamp()>time()+60)throw new DomainException('Un convoi effectué ne peut pas être daté dans le futur.',422);
        $date=$parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
    }
    if($action==='convoi')$payload['date_convoi']=$date;
    $objet=null;$niveau=null;$souhaitee=null;$arrivee=null;
    if(in_array($action,['demande','modification'],true)){
        if(isset($body['objet'])){if(!is_string($body['objet']) || !($objet=trim($body['objet'])) || mb_strlen($objet)>160)throw new DomainException('Objet de la demande invalide (1 à 160 caractères).',422);$payload['objet']=$objet;}
        if(isset($body['niveau'])){$niveau=botNumber($body['niveau'],true);if((int)$niveau>999)throw new DomainException('Niveau attendu entre 1 et 999.',422);$payload['niveau']=$niveau;}
        if(($objet===null)!==($niveau===null))throw new DomainException('Renseigne ensemble la construction/recherche et son niveau.',422);
        if(isset($body['date_souhaitee'])){
            $souhaitee=$body['date_souhaitee'];
            if(!is_string($souhaitee)||!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/',$souhaitee,$parts)||!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]))throw new DomainException('Date souhaitée invalide (AAAA-MM-JJ).',422);
            if($souhaitee<(new DateTimeImmutable('now',new DateTimeZone('Europe/Paris')))->format('Y-m-d'))throw new DomainException('La date souhaitée est déjà passée.',422);
            $payload['date_souhaitee']=$souhaitee;
        }
    }
    if($action==='modification'){
        foreach(['objet','niveau','date_souhaitee'] as $key)if(!array_key_exists($key,$body))throw new DomainException('Renseigne tous les champs de la demande à modifier.',422);
        $payload+=['objet'=>$objet,'niveau'=>$niveau,'date_souhaitee'=>$souhaitee];
    }
    if($action==='convoi' && isset($body['date_arrivee'])){$arrivee=botTimestamp($body['date_arrivee']);$payload['date_arrivee']=$arrivee;}
    $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
    $p->beginTransaction();
    try {
        if($action==='suspension'&&!$chainOnly&&!in_array($discord,botConfiguredAdmins(),true))throw new DomainException('La suspension est réservée aux administrateurs listés dans ADMIN_DISCORD_IDS.',403);
        $actor=botActor($p,$game,$discord,$action==='validation'||($chainOnly&&$action==='suspension'),$chainOnly);
        // Same command is serialized even across different endpoints or requests.
        $q=$p->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))');$q->execute(['uptep-bot:'.$command]);
        $q=$p->prepare('SELECT empreinte,statut,reponse FROM bot_commandes WHERE commande_id=?');$q->execute([$command]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        if($prior){if(in_array($action,['annulation','terminaison','ajustement','modification'],true)){$priorDemand=botDemand($p,$id);if($actor['id']!==$priorDemand['iddemandeur'])botActor($p,$game,$discord,true,$chainOnly);}if(!hash_equals($prior['empreinte'],$hash))throw new DomainException('commande_id déjà utilisé pour une autre action ou un autre contenu.',409);$p->commit();return [(int)$prior['statut'],json_decode($prior['reponse'],true,512,JSON_THROW_ON_ERROR),true];}
        $status=200;
        if($action==='demande'){
            $q=$p->prepare('INSERT INTO demandes_convois(iddemandeur,pseudo_demandeur,discord_demandeur,nbpomme_original,nbpomme_restant,nbmateriaux_original,nbmateriaux_restant,objet,niveau,date_souhaitee) VALUES(?,?,?,?,?,?,?,?,?,?) RETURNING id');
            $q->execute([$actor['id'],$actor['pseudo'],$discord,$apple,$apple,$wood,$wood,$objet,$niveau,$souhaitee]);$id=(string)$q->fetchColumn();$status=201;
        }else{
            $q=$p->prepare('SELECT * FROM demandes_convois WHERE id=? FOR UPDATE');$q->execute([$id]);$d=$q->fetch(PDO::FETCH_ASSOC);
            if(!$d)throw new DomainException('Demande introuvable.',404);
            if($d['annulee'])throw new DomainException('Cette demande a été annulée.',409);
            if($action==='modification'){
                if($actor['id']!==(string)$d['iddemandeur'])botActor($p,$game,$discord,true,$chainOnly);
                if($d['valide']||$d['fini'])throw new DomainException('Seule une demande en attente de validation peut être modifiée. Rouvre le tableau.',409);
                if((string)$d['nbpomme_restant']!==(string)$d['nbpomme_original']||(string)$d['nbmateriaux_restant']!==(string)$d['nbmateriaux_original'])throw new DomainException('Cette demande conserve des livraisons ou ajustements : valide-la à nouveau avant de modifier son restant.',409);
                if(!hash_equals(botDemandVersion($d),$payload['version_attendue']))throw new DomainException('La demande a changé depuis l’ouverture du formulaire. Rouvre Modifier une demande.',409);
                $q=$p->prepare('UPDATE demandes_convois SET nbpomme_original=?,nbpomme_restant=?,nbmateriaux_original=?,nbmateriaux_restant=?,objet=?,niveau=?,date_souhaitee=? WHERE id=?');
                $q->execute([$apple,$apple,$wood,$wood,$objet,$niveau,$souhaitee,$id]);
            }elseif($action==='ajustement'){
                if($actor['id']!==(string)$d['iddemandeur'])botActor($p,$game,$discord,true,$chainOnly);
                if(!$d['valide']||$d['fini'])throw new DomainException('Seule une demande validée et en cours peut être ajustée.',409);
                if((string)$d['nbpomme_restant']!==$payload['nbpomme_attendu']||(string)$d['nbmateriaux_restant']!==$payload['nbmateriaux_attendu'])throw new DomainException('Le restant a changé depuis l’ouverture du formulaire. Rouvre Modifier une demande pour utiliser les nouvelles quantités.',409);
                $q=$p->prepare('SELECT (?::numeric-COALESCE(sum(nbpomme),0))::text AS pommes,(?::numeric-COALESCE(sum(nbmateriaux),0))::text AS materiaux FROM convois_effectues WHERE iddemande=? AND NOT annule');$q->execute([$d['nbpomme_original'],$d['nbmateriaux_original'],$id]);$maximum=$q->fetch(PDO::FETCH_ASSOC);
                $apple=$payload['nbpomme_restant'];$wood=$payload['nbmateriaux_restant'];
                if((int)$apple>(int)$maximum['pommes']||(int)$wood>(int)$maximum['materiaux'])throw new DomainException('Le restant ne peut pas dépasser les quantités initiales moins les convois déjà déclarés et non annulés.',422);
                if($apple===(string)$d['nbpomme_restant']&&$wood===(string)$d['nbmateriaux_restant'])throw new DomainException('Les quantités restantes sont inchangées.',422);
                $q=$p->prepare('INSERT INTO demandes_ajustements(iddemande,discord_acteur,pseudo_acteur,pommes_avant,pommes_apres,materiaux_avant,materiaux_apres) VALUES(?,?,?,?,?,?,?) RETURNING id::text');$q->execute([$id,$discord,$actor['pseudo'],$d['nbpomme_restant'],$apple,$d['nbmateriaux_restant'],$wood]);
                $done=$apple==='0'&&$wood==='0';
                $q=$p->prepare('UPDATE demandes_convois SET nbpomme_restant=?,nbmateriaux_restant=?,fini=?::boolean,fin_manuelle=?::boolean,date_fin=CASE WHEN ?::boolean THEN now() ELSE NULL END,discord_cloture=?,pseudo_cloture=? WHERE id=?');$q->execute([$apple,$wood,$done?'true':'false',$done?'true':'false',$done?'true':'false',$done?$discord:null,$done?$actor['pseudo']:null,$id]);
            }elseif($action==='annulation'){
                if($d['fini'])throw new DomainException('Une demande terminée ne peut pas être annulée.',409);
                if($actor['id']!==(string)$d['iddemandeur'])botActor($p,$game,$discord,true,$chainOnly);
                $q=$p->prepare('UPDATE demandes_convois SET annulee=true,date_annulation=now(),idannulateur=?,pseudo_annulateur=?,discord_annulateur=? WHERE id=?');$q->execute([$actor['id'],$actor['pseudo'],$discord,$id]);
            }elseif($action==='terminaison'){
                if($actor['id']!==(string)$d['iddemandeur'])botActor($p,$game,$discord,true,$chainOnly);
                if(!$d['valide']||$d['fini'])throw new DomainException('Seule une demande validée et en cours peut être terminée.',409);
                $q=$p->prepare('UPDATE demandes_convois SET fini=true,fin_manuelle=true,date_fin=now(),discord_cloture=?,pseudo_cloture=? WHERE id=?');$q->execute([$discord,$actor['pseudo'],$id]);
            }elseif($action==='suspension'){
                if(!$d['valide']||$d['fini'])throw new DomainException('Seule une demande validée et en cours peut être suspendue.',409);
                $q=$p->prepare('UPDATE demandes_convois SET valide=false,idvalidateur=NULL,pseudo_validateur=NULL,discord_validateur=NULL,date_validation=NULL WHERE id=?');$q->execute([$id]);
            }elseif($action==='validation'){
                if($d['valide'])throw new DomainException('Demande déjà validée.',409);
                if(isset($payload['version_attendue'])&&!hash_equals(botDemandVersion($d),$payload['version_attendue']))throw new DomainException('La demande a été modifiée. Consulte son nouveau contenu avant de la valider.',409);
                $q=$p->prepare('UPDATE demandes_convois SET valide=true,idvalidateur=?,pseudo_validateur=?,discord_validateur=?,date_validation=now() WHERE id=?');$q->execute([$actor['id'],$actor['pseudo'],$discord,$id]);
            }else{
                if(!$d['valide']||$d['fini'])throw new DomainException('La demande doit être validée et non terminée.',409);
                if($actor['id']===(string)$d['iddemandeur'])throw new DomainException('Un joueur ne peut pas s’envoyer un convoi à lui-même.',422);
                if((int)$apple>(int)$d['nbpomme_restant']||(int)$wood>(int)$d['nbmateriaux_restant'])throw new DomainException('Les quantités dépassent le restant de la demande.',409);
                $q=$p->prepare('INSERT INTO convois_effectues(iddemande,idenvoyeur,pseudo_envoyeur,discord_envoyeur,nbpomme,nbmateriaux,date_convoi,date_arrivee) VALUES(?,?,?,?,?,?,COALESCE(?::timestamptz,now()),?::timestamptz) RETURNING id::text,date_convoi,date_arrivee');$q->execute([$id,$actor['id'],$actor['pseudo'],$discord,$apple,$wood,$date,$arrivee]);$convoy=$q->fetch(PDO::FETCH_ASSOC);
                $remainingApple=(int)$d['nbpomme_restant']-(int)$apple;$remainingWood=(int)$d['nbmateriaux_restant']-(int)$wood;$done=$remainingApple===0&&$remainingWood===0;
                $q=$p->prepare('UPDATE demandes_convois SET nbpomme_restant=?,nbmateriaux_restant=?,fini=?,date_fin=CASE WHEN ?::boolean THEN now() ELSE NULL END WHERE id=?');$q->execute([$remainingApple,$remainingWood,$done?'true':'false',$done?'true':'false',$id]);$status=201;
            }
        }
        $out=['ok'=>true,'demande'=>botDemand($p,$id)];
        if(isset($convoy))$out['convoi']=$convoy+['iddemande'=>$id,'idenvoyeur'=>$actor['id'],'pseudo_envoyeur'=>$actor['pseudo'],'discord_envoyeur'=>$discord,'nbpomme'=>$apple,'nbmateriaux'=>$wood];
        $q=$p->prepare('INSERT INTO bot_commandes(commande_id,empreinte,discord_acteur,action,statut,reponse) VALUES(?,?,?,?,?,?::jsonb)');$q->execute([$command,$hash,$discord,$action,$status,json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        if($eventSink!==null && in_array($action,['validation','convoi'],true))$eventSink($p,$action,$out);
        $p->commit();return [$status,$out,false];
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
function botListDemands(PDO $p,array $query): array {
    if(array_diff(array_keys($query),['etat','limite','apres','include','terminees_depuis']))throw new DomainException('Paramètre inconnu.',422);
    $state=$query['etat']??'les_deux';if(!is_string($state)||!in_array($state,['attente','en_cours','les_deux'],true))throw new DomainException('etat attendu : attente, en_cours ou les_deux.',422);
    $limit=botNumber($query['limite']??'100',true);if((int)$limit>100)throw new DomainException('limite doit être comprise entre 1 et 100.',422);
    $after=botNumber($query['apres']??'0');
    $where=$state==='attente'?' AND NOT valide':($state==='en_cours'?' AND valide':'');
    $params=[$after];$closed='';
    if(isset($query['terminees_depuis'])){$since=botTimestamp($query['terminees_depuis']);$closed=' OR (fini AND date_fin>=?::timestamptz)';$params[]=$since;}
    if(isset($query['include'])&&$query['include']!=='convois')throw new DomainException('include attendu : convois.',422);
    $q=$p->prepare('SELECT id::text FROM demandes_convois WHERE NOT annulee AND id>? AND ((NOT fini'.$where.')'.$closed.') ORDER BY demandes_convois.id LIMIT '.((int)$limit+1));$q->execute($params);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$more=count($ids)>(int)$limit;$ids=array_slice($ids,0,(int)$limit);
    $demands=array_map(fn($id)=>botDemand($p,$id),$ids);
    if(($query['include']??null)==='convois'){
        // All selected requests in one query; no HTTP request per player.
        $byDemand=[];
        if($ids){$q=$p->prepare('SELECT id::text,iddemande::text,idenvoyeur::text,pseudo_envoyeur,discord_envoyeur::text,nbpomme::text,nbmateriaux::text,date_convoi,date_enregistrement,date_arrivee,annule,date_annulation,discord_annulateur::text,pseudo_annulateur FROM convois_effectues WHERE iddemande IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id');$q->execute($ids);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$byDemand[$row['iddemande']][]=$row;}
        foreach($demands as &$d)$d['convois']=$byDemand[$d['id']]??[];unset($d);
    }
    return ['ok'=>true,'demandes'=>$demands,'suite_apres'=>$more?end($ids):null];
}

function botListConvoys(PDO $p,string $id,array $query): array {
    $id=botNumber($id,true);
    if(array_diff(array_keys($query),['limite','apres']))throw new DomainException('Paramètre inconnu.',422);
    $limit=botNumber($query['limite']??'100',true);
    if((int)$limit>100)throw new DomainException('limite doit être comprise entre 1 et 100.',422);
    $after=botNumber($query['apres']??'0');
    botDemand($p,$id); // Distinguish a missing request from an empty convoy history.
    $q=$p->prepare('SELECT id::text,iddemande::text,idenvoyeur::text,pseudo_envoyeur,discord_envoyeur::text,nbpomme::text,nbmateriaux::text,date_convoi,date_enregistrement,date_arrivee,annule,date_annulation,discord_annulateur::text,pseudo_annulateur FROM convois_effectues WHERE iddemande=? AND id>? ORDER BY id LIMIT '.((int)$limit+1));
    $q->execute([$id,$after]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>(int)$limit;$rows=array_slice($rows,0,(int)$limit);
    return ['ok'=>true,'iddemande'=>$id,'convois'=>$rows,'suite_apres'=>$more?$rows[count($rows)-1]['id']:null];
}

function botTimestamp(mixed $raw): string {
    if(!is_string($raw)||!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/',$raw))throw new DomainException('Date attendue en ISO 8601 avec secondes et fuseau horaire.',422);
    try{$date=new DateTimeImmutable($raw);$warnings=DateTimeImmutable::getLastErrors();}catch(Throwable $e){throw new DomainException('Date invalide.',422);}
    if($warnings&&($warnings['warning_count']||$warnings['error_count']))throw new DomainException('Date invalide.',422);
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
}
function botMember(PDO $p,PDO $game,string $discord): array {
    $discord=botNumber($discord,true);$actor=botActor($p,$game,$discord,false);$manager=false;
    try{botActor($p,$game,$discord,true);$manager=true;}catch(DomainException $e){if($e->getCode()!==403)throw $e;}
    return ['ok'=>true,'membre'=>['discord_id'=>$discord,'player_id'=>$actor['id'],'pseudo'=>$actor['pseudo'],'is_chaine'=>$manager]];
}

// Listing scoped to the authenticated interaction author, including older/closed requests.
function botCancelableConvoys(PDO $p,PDO $game,array $query): array {
    if(array_diff(array_keys($query),['discord_id','apres','limite']))throw new DomainException('Paramètre inconnu.',422);
    $member=botMember($p,$game,botNumber($query['discord_id']??null,true))['membre'];
    $after=botNumber($query['apres']??'0');$limit=botNumber($query['limite']??'100',true);
    if((int)$limit>100)throw new DomainException('limite doit être comprise entre 1 et 100.',422);
    $params=[$after];$where='';if(!$member['is_chaine']){$where=' AND c.idenvoyeur=?';$params[]=$member['player_id'];}
    $q=$p->prepare('SELECT c.id::text,c.iddemande::text,c.idenvoyeur::text,c.pseudo_envoyeur,c.nbpomme::text,c.nbmateriaux::text,c.date_convoi,c.date_arrivee,d.pseudo_demandeur FROM convois_effectues c JOIN demandes_convois d ON d.id=c.iddemande WHERE NOT c.annule AND c.id>?'.$where.' ORDER BY c.id LIMIT '.((int)$limit+1));$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>(int)$limit;$rows=array_slice($rows,0,(int)$limit);
    return ['ok'=>true,'convois'=>$rows,'suite_apres'=>$more?end($rows)['id']:null];
}
function botCancelConvoy(PDO $p,PDO $game,string $id,array $body,bool $chainOnly=false): array {
    if(array_diff(array_keys($body),['commande_id','discord_id']))throw new DomainException('Champ inconnu.',422);
    $command=$body['commande_id']??null;if(!is_string($command)||!preg_match('/\A[A-Za-z0-9_.:-]{1,100}\z/',$command))throw new DomainException('commande_id invalide.',422);
    $id=botNumber($id,true);$discord=botNumber($body['discord_id']??null,true);
    $hash=hash('sha256',json_encode(['action'=>'annulation_convoi','id'=>$id,'discord_id'=>$discord],JSON_THROW_ON_ERROR));
    $p->beginTransaction();
    try{
        $actor=botActor($p,$game,$discord,false);
        $q=$p->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))');$q->execute(['uptep-bot:'.$command]);
        $q=$p->prepare('SELECT iddemande::text,idenvoyeur::text FROM convois_effectues WHERE id=?');$q->execute([$id]);$link=$q->fetch(PDO::FETCH_ASSOC);
        if(!$link)throw new DomainException('Convoi introuvable.',404);
        if($actor['id']!==$link['idenvoyeur'])botActor($p,$game,$discord,true,$chainOnly);
        $q=$p->prepare('SELECT empreinte,statut,reponse FROM bot_commandes WHERE commande_id=?');$q->execute([$command]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        if($prior){if(!hash_equals($prior['empreinte'],$hash))throw new DomainException('commande_id déjà utilisé pour un autre contenu.',409);$p->commit();return [(int)$prior['statut'],json_decode($prior['reponse'],true,512,JSON_THROW_ON_ERROR),true];}
        // All writers lock the demand first, so cancel/send operations serialize together.
        $q=$p->prepare('SELECT * FROM demandes_convois WHERE id=? FOR UPDATE');$q->execute([$link['iddemande']]);$d=$q->fetch(PDO::FETCH_ASSOC);
        $q=$p->prepare('SELECT * FROM convois_effectues WHERE id=? FOR UPDATE');$q->execute([$id]);$c=$q->fetch(PDO::FETCH_ASSOC);
        if($c['annule'])throw new DomainException('Ce convoi est déjà annulé.',409);
        $q=$p->prepare('UPDATE convois_effectues SET annule=true,date_annulation=now(),discord_annulateur=?,pseudo_annulateur=? WHERE id=?');$q->execute([$discord,$actor['pseudo'],$id]);
        $q=$p->prepare('UPDATE demandes_convois SET nbpomme_restant=nbpomme_restant+?,nbmateriaux_restant=nbmateriaux_restant+?,fini=fin_manuelle,date_fin=CASE WHEN fin_manuelle THEN date_fin ELSE NULL END WHERE id=?');$q->execute([$c['nbpomme'],$c['nbmateriaux'],$link['iddemande']]);
        $out=['ok'=>true,'demande'=>botDemand($p,$link['iddemande']),'convoi'=>['id'=>$id,'annule'=>true]];
        $q=$p->prepare('INSERT INTO bot_commandes(commande_id,empreinte,discord_acteur,action,statut,reponse) VALUES(?,?,?,?,200,?::jsonb)');$q->execute([$command,$hash,$discord,'annulation_convoi',json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        $p->commit();return [200,$out,false];
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
