<?php
declare(strict_types=1);
function floodJson($v): string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
function floodDate($v): DateTimeImmutable {
 if(!is_string($v)||!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/',$v))throw new DomainException('Date de flood invalide.',422);
 $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$v,new DateTimeZone('UTC'));
 if(!$d||$d->format('Y-m-d\TH:i:s\Z')!==$v)throw new DomainException('Date de flood invalide.',422);return $d;
}
function floodResolve(PDO $p,PDO $game,array $b): array {
 $rows=$b['floods']??null;if(!is_array($rows)||!array_is_list($rows)||!count($rows)||count($rows)>20)throw new DomainException('Colle entre 1 et 20 floods par déclaration.',422);
 $captured=floodDate($b['captured_at']??null);$now=time();if($captured->getTimestamp()>$now+60||$captured->getTimestamp()<$now-3600)throw new DomainException('Formulaire expiré. Recolle un relevé à jour.',422);
 $out=[];
 foreach($rows as $r){
  if(!is_array($r))throw new DomainException('Flood invalide.',422);
  $pseudo=$r['pseudo']??null;$location=$r['location']??null;$troops=$r['troops']??null;
  if(!is_string($pseudo)||trim($pseudo)===''||strlen($pseudo)>200||!in_array($location,['Terrain de chasse','Dôme','Loge'],true)||($troops!==null&&(!is_string($troops)||strlen($troops)>2000)))throw new DomainException('Pseudo, lieu ou troupes invalides.',422);
  $arrival=floodDate($r['arrival_at']??null);$delay=$arrival->getTimestamp()-$captured->getTimestamp();if($delay<1||$delay>31*86400||$arrival->getTimestamp()<=$now)throw new DomainException('Une arrivée est déjà passée ou sa durée est invalide. Recolle le relevé à jour.',422);
  $q=$game->prepare("SELECT player_id::text,pseudo FROM players WHERE server='s5' AND is_present=true AND lower(pseudo)=lower(?)");$q->execute([trim($pseudo)]);$targets=$q->fetchAll(PDO::FETCH_ASSOC);$target=count($targets)===1?$targets[0]:null;$discord=null;$warning=null;
  if($target){$q=$p->prepare("SELECT iddiscord::text FROM chaine_members WHERE server='s5' AND player_id=? AND iddiscord IS NOT NULL");$q->execute([$target['player_id']]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);if(count($ids)===1){$q=$p->prepare("SELECT count(*) FROM chaine_members WHERE server='s5' AND iddiscord=?");$q->execute([$ids[0]]);if((int)$q->fetchColumn()===1)$discord=$ids[0];}}
  if(!$target)$warning='Pseudo introuvable ou ambigu : aucun MP.';elseif(!$discord)$warning='Aucun ID Discord unique associé : aucun MP.';
  $out[]=['pseudo'=>$target['pseudo']??trim($pseudo),'location'=>$location,'troops'=>$troops,'arrival_at'=>$arrival->format('Y-m-d\TH:i:s\Z'),'target_id'=>$target['player_id']??null,'target_discord'=>$discord,'warning'=>$warning];
 }
 return $out;
}
function floodCreate(PDO $p,PDO $game,array $b): array {
 $discord=botNumber($b['discord_id']??null,true);$command=$b['commande_id']??null;
 if(!is_string($command)||!preg_match('/\A[A-Za-z0-9:-]{1,100}\z/',$command))throw new DomainException('Identifiant de déclaration invalide.',422);
 $hash=hash('sha256',floodJson($b));$p->beginTransaction();
 try{
  $actor=botActor($p,$game,$discord,false);
  $q=$p->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))');$q->execute(['flood-sender:'.$actor['id']]);
  $q=$p->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))');$q->execute(['flood:'.$command]);
  $q=$p->prepare('SELECT payload_hash,response FROM bot_flood_batches WHERE commande_id=?');$q->execute([$command]);$old=$q->fetch(PDO::FETCH_ASSOC);
  if($old){if(!hash_equals($old['payload_hash'],$hash))throw new DomainException('Cette déclaration a déjà été utilisée avec un autre contenu.',409);$p->commit();return json_decode($old['response'],true,512,JSON_THROW_ON_ERROR);}
  $rows=floodResolve($p,$game,$b);
  foreach($rows as $i=>$r)foreach(['target_id','target_discord'] as $key)if(!array_key_exists($key,$b['floods'][$i])||$b['floods'][$i][$key]!==$r[$key])throw new DomainException('Une association joueur a changé. Recommence la déclaration pour vérifier le destinataire.',409);
  $q=$p->prepare("INSERT INTO bot_flood_batches(commande_id,payload_hash,response) VALUES (?,?,'{}')");$q->execute([$command,$hash]);$saved=[];
  foreach($rows as $i=>$r){$q=$p->prepare('INSERT INTO floods(commande_id,ordinal,sender_id,sender_pseudo,sender_discord,target_id,target_pseudo,target_discord,location,troops,arrival_at,captured_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id::text');$q->execute([$command,$i,$actor['id'],$actor['pseudo'],$discord,$r['target_id'],$r['pseudo'],$r['target_discord'],$r['location'],$r['troops'],$r['arrival_at'],$b['captured_at']]);$saved[]=$r+['id'=>$q->fetchColumn(),'sender_pseudo'=>$actor['pseudo']];}
  $out=['ok'=>true,'floods'=>$saved];$q=$p->prepare('UPDATE bot_flood_batches SET response=?::jsonb WHERE commande_id=?');$q->execute([floodJson($out),$command]);$p->commit();return $out;
 }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
function floodList(PDO $p,PDO $game,array $b): array {
 $actor=botActor($p,$game,botNumber($b['discord_id']??null,true),false);$scope=$b['scope']??'all';if(!in_array($scope,['all','self'],true))throw new DomainException('Liste invalide.',422);
 $page=botNumber($b['page']??'1',true);if((int)$page>100000)throw new DomainException('Page invalide.',422);
 $where='arrival_at>now()';$params=[];if($scope==='self'){$where.=' AND target_id=?';$params[]=$actor['id'];}
 $q=$p->prepare('SELECT count(*) FROM floods WHERE '.$where);$q->execute($params);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/5));$page=min((int)$page,$pages);
 $q=$p->prepare('SELECT id::text,sender_pseudo,target_pseudo AS pseudo,location,troops,arrival_at FROM floods WHERE '.$where.' ORDER BY arrival_at,id LIMIT 5 OFFSET '.(($page-1)*5));$q->execute($params);
 return ['ok'=>true,'floods'=>$q->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page,'pages'=>$pages];
}
