<?php
declare(strict_types=1);

/** Saves hunts read by UPTEP+ or pasted on Discord. Listed hunts are inserted or refreshed. When the
 * list is complete (UPTEP+ on AcquerirTerrain.php or the Ressources.php hunting box), hunts still due
 * that no longer appear are marked cancelled; a partial list (Discord) only adds. Hunts whose arrival
 * has passed stay as history. Runs inside the caller's transaction. */
function scriptHuntsSync(PDO $p,?int $userId,string $playerId,mixed $values,bool $complete=true,string $source='userscript'): int {
 if(!in_array($source,['userscript','discord'],true))throw new LogicException('Unknown hunt source.');
 if(!is_array($values)||!array_is_list($values)||count($values)>50)throw new DomainException('Relevé de chasses invalide.',422);
 $now=time();$hunts=[];
 foreach($values as $h){
  if(!is_array($h)||array_diff(array_keys($h),['id','area','arrival_at']))throw new DomainException('Chasse invalide.',422);
  $id=$h['id']??null;$area=$h['area']??null;$at=$h['arrival_at']??null;
  if($id!==null&&(!is_string($id)||!preg_match('/\A[1-9][0-9]{0,17}\z/',$id)))throw new DomainException('Identifiant de chasse invalide.',422);
  if(!is_string($area)||!preg_match('/\A[1-9][0-9]{0,12}\z/',$area))throw new DomainException('Surface de chasse invalide.',422);
  if(!is_string($at)||!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/',$at))throw new DomainException('Heure d’arrivée invalide.',422);
  $ts=(new DateTimeImmutable($at))->getTimestamp();
  if($ts<$now-600||$ts>$now+31*86400)throw new DomainException('Heure d’arrivée hors limites.',422);
  $hunts[]=['id'=>$id,'area'=>$area,'at'=>$at];
 }
 $q=$p->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))');$q->execute(['hunts:'.$playerId]);
 // Same hunt seen elsewhere: same area and an arrival within 3 minutes (Discord times are to the minute).
 $near=static function(array $h,array $kept,bool $withoutGameId)use($p,$playerId){
  $q=$p->prepare("SELECT id FROM script_hunts WHERE player_id=? AND cancelled_at IS NULL AND area=?".($withoutGameId?' AND game_hunt_id IS NULL':'')."
     AND abs(extract(epoch FROM arrival_at-?::timestamptz))<=180 AND NOT (id=ANY(?::bigint[]))
     ORDER BY abs(extract(epoch FROM arrival_at-?::timestamptz)) LIMIT 1");
  $q->execute([$playerId,$h['area'],$h['at'],'{'.implode(',',$kept).'}',$h['at']]);return $q->fetchColumn();
 };
 $kept=[];
 foreach($hunts as $h){
  if($h['id']!==null){
   // A hunt first declared without its game id (Discord, text layout) receives it now.
   $q=$p->prepare('SELECT 1 FROM script_hunts WHERE player_id=? AND game_hunt_id=?');$q->execute([$playerId,$h['id']]);
   if(!$q->fetchColumn()&&($match=$near($h,$kept,true))!==false){$q=$p->prepare('UPDATE script_hunts SET game_hunt_id=? WHERE id=?');$q->execute([$h['id'],$match]);}
   $q=$p->prepare('INSERT INTO script_hunts(player_id,user_id,game_hunt_id,area,arrival_at,source) VALUES (?,?,?,?,?,?)
     ON CONFLICT (player_id,game_hunt_id) DO UPDATE SET user_id=coalesce(EXCLUDED.user_id,script_hunts.user_id),area=EXCLUDED.area,arrival_at=EXCLUDED.arrival_at,last_seen_at=now(),cancelled_at=NULL RETURNING id');
   $q->execute([$playerId,$userId,$h['id'],$h['area'],$h['at'],$source]);$kept[]=(int)$q->fetchColumn();continue;
  }
  $match=$near($h,$kept,false);
  if($match!==false){
   // Keep the more precise arrival of a hunt already known with its game id.
   $q=$p->prepare('UPDATE script_hunts SET user_id=coalesce(?,user_id),arrival_at=CASE WHEN game_hunt_id IS NULL THEN ?::timestamptz ELSE arrival_at END,last_seen_at=now() WHERE id=?');$q->execute([$userId,$h['at'],$match]);$kept[]=(int)$match;
  }else{$q=$p->prepare('INSERT INTO script_hunts(player_id,user_id,area,arrival_at,source) VALUES (?,?,?,?,?) RETURNING id');$q->execute([$playerId,$userId,$h['area'],$h['at'],$source]);$kept[]=(int)$q->fetchColumn();}
 }
 if(!$complete)return count($hunts);
 // A hunt still due a minute from now but absent from a complete list was cancelled in the game.
 $q=$p->prepare("UPDATE script_hunts SET cancelled_at=now() WHERE player_id=? AND cancelled_at IS NULL AND arrival_at>now()+interval '1 minute' AND NOT (id=ANY(?::bigint[]))");
 $q->execute([$playerId,'{'.implode(',',$kept).'}']);
 return count($hunts);
}
