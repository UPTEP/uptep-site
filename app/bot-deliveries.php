<?php
declare(strict_types=1);
function botDeliveryTravel(?array $origin,?array $target,int $speed): array {
 foreach([$origin,$target] as $point)foreach(['x','y'] as $key)if(!isset($point[$key])||!is_numeric($point[$key])||(float)$point[$key]<0)return ['distance'=>null,'seconds'=>null];
 $d=hypot((float)$target['x']-(float)$origin['x'],(float)$target['y']-(float)$origin['y']);
 return ['distance'=>$d,'seconds'=>(int)round(86400*(1-exp(-$d/350))*7.375*pow(0.9,$speed))];
}
function botDeliveries(PDO $p,PDO $game,array $query): array {
 if(array_diff(array_keys($query),['discord_id','vitesse','page']))throw new DomainException('Paramètre inconnu.',422);
 $speed=$query['vitesse']??null;$page=$query['page']??'1';
 if(!is_string($speed)||!preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/',$speed))throw new DomainException('Renseigne un niveau de Vitesse d’attaque entre 0 et 999.',422);
 if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,5}\z/',$page))throw new DomainException('Page invalide.',422);
 $actor=botActor($p,$game,botNumber($query['discord_id']??null,true),false);
 $q=$p->prepare('SELECT id::text,iddemandeur::text,pseudo_demandeur,nbpomme_restant::text,nbmateriaux_restant::text FROM demandes_convois WHERE valide AND NOT fini AND NOT annulee AND iddemandeur<>? ORDER BY id');$q->execute([$actor['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
 $ids=array_values(array_unique(array_merge([$actor['id']],array_column($rows,'iddemandeur'))));
 $q=$game->prepare("SELECT player_id::text,x,y FROM players WHERE server='s5' AND is_present=true AND player_id IN (".implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$coords=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$coords[$r['player_id']]=$r;
 foreach($rows as &$r)$r+=botDeliveryTravel($coords[$actor['id']]??null,$coords[$r['iddemandeur']]??null,(int)$speed);unset($r);
 usort($rows,fn($a,$b)=>($a['distance']===null)<=>($b['distance']===null) ?: ($a['distance']??0)<=>($b['distance']??0) ?: (int)$a['id']<=>(int)$b['id']);
 $total=count($rows);$pages=max(1,(int)ceil($total/8));$page=min((int)$page,$pages);
 return ['ok'=>true,'joueur'=>$actor['pseudo'],'vitesse'=>(int)$speed,'page'=>$page,'pages'=>$pages,'total'=>$total,'lignes'=>array_slice($rows,($page-1)*8,8)];
}
