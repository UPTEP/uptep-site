<?php
declare(strict_types=1);
function hourlyCollectionDue(int $id): bool {
 $q=db()->prepare("SELECT resources_synced_at IS NULL OR resources_synced_at<=now()-interval '5 minutes' FROM user_stats WHERE user_id=?");$q->execute([$id]);$r=$q->fetch(PDO::FETCH_NUM);return !$r||(bool)$r[0];
}
/** Called inside the authorized settings transaction, with its preferences row locked. */
function saveHourlyCollection(int $id,array $body): bool {
 // Workers now come from Reine.php (saveWorkersCollection); an "ouvrieres" value sent by a page
 // loaded before that change is accepted and ignored.
 $values=$body['values']??null;$keys=['nourriture','materiaux','terrain'];
 if(!is_array($values)||!in_array(count($values),[3,4],true)||(count($values)===4&&!array_key_exists('ouvrieres',$values)))throw new DomainException('Relevé incomplet.',422);
 foreach($keys as $key){$v=$values[$key]??null;if(!is_string($v)||!preg_match('/^(0|[1-9][0-9]{0,18})$/D',$v)||strlen($v)>strlen((string)PHP_INT_MAX)||(strlen($v)===strlen((string)PHP_INT_MAX)&&strcmp($v,(string)PHP_INT_MAX)>0))throw new DomainException('Quantité invalide.',422);}
 if(!hourlyCollectionDue($id))return false;
 $json=json_encode(['resources'=>['nourriture'=>$values['nourriture'],'materiaux'=>$values['materiaux'],'terrain'=>$values['terrain']]],JSON_THROW_ON_ERROR);
 $q=db()->prepare("INSERT INTO user_stats(user_id,data,resources_synced_at) VALUES (?,?::jsonb,now()) ON CONFLICT(user_id) DO UPDATE SET data=user_stats.data||EXCLUDED.data,resources_synced_at=now(),version=user_stats.version+1,updated_at=now()");$q->execute([$id,$json]);return true;
}
/** Workers read on Reine.php (#armee_initial, the game's own count), at every visit of that page. */
function saveWorkersCollection(int $id,array $body): void {
 $values=$body['values']??null;$v=is_array($values)&&count($values)===1?($values['total']??null):null;
 if(!is_string($v)||!preg_match('/^(0|[1-9][0-9]{0,18})$/D',$v)||strlen($v)>strlen((string)PHP_INT_MAX)||(strlen($v)===strlen((string)PHP_INT_MAX)&&strcmp($v,(string)PHP_INT_MAX)>0))throw new DomainException('Nombre d’ouvrières invalide.',422);
 $json=json_encode(['workers'=>['total'=>$v]],JSON_THROW_ON_ERROR);
 $q=db()->prepare("INSERT INTO user_stats(user_id,data,workers_synced_at) VALUES (?,?::jsonb,now()) ON CONFLICT(user_id) DO UPDATE SET data=user_stats.data||EXCLUDED.data,workers_synced_at=now(),version=user_stats.version+1,updated_at=now()");$q->execute([$id,$json]);
}
