<?php
declare(strict_types=1);
require_once __DIR__.'/script-convoys.php';

/** Opt-in read enrichment: ordinary bot/site API calls keep their existing shape. */
function botProductionQuery(array $query): array {
 $enabled=array_key_exists('estimation',$query);
 if($enabled&&$query['estimation']!=='1')throw new DomainException('estimation attendue : 1.',422);
 unset($query['estimation']);return [$query,$enabled];
}
function botProductionDemands(PDO $p,PDO $game,array $rows): array {
 if(!$rows)return $rows;
 $ids=[];foreach($rows as $row)$ids[]=botNumber((string)$row['id'],true);
 $q=$p->prepare("SELECT id::text,iddemandeur::text,serveur,valide,fini,annulee,date_validation,nbpomme_restant::text,nbmateriaux_restant::text,(SELECT max(date_ajustement) FROM demandes_ajustements a WHERE a.iddemande=demandes_convois.id) AS adjusted_at FROM demandes_convois WHERE id IN (".implode(',',array_fill(0,count($ids),'?')).")");$q->execute($ids);$demands=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $d)$demands[$d['id']]=$d;
 $players=[];foreach($game->query("SELECT player_id::text,field::text,master_player_id::text,alliance_tag,imported_at FROM players WHERE server='s5' AND is_present AND upper(alliance_tag)='UPTEP'")->fetchAll(PDO::FETCH_ASSOC) as $player)$players[$player['player_id']]=$player;
 $levels=scriptConvoyCurrentColonyLevels($p);$now=gmdate('c');$cache=[];
 foreach($rows as &$row){
  unset($row['production']);$d=$demands[(string)$row['id']]??null;if(!$d)continue;
  foreach(['valide','fini','annulee','nbpomme_restant','nbmateriaux_restant'] as $key)$row[$key]=$d[$key];
  if($d['serveur']!=='s5'||!$d['valide']||$d['fini']||$d['annulee']||!$d['date_validation'])continue;
  $id=$d['iddemandeur'];$player=$players[$id]??null;if(!$player){$q=$game->prepare("SELECT field::text,master_player_id::text,alliance_tag,imported_at FROM players WHERE server='s5' AND player_id=? AND is_present");$q->execute([$id]);$player=$q->fetch(PDO::FETCH_ASSOC)?:[];}
  $production=scriptConvoyProduction($player);$map=$players;$map[$id]=$player;
  $production['capacity_per_cycle']=scriptConvoyColonyCapacity($id,$map,$levels);$production['colonies_included']=true;
  $cache[$d['id']]??=scriptConvoyProjection($p,$game,$d,$now);$production['projection']=$cache[$d['id']];$row['production']=$production;
 }unset($row);
 return $rows;
}
