<?php
declare(strict_types=1);

/** Keep exact resource arithmetic in the userscript. These expressions describe
 * direct transfers of each colony's own terrain yield, with equal debit/credit.
 */
function scriptConvoyColonyCapacity(string $id,array $players,array $levels,bool $assumeBase=true): ?array {
 $self=$players[$id]??null;if(!$self||!isset($self['field']))return null;
 $out=['own'=>(string)$self['field'],'loss'=>null,'gains'=>[],'assumed_rates'=>false];
 $internal=static fn($row)=>$row&&strtoupper(trim($row['alliance_tag']??''))==='UPTEP';
 if(!$internal($self))return $out;
 $rate=static function(string $master)use($levels,$assumeBase,&$out):?int{
  $level=$levels[$master]??null;
  if(!is_string($level)||!preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/',$level)){
   if(!$assumeBase)return null;$out['assumed_rates']=true;return 20;
  }
  return min(100,20+(int)$level);
 };
 $master=(string)($self['master_player_id']??'');
 if($master!==''&&$master!=='0'&&$master!==$id&&$internal($players[$master]??null)){
  $percent=$rate($master);if($percent===null)return null;
  $out['loss']=['terrain'=>(string)$self['field'],'percent'=>$percent];
 }
 foreach($players as $childId=>$child){
  if((string)$childId===$id||(string)($child['master_player_id']??'')!==$id||!$internal($child))continue;
  $percent=$rate($id);if($percent===null)return null;
  $out['gains'][]=['terrain'=>(string)$child['field'],'percent'=>$percent];
 }
 return $out;
}
function scriptConvoyColonyTimeline(string $id,array $snapshots,array $observations,bool $assumeBase=true): array {
 $events=[];foreach($snapshots as $snapshot)$events[]=['at'=>$snapshot['observed_at'],'snapshot'=>$snapshot['players']];
 foreach($observations as $row)$events[]=['at'=>$row['observed_at'],'player'=>(string)$row['player_id'],'level'=>$row['level']];
 usort($events,static fn($a,$b)=>(new DateTimeImmutable($a['at']))<=>(new DateTimeImmutable($b['at'])));
 $players=[];$levels=[];$out=[];
 foreach($events as $event){
  if(isset($event['snapshot']))$players=$event['snapshot'];else $levels[$event['player']]=$event['level'];
  $value=scriptConvoyColonyCapacity($id,$players,$levels,$assumeBase);
  // Group equal timestamps, then compress unchanged capacity expressions.
  $at=(new DateTimeImmutable($event['at']))->format('Y-m-d\TH:i:s.uP');
  if($out&&end($out)['observed_at']===$at)array_pop($out);
  if(!$out||end($out)['value']!==$value)$out[]=['observed_at'=>$at,'value'=>$value];
 }
 return $out;
}
function scriptConvoyCurrentColonyLevels(PDO $p):array {
 $rows=$p->query("SELECT u.game_player_id::text AS player_id,s.data->'buildings'->>'etable_pucerons' AS level FROM users u JOIN user_stats s ON s.user_id=u.id WHERE u.status='active' AND u.game_player_id IS NOT NULL AND (SELECT count(*) FROM users other WHERE other.game_player_id=u.game_player_id AND other.status='active')=1")->fetchAll(PDO::FETCH_ASSOC);
 $levels=[];foreach($rows as $row)$levels[$row['player_id']]=$row['level'];return $levels;
}
function scriptConvoyColonyHistory(PDO $p,PDO $game,string $id,string $anchor,string $now,bool $assumeBase=true):array {
 $q=$game->prepare("WITH exports AS MATERIALIZED (
 SELECT DISTINCT ON(export_version) id,exported_at FROM historique_exports WHERE server='s5' AND export_type='players' AND exported_at<=?::timestamptz AT TIME ZONE 'UTC' ORDER BY export_version,id DESC
 ), seed AS (SELECT * FROM exports WHERE exported_at<=?::timestamptz AT TIME ZONE 'UTC' ORDER BY exported_at DESC LIMIT 1), chosen AS (
 SELECT * FROM seed UNION ALL SELECT * FROM exports WHERE exported_at>?::timestamptz AT TIME ZONE 'UTC')
 SELECT e.id,e.exported_at AT TIME ZONE 'UTC' AS observed_at,h.player_id::text,h.field::text,h.master_player_id::text,h.alliance_tag FROM chosen e JOIN historique_players h ON h.export_id=e.id WHERE upper(h.alliance_tag)='UPTEP' OR h.player_id=? ORDER BY e.exported_at,e.id,h.player_id");
 $q->execute([$now,$anchor,$anchor,$id]);$snapshots=[];
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$key=$row['id'];$snapshots[$key]['observed_at']=$row['observed_at'];$snapshots[$key]['players'][$row['player_id']]=array_intersect_key($row,array_flip(['field','master_player_id','alliance_tag']));}
 $q=$p->prepare("WITH observations AS MATERIALIZED (
 SELECT u.game_player_id::text AS player_id,h.observed_at,h.data->>'etable_pucerons' AS level,h.id FROM users u JOIN member_stat_history h ON h.user_id=u.id WHERE u.status='active' AND h.stat_group='buildings' AND h.observed_at<=?::timestamptz AND (SELECT count(*) FROM users other WHERE other.game_player_id=u.game_player_id AND other.status='active')=1
 ), seed AS (SELECT DISTINCT ON(player_id) player_id,observed_at,level FROM observations WHERE observed_at<=?::timestamptz ORDER BY player_id,observed_at DESC,id DESC)
 SELECT * FROM seed UNION ALL SELECT player_id,observed_at,level FROM observations WHERE observed_at>?::timestamptz ORDER BY observed_at");
 $q->execute([$now,$anchor,$anchor]);$observations=$q->fetchAll(PDO::FETCH_ASSOC);
 return ['timeline'=>scriptConvoyColonyTimeline($id,array_values($snapshots),$observations,$assumeBase),'last_export_at'=>$snapshots?end($snapshots)['observed_at']:null];
}
