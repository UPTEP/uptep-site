<?php
declare(strict_types=1);
function terrainId(mixed $v,bool $zero=false): string {
 if(!is_string($v)||!preg_match($zero?'/\A(?:0|[1-9][0-9]{0,19})\z/':'/\A[1-9][0-9]{0,19}\z/',$v))throw new DomainException('Identifiant terrain invalide.',422);return $v;
}
function terrainJson(array $v): string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
function terrainTotals(PDO $p,string $g,string $c): array {
 $q=$p->prepare("SELECT coalesce(sum(amount) FILTER(WHERE direction='gain'),0)::text gains,coalesce(sum(amount) FILTER(WHERE direction='loss'),0)::text losses,coalesce(sum(CASE WHEN direction='gain' THEN amount ELSE -amount END),0)::text balance FROM terrain_discord_movements WHERE guild_id=? AND channel_id=?");$q->execute([$g,$c]);return $q->fetch(PDO::FETCH_ASSOC);
}
function terrainState(PDO $p,string $g,string $c): array {
 $q=$p->prepare('SELECT last_message_id::text FROM terrain_discord_channels WHERE guild_id=? AND channel_id=?');$q->execute([$g,$c]);$cursor=$q->fetchColumn();
 $q=$p->prepare('SELECT response FROM terrain_discord_batches WHERE guild_id=? AND channel_id=? AND published_message_id IS NULL ORDER BY created_at,id LIMIT 1');$q->execute([$g,$c]);$pending=$q->fetchColumn();
 return ['ok'=>true,'last_message_id'=>$cursor===false?'0':$cursor,'totals'=>terrainTotals($p,$g,$c),'pending'=>$pending===false?null:json_decode($pending,true,512,JSON_THROW_ON_ERROR)];
}
function terrainCommit(PDO $p,string $g,string $c,array $b): array {
 $id=terrainId($b['batch_id']??null);$expected=terrainId($b['expected_message_id']??null,true);$last=terrainId($b['last_message_id']??null,true);
 $reports=$b['reports']??null;$movements=$b['movements']??null;$warnings=$b['warnings']??[];
 if(!is_array($reports)||!array_is_list($reports)||count($reports)>20000||!is_array($movements)||!array_is_list($movements)||count($movements)>10000||!is_array($warnings)||count($warnings)>1000)throw new DomainException('Lot terrain invalide.',422);
 foreach($warnings as $w)if(!is_string($w)||strlen($w)>4000)throw new DomainException('Avertissement invalide.',422);
 $hash=hash('sha256',terrainJson($b));$p->beginTransaction();
 try{
  $q=$p->prepare('INSERT INTO terrain_discord_channels(guild_id,channel_id) VALUES (?,?) ON CONFLICT DO NOTHING');$q->execute([$g,$c]);
  $q=$p->prepare('SELECT last_message_id::text FROM terrain_discord_channels WHERE guild_id=? AND channel_id=? FOR UPDATE');$q->execute([$g,$c]);$current=$q->fetchColumn();
  $q=$p->prepare('SELECT guild_id,channel_id,payload_hash,response FROM terrain_discord_batches WHERE id=?');$q->execute([$id]);$prior=$q->fetch(PDO::FETCH_ASSOC);
  if($prior){if($prior['guild_id']!==$g||$prior['channel_id']!==$c||!hash_equals($prior['payload_hash'],$hash))throw new DomainException('Identifiant de lot déjà utilisé autrement.',409);$p->commit();return json_decode($prior['response'],true,512,JSON_THROW_ON_ERROR);}
  if($current!==$expected)throw new DomainException('La conversation a déjà été actualisée. Relance la commande.',409);
  $q=$p->prepare('SELECT ?::numeric >= ?::numeric');$q->execute([$last,$current]);if(!$q->fetchColumn())throw new DomainException('Le dernier message ne peut pas reculer.',422);
  $q=$p->prepare('SELECT 1 FROM terrain_discord_batches WHERE guild_id=? AND channel_id=? AND published_message_id IS NULL');$q->execute([$g,$c]);if($q->fetchColumn())throw new DomainException('Un solde attend encore sa publication. Relance la commande.',409);
  $q=$p->prepare("INSERT INTO terrain_discord_batches(id,guild_id,channel_id,payload_hash,response,warnings) VALUES (?,?,?,?,'{}',?::jsonb)");$q->execute([$id,$g,$c,$hash,terrainJson($warnings)]);
  $sources=[];$duplicates=[];
  foreach($reports as $r){
   if(!is_array($r))throw new DomainException('Rapport invalide.',422);
   $mid=terrainId($r['id']??null);$author=isset($r['author'])?terrainId($r['author']):null;$text=$r['text']??null;
   if(isset($sources[$mid])||!is_string($text)||strlen($text)>250000)throw new DomainException('Rapport invalide ou répété.',422);
   $q=$p->prepare('SELECT ?::numeric > ?::numeric AND ?::numeric <= ?::numeric');$q->execute([$mid,$expected,$mid,$last]);if(!$q->fetchColumn())throw new DomainException('Rapport hors du créneau de lecture.',422);
   $fingerprint=$author!==null&&preg_match('/\d{2}\/\d{2}\/\d{2,4}/',$text)?hash('sha256',$author.'|'.preg_replace('/\s+/u',' ',trim($text))):null;$duplicate=null;
   if($fingerprint){$q=$p->prepare('SELECT message_id FROM terrain_discord_reports WHERE guild_id=? AND channel_id=? AND fingerprint=? AND duplicate_of IS NULL');$q->execute([$g,$c,$fingerprint]);$duplicate=$q->fetchColumn()?:null;}
   $q=$p->prepare('INSERT INTO terrain_discord_reports(guild_id,channel_id,message_id,author_id,content,fingerprint,duplicate_of,batch_id) VALUES (?,?,?,?,?,?,?,?)');$q->execute([$g,$c,$mid,$author,$text,$fingerprint,$duplicate,$id]);$sources[$mid]=$text;if($duplicate)$duplicates[$mid]=true;
  }
  $seen=[];
  foreach($movements as $m){
   if(!is_array($m))throw new DomainException('Mouvement invalide.',422);
   $mid=terrainId($m['source_id']??null);$event=$m['id']??'';$amount=$m['amount']??'';$quote=$m['quote']??'';$enemy=$m['enemy']??'';
   if(!is_string($event)||!preg_match('/\A'.preg_quote($mid,'/').':[0-9]{1,9}\z/',$event)||isset($seen[$event])||!isset($sources[$mid])||!is_string($amount)||!preg_match('/\A[1-9][0-9]{0,18}\z/',$amount)||!in_array($m['direction']??null,['gain','loss'],true)||!is_string($quote)||$quote===''||!str_contains($sources[$mid],$quote)||!is_string($enemy)||$enemy===''||strlen($enemy)>400)throw new DomainException('Mouvement sans preuve valide.',422);
   foreach(['member','date'] as $field)if(isset($m[$field])&&(!is_string($m[$field])||strlen($m[$field])>400))throw new DomainException('Information de rapport invalide.',422);
   preg_match_all('/[0-9]+/',preg_replace('/\s+/u','',$quote),$numbers);if(!in_array($amount,$numbers[0],true))throw new DomainException('Montant absent de la citation.',422);
   $seen[$event]=true;if(isset($duplicates[$mid]))continue;
   $q=$p->prepare('INSERT INTO terrain_discord_movements(guild_id,channel_id,event_id,message_id,direction,amount,enemy,member,occurred_at_text,quote) VALUES (?,?,?,?,?,?,?,?,?,?)');$q->execute([$g,$c,$event,$mid,$m['direction'],$amount,$enemy,$m['member']??null,$m['date']??null,$quote]);
  }
  $q=$p->prepare('UPDATE terrain_discord_channels SET last_message_id=?,updated_at=now() WHERE guild_id=? AND channel_id=?');$q->execute([$last,$g,$c]);
  $out=['ok'=>true,'batch_id'=>$id,'last_message_id'=>$last,'totals'=>terrainTotals($p,$g,$c),'duplicate_reports'=>count($duplicates)];
  $q=$p->prepare('UPDATE terrain_discord_batches SET response=?::jsonb WHERE id=?');$q->execute([terrainJson($out),$id]);$p->commit();return $out;
 }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
function terrainPublished(PDO $p,string $g,string $c,array $b): array {
 $id=terrainId($b['batch_id']??null);$mid=terrainId($b['published_message_id']??null);
 $q=$p->prepare('UPDATE terrain_discord_batches SET published_message_id=? WHERE id=? AND guild_id=? AND channel_id=? AND (published_message_id IS NULL OR published_message_id=?) RETURNING id');$q->execute([$mid,$id,$g,$c,$mid]);if(!$q->fetchColumn())throw new DomainException('Publication inconnue ou déjà enregistrée autrement.',409);return ['ok'=>true];
}
