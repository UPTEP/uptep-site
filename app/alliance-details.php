<?php
declare(strict_types=1);
function diplomacyDates(array $rows): array {
 $active=[];$previous=null;$events=[];
 foreach($rows as $row){
  $date=(new DateTimeImmutable($row['exported_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Paris'));
  $stamp=['timestamp'=>$date->getTimestamp()*1000,'datetime'=>$date->format('d/m/Y à H:i')];
  $dip=json_decode($row['diplomacy']??'{}',true)?:[];$current=[];
  foreach(['pacts','wars'] as $type)foreach($dip[$type]??[] as $item){$item=is_string($item)?['tag'=>$item]:$item;if(empty($item['tag']))continue;$key=$type.':'.$item['tag'];$current[$key]=$item;
   if(!isset($active[$key]))$active[$key]=$stamp+['at_least'=>$previous===null];
   if($previous!==null && (!isset($previous[$key])||$previous[$key]!=$item))$events[]=$stamp+['type'=>$type,'action'=>isset($previous[$key])?'modified':'started','tag'=>$item['tag']];
  }
  foreach($active as $key=>$unused)if(!isset($current[$key])){if($previous!==null)$events[]=$stamp+['type'=>explode(':',$key,2)[0],'action'=>'ended','tag'=>$previous[$key]['tag']];unset($active[$key]);}
  $previous=$current;
 }
 return ['dates'=>$active,'events'=>array_reverse($events)];
}
function allianceDetails(PDO $p,string $tag,array $rows): array {
 $q=$p->prepare("WITH exports AS MATERIALIZED (
 SELECT DISTINCT ON (export_version) id,exported_at FROM historique_exports WHERE server='s5' AND export_type='players' ORDER BY export_version,id DESC
 ), candidates AS (SELECT DISTINCT h.player_id FROM historique_players h JOIN exports e ON e.id=h.export_id WHERE h.alliance_tag=?), changes AS (
 SELECT h.player_id,h.pseudo,h.alliance_tag,e.exported_at,e.id,
 lag(h.alliance_tag) OVER w AS before_tag,lag(e.id) OVER w AS previous_export
 FROM historique_players h JOIN exports e ON e.id=h.export_id JOIN candidates c ON c.player_id=h.player_id
 WINDOW w AS (PARTITION BY h.player_id ORDER BY e.exported_at,e.id)
 ) SELECT * FROM changes WHERE previous_export IS NOT NULL AND alliance_tag IS DISTINCT FROM before_tag AND (alliance_tag=? OR before_tag=?) ORDER BY exported_at DESC,id DESC,player_id LIMIT 501");
 $q->execute([$tag,$tag,$tag]);$moves=$q->fetchAll(PDO::FETCH_ASSOC);$truncated=count($moves)>500;$moves=array_slice($moves,0,500);
 foreach($moves as &$move){$date=(new DateTimeImmutable($move['exported_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Paris'));$move=['player_id'=>(string)$move['player_id'],'pseudo'=>$move['pseudo'],'before'=>$move['before_tag'],'after'=>$move['alliance_tag'],'timestamp'=>$date->getTimestamp()*1000,'datetime'=>$date->format('d/m/Y à H:i')];}unset($move);
 $map=$p->query("SELECT player_id::text,pseudo,alliance_tag,x,y,field::text FROM players WHERE server='s5' AND is_present=true AND x IS NOT NULL AND y IS NOT NULL ORDER BY player_id")->fetchAll(PDO::FETCH_ASSOC);
 return ['movements'=>$moves,'movements_truncated'=>$truncated,'map'=>$map,'diplomacy_history'=>diplomacyDates($rows)];
}
