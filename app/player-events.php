<?php
declare(strict_types=1);
/** Input: deduplicated snapshots in chronological order. No event is inferred before the first observation. */
function playerEvents(array $rows): array {
 $events=[];$previous=null;
 foreach($rows as $row){
  $state=['colonization'=>empty($row['master_player_id'])?null:(string)$row['master_player_id'],'holiday'=>$row['on_holiday']??null,'ban'=>$row['is_banned']??null,'alliance'=>trim($row['alliance_tag']??'')?:null,'grade'=>trim($row['grade']??'')?:null];
  if($previous!==null){
   $date=(new DateTimeImmutable($row['exported_at']))->setTimezone(new DateTimeZone('Europe/Paris'));
   foreach($state as $type=>$value){
    if($previous[$type]===$value)continue;
    if(in_array($type,['holiday','ban'],true)&&($previous[$type]===null||$value===null))continue;
    $events[]=['type'=>$type,'before'=>$previous[$type],'after'=>$value,'timestamp'=>$date->getTimestamp()*1000,'datetime'=>$date->format('d/m/Y à H:i'),'export_version'=>$row['export_version']];
   }
  }
  $previous=$state;
 }
 return array_reverse($events);
}

/** Resolve all colonizers in one read, including players absent from the latest export. */
function playerEventIdentities(PDO $p,array $events): array {
 $ids=[];foreach($events as $event)if($event['type']==='colonization')foreach(['before','after'] as $key){$id=$event[$key]??null;if($id!==null)$ids[(string)$id]=(string)$id;}
 if(!$ids)return [];
 $q=$p->prepare("SELECT player_id::text,pseudo,alliance_tag FROM players WHERE server='s5' AND player_id IN (".implode(',',array_fill(0,count($ids),'?')).')');$q->execute(array_values($ids));
 $players=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $player)$players[$player['player_id']]=['pseudo'=>$player['pseudo'],'alliance_tag'=>$player['alliance_tag']];return $players;
}
