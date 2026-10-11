<?php
declare(strict_types=1);
require_once __DIR__.'/player-events.php';
require_once __DIR__.'/alliance-details.php';
function observatory(array $body): array {
 $p=db(true);$p->exec("SET statement_timeout='8s'");$action=$body['action']??'';
 if($action==='overview'){
  $counts=[];foreach(['players','alliances'] as $table)$counts[$table]=(int)$p->query("SELECT count(*) FROM $table WHERE server='s5' AND is_present=true")->fetchColumn();
  return ['counts'=>$counts];
 }
 $kind=$body['kind']??'';$term=$body['q']??'';
 if(!in_array($kind,['players','alliances'],true)||!is_string($term)||mb_strlen($term)>100)throw new DomainException('Recherche invalide.',422);
 $term=trim($term);$alliance=$kind==='alliances';$column=$alliance?'tag':'pseudo';
 $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$term).'%';
 if($action==='suggest'){
  if($term==='')return ['suggestions'=>[]];
  $sql=$alliance?"SELECT tag AS value,name AS label FROM alliances WHERE server='s5' AND is_present=true AND (public.fzzz_normalize(tag) LIKE public.fzzz_normalize(?) ESCAPE '!' OR public.fzzz_normalize(name) LIKE public.fzzz_normalize(?) ESCAPE '!') ORDER BY tag LIMIT 12":"SELECT pseudo AS value,coalesce(alliance_tag,'') AS label,player_id FROM players WHERE server='s5' AND is_present=true AND public.fzzz_normalize(pseudo) LIKE public.fzzz_normalize(?) ESCAPE '!' ORDER BY pseudo LIMIT 12";
  $q=$p->prepare($sql);$q->execute($alliance?[$pattern,$pattern]:[$pattern]);return ['suggestions'=>$q->fetchAll(PDO::FETCH_ASSOC)];
 }
 if($action!=='stats')throw new DomainException('Action inconnue.',422);
 $id=$body['player_id']??null;
 if($id!==null && (!is_string($id)||!preg_match('/^[1-9][0-9]{0,17}$/D',$id)))throw new DomainException('Identifiant invalide.',422);
 if($term==='' && $id===null)throw new DomainException('Indique un joueur ou une alliance.',422);
 if(!$alliance&&$id!==null){$q=$p->prepare("SELECT * FROM players WHERE server='s5' AND player_id=?");$q->execute([$id]);}
 else{$q=$p->prepare("SELECT * FROM $kind WHERE server='s5' AND public.fzzz_normalize($column)=public.fzzz_normalize(?) ORDER BY is_present DESC LIMIT 1");$q->execute([$term]);}
 $entity=$q->fetch(PDO::FETCH_ASSOC)?:null;
 if(!$entity){$result=observatory(['action'=>'suggest','kind'=>$kind,'q'=>$term]);if(count($result['suggestions'])===1)return observatory(['action'=>'stats','kind'=>$kind,'q'=>$result['suggestions'][0]['value']]);return ['entity'=>null,'candidates'=>$result['suggestions']];}
 $metrics=$alliance?['total_field'=>['Terrain de chasse','', '#4f6536'],'total_building_score'=>['Bâtiments','points','#985e29'],'total_technology_score'=>['Technologie','points','#7e503a'],'total_trophy_score'=>['Trophées','points','#8c7934'],'players_count'=>['Membres','joueurs','#655975']]:['field'=>['Terrain de chasse','','#4f6536'],'building_score'=>['Bâtiments','points','#985e29'],'technology_score'=>['Technologie','points','#7e503a'],'trophy_score'=>['Trophées','points','#8c7934']];
 $idColumn=$alliance?'tag':'player_id';
 $q=$p->prepare("SELECT h.*,e.export_version,e.exported_at FROM historique_$kind h JOIN historique_exports e ON e.id=h.export_id WHERE e.server='s5' AND e.export_type=? AND h.$idColumn=? AND NOT EXISTS(SELECT 1 FROM historique_exports newer WHERE newer.server=e.server AND newer.export_type=e.export_type AND newer.export_version=e.export_version AND newer.id>e.id) ORDER BY e.exported_at DESC,e.id DESC LIMIT 5001");$q->execute([$kind,$entity[$idColumn]]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$events=$alliance?[]:playerEvents(array_reverse($rows));$truncated=count($rows)>5000;if($truncated)array_pop($rows);$history=[];
 foreach(array_reverse($rows) as $h){$date=(new DateTimeImmutable($h['exported_at']))->setTimezone(new DateTimeZone('Europe/Paris'));$point=['date'=>$date->format('d/m/Y'),'datetime'=>$date->format('d/m/Y à H:i'),'timestamp'=>$date->getTimestamp()*1000,'export_version'=>$h['export_version']];foreach($metrics as $key=>$unused)$point[$key]=$h[$key]===null?null:(string)$h[$key];if(!$alliance)$point['alliance_tag']=$h['alliance_tag'];$history[]=$point;}
 $fields=$alliance?['tag','name','players_count','is_present','export_version','diplomacy']:['player_id','pseudo','alliance_tag','grade','x','y','is_present','on_holiday','is_banned','master_player_id','export_version'];$clean=[];foreach(array_unique(array_merge($fields,array_keys($metrics))) as $field)$clean[$field]=isset($metrics[$field])&&$entity[$field]!==null?(string)$entity[$field]:$entity[$field];
 if($alliance)$clean['diplomacy']=json_decode($entity['diplomacy']??'{}',true)?:[];
 $details=$alliance?allianceDetails($p,$entity['tag'],array_reverse($rows)):[];
 return $details+['entity'=>$clean,'kind'=>$kind,'metrics'=>$metrics,'history'=>$history,'truncated'=>$truncated,'events'=>$events];
}
