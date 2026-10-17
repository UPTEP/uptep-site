<?php
declare(strict_types=1);
require_once __DIR__.'/script-convoys.php';
require_once __DIR__.'/bot-floods.php';
function scriptFloodMatch(PDO $p,array $member,array $f):array {
 $q=$p->prepare('SELECT f.id::text,f.target_pseudo,f.location FROM script_flood_links l JOIN floods f ON f.id=l.flood_id WHERE l.sender_id=? AND l.game_id=?');$q->execute([$member['id'],$f['game_id']]);$linked=$q->fetch(PDO::FETCH_ASSOC);
 if($linked){if(mb_strtolower($linked['target_pseudo'])!==mb_strtolower($f['pseudo'])||$linked['location']!==$f['location'])throw new DomainException('Cet identifiant d’attaque est déjà associé à un autre flood.',409);return ['state'=>'declared','id'=>$linked['id']];}
 // Legacy Discord reports have no game ID. Never claim a flood already bound to another attack.
 $q=$p->prepare("SELECT f.id::text,f.troops,abs(extract(epoch FROM (f.arrival_at-?::timestamptz))) AS gap FROM floods f WHERE f.sender_id=? AND lower(f.target_pseudo)=lower(?) AND f.location=? AND abs(extract(epoch FROM (f.arrival_at-?::timestamptz)))<=120 AND NOT EXISTS(SELECT 1 FROM script_flood_links l WHERE l.flood_id=f.id) ORDER BY f.id");$q->execute([$f['arrival_at'],$member['id'],$f['pseudo'],$f['location'],$f['arrival_at']]);$matches=[];
 $norm=fn($v)=>mb_strtolower(preg_replace('/[\s\x{00a0}\x{202f}.]+/u','',trim($v??'')));
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$a=$norm($r['troops']);$b=$norm($f['troops']);if($a!==''&&$b!==''&&$a!==$b)continue;if((float)$r['gap']>3&&($a===''||$b===''||$a!==$b))continue;$matches[]=$r;}
 if(count($matches)>1)return ['state'=>'ambiguous'];
 if(count($matches)===1)return ['state'=>'existing','id'=>$matches[0]['id']];
 return ['state'=>'new'];
}
function scriptFloods(PDO $p,PDO $game,array $actor,array $b):array {
 if(array_diff(array_keys($b),['action','pseudo','alliance','captured_at','floods','confirmed']))throw new DomainException('Champ inconnu.',422);
 $action=$b['action']??'';if(!in_array($action,['flood-check','flood-declare'],true))throw new DomainException('Action inconnue.',422);
 $member=scriptConvoyIdentity($p,$game,$actor);$items=$b['floods']??null;
 if(!is_array($items)||!array_is_list($items)||!count($items)||count($items)>20||($action==='flood-declare'&&count($items)!==1))throw new DomainException('Liste de floods invalide.',422);
 $seen=[];foreach($items as &$f){if(!is_array($f)||array_diff(array_keys($f),['game_id','pseudo','location','troops','arrival_at']))throw new DomainException('Flood invalide.',422);$f['game_id']=botNumber($f['game_id']??null,true);if(isset($seen[$f['game_id']]))throw new DomainException('Identifiant d’attaque répété.',422);$seen[$f['game_id']]=true;
 if(!is_string($f['pseudo']??null)||trim($f['pseudo'])===''||strlen($f['pseudo'])>200||!in_array($f['location']??null,['Terrain de chasse','Dôme','Loge'],true)||!array_key_exists('troops',$f)||($f['troops']!==null&&(!is_string($f['troops'])||strlen($f['troops'])>2000)))throw new DomainException('Données du flood invalides.',422);floodDate($f['arrival_at']??null);
 }unset($f);
 if($action==='flood-check'){
 $results=array_map(fn($f)=>['game_id'=>$f['game_id']]+scriptFloodMatch($p,$member,$f),$items);$counts=[];
 foreach($results as $r)if($r['state']==='existing')$counts[$r['id']]=($counts[$r['id']]??0)+1;
 foreach($results as &$r)if($r['state']==='existing'&&$counts[$r['id']]>1)$r=['game_id'=>$r['game_id'],'state'=>'ambiguous'];unset($r);
 return ['floods'=>$results];
 }
 if(($b['confirmed']??false)!==true)throw new DomainException('Confirme la déclaration.',422);
 $p->beginTransaction();try{
 $q=$p->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))');$q->execute(['flood-sender:'.$member['id']]);
 $f=$items[0];$match=scriptFloodMatch($p,$member,$f);
 if($match['state']==='ambiguous')throw new DomainException('Plusieurs floods correspondent. Vérifie les déclarations sur Discord.',409);
 if($match['state']==='declared'){$p->commit();return $match;}
 if($match['state']==='existing'){
 $q=$p->prepare('INSERT INTO script_flood_links(sender_id,game_id,flood_id) VALUES(?,?,?)');$q->execute([$member['id'],$f['game_id'],$match['id']]);$p->commit();return ['state'=>'declared','id'=>$match['id']];
 }
 $r=floodResolve($p,$game,['captured_at'=>$b['captured_at']??null,'floods'=>[$f]])[0];
 $command='script-flood:'.$member['id'].':'.$f['game_id'];
 $q=$p->prepare("INSERT INTO bot_flood_batches(commande_id,payload_hash,response) VALUES (?,?,'{}')");$q->execute([$command,hash('sha256',floodJson($f))]);
 $q=$p->prepare('INSERT INTO floods(commande_id,ordinal,sender_id,sender_pseudo,sender_discord,target_id,target_pseudo,target_discord,location,troops,arrival_at,captured_at) VALUES (?,0,?,?,?,?,?,?,?,?,?,?) RETURNING id::text');
 $q->execute([$command,$member['id'],$member['pseudo'],$member['discord'],$r['target_id'],$r['pseudo'],$r['target_discord'],$r['location'],$r['troops'],$r['arrival_at'],$b['captured_at']]);$id=$q->fetchColumn();
 $out=['ok'=>true,'floods'=>[$r+['id'=>$id,'sender_pseudo'=>$member['pseudo']]]];
 $q=$p->prepare('UPDATE bot_flood_batches SET response=?::jsonb WHERE commande_id=?');$q->execute([floodJson($out),$command]);
 $q=$p->prepare('INSERT INTO script_flood_links(sender_id,game_id,flood_id) VALUES(?,?,?)');$q->execute([$member['id'],$f['game_id'],$id]);
 scriptConvoyEvent($p,'flood',$out);$p->commit();return ['state'=>'declared','id'=>$id];
 }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
