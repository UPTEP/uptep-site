<?php
declare(strict_types=1);
require_once __DIR__.'/script-hunts.php';

/* Hunts pasted on Discord by members who do not use UPTEP+. The bot parses and shows the hunts, the
 * member confirms, then the list is saved as partial: it adds or refreshes, never cancels. */

function huntDeclare(PDO $p,PDO $game,array $b): array {
 if(array_diff(array_keys($b),['discord_id','captured_at','hunts']))throw new DomainException('Champ inconnu.',422);
 $actor=botActor($p,$game,botNumber($b['discord_id']??null,true),false);
 $hunts=$b['hunts']??null;
 if(!is_array($hunts)||!array_is_list($hunts)||!count($hunts))throw new DomainException('Aucune chasse à enregistrer.',422);
 $values=[];foreach($hunts as $h){if(!is_array($h)||array_diff(array_keys($h),['area','arrival_at']))throw new DomainException('Chasse invalide.',422);$values[]=['id'=>null,'area'=>$h['area']??null,'arrival_at'=>$h['arrival_at']??null];}
 // The site account is optional: members without UPTEP+ may have no site account at all.
 $q=$p->prepare("SELECT id FROM users WHERE discord_login_id=? AND status='active'");$q->execute([$actor['discord']]);$user=$q->fetchColumn();
 $p->beginTransaction();
 try{$count=scriptHuntsSync($p,$user===false?null:(int)$user,$actor['id'],$values,false,'discord');$p->commit();}
 catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
 return ['ok'=>true,'count'=>$count]+huntList($p,$game,['discord_id'=>$actor['discord']]);
}

/** Hunts in progress of the interaction author only. */
function huntList(PDO $p,PDO $game,array $b): array {
 $actor=botActor($p,$game,botNumber($b['discord_id']??null,true),false);
 $q=$p->prepare("SELECT area::text,arrival_at,source,game_hunt_id IS NOT NULL AS exact FROM script_hunts WHERE player_id=? AND cancelled_at IS NULL AND arrival_at>now() ORDER BY arrival_at,id LIMIT 50");
 $q->execute([$actor['id']]);
 $rows=array_map(static fn($r)=>['area'=>$r['area'],'arrival_at'=>(new DateTimeImmutable($r['arrival_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),'source'=>$r['source'],'exact'=>(bool)$r['exact']],$q->fetchAll(PDO::FETCH_ASSOC));
 return ['ok'=>true,'pseudo'=>$actor['pseudo'],'hunts'=>$rows];
}
